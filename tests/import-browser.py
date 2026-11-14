#!/usr/bin/env python3
"""Browser directory selection, actual preview/apply and editor handoff."""
import os, socket, subprocess, tempfile, time
from pathlib import Path
from playwright.sync_api import sync_playwright, expect
from auth_helpers import login
ROOT=Path(__file__).resolve().parents[1]
PASSWORD='import-browser-password-123'

def main():
    with tempfile.TemporaryDirectory(prefix='book-import-browser-') as tmp:
        root=Path(tmp);docs=root/'Selected';docs.mkdir();(docs/'sub').mkdir()
        (docs/'README.md').write_text('# Browser import\n\n[Next](sub/next.md)\n\n[File](sub/file.txt)\n')
        (docs/'sub/next.md').write_text('# Next page\n\n[Home](../README.md)\n')
        (docs/'sub/file.txt').write_text('browser binary attachment')
        env=dict(os.environ,BOOK_DATA_DIR=str(root/'data'),BOOK_SECURE_COOKIE='0',BOOK_ADMIN_HASH=subprocess.check_output(['php','-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',PASSWORD],text=True))
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        base=f'http://127.0.0.1:{port}'
        server=subprocess.Popen(['php','-d','upload_max_filesize=20M','-d','post_max_size=72M','-d','max_file_uploads=200','-S',f'127.0.0.1:{port}','-t','public','public/router.php'],cwd=ROOT,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1',port),timeout=.2):break
                except OSError:time.sleep(.05)
            else:raise RuntimeError('PHP server did not start')
            with sync_playwright() as p:
                browser=p.chromium.launch(headless=True);context=browser.new_context();login(context.request,base,PASSWORD);page=context.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
                try:
                    assert page.goto(base+'/studio/import').status==200
                    form=page.locator('[data-markdown-import]');form.locator('[name=book]').fill('browser');form.locator('[name=mode]').select_option('directory');form.locator('[data-import-input=directory] input').set_input_files(str(docs))
                    form.get_by_role('button',name='Preview import').click();expect(page.get_by_role('heading',name='Import plan')).to_be_visible(timeout=15000);expect(page.locator('[data-import-result]')).to_contain_text('browser/1.0/sub/next');expect(form.locator('[data-import-apply]')).to_be_enabled()
                    # Change an option: previous confirmation cannot be reused.
                    form.locator('[name=version]').fill('2.0');expect(form.locator('[data-import-apply]')).to_be_disabled();expect(page.locator('[data-import-result]')).to_be_hidden()
                    form.get_by_role('button',name='Preview import').click();expect(page.get_by_role('heading',name='Import plan')).to_be_visible(timeout=15000);form.locator('[data-import-apply]').click();expect(page.get_by_role('heading',name='Import completed')).to_be_visible(timeout=15000)
                    assert context.request.get(base+'/api/v1/pages/browser/2.0/index').status==404
                    page.locator('[data-import-result] a').filter(has_text='browser/2.0/index').click();expect(page.get_by_role('heading',name='Edit page')).to_be_visible();expect(page.locator('textarea[name=body]')).to_have_value(re.compile(r'/docs/browser/2\.0/sub/next'))
                    assert not errors,errors
                    print('PASS actual Chromium directory upload, plan invalidation, draft commit and Studio handoff',flush=True)
                finally:browser.close()
        finally:server.terminate();server.wait(timeout=5)

if __name__=='__main__':
    import re
    main()
