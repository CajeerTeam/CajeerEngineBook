#!/usr/bin/env python3
"""Real workspace UI in Chromium. Optional form bridge for loopback-restricted test sandboxes."""
import os, re, socket, subprocess, tempfile, time
from pathlib import Path
from playwright.sync_api import sync_playwright, expect
ROOT=Path(__file__).resolve().parents[1]
PASSWORD='workspace-browser-password-123'

def main():
    bridged=os.getenv('BOOK_TEST_BROWSER_TRANSPORT')=='1'
    with tempfile.TemporaryDirectory(prefix='book-workspaces-browser-') as tmp:
        env=dict(os.environ,BOOK_DATA_DIR=tmp,BOOK_SECURE_COOKIE='0',BOOK_ADMIN_HASH=subprocess.check_output(['php','-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',PASSWORD],text=True))
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        base=f'http://127.0.0.1:{port}';server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','public/router.php'],cwd=ROOT,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        try:
            for _ in range(60):
                try:
                    with socket.create_connection(('127.0.0.1',port),timeout=.2):break
                except OSError:time.sleep(.1)
            with sync_playwright() as p:
                opts={'headless':True};exe=os.getenv('BOOK_CHROMIUM');
                if exe:opts['executable_path']=exe
                browser=p.chromium.launch(**opts);owner=browser.new_page();guest=browser.new_page();errors=[];owner.on('pageerror',lambda e:errors.append(str(e)))
                def load(page,path,status=200):
                    if not bridged:
                        r=page.goto(base+path);assert r.status==status,(r.status,path);return
                    r=page.context.request.get(base+path,max_redirects=0);assert r.status==status,(r.status,path)
                    page.set_content(re.sub(r'<script[^>]*>.*?</script>|<link[^>]*>','',r.text(),flags=re.S));page.add_style_tag(path=str(ROOT/'public/assets/book.css'))
                    page.evaluate('''() => { window.formData=null; document.addEventListener('submit',e=>{e.preventDefault();window.formData={path:e.target.getAttribute('action'),data:Object.fromEntries(new FormData(e.target,e.submitter))};}); }''')
                def submit(page,form,fields,button):
                    for k,v in fields.items():form.locator(f'input[name="{k}"]').fill(v)
                    if not bridged:
                        with page.expect_navigation(wait_until='domcontentloaded') as nav:form.get_by_role('button',name=button,exact=True).click()
                        assert nav.value.status==200;return page.url.removeprefix(base)
                    form.get_by_role('button',name=button,exact=True).click();data=page.evaluate('window.formData');assert data
                    r=page.context.request.post(base+data['path'],form=data['data'],max_redirects=0);assert r.status==303,(r.status,r.text());path=r.headers['location'];load(page,path);return path
                def action(name):return owner.locator('form').filter(has=owner.locator(f'input[name="action"][value="{name}"]'))
                load(owner,'/login');submit(owner,owner.locator('form[action="/login"]'),{'username':'admin','password':PASSWORD},'Sign in');load(owner,'/organizations')
                submit(owner,action('create'),{'name':'Engineering','slug':'engineering'},'Create organization');load(owner,'/workspaces')
                submit(owner,action('create'),{'name':'Private documentation','slug':'private-docs'},'Create workspace');expect(owner.get_by_role('heading',name='Private documentation')).to_be_visible()
                studio=owner.get_by_role('link',name='Studio',exact=True).last.get_attribute('href');assert re.fullmatch('/w/[a-f0-9]{32}/[a-f0-9]{32}/studio',studio);prefix=studio[:-7]
                print('PASS workspace created through browser form',flush=True)
                load(owner,prefix+'/studio/books');submit(owner,action('create'),{'slug':'guide','title':'Engineering guide'},'Create book');expect(owner.get_by_role('heading',name='Engineering guide')).to_be_visible();link=owner.get_by_role('link',name='Create page').get_attribute('href');assert link==prefix+'/studio?book=guide';load(owner,link)
                expect(owner.locator('form[data-editor-form] input[name="book"]')).to_have_value('guide');assert owner.locator('form[data-editor-form]').get_attribute('action')==prefix+'/studio';print('PASS scoped book and editor form',flush=True)
                load(guest,prefix+'/',401);expect(guest.get_by_role('heading',name='Workspace unavailable')).to_be_visible();print('PASS anonymous Reader denied',flush=True)
                if not bridged:
                    # The real editor executes AJAX save, scoped history URL, visual/preview render and publication.
                    editor=owner.locator('form[data-editor-form]');owner.locator('[data-mode="markdown"]').click();expect(owner.locator('[data-markdown-panel]')).to_be_visible();editor.locator('input[name="path"]').fill('start');editor.locator('input[name="title"]').fill('Scoped page');editor.locator('textarea[name="body"]').fill('# Scoped page\n\n[Self](/docs/guide/1.0/start)')
                    editor.locator('button[name="action"][value="publish"]').click();expect(owner.locator('[data-save-state]')).to_contain_text(re.compile('Published|Saved',re.I),timeout=15000)
                    assert owner.url.startswith(base+prefix+'/studio?edit=');load(owner,prefix+'/docs/guide/1.0/start');expect(owner.get_by_role('link',name='Self')).to_have_attribute('href',prefix+'/docs/guide/1.0/start');print('PASS real editor AJAX publish and scoped Reader link',flush=True)
                load(owner,'/workspaces');submit(owner,action('archive'),{},'Archive workspace');load(owner,prefix+'/',404);load(owner,'/workspaces');submit(owner,action('restore'),{},'Restore workspace');load(owner,prefix+'/studio/books');expect(owner.get_by_role('heading',name='Engineering guide')).to_be_visible();print('PASS archive/restore preserves book',flush=True)
                assert not errors,errors;browser.close()
        finally:server.terminate();server.wait(timeout=5)
if __name__=='__main__':main()
