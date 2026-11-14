#!/usr/bin/env python3
"""Chromium UI for RBAC; optional bridge submits actual browser FormData to PHP."""
import os,re,socket,subprocess,tempfile,time,json
from pathlib import Path
from playwright.sync_api import sync_playwright,expect
ROOT=Path(__file__).resolve().parents[1]
PASSWORD='rbac-browser-password-123'

def main():
    bridged=os.getenv('BOOK_TEST_BROWSER_TRANSPORT')=='1'
    with tempfile.TemporaryDirectory(prefix='book-rbac-browser-') as tmp:
        data=Path(tmp);env=dict(os.environ,BOOK_DATA_DIR=tmp,BOOK_SECURE_COOKIE='0',BOOK_ADMIN_HASH=subprocess.check_output(['php','-r','echo password_hash($argv[1],PASSWORD_DEFAULT);',PASSWORD],text=True))
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        base=f'http://127.0.0.1:{port}';server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','public/router.php'],cwd=ROOT,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        try:
            for _ in range(60):
                try:
                    with socket.create_connection(('127.0.0.1',port),timeout=.2):break
                except OSError:time.sleep(.1)
            with sync_playwright() as p:
                opts={'headless':True};exe=os.getenv('BOOK_CHROMIUM')
                if exe:opts['executable_path']=exe
                browser=p.chromium.launch(**opts);owner,writer=[browser.new_page() for _ in range(2)];errors=[];owner.set_default_timeout(5000);writer.set_default_timeout(5000)
                for page in [owner,writer]:page.on('pageerror',lambda e:errors.append(str(e)))
                def load(page,path,status=200):
                    if not bridged:
                        r=page.goto(base+path);assert r.status==status,(r.status,path);return
                    r=page.context.request.get(base+path,max_redirects=0);assert r.status==status,(r.status,path)
                    page.set_content(re.sub(r'<script[^>]*>.*?</script>|<link[^>]*>','',r.text(),flags=re.S));page.add_style_tag(path=str(ROOT/'public/assets/book.css'))
                    page.evaluate('''() => {window.formData=null; document.addEventListener('submit',e=>{e.preventDefault();window.formData={path:e.target.getAttribute('action'),data:Object.fromEntries(new FormData(e.target,e.submitter))};});}''')
                def submit(page,form,fields,button,redirect=True):
                    for k,v in fields.items():
                        field=form.locator(f'[name="{k}"]');field.select_option(v) if field.evaluate('(el)=>el.tagName')=='SELECT' else field.fill(v)
                    if not bridged:
                        with page.expect_navigation(wait_until='domcontentloaded') as nav:form.get_by_role('button',name=button,exact=True).click()
                        assert nav.value.status==200;return page.url.removeprefix(base)
                    form.get_by_role('button',name=button,exact=True).click();payload=page.evaluate('window.formData');assert payload
                    r=page.context.request.post(base+payload['path'],form=payload['data'],max_redirects=0);assert r.status==(303 if redirect else 200),(r.status,r.text());
                    if redirect:path=r.headers['location'];load(page,path);return path
                    page.set_content(re.sub(r'<script[^>]*>.*?</script>|<link[^>]*>','',r.text(),flags=re.S));return payload['path']
                def action(page,name):return page.locator('form').filter(has=page.locator(f'input[name="action"][value="{name}"]'))
                load(owner,'/login');submit(owner,owner.locator('form[action="/login"]'),{'username':'admin','password':PASSWORD},'Sign in')
                load(owner,'/account/users');submit(owner,action(owner,'create'),{'username':'writer','name':'Writer','email':'writer@example.org','password':'writer-browser-password-123'},'Create account')
                load(owner,'/organizations');org=submit(owner,action(owner,'create'),{'name':'RBAC team','slug':'rbac-team'},'Create organization')
                submit(owner,action(owner,'invite'),{'username':'writer'},'Create / replace invitation',False)
                token=re.search(r'/organizations/join#([a-f0-9]{64})',owner.locator('[data-org-invite-link]').input_value()).group(1)
                load(writer,'/login');submit(writer,writer.locator('form[action="/login"]'),{'username':'writer','password':'writer-browser-password-123'},'Sign in');load(writer,'/organizations/join');submit(writer,action(writer,'accept'),{'token':token},'Join organization')
                load(owner,org);submit(owner,action(owner,'role'),{'role':'editor'},'Change role');expect(owner.get_by_text('writer · editor',exact=False)).to_be_visible()
                print('PASS role assigned through browser form',flush=True)
                load(owner,'/workspaces');submit(owner,action(owner,'create'),{'name':'Private documentation','slug':'private-docs'},'Create workspace');prefix=owner.get_by_role('link',name='Reader',exact=True).get_attribute('href').rstrip('/')
                load(writer,prefix+'/studio');expect(writer.locator('[data-editor-form]')).to_have_attribute('data-can-publish','0');assert writer.locator('button[value="publish"]').count()==0
                print('PASS editor has Studio but no publication button',flush=True)
                load(owner,'/workspaces');submit(owner,action(owner,'role'),{'role':'viewer'},'Save access');load(writer,prefix+'/studio',403);load(writer,prefix+'/');assert writer.get_by_role('link',name='Studio',exact=True).count()==0
                print('PASS scope downgrade hides Studio and denies route',flush=True)
                load(owner,'/workspaces');submit(owner,action(owner,'role'),{'role':'none'},'Save access');load(writer,prefix+'/',404);load(writer,'/workspaces');assert writer.get_by_role('heading',name='Private documentation').count()==0
                print('PASS denied scope hidden and inaccessible',flush=True)
                load(owner,'/workspaces');submit(owner,action(owner,'role'),{'role':'inherit'},'Save access');load(writer,prefix+'/studio');expect(writer.locator('[data-editor-form]')).to_be_visible()
                print('PASS inherited role restores access',flush=True)
                assert not errors,errors;browser.close()
        finally:server.terminate();server.wait(timeout=5)
if __name__=='__main__':main()
