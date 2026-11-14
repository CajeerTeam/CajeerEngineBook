#!/usr/bin/env python3
"""Browser E2E for Studio. Requires Composer dependencies and Playwright Chromium."""
import json
import os
import socket
import subprocess
import tempfile
import time
import urllib.request
from pathlib import Path
from playwright.sync_api import sync_playwright, expect
from auth_helpers import login

ROOT = Path(__file__).resolve().parents[1]
PASSWORD = 'editor-test-only-password'


def check(condition, name):
    assert condition, name
    print('PASS', name, flush=True)


def exercise(page, base, fixture=False):
    if not fixture: page.goto(base + '/studio')
    expect(page.locator('[data-visual-panel]')).to_be_visible()
    for name, value in {'book': 'qa', 'version': '1.0', 'path': 'visual', 'title': 'Visual editor'}.items():
        page.locator(f'[data-editor-form] [name="{name}"]').fill(value)
    editor = page.locator('[contenteditable=true]').first
    editor.click()
    editor.press_sequentially('Hello world')
    editor.evaluate("el => {const r=document.createRange();r.selectNodeContents(el);const s=getSelection();s.removeAllRanges();s.addRange(r);}")
    page.locator('[data-command=bold]').click()
    page.locator('[data-mode=markdown]').click()
    source = page.locator('[data-editor-form] textarea[name=body]')
    expect(source).to_be_visible()
    check('**Hello world**' in source.input_value(), 'visual bold serializes to Markdown')
    page.locator('[data-mode=visual]').click()
    expect(page.locator('[data-visual-panel]')).to_be_visible()
    page.locator('[data-add-kind]').select_option('ul')
    page.locator('[data-add]').click()
    bullet = page.locator('[contenteditable=true]').last
    bullet.locator('li').click()
    bullet.press_sequentially('First')
    bullet.press('Enter')
    bullet.press_sequentially('Second')
    page.locator('[data-add-kind]').select_option('p')
    page.locator('[data-add]').click()
    link = page.locator('[contenteditable=true]').last
    link.click()
    link.press_sequentially('Guide')
    link.evaluate("el=>{const r=document.createRange();r.selectNodeContents(el);const s=getSelection();s.removeAllRanges();s.addRange(r);}")
    page.once('dialog', lambda dialog: dialog.accept('guide'))
    page.locator('[data-command=link]').click()
    page.locator('[data-mode=markdown]').click()
    check('- First\n- Second' in source.input_value(), 'visual list serializes to Markdown')
    check('[Guide](<guide>)' in source.input_value(), 'visual link preserves relative URL')
    original = '# Hello\n\nA **bold** paragraph.\n\n| A | B |\n| --- | --- |\n| x | y |\n\n[guide](guide)\n\n[reference]: /docs/qa/1.0/guide\n'
    source.fill(original)
    page.locator('[data-mode=visual]').click()
    expect(page.locator('[data-visual-panel]')).to_be_visible()
    expect(page.locator('.editor-block table th[contenteditable=true]').first).to_be_visible()
    page.locator('[data-mode=markdown]').click()
    check(source.input_value() == original, 'untouched rich/GFM/reference source round-trips byte-for-byte (LF)')
    page.locator('[data-mode=visual]').click()
    expect(page.locator('[data-visual-panel]')).to_be_visible()
    heading = page.locator('[contenteditable=true]').first
    heading.locator('h1').click()
    heading.press('End')
    heading.press_sequentially(' updated')
    page.locator('[data-add-kind]').select_option('code')
    page.locator('[data-add]').click()
    page.get_by_role('textbox', name='Code language').fill('php')
    page.get_by_role('textbox', name='Code content').fill('echo `x`;\n')
    page.locator('[data-mode=markdown]').click()
    changed = source.input_value()
    check('# Hello updated' in changed and '| A | B |\n| --- | --- |\n| x | y |' in changed and '[reference]: /docs/qa/1.0/guide' in changed, 'edited heading preserves unrelated source and reference definitions')
    check('```php\necho `x`;\n\n```' in changed, 'code block preserves trailing blank line')
    page.locator('[data-mode=preview]').click()
    expect(page.locator('[data-preview-panel]')).to_be_visible()
    expect(page.locator('[data-live-preview] h1')).to_have_text('Hello updated')
    expect(page.locator('[data-live-preview] a').first).to_have_attribute('href', '/docs/qa/1.0/guide')
    check(True, 'server preview displays unsaved Markdown and canonical link')
    page.locator('[data-editor-form] button[value=publish]').click()
    expect(page.locator('[data-save-state]')).to_have_text('Published')
    expect(page.locator('[data-editor-form] [name=revision]')).to_have_value('1')
    api = base + '/api/v1/pages/qa/1.0/visual'
    check(page.request.get(api).json()['body'] == changed, 'published API returns exact Markdown')
    if not fixture:
        reader = page.context.new_page()
        reader.goto(base + '/docs/qa/1.0/visual')
        expect(reader.locator('article h1')).to_have_text('Hello updated')
        expect(reader.locator('article table')).to_be_visible()
        reader.close()
        check(True, 'public Reader renders visual edits and GFM')
    page.locator('[data-mode=markdown]').click()
    source.fill(changed + '\n\nDraft only')
    expect(page.locator('[data-editor-form] [name=revision]')).to_have_value('2', timeout=10000)
    check(page.request.get(api).json()['body'] == changed, 'autosave persists draft without replacing publication')
    # The second writer commits using the same CSRF/session, simulating another Studio tab.
    fields = page.locator('[data-editor-form]').evaluate("f=>Object.fromEntries(new FormData(f))")
    fields.update(action='save', body='Second writer')
    response = page.request.post(base + '/studio', form=fields, headers={'X-Book-Studio': 'editor'})
    check(response.status == 200 and response.json()['revision'] == 3, 'second writer commits revision 3')
    source.fill(changed + '\n\nMy unsaved text')
    expect(page.locator('[data-save-state]')).to_have_text('Revision conflict', timeout=10000)
    expect(source).to_have_value(changed + '\n\nMy unsaved text')
    expect(page.locator('[data-editor-form] button[value=publish]')).to_be_disabled()
    check(True, 'stale autosave stops and retains local edits')
    backup = page.request.get(base + '/studio/export').json()
    check(backup['pages']['qa/1.0/visual']['draft']['body'] == 'Second writer', 'conflict does not overwrite another writer')
    # Source-only fallback and CSRF rejection.
    check(page.request.post(base + '/studio/editor/render', form={'body': '# test', 'mode': 'visual'}).status == 403, 'editor render requires CSRF')
    check(page.request.get(base + '/studio/editor/render').status == 404, 'editor render is POST-only')


def exercise_tables(page, base, fixture=False):
    if not fixture: page.goto(base + '/studio')
    expect(page.locator('[data-visual-panel]')).to_be_visible()
    for name, value in {'book': 'qa', 'version': '1.0', 'path': 'tables', 'title': 'Rich tables'}.items():
        page.locator(f'[data-editor-form] [name="{name}"]').fill(value)
    page.locator('[data-add-kind]').select_option('table')
    page.locator('[data-add]').click()
    table = page.locator('.editor-block table').first
    expect(table.locator('th')).to_have_count(2)
    table.locator('th').first.fill('Name')
    table.locator('th').nth(1).fill('Value')
    cell = table.locator('tbody td').first
    cell.fill('Alpha')
    cell.evaluate("el=>{el.focus();const r=document.createRange();r.selectNodeContents(el);const s=getSelection();s.removeAllRanges();s.addRange(r);}")
    page.locator('[data-command=bold]').click()
    expect(cell.locator('b,strong')).to_have_text('Alpha')
    table.locator('tbody td').nth(1).fill('a|b')
    page.locator('[data-table-align]').select_option('right')
    page.locator('[data-table-action=add-row]').click()
    expect(table.locator('tbody tr')).to_have_count(2)
    table.locator('tbody tr').nth(1).locator('td').first.fill('Second')
    page.locator('[data-table-action=row-up]').click()
    expect(table.locator('tbody tr').first.locator('td').first).to_have_text('Second')
    page.locator('[data-table-action=column-right]').click()
    expect(table.locator('th').first).to_have_text('Value')
    page.locator('[data-table-action=add-column]').click()
    expect(table.locator('th')).to_have_count(3)
    page.locator('[data-command=undo]').click()
    expect(table.locator('th')).to_have_count(2)
    page.locator('[data-command=redo]').click()
    expect(table.locator('th')).to_have_count(3)
    page.locator('[data-table-action=delete-column]').click()
    expect(table.locator('th')).to_have_count(2)
    page.locator('[data-table-action=delete-row]').click()
    expect(table.locator('tbody tr')).to_have_count(1)
    check(True, 'table row and column insert/delete/reorder, column alignment')
    table.locator('tbody tr td').first.click()
    page.get_by_text('Import CSV / TSV', exact=True).click()
    page.get_by_role('textbox', name='Delimited table data').fill('"unclosed')
    page.get_by_role('button', name='Insert data at selected cell').click()
    expect(page.locator('[data-editor-error]')).to_contain_text('Unclosed quoted field')
    expect(table.locator('tbody tr')).to_have_count(1)
    page.get_by_role('textbox', name='Delimited table data').fill('"a,b","quoted ""text"""\nfirst,second')
    page.get_by_role('button', name='Insert data at selected cell').click()
    expect(table.locator('tbody tr').first.locator('td').first).to_have_text('a,b')
    expect(table.locator('tbody tr').first.locator('td').nth(1)).to_have_text('quoted "text"')
    expect(table.locator('tbody tr')).to_have_count(2)
    table.locator('tbody tr').nth(1).locator('td').first.evaluate(r"""el=>{el.focus();const data=new DataTransfer();data.setData('text/plain','a|b\tplain\nthird\tfourth');el.dispatchEvent(new ClipboardEvent('paste',{clipboardData:data,bubbles:true,cancelable:true}));}""")
    expect(table.locator('tbody tr')).to_have_count(3)
    expect(table.locator('tbody tr').nth(2).locator('td').nth(1)).to_have_text('fourth')
    check(True, 'quoted CSV and spreadsheet TSV paste expand table')
    table.locator('tbody tr').nth(1).locator('td').first.evaluate("el=>{el.focus();const r=document.createRange();r.selectNodeContents(el);const s=getSelection();s.removeAllRanges();s.addRange(r);}")
    page.locator('[data-command=inlineCode]').click()
    expect(table.locator('tbody tr').nth(1).locator('td').first.locator('code')).to_have_text('a|b')
    plain_cell = table.locator('tbody tr').nth(2).locator('td').first
    plain_cell.fill('literal|pipe')
    bold_cell = table.locator('tbody tr').nth(2).locator('td').nth(1)
    bold_cell.evaluate("el=>{el.focus();const r=document.createRange();r.selectNodeContents(el);const s=getSelection();s.removeAllRanges();s.addRange(r);}")
    page.locator('[data-command=bold]').click()
    expect(bold_cell.locator('b,strong')).to_have_text('fourth')
    page.locator('[data-add-kind]').select_option('task')
    page.locator('[data-add]').click()
    task = page.locator('.editor-block-content input[type=checkbox]').last
    task.check()
    page.locator('[data-mode=markdown]').click()
    source = page.locator('[data-editor-form] textarea[name=body]')
    md = source.input_value()
    check('| Value | Name |' in md and '| ---: | --- |' in md and '`a\\|b`' in md and '- [x] Task' in md and '**fourth**' in md, 'GFM source has moved header, alignment, escaped code pipe and completed task')
    page.locator('[data-mode=visual]').click()
    expect(page.locator('[data-visual-panel]')).to_be_visible()
    expect(page.locator('.editor-block table code').first).to_have_text('a|b')
    expect(page.locator('.editor-block table td').filter(has_text='literal|pipe')).to_have_count(1)
    expect(page.locator('.editor-block table strong').last).to_have_text('fourth')
    if not fixture: expect(page.locator('.editor-block-content input[type=checkbox]').last).to_be_checked()
    page.locator('[data-mode=markdown]').click()
    check(source.input_value() == md, 'edited table round-trips without source loss')
    source.fill(md + '\n\n```php\n<?php echo "<tag>";\n```\n')
    page.locator('[data-mode=preview]').click()
    expect(page.locator('[data-preview-panel]')).to_be_visible()
    expect(page.locator('[data-live-preview] .syntax-keyword').first).to_have_text('echo')
    expect(page.locator('[data-live-preview] pre code')).to_have_text('<?php echo "<tag>";')
    expect(page.locator('[data-live-preview] .copy')).to_be_visible()
    check(True, 'safe local code highlighting and copy control in Preview')
    page.locator('[data-editor-form] button[value=publish]').click()
    expect(page.locator('[data-save-state]')).to_have_text('Published')
    check(page.request.get(base + '/api/v1/pages/qa/1.0/tables').json()['body'] == source.input_value(), 'published table Markdown persisted verbatim')
    if not fixture:
        reader = page.context.new_page(); reader.goto(base + '/docs/qa/1.0/tables')
        expect(reader.locator('article table code').first).to_have_text('a|b')
        expect(reader.locator('article input[type=checkbox]')).to_be_disabled()
        expect(reader.locator('article .syntax-keyword').first).to_have_text('echo')
        reader.close()
        check(True, 'Reader renders edited table, read-only task and highlighted code')


def main():
    with tempfile.TemporaryDirectory() as temp:
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
        base = f'http://127.0.0.1:{port}'
        password_hash = subprocess.check_output(['php', '-r', 'echo password_hash($argv[1],PASSWORD_DEFAULT);', PASSWORD], text=True)
        env = dict(os.environ, BOOK_DATA_DIR=temp, BOOK_ADMIN_HASH=password_hash, BOOK_SECURE_COOKIE='0')
        with open(Path(temp) / 'server.log', 'w+') as log:
            server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', 'public', 'public/router.php'], cwd=ROOT, env=env, stdout=log, stderr=log)
            try:
                for _ in range(50):
                    try:
                        urllib.request.urlopen(base + '/health/live', timeout=1).close(); break
                    except OSError: time.sleep(.1)
                else: raise RuntimeError('PHP server did not start')
                with sync_playwright() as p:
                    options = {'headless': True}
                    if os.environ.get('BOOK_TEST_CHROMIUM'): options['executable_path'] = os.environ['BOOK_TEST_CHROMIUM']
                    browser = p.chromium.launch(**options)
                    context = browser.new_context()
                    login(context.request, base, PASSWORD)
                    page = context.new_page()
                    page_errors = []
                    page.on('pageerror', lambda error: page_errors.append(str(error)))
                    exercise(page, base)
                    table_page = context.new_page()
                    table_page.on('pageerror', lambda error: page_errors.append(str(error)))
                    exercise_tables(table_page, base)
                    check(not page_errors, f'no browser JavaScript exceptions: {page_errors}')
                    browser.close()
            except Exception:
                log.seek(0); print(log.read()); raise
            finally:
                server.terminate(); server.wait(timeout=10)


if __name__ == '__main__': main()
