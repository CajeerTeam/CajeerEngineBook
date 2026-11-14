#!/usr/bin/env python3
"""Studio → CommonMark → publication E2E (requires installed Composer deps)."""
import base64
import json
import os
import re
import socket
import subprocess
import tempfile
import time
from pathlib import Path
from playwright.sync_api import sync_playwright, expect
from auth_helpers import login

ROOT = Path(__file__).resolve().parents[1]
PASSWORD = 'rich-blocks-test-password'
PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg==')


def check(ok, message):
    assert ok, message
    print('PASS', message, flush=True)


def ui(page, image_id=None):
    page.locator('[data-visual-panel]').wait_for()
    form = page.locator('[data-editor-form]')
    for key, val in {'book':'qa','version':'1.0','path':'components','title':'Components QA'}.items():
        form.locator(f'[name={key}]').fill(val)
    def add(kind):
        page.locator('[data-add-kind]').select_option(kind)
        page.locator('[data-add]').click()
    add('callout')
    page.get_by_role('combobox',name='Callout type').select_option('warning')
    page.get_by_role('textbox',name='Callout title').fill('Warning <safe>')
    visual=page.get_by_role('textbox',name='Callout body visual')
    visual.fill('Important')
    visual.evaluate("el=>{const r=document.createRange();r.selectNodeContents(el);const s=getSelection();s.removeAllRanges();s.addRange(r);}")
    page.locator('.editor-rich-body').first.get_by_role('button',name='Bold',exact=True).click()
    callout=page.locator('.editor-block').filter(has=page.get_by_role('textbox',name='Callout title'))
    callout.get_by_role('button',name='Refresh component preview').click()
    expect(callout.locator('.book-callout-warning strong')).to_have_text('Important')
    check(True,'callout visual formatting and server preview')
    callout.get_by_role('button',name='Markdown',exact=True).click()
    page.get_by_role('textbox',name='Callout body Markdown').fill('**Important**\n\n| A | B |\n| --- | --- |\n| 1 | 2 |')
    add('cards')
    page.get_by_role('combobox',name='Card columns').select_option('3')
    first=page.locator('.editor-card-fields').first
    first.get_by_role('textbox',name='Card title').fill('First')
    first.get_by_role('textbox',name='Card link').fill('guide')
    first.get_by_role('combobox',name='Card icon').select_option('code')
    first.get_by_role('textbox',name='Card body visual').fill('First description')
    if image_id:
        first.get_by_role('button',name='Choose card image').click()
        page.locator('[data-book-media-picker]').get_by_role('button',name=re.compile('diagram.png')).click()
        expect(first.get_by_role('textbox',name='Card image URL')).to_have_value('/media/'+image_id)
    page.get_by_role('button',name='Add card',exact=True).click()
    second=page.locator('.editor-card-fields').nth(1)
    second.get_by_role('textbox',name='Card title').fill('Second')
    second.get_by_role('button',name='Card ↑').click()
    check(page.get_by_role('textbox',name='Card title').first.input_value()=='Second','card reorder updates model and DOM')
    page.get_by_role('button',name='Add card',exact=True).click()
    page.once('dialog',lambda dialog:dialog.accept())
    page.locator('.editor-card-fields').last.get_by_role('button',name='Remove card').click()
    check(page.locator('.editor-card-fields').count()==2,'card add and remove')
    page.get_by_role('textbox',name='Card link').first.fill('javascript:alert(1)')
    expect(page.locator('[data-editor-error]')).to_be_visible()
    page.get_by_role('textbox',name='Card link').first.fill('https://example.org')
    page.locator('[data-mode=markdown]').click()
    source=form.locator('textarea[name=body]').input_value()
    check('book-callout' in source and 'book-cards' in source and '"columns": 3' in source and '"type": "warning"' in source,'structured components serialize to Markdown')
    check('javascript:alert' not in source and '**Important**' in source and 'First description' in source,'unsafe URL rejected and body preserved')
    page.locator('[data-mode=visual]').click()
    expect(page.get_by_role('textbox',name='Callout title')).to_have_value('Warning <safe>')
    expect(page.get_by_role('textbox',name='Callout body Markdown')).to_be_visible()
    check(page.get_by_role('textbox',name='Card title').first.input_value()=='Second','Markdown → Visual round trip and complex body source fallback')
    page.locator('[data-mode=markdown]').click()
    check(form.locator('textarea[name=body]').input_value()==source,'unchanged component round trip exact')
    page.locator('[data-mode=preview]').click()
    expect(page.locator('[data-live-preview] .book-callout-warning strong')).to_have_text('Important')
    expect(page.locator('[data-live-preview] .book-card-grid .book-card')).to_have_count(2)
    check(True,'full shared preview contains both components')
    return source


def main():
    with tempfile.TemporaryDirectory(prefix='book-rich-e2e-') as temp:
        password_hash=subprocess.check_output(['php','-r',f'echo password_hash("{PASSWORD}",PASSWORD_DEFAULT);'],text=True)
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        base=f'http://127.0.0.1:{port}'
        env=dict(os.environ,BOOK_DATA_DIR=str(Path(temp)/'data'),BOOK_ADMIN_HASH=password_hash,BOOK_SECURE_COOKIE='0')
        with (Path(temp)/'server.log').open('w+') as log:
            server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','public/router.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
            try:
                for _ in range(50):
                    try:
                        with socket.create_connection(('127.0.0.1',port),timeout=.2):break
                    except OSError:time.sleep(.1)
                with sync_playwright() as pw:
                    auth={'username':'admin','password':PASSWORD}
                    api=login(pw.request.new_context(),base,PASSWORD);public=pw.request.new_context()
                    csrf=re.search(r'name="csrf" value="([a-f0-9]{64})"',api.get(base+'/studio/media').text()).group(1)
                    upload=api.post(base+'/studio/media',headers={'X-Book-Media':'1'},multipart={'csrf':csrf,'action':'upload','folder':'qa','file':{'name':'diagram.png','mimeType':'image/png','buffer':PNG}})
                    assert upload.ok,upload.text();image_id=upload.json()['item']['id']
                    launch={'headless':True}
                    if os.environ.get('BOOK_CHROMIUM'):launch['executable_path']=os.environ['BOOK_CHROMIUM']
                    browser=pw.chromium.launch(**launch);context=browser.new_context();login(context.request,base,PASSWORD);page=context.new_page();errors=[]
                    page.on('pageerror',lambda e:errors.append(str(e)))
                    page.goto(base+'/studio');source=ui(page,image_id)
                    page.locator('[data-editor-form] button[value=save]').click();expect(page.locator('[data-save-state]')).to_have_text('Draft saved')
                    expect(page.locator('[data-editor-form] [name=revision]')).to_have_value('1')
                    check(public.get(base+'/media/'+image_id).status==404,'draft card image private')
                    page.locator('[data-editor-form] button[value=publish]').click();expect(page.locator('[data-save-state]')).to_have_text('Published')
                    check(public.get(base+'/media/'+image_id).body()==PNG,'card image published through actual reference tracking')
                    check(public.get(base+'/api/v1/pages/qa/1.0/components').json()['body']==source,'public API source exact')
                    page.goto(base+'/docs/qa/1.0/components');expect(page.locator('article .book-card')).to_have_count(2);expect(page.locator('article .book-callout-warning')).to_be_visible()
                    expect(page.locator('article .book-card-image')).to_have_attribute('src','/media/'+image_id)
                    check(not errors,'Reader and Studio no JavaScript exceptions');browser.close();api.dispose();public.dispose()
            except Exception:
                log.flush();log.seek(0);print(log.read());raise
            finally:server.terminate();server.wait(timeout=5)

if __name__=='__main__':main()
