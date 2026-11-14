"""Real media HTTP and Chromium integration; --storage-only skips CommonMark-dependent cases."""
import base64
import io
import json
import os
from pathlib import Path
import socket
import subprocess
import sys
import tarfile
import tempfile
import time
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg==')
PASSWORD = 'media-test-password'
STORAGE_ONLY = '--storage-only' in sys.argv
HTTP_ONLY = '--http-only' in sys.argv


def check(condition, message):
    assert condition, message
    print('PASS', message, flush=True)


def exercise(pw, base, data, work):
    public = pw.request.new_context()
    api = pw.request.new_context(http_credentials={'username':'admin','password':PASSWORD})
    check(public.get(base+'/studio/media').status==401, 'media library requires admin')
    response = api.get(base+'/studio/media')
    import re
    csrf = re.search(r'name="csrf" value="([a-f0-9]{64})"',response.text()).group(1)
    check(response.status==200 and 'Media library' in response.text(),'authenticated library UI')
    def upload(name, content, token=csrf, folder='docs'):
        return api.post(base+'/studio/media', headers={'X-Book-Media':'1'}, multipart={'csrf':token,'action':'upload','folder':folder,'file':{'name':name,'mimeType':'application/octet-stream','buffer':content}})
    def action(action_name, id, revision, **fields):
        return api.post(base+'/studio/media',headers={'X-Book-Media':'1'},form={'csrf':csrf,'action':action_name,'id':id,'revision':str(revision),**fields})
    check(upload('no.png',PNG,'bad').status==403,'upload requires CSRF')
    check(upload('evil.php',PNG).status==422,'reject executable extension over HTTP')
    check(upload('evil.png',b'<html><script>alert(1)</script></html>').status==422,'reject fake MIME over HTTP')
    first=upload('pixel.png',PNG,folder='docs/images');second=upload('notes.txt',b'Media library notes\n')
    check(first.status==200 and second.status==200,'real multipart upload of image and text')
    id=first.json()['item']['id'];tid=second.json()['item']['id']
    path='/media/'+id;private='/studio/media/file/'+id
    check(public.get(base+path).status==404 and public.get(base+private).status==401,'unreferenced file cannot be accessed publicly')
    r=api.get(base+private);check(r.status==200 and r.body()==PNG and r.headers['content-type']=='image/png' and r.headers['x-content-type-options']=='nosniff','authenticated image download exact bytes and headers')
    r=api.get(base+'/studio/media/file/'+tid);check(r.status==200 and 'attachment;' in r.headers['content-disposition'],'non-image forced attachment')
    check(api.head(base+private).status==200,'HEAD supported')
    check(api.get(base+private+'?thumb=1').status==200,'private thumbnail route')
    duplicate=upload('duplicate.png',PNG).json();check(duplicate['duplicate'] and duplicate['item']['id']==id,'HTTP deduplication returns original stable ID')
    check(action('update',id,1,name='renamed.png',folder='screenshots').status==200,'HTTP rename and folder update')
    check(action('delete',id,1).status==409,'stale media revision returns conflict')
    check('renamed.png' in api.get(base+'/studio/media?q=renamed').text() and 'notes.txt' not in api.get(base+'/studio/media?q=renamed').text(),'search filter works')
    check('notes.txt' in api.get(base+'/studio/media?type=text/plain').text() and 'renamed.png' not in api.get(base+'/studio/media?type=text/plain').text(),'type filter works')
    check('renamed.png' in api.get(base+'/studio/media?folder=screenshots').text() and 'notes.txt' not in api.get(base+'/studio/media?folder=screenshots').text(),'folder filter works')
    if not STORAGE_ONLY:
        key='qa/1.0/media';page_data={'csrf':csrf,'book':'qa','version':'1.0','path':'media','title':'Media QA','section':'Test','order':'1'}
        body=f'![pixel][image]\n\n[image]: {path}\n\n[notes](/media/{tid})'
        def save(action_name, revision, text):
            return api.post(base+'/studio',headers={'X-Book-Studio':'editor'},form=page_data|{'action':action_name,'revision':str(revision),'body':text})
        check(save('save',0,body).status==200,'real draft save records CommonMark image and link references')
        preview=api.post(base+'/studio/editor/render',form=page_data|{'mode':'preview','body':body});check(preview.status==200 and private in preview.json()['html'],'real draft preview rewrites to private route')
        check(public.get(base+path).status==404,'draft references remain private')
        check(action('delete',id,2).status==409,'draft reference blocks deletion')
        check(save('publish',1,f'`{path}`').status==200 and public.get(base+path).status==404,'code example does not grant public access')
        check(save('publish',2,body).status==200,'publish actual references')
        r=public.get(base+path);check(r.status==200 and r.body()==PNG,'published image publicly served exact bytes')
        check(public.get(base+'/docs/'+key).status==200,'published Reader renders document')
        check(save('save',3,'Draft without image').status==200 and public.get(base+path).status==200,'draft edits do not revoke published media')
        check(action('delete',id,2).status==409,'published reference blocks deletion')
        unpublish=api.post(base+'/studio',form={'csrf':csrf,'action':'unpublish','key':key,'revision':'4'},max_redirects=0)
        check(unpublish.status==303 and public.get(base+path).status==404,'unpublish revokes access immediately')
        check(save('save',5,'[bad](/media/'+'f'*32+')').status==422,'missing media rejected atomically')
    backup=api.get(base+'/studio/media/backup');check(backup.status==200 and 'attachment' in backup.headers['content-disposition'],'authenticated HTTP full backup')
    with tarfile.open(fileobj=io.BytesIO(backup.body()),mode='r:') as tar:
        check(tar.extractfile('media/'+id+'.blob').read()==PNG and id in json.load(tar.extractfile('books.json'))['media'],'backup contains correct database and blob')
    if not STORAGE_ONLY:
        archive=work/'download.tar';archive.write_bytes(backup.body());restored=work/'restored'
        subprocess.run(['php','bin/media-backup.php','restore',str(archive),str(restored)],cwd=ROOT,check=True)
        check((restored/'media'/(id+'.blob')).read_bytes()==PNG,'CLI restore full real database and files')
    if not HTTP_ONLY:
        launch={'headless':True}
        if os.environ.get('BOOK_CHROMIUM'):launch['executable_path']=os.environ['BOOK_CHROMIUM']
        browser=pw.chromium.launch(**launch)
        ctx=browser.new_context(http_credentials={'username':'admin','password':PASSWORD})
        page=ctx.new_page();errors=[];page.on('pageerror',lambda err:errors.append(str(err)))
        page.goto(base+'/studio/media');page.locator('[data-media-upload]').wait_for()
        page.locator('[data-media-upload] input[name=folder]').fill('browser')
        page.locator('input[type=file]').set_input_files([{'name':'batch1.txt','mimeType':'text/plain','buffer':b'First browser upload\n'},{'name':'batch2.txt','mimeType':'text/plain','buffer':b'Second browser upload\n'}])
        page.locator('[data-media-upload] button').click()
        page.locator('.media-item h2',has_text='batch2.txt').wait_for(timeout=15000)
        check(page.locator('.media-item').count()==4,'browser batch uploads persist and appear in library')
        card=page.locator('.media-item').filter(has=page.locator('h2',has_text='renamed.png'));card.locator('summary').click();card.locator('input[name=name]').fill('browser-renamed.png');card.locator('input[name=folder]').fill('browser-folder');card.get_by_role('button',name='Save details').click();page.locator('h2',has_text='browser-renamed.png').wait_for()
        check(json.loads((data/'books.json').read_text())['media'][id]['folder']=='browser-folder','browser rename and folder update')
        page.locator('.media-filters input[name=q]').fill('browser-renamed');page.locator('.media-filters button').click();page.locator('h2',has_text='browser-renamed.png').wait_for();check(page.locator('.media-item').count()==1,'browser search')
        page.on('dialog',lambda dialog:dialog.accept());card=page.locator('.media-item').filter(has=page.locator('h2',has_text='browser-renamed.png'));card.locator('summary').click();card.get_by_role('button',name='Delete',exact=True).click();page.locator('h2',has_text='batch2.txt').wait_for();check(id not in json.loads((data/'books.json').read_text())['media'],'browser deletion')
        check(not errors,'no browser JavaScript errors');browser.close()
    else:
        check(action('delete',id,2).status==200 and not (data/'media'/(id+'.blob')).exists(),'HTTP deletion removes unused blob')
    public.dispose();api.dispose()


def main():
    with tempfile.TemporaryDirectory(prefix='book-media-http-') as temp:
        work=Path(temp);data=work/'data'
        password_hash=subprocess.check_output(['php','-r',f'echo password_hash("{PASSWORD}", PASSWORD_DEFAULT);'],text=True)
        env=dict(os.environ,BOOK_DATA_DIR=str(data),BOOK_ADMIN_HASH=password_hash,BOOK_SECURE_COOKIE='0')
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        base=f'http://127.0.0.1:{port}'
        with (work/'server.log').open('w+') as log:
            server=subprocess.Popen(['php','-d','upload_max_filesize=20M','-d','post_max_size=24M','-S',f'127.0.0.1:{port}','-t','public','public/router.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
            try:
                for _ in range(50):
                    try:
                        with socket.create_connection(('127.0.0.1',port),timeout=.2):break
                    except OSError:time.sleep(.1)
                with sync_playwright() as pw:exercise(pw,base,data,work)
            except Exception:
                log.flush();log.seek(0);print(log.read(),file=sys.stderr);raise
            finally:server.terminate();server.wait(timeout=5)

if __name__=='__main__':main()
