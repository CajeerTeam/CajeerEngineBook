#!/usr/bin/env python3
"""Run production Reader enhancement in isolation or complete PHP/Composer Studio E2E."""
import argparse
import json
import os
import re
import socket
import subprocess
import tempfile
import time
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[1]
PASSWORD = 'interactive-test-password'


def check(ok, msg):
    assert ok, msg
    print('PASS', msg, flush=True)


def render_fixture():
    # Actual PHP component HTML, without requiring CommonMark for the body fixtures.
    code = r'''require 'src/Markdown.php';require 'src/RichBlocks.php';
    $tabs=['v'=>1,'kind'=>'tabs','sync'=>'os','items'=>[['key'=>'linux','title'=>'Linux','body'=>''],['key'=>'windows','title'=>'Windows','body'=>'']]];
    $code=['v'=>1,'kind'=>'code-tabs','sync'=>'os','items'=>[['key'=>'windows','title'=>'PowerShell','language'=>'powershell','code'=>'Get-Item'],['key'=>'linux','title'=>'Bash','language'=>'bash','code'=>'echo hi']]];
    $acc=['v'=>1,'kind'=>'accordion','items'=>[['title'=>'Details','body'=>'','open'=>false]]];
    $steps=['v'=>1,'kind'=>'steps','items'=>[['title'=>'First','body'=>''],['title'=>'Second','body'=>'']]];
    $r=fn($d,$b)=>CajeerBook\RichBlocks::html($d,$b,fn($u,$i)=>$u);
    echo $r($tabs,['<p>Linux body</p>','<h2 id="hidden-heading">Windows body</h2>'.$r($acc,['<p id="deep-anchor">Details body</p>'])]).$r($code,['','']).$r($steps,['<p>First body</p>','<p>Second body</p>']);'''
    return subprocess.check_output(['php','-r',code],cwd=ROOT,text=True)


def reader(page):
    groups=page.locator('[data-book-tabs]'); assert groups.count()==2
    first=groups.filter(has=page.get_by_role('tab',name='Linux',exact=True)); second=groups.filter(has=page.get_by_role('tab',name='Bash',exact=True))
    initial_key=groups.first.locator('[role=tabpanel]:visible').get_attribute('data-tab-key')
    expect(first.locator('[role=tabpanel]:visible')).to_have_attribute('data-tab-key',initial_key)
    expect(second.locator('[role=tabpanel]:visible')).to_have_attribute('data-tab-key',initial_key)
    first.get_by_role('tab',name='Linux').click()
    check(True,'initial sync matches keys, not index')
    first.get_by_role('tab',name='Windows').click()
    expect(first.get_by_role('tabpanel')).to_contain_text('Windows body')
    expect(second.get_by_role('tab',name='PowerShell')).to_have_attribute('aria-selected','true')
    check(True,'click selects and synchronizes peer')
    first.get_by_role('tab',name='Windows').press('ArrowRight')
    expect(first.get_by_role('tab',name='Linux')).to_be_focused()
    expect(second.get_by_role('tab',name='Bash')).to_have_attribute('aria-selected','true')
    first.get_by_role('tab',name='Linux').press('End')
    expect(first.get_by_role('tab',name='Windows')).to_be_focused()
    first.get_by_role('tab',name='Windows').press('Home')
    expect(first.get_by_role('tab',name='Linux')).to_be_focused()
    check(True,'keyboard arrows, Home, End and roving focus')
    first.get_by_role('tab',name='Windows').click()
    page.locator('.book-accordion-item summary').click()
    expect(page.locator('.book-accordion-item')).to_have_attribute('open','')
    expect(page.locator('.book-steps > li')).to_have_count(2)
    check(True,'native accordion and ordered steps')
    page.evaluate('window.BookInteractive.enhance();window.BookInteractive.enhance()')
    expect(page.get_by_role('tablist')).to_have_count(2)
    ids=page.locator('[role=tab]').evaluate_all('(els)=>els.map(e=>e.id)')
    check(len(ids)==len(set(ids)),'unique ARIA IDs and idempotent enhancement')


def editor(page):
    form=page.locator('[data-editor-form]')
    page.locator('[data-visual-panel]').wait_for()
    for k,v in {'book':'qa','version':'1.0','path':'interactive','title':'Interactive QA'}.items():form.locator(f'[name={k}]').fill(v)
    def add(kind):
        page.locator('[data-add-kind]').select_option(kind);page.locator('[data-add]').click()
        return page.locator('.editor-block').filter(has=page.get_by_role('button',name='Refresh component preview')).first
    tabs=add('tabs')
    tabs.get_by_role('textbox',name='Synchronization group (optional)').fill('os')
    tabs.get_by_role('textbox',name='Item title').fill('Linux')
    tabs.get_by_role('textbox',name='Tab key').fill('linux')
    tabs.get_by_role('textbox',name='Item body visual').fill('Linux body')
    tabs.get_by_role('button',name='Add tab').click()
    second=tabs.locator('.editor-interactive-item').nth(1)
    second.get_by_role('textbox',name='Item title').fill('Windows')
    second.get_by_role('textbox',name='Tab key').fill('linux')
    expect(page.locator('[data-editor-error]')).to_be_visible()
    second.get_by_role('textbox',name='Tab key').fill('windows')
    second.get_by_role('textbox',name='Item body visual').fill('Windows body')
    second.get_by_role('button',name='Move up').click()
    expect(tabs.get_by_role('textbox',name='Item title').first).to_have_value('Windows')
    tabs.locator('.editor-interactive-item').first.get_by_role('button',name='Move down').click()
    tabs.get_by_role('button',name='Add tab').click()
    page.once('dialog',lambda d:d.accept());tabs.locator('.editor-interactive-item').last.get_by_role('button',name='Remove tab').click()
    check(tabs.locator('.editor-interactive-item').count()==2,'tabs add, rename, reorder, delete, reject duplicate key')
    tabs.get_by_role('button',name='Refresh component preview').click()
    expect(tabs.get_by_role('tab',name='Linux')).to_be_visible()
    tabs.get_by_role('tab',name='Windows').click()
    expect(tabs.get_by_role('tabpanel')).to_contain_text('Windows body')
    check(True,'interactive component preview')
    code=add('code-tabs');code.get_by_role('textbox',name='Synchronization group (optional)').fill('os')
    code.get_by_role('textbox',name='Item title').fill('PowerShell');code.get_by_role('textbox',name='Tab key').fill('windows')
    code.get_by_role('textbox',name='Code language').fill('powershell');code.get_by_role('textbox',name='Code content').fill('Get-Item')
    code.get_by_role('button',name='Add tab').click();item=code.locator('.editor-interactive-item').nth(1)
    item.get_by_role('textbox',name='Item title').fill('Bash');item.get_by_role('textbox',name='Tab key').fill('linux');item.get_by_role('textbox',name='Code language').fill('bash');item.get_by_role('textbox',name='Code content').fill('echo hi')
    acc=add('accordion');acc.get_by_role('textbox',name='Item title').fill('Details');acc.get_by_role('textbox',name='Item body visual').fill('Details body')
    acc.get_by_role('checkbox',name='Initially expanded').check();acc.get_by_role('checkbox',name='Initially expanded').uncheck()
    steps=add('steps');steps.get_by_role('textbox',name='Item title').fill('First');steps.get_by_role('textbox',name='Item body visual').fill('First body');steps.get_by_role('button',name='Add step').click();steps.locator('.editor-interactive-item').nth(1).get_by_role('textbox',name='Item title').fill('Second')
    page.locator('[data-mode=markdown]').click();source=form.locator('textarea[name=body]').input_value()
    check(all('book-'+kind in source for kind in ['tabs','code-tabs','accordion','steps']) and '"open": false' in source and 'echo hi' in source,'four block types serialize to Markdown')
    page.locator('[data-mode=visual]').click();expect(page.get_by_role('textbox',name='Item title').first).to_have_value('First')
    page.locator('[data-mode=markdown]').click();check(form.locator('textarea[name=body]').input_value()==source,'unchanged round trip exact')
    page.locator('[data-mode=preview]').click();expect(page.locator('[data-live-preview] [role=tablist]')).to_have_count(2)
    check(True,'page preview enhances interactive blocks')
    return source


def main():
    parser=argparse.ArgumentParser();parser.add_argument('--reader-only',action='store_true');args=parser.parse_args()
    with sync_playwright() as pw:
        launch={'headless':True}
        if os.environ.get('BOOK_CHROMIUM'):launch['executable_path']=os.environ['BOOK_CHROMIUM']
        browser=pw.chromium.launch(**launch)
        if args.reader_only:
            page=browser.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
            page.set_content(render_fixture());expect(page.locator('[data-tab-panel]')).to_have_count(4);check(page.locator('[data-tab-panel]:visible').count()==4,'no-JS fallback displays every tab')
            page.add_script_tag(path=str(ROOT/'public/assets/book.js'));reader(page)
            page.locator('[data-book-tabs]').first.get_by_role('tab',name='Linux').click();page.evaluate("location.hash='deep-anchor'")
            expect(page.locator('#deep-anchor')).to_be_visible();check(True,'hash reveals hidden tab and accordion')
            check(not errors,'no JS exceptions');browser.close();return
        with tempfile.TemporaryDirectory(prefix='book-interactive-e2e-') as temp:
            password_hash=subprocess.check_output(['php','-r',f'echo password_hash("{PASSWORD}",PASSWORD_DEFAULT);'],text=True)
            with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
            base=f'http://127.0.0.1:{port}';env=dict(os.environ,BOOK_DATA_DIR=str(Path(temp)/'data'),BOOK_ADMIN_HASH=password_hash,BOOK_SECURE_COOKIE='0')
            with (Path(temp)/'server.log').open('w+') as log:
                server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','public/router.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
                try:
                    for _ in range(50):
                        try:
                            with socket.create_connection(('127.0.0.1',port),timeout=.2):break
                        except OSError:time.sleep(.1)
                    auth={'username':'admin','password':PASSWORD};context=browser.new_context(http_credentials=auth);page=context.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
                    page.goto(base+'/studio');source=editor(page)
                    page.locator('[data-editor-form] button[value=save]').click();expect(page.locator('[data-save-state]')).to_have_text('Draft saved')
                    page.locator('[data-editor-form] button[value=publish]').click();expect(page.locator('[data-save-state]')).to_have_text('Published')
                    api=pw.request.new_context();check(api.get(base+'/api/v1/pages/qa/1.0/interactive').json()['body']==source,'published API preserves exact source')
                    page.goto(base+'/docs/qa/1.0/interactive');reader(page);check(not errors,'no JavaScript exceptions in full E2E')
                    api.dispose();context.close()
                except Exception:
                    log.flush();log.seek(0);print(log.read());raise
                finally:server.terminate();server.wait(timeout=5)
        browser.close()

if __name__=='__main__':main()
