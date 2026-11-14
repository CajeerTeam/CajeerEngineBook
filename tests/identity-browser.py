#!/usr/bin/env python3
"""Actual form interaction, independent sessions and owner-only access.

CI uses normal browser navigation. A local sandbox can set BOOK_TEST_BROWSER_TRANSPORT=1
when browser loopback navigation is blocked; the same forms are loaded from PHP and
submitted via the browser context's real HTTP client.
"""
import os
from pathlib import Path
import re
import socket
import subprocess
import tempfile
import time
from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[1]
PASSWORD = 'identity-browser-password-123'

def check(name): print('PASS',name,flush=True)

def main():
    with tempfile.TemporaryDirectory(prefix='book-identity-browser-') as tmp:
        env=dict(os.environ,BOOK_DATA_DIR=tmp,BOOK_SECURE_COOKIE='0',BOOK_ADMIN_HASH=subprocess.check_output(['php','-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',PASSWORD],text=True))
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        base=f'http://127.0.0.1:{port}'
        server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','public/router.php'],cwd=ROOT,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        try:
            for _ in range(50):
                try:
                    with socket.create_connection(('127.0.0.1',port),timeout=.2):break
                except OSError:time.sleep(.1)
            with sync_playwright() as p:
                launch={'headless':True}
                if os.environ.get('BOOK_CHROMIUM'):launch['executable_path']=os.environ['BOOK_CHROMIUM']
                browser=p.chromium.launch(**launch)
                bridged=os.environ.get('BOOK_TEST_BROWSER_TRANSPORT')=='1'
                owner=browser.new_context();member=browser.new_context();ownerpage=owner.new_page();memberpage=member.new_page();errors=[]
                for page in (ownerpage,memberpage):page.on('pageerror',lambda e:errors.append(str(e)))
                def load(page, path, status=200):
                    if not bridged:
                        response=page.goto(base+path);assert response.status==status, (path,response.status)
                    else:
                        response=page.context.request.get(base+path,max_redirects=0);assert response.status==status, (path,response.status,response.text())
                        markup=re.sub(r'<script[^>]*>.*?</script>|<link[^>]*>', '',response.text(),flags=re.S)
                        page.set_content(markup);page.add_style_tag(path=str(ROOT/'public/assets/book.css'))
                        page.evaluate('''() => { window.formData=null; document.addEventListener('submit',e=>{e.preventDefault();window.formData={path:e.target.getAttribute('action'),data:Object.fromEntries(new FormData(e.target,e.submitter))};}); }''')
                def submit(page, form, fields, button, next_path, expected=303):
                    for name,value in fields.items():form.locator(f'input[name="{name}"]').fill(value)
                    if not bridged:
                        with page.expect_navigation(wait_until='domcontentloaded'):
                            form.get_by_role('button',name=button,exact=True).click()
                        assert page.url==base+next_path, page.url
                    else:
                        form.get_by_role('button',name=button,exact=True).click()
                        data=page.evaluate('window.formData');assert data and data['path'].startswith('/')
                        response=page.context.request.post(base+data['path'],form=data['data'],max_redirects=0);assert response.status==expected,(response.status,response.text())
                        assert response.headers['location']==next_path,response.headers
                        load(page,next_path)
                load(ownerpage,'/login');expect(ownerpage.get_by_role('heading',name='Sign in')).to_be_visible()
                submit(ownerpage,ownerpage.locator('form[action="/login"]'),{'username':'admin','password':PASSWORD},'Sign in','/studio')
                expect(ownerpage.get_by_role('heading',name='Documentation Studio')).to_be_visible();check('owner signs in through browser form')
                load(ownerpage,'/account/users');submit(ownerpage,ownerpage.locator('form[action="/account/users"]').first,{'username':'writer','name':'Writer','email':'writer@example.org','password':'writer-browser-password'},'Create account','/account/users?updated=1')
                expect(ownerpage.get_by_text('Writer',exact=True)).to_be_visible();check('owner creates another account through UI')
                load(memberpage,'/login');submit(memberpage,memberpage.locator('form[action="/login"]'),{'username':'writer','password':'writer-browser-password'},'Sign in','/account')
                expect(memberpage.get_by_role('heading',name='Your account')).to_be_visible();load(memberpage,'/studio',403);expect(memberpage.get_by_role('heading',name='Installation owner permission required')).to_be_visible();check('independent member session is denied Studio')
                load(ownerpage,'/account/users');card=ownerpage.locator('.account-row').filter(has_text='Writer');submit(ownerpage,card.locator('form'),{},'Disable account','/account/users?updated=1')
                load(memberpage,'/account',401);expect(memberpage.get_by_role('heading',name='Sign in required')).to_be_visible();check('owner disable invalidates existing browser session')
                load(ownerpage,'/account');submit(ownerpage,ownerpage.locator('form[action="/logout"]'),{},'Sign out','/login')
                load(ownerpage,'/studio',401);check('sign out form revokes session')
                assert not errors,errors;check('no browser JavaScript errors')
                browser.close()
        finally:server.terminate();server.wait(timeout=5)

if __name__=='__main__':main()
