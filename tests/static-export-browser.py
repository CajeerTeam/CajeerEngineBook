#!/usr/bin/env python3
"""Extract production ZIP and exercise real Mermaid and search on file:// and a nested HTTP prefix."""
import functools, http.server, subprocess, tempfile, threading, zipfile
from pathlib import Path
from playwright.sync_api import sync_playwright, expect
ROOT=Path(__file__).resolve().parents[1]

def exercise(browser,base):
    ctx=browser.new_context();page=ctx.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
    # Any network request beyond the static host is a failure, even if it is blocked.
    unexpected=[]
    def route(r):
        url=r.request.url
        if url.startswith(('http:','https:')) and not url.startswith(base):unexpected.append(url);r.abort()
        else:r.continue_()
    ctx.route('**/*',route)
    try:
        page.goto(base+'index.html');expect(page.get_by_role('heading',name='Documentation',exact=True)).to_be_visible()
        page.locator('form.search input').fill('Русский поиск');page.locator('form.search button').click()
        expect(page.locator('[data-search-status]')).to_have_text('1 result');page.locator('[data-search-results] a').click()
        expect(page.get_by_role('heading',name='Offline guide',exact=True)).to_be_visible();expect(page.locator('img[alt=Pixel]')).to_be_visible();assert page.locator('img[alt=Pixel]').evaluate('(i)=>i.complete && i.naturalWidth > 0')
        expect(page.get_by_role('link',name='Attachment',exact=True)).to_have_attribute('href',__import__('re').compile(r'\.txt$'))
        expect(page.locator('[data-book-tabs]')).to_be_visible();page.get_by_role('tab',name='Windows').click();expect(page.get_by_text('Windows instructions')).to_be_visible();page.get_by_role('tab',name='Linux').click();expect(page.get_by_text('Nested keyword')).to_be_visible()
        diagram=page.locator('.book-diagram');diagram.scroll_into_view_if_needed();expect(diagram.locator('.book-diagram-status')).to_have_text('Diagram ready.',timeout=30000);expect(diagram.frame_locator('iframe').locator('svg')).to_have_count(1);assert page.evaluate("document.querySelector('iframe').contentDocument === null")
        with page.expect_download(timeout=20000) as download:diagram.get_by_role('button',name='Download PNG').click()
        assert Path(download.value.path()).read_bytes()[:8]==b'\x89PNG\r\n\x1a\n'
        page.get_by_role('link',name='Next',exact=True).first.click();expect(page.get_by_role('heading',name='Child',exact=True)).to_be_visible();assert page.url.endswith('#child-heading')
        page.goto(base+'docs/p-guide/p-1/p-old/index.html');expect(page.get_by_role('heading',name='Moved page',exact=True)).to_be_visible()
        page.goto(base+'search.html?q=PRIVATE_PAGE_SECRET');expect(page.locator('[data-search-status]')).to_have_text('0 results');assert not unexpected and not errors,(unexpected,errors)
        print('PASS static browser search, media, tabs, links, redirects, real Mermaid and PNG:',base,flush=True)
    finally:ctx.close()

if __name__=='__main__':
    with tempfile.TemporaryDirectory(prefix='book-static-browser-') as temp:
        root=Path(temp);archive=root/'site.zip';site=root/'prefix'/'manual';site.mkdir(parents=True)
        subprocess.run(['php','tests/static-export.php','--site='+str(archive)],cwd=ROOT,check=True)
        with zipfile.ZipFile(archive) as z:z.extractall(site)
        handler=functools.partial(http.server.SimpleHTTPRequestHandler,directory=temp);server=http.server.ThreadingHTTPServer(('127.0.0.1',0),handler);thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
        try:
            with sync_playwright() as p:
                browser=p.chromium.launch(headless=True)
                exercise(browser,site.as_uri()+'/');exercise(browser,f'http://127.0.0.1:{server.server_port}/prefix/manual/');browser.close()
        finally:server.shutdown();server.server_close();thread.join()
