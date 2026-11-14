#!/usr/bin/env python3
"""Real Mermaid bundle + production PHP + Studio/Reader browser E2E.
Run bin/install-mermaid.sh and composer install first.
"""
import os
import socket
import subprocess
import tempfile
import time
from pathlib import Path
from playwright.sync_api import sync_playwright, expect
from auth_helpers import login

ROOT = Path(__file__).resolve().parents[1]
PASSWORD = 'mermaid-test-password'

def check(ok, name):
    assert ok, name
    print('PASS', name, flush=True)

def main():
    assert (ROOT/'public/assets/mermaid/VERSION').read_text().strip() == '11.17.2'
    assert (ROOT/'public/assets/mermaid/mermaid.min.js').stat().st_size > 100000
    with tempfile.TemporaryDirectory() as data, socket.socket() as sock:
        sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
        env=dict(os.environ,BOOK_DATA_DIR=data,BOOK_SECURE_COOKIE='0',BOOK_ADMIN_HASH=subprocess.check_output(['php','-r',f'echo password_hash("{PASSWORD}", PASSWORD_DEFAULT);'],text=True))
        server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','public/router.php'],cwd=ROOT,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        base=f'http://127.0.0.1:{port}'
        try:
            with sync_playwright() as p:
                browser=p.chromium.launch(headless=True)
                ctx=browser.new_context(accept_downloads=True)
                for _ in range(50):
                    try:
                        if ctx.request.get(base+'/health/live',timeout=1000).ok:break
                    except Exception:time.sleep(.1)
                login(ctx.request,base,PASSWORD)
                page=ctx.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
                page.goto(base+'/studio');expect(page.locator('[data-visual-panel]')).to_be_visible()
                for key,value in {'book':'qa','version':'1.0','path':'diagram','title':'Diagram QA'}.items():page.locator(f'[data-editor-form] [name="{key}"]').fill(value)
                page.locator('[data-add-kind]').select_option('mermaid');page.locator('[data-add]').click()
                code=page.get_by_role('textbox',name='Code content');code.fill('flowchart LR\nA[Start] --> B[Finish]')
                diagram=page.locator('.editor-diagram-panel .book-diagram')
                expect(diagram.locator('.book-diagram-status')).to_have_text('Diagram ready.',timeout=25000)
                expect(diagram.frame_locator('iframe').locator('svg')).to_have_count(1)
                check(True,'real Mermaid Studio live preview')
                page.locator('[data-mode=markdown]').click();source=page.locator('textarea[name=body]');md=source.input_value()
                check('```mermaid\nflowchart LR\nA[Start] --> B[Finish]\n```' in md,'diagram serializes to standard Markdown')
                source.fill('# First\n\n'+md+'\n\n# Last\n')
                page.locator('[data-mode=visual]').click();expect(page.locator('[data-visual-panel]')).to_be_visible()
                codeblock=page.locator('.editor-block').filter(has=page.get_by_role('textbox',name='Code content'))
                codeblock.get_by_role('button',name='Move block up').click()
                page.locator('[data-mode=markdown]').click();md=source.input_value();check(md.index('```mermaid')<md.index('# First')<md.index('# Last'),'block reorder persisted in Markdown')
                page.locator('[data-mode=visual]').click();expect(page.locator('[data-visual-panel]')).to_be_visible()
                check(page.get_by_role('textbox',name='Code content').input_value()=='flowchart LR\nA[Start] --> B[Finish]','round-trip reopens diagram')
                page.locator('[data-editor-form] button[value=publish]').click();expect(page.locator('[data-save-state]')).to_have_text('Published')
                reader=browser.new_page(accept_downloads=True);reader.on('pageerror',lambda e:errors.append(str(e)))
                reader.goto(base+'/docs/qa/1.0/diagram');d=reader.locator('.book-diagram');expect(d.locator('.book-diagram-status')).to_have_text('Diagram ready.',timeout=25000)
                expect(d.frame_locator('iframe').locator('svg')).to_have_count(1)
                check(reader.evaluate("() => document.querySelector('iframe').contentDocument === null"),'sandbox opaque origin')
                response=ctx.request.get(base+'/diagram-frame');csp=response.headers['content-security-policy'];check('sandbox allow-scripts' in csp and "connect-src 'none'" in csp and 'allow-same-origin' not in csp,'frame CSP isolates scripts and network')
                d.get_by_role('button',name='Zoom in').click();expect(d.frame_locator('iframe').locator('svg')).to_have_attribute('style',__import__('re').compile('width: [0-9]+px'))
                d.get_by_role('button',name='Expand').click();expect(d).to_have_class(__import__('re').compile('book-diagram-expanded'))
                with reader.expect_download(timeout=20000) as download:d.get_by_role('button',name='Download PNG').click()
                check(Path(download.value.path()).read_bytes()[:8]==b'\x89PNG\r\n\x1a\n','real PNG export')
                reader.locator('#theme').click();expect(d.locator('.book-diagram-status')).to_have_text('Diagram ready.',timeout=25000)
                check(True,'theme redraw')
                page.locator('[data-mode=markdown]').click();source.fill('```mermaid\n%%{init: {"securityLevel":"loose"}}%%\nflowchart LR\nA-->B\n```\n');page.locator('[data-mode=preview]').click();expect(page.locator('[data-live-preview] .book-diagram-status')).to_contain_text('configuration directives',timeout=25000)
                check(True,'unsafe configuration rejected, source remains')
                page.locator('[data-mode=markdown]').click();source.fill('```mermaid\nflowchart LR\nA --> [\n```\n');page.locator('[data-mode=preview]').click();expect(page.locator('[data-live-preview] .book-diagram-status')).to_contain_text('Diagram error:',timeout=25000)
                check(True,'invalid diagram displays error without breaking page')
                nojs=browser.new_context(java_script_enabled=False).new_page();nojs.goto(base+'/docs/qa/1.0/diagram');expect(nojs.locator('code[data-book-mermaid]')).to_contain_text('flowchart LR');check(True,'no JavaScript source fallback')
                check(not errors,'no uncaught browser errors: '+str(errors));browser.close()
        finally:
            server.terminate();server.wait(timeout=5)

if __name__=='__main__':main()
