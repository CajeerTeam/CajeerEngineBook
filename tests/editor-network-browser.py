#!/usr/bin/env python3
"""Exercise the real Studio JavaScript with an intentionally stalled transport.

No Markdown-parser or server behavior is mocked in the full integration suite;
this narrow browser test injects transport responses to test client recovery.
"""
import os
from pathlib import Path
import subprocess
from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[1]


def check(ok, msg):
    assert ok, msg
    print('PASS', msg, flush=True)


def main():
    markup = subprocess.check_output(['php', '-r', 'require "src/Markdown.php"; require "src/StudioEditor.php"; echo CajeerBook\\StudioEditor::markup("");'], cwd=ROOT, text=True)
    fields = ''.join(f'<input name="{key}" value="{val}">' for key, val in {'book': 'qa', 'version': '1.0', 'path': 'network', 'title': 'Network', 'section': 'QA', 'order': '10', 'csrf': 'fixture', 'revision': '0'}.items())
    with sync_playwright() as pw:
        opts = {'headless': True}
        if os.environ.get('BOOK_CHROMIUM'):
            opts['executable_path'] = os.environ['BOOK_CHROMIUM']
        browser = pw.chromium.launch(**opts)
        page = browser.new_page()
        errors = []
        page.on('pageerror', lambda e: errors.append(str(e)))
        page.set_content('<form data-editor-form>' + fields + markup + '<button name="action" value="save">Save</button><button name="action" value="publish">Publish</button></form>')
        page.evaluate('''() => {
            const timer = window.setTimeout.bind(window);
            window.setTimeout = (fn, delay, ...args) => timer(fn, delay === 30000 ? 100 : delay, ...args);
            window.transportMode = 'normal'; window.abortCount = 0;
            window.fetch = async (url, options) => {
                if (window.transportMode === 'hang') return new Promise((resolve, reject) => {
                    options.signal.addEventListener('abort', () => {window.abortCount++; reject(new DOMException('Aborted','AbortError'));}, {once:true});
                });
                if (url === '/studio/editor/render') return {ok:true, json:async()=>({html:'',blocks:[]})};
                if (window.transportMode === 'conflict') return {ok:false,status:409,json:async()=>({error:'Page changed'})};
                return {ok:true,json:async()=>({key:'qa/1.0/network',revision:1,published:false,navigation_changed:false})};
            };
            // set_content uses about:blank, where the production history URL is not available.
            history.replaceState = () => {};
        }''')
        page.add_script_tag(path=str(ROOT/'public/assets/studio-editor.js'))
        expect(page.locator('[data-visual-panel]')).to_be_visible()
        page.locator('[data-mode=markdown]').click()
        body = page.locator('textarea[name=body]')
        body.fill('## Retained edits\n\nThe network is slow.')
        page.evaluate("window.transportMode='hang'")
        page.locator('[data-editor-form] button[value=save]').click()
        expect(page.locator('[data-editor-error]')).to_contain_text('Request timed out')
        expect(page.locator('[data-editor-form] button[value=save]')).to_be_enabled()
        expect(body).to_have_value('## Retained edits\n\nThe network is slow.')
        check(page.evaluate('window.abortCount') == 1, 'stalled save aborted and local Markdown retained')
        page.locator('[data-mode=preview]').click()
        expect(page.locator('[data-editor-error]')).to_contain_text('Request timed out')
        expect(page.locator('[data-mode=preview]')).to_be_enabled()
        check(page.evaluate('window.abortCount') == 2, 'stalled preview aborts and unlocks mode controls')
        page.evaluate("window.transportMode='normal'")
        page.locator('[data-editor-form] button[value=save]').click()
        expect(page.locator('[data-save-state]')).to_have_text('Draft saved')
        expect(page.locator('[data-editor-form] input[name=revision]')).to_have_value('1')
        check(True, 'explicit retry commits and updates revision')
        body.fill('## Newer local edits')
        page.evaluate("window.transportMode='conflict'")
        page.locator('[data-editor-form] button[value=save]').click()
        expect(page.locator('[data-save-state]')).to_have_text('Revision conflict')
        expect(body).to_have_value('## Newer local edits')
        expect(page.locator('[data-editor-form] button[value=save]')).to_be_disabled()
        check(True, 'conflict after retry preserves source and blocks stale writes')
        check(not errors, 'no JavaScript exceptions')
        browser.close()


if __name__ == '__main__':
    main()
