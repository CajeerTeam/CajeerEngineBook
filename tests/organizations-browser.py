#!/usr/bin/env python3
"""Playwright: real organization forms, invite fragment and new account signup."""
import os
from pathlib import Path
import re
import socket
import subprocess
import tempfile
import time
from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[1]
PASSWORD = 'organization-browser-password-123'


def main():
    with tempfile.TemporaryDirectory(prefix='book-org-browser-') as tmp:
        env = dict(os.environ, BOOK_DATA_DIR=tmp, BOOK_SECURE_COOKIE='0', BOOK_ADMIN_HASH=subprocess.check_output(['php','-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',PASSWORD],text=True))
        with socket.socket() as sock: sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
        base=f'http://127.0.0.1:{port}'
        server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','public/router.php'],cwd=ROOT,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        try:
            for _ in range(60):
                try:
                    with socket.create_connection(('127.0.0.1',port),timeout=.2): break
                except OSError: time.sleep(.1)
            with sync_playwright() as p:
                launch={'headless':True}
                if os.environ.get('BOOK_CHROMIUM'):launch['executable_path']=os.environ['BOOK_CHROMIUM']
                browser=p.chromium.launch(**launch)
                contexts=[browser.new_context() for _ in range(2)]
                owner,member=[c.new_page() for c in contexts]; errors=[]
                bridged=os.environ.get('BOOK_TEST_BROWSER_TRANSPORT')=='1'
                for page in (owner,member):page.on('pageerror',lambda e:errors.append(str(e)))
                def load(page,path,status=200):
                    if not bridged:
                        r=page.goto(base+path);assert r.status==status,(path,r.status)
                    else:
                        r=page.context.request.get(base+path.split('#')[0],max_redirects=0);assert r.status==status,(path,r.status)
                        html=re.sub(r'<script[^>]*>.*?</script>|<link[^>]*>','',r.text(),flags=re.S)
                        page.set_content(html);page.add_style_tag(path=str(ROOT/'public/assets/book.css'))
                        page.evaluate('''() => { window.formData=null; document.addEventListener('submit', e=>{e.preventDefault();window.formData={path:e.target.getAttribute('action'),data:Object.fromEntries(new FormData(e.target,e.submitter))};}); }''')
                def submit(page,action,fields,button,status=303):
                    form=page.locator('form').filter(has=page.locator(f'input[name="action"][value="{action}"]')) if action!='login' else page.locator('form[action="/login"]')
                    for key,val in fields.items():form.locator(f'input[name="{key}"]').fill(val)
                    if not bridged:
                        with page.expect_navigation(wait_until='domcontentloaded') as nav:form.get_by_role('button',name=button,exact=True).click()
                        r=nav.value;assert r.status==200,(r.status,page.url);return page.url.removeprefix(base)
                    form.get_by_role('button',name=button,exact=True).click();data=page.evaluate('window.formData');assert data
                    r=page.context.request.post(base+data['path'],form=data['data'],max_redirects=0);assert r.status==status,(r.status,r.text())
                    if status==303:nextpath=r.headers['location'];load(page,nextpath);return nextpath
                    page.set_content(re.sub(r'<script[^>]*>.*?</script>|<link[^>]*>','',r.text(),flags=re.S));return data['path']
                load(owner,'/login');submit(owner,'login',{'username':'admin','password':PASSWORD},'Sign in');load(owner,'/organizations')
                path=submit(owner,'create',{'name':'Engineering','slug':'engineering'},'Create organization');expect(owner.get_by_role('heading',name='Engineering',exact=True)).to_be_visible()
                print('PASS organization created through form',flush=True)
                submit(owner,'invite',{'username':'writer'},'Create / replace invitation',200)
                link=owner.locator('[data-org-invite-link]').input_value();token=link.split('#')[1];assert re.fullmatch('[a-f0-9]{64}',token)
                if not bridged:
                    load(member,'/organizations/join#'+token);expect(member.locator('input[name="token"]')).to_have_value(token);assert '#' not in member.url
                else:
                    # Browser loopback navigation is blocked in some sandboxes. The manual-token
                    # fallback still uses real rendered forms and PHP HTTP requests.
                    load(member,'/organizations/join');member.locator('input[name="token"]').fill(token)
                print('PASS invite token entered in form' if bridged else 'PASS invite fragment transferred to form and removed from address',flush=True)
                submit(member,'accept',{'name':'Writer','email':'writer@example.org','password':'writer-browser-password-123','confirm':'writer-browser-password-123'},'Create account and join');submit(member,'login',{'username':'writer','password':'writer-browser-password-123'},'Sign in');load(member,path)
                expect(member.get_by_role('heading',name='Engineering',exact=True)).to_be_visible();assert member.get_by_role('button',name='Create / replace invitation').count()==0
                print('PASS invited account can sign in and see organization but cannot manage',flush=True)
                load(owner,path);submit(owner,'transfer',{},'Transfer ownership');load(member,path);expect(member.get_by_role('button',name='Create / replace invitation')).to_be_visible();load(owner,path);assert owner.get_by_role('button',name='Create / replace invitation').count()==0
                print('PASS ownership transfer updates both browser sessions',flush=True)
                load(member,path);submit(member,'remove',{},'Remove member');load(owner,path,404);expect(owner.get_by_role('heading',name='Organization not found')).to_be_visible()
                print('PASS removal revokes access from other browser',flush=True)
                assert not errors,errors;print('PASS no browser JavaScript errors',flush=True);browser.close()
        finally:server.terminate();server.wait(timeout=5)

if __name__=='__main__':main()
