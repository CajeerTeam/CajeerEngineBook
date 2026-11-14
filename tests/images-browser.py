"""Full-stack images/attachments E2E against the real CommonMark renderer and PHP storage."""
import base64, json, os, re, socket, subprocess, tempfile, time
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg==')
PASSWORD = 'images-test-password'

def check(ok, message):
    assert ok, message
    print('PASS', message, flush=True)

def exercise(pw, base):
    api = pw.request.new_context(http_credentials={'username':'admin','password':PASSWORD})
    public = pw.request.new_context()
    csrf = re.search(r'name="csrf" value="([a-f0-9]{64})"', api.get(base+'/studio/media').text()).group(1)
    def upload(name, content):
        result=api.post(base+'/studio/media',headers={'X-Book-Media':'1'},multipart={'csrf':csrf,'action':'upload','folder':'qa','file':{'name':name,'mimeType':'application/octet-stream','buffer':content}})
        check(result.ok,'upload '+name);return result.json()['item']['id']
    iid=upload('diagram.png',PNG);aid=upload('guide.txt',b'Attachment content\n')
    check(public.get(base+'/studio/media/picker').status==401,'picker requires admin')
    result=api.get(base+'/studio/media/picker?kind=image&q=diagram').json()
    check(result['total']==1 and result['items'][0]['id']==iid,'picker image filtering and search')
    check(api.get(base+'/studio/media/picker?kind=image&q=guide').json()['total']==0,'non-images excluded from image picker')
    check(api.get(base+'/studio/media/picker?kind=attachment&q=guide').json()['items'][0]['id']==aid,'attachment picker includes documents')
    launch={'headless':True}
    if os.environ.get('BOOK_CHROMIUM'):launch['executable_path']=os.environ['BOOK_CHROMIUM']
    browser=pw.chromium.launch(**launch);ctx=browser.new_context(http_credentials={'username':'admin','password':PASSWORD});page=ctx.new_page();errors=[]
    page.on('pageerror',lambda e:errors.append(str(e)))
    page.goto(base+'/studio');page.locator('[data-visual-panel]').wait_for()
    for name,val in {'book':'qa','version':'1.0','path':'images','title':'Images QA'}.items():page.locator(f'[data-editor-form] [name={name}]').fill(val)
    page.locator('[data-insert-media=image]').click();dialog=page.locator('[data-book-media-picker]');dialog.get_by_role('button',name=re.compile('diagram.png')).click()
    image=page.locator('.editor-media-controls').first
    image.get_by_role('textbox',name='Alternative text').fill('Architecture diagram');image.get_by_role('textbox',name='Caption').fill('Caption <safe> & details')
    image.get_by_role('combobox',name='Image width').select_option('50');image.get_by_role('combobox',name='Image alignment').select_option('right')
    check(page.locator('.editor-media-preview img').get_attribute('src')=='/studio/media/file/'+iid,'visual image uses private route')
    page.locator('[data-insert-media=attachment]').click();dialog=page.locator('[data-book-media-picker]');dialog.get_by_role('button',name=re.compile('guide.txt')).click()
    page.get_by_role('textbox',name='Attachment label').fill('Download guide')
    page.locator('[data-mode=markdown]').click();source=page.locator('textarea[name=body]').input_value()
    check('book-image:v1:' in source and 'book-attachment:v1' in source and '/media/'+iid in source and '/media/'+aid in source,'visual media serializes canonical Markdown')
    page.locator('[data-mode=visual]').click();page.get_by_role('textbox',name='Caption').wait_for();check(page.get_by_role('textbox',name='Caption').input_value()=='Caption <safe> & details','round trip preserves caption')
    page.locator('[data-mode=preview]').click();page.locator('[data-live-preview] figure.book-image-50.book-image-right').wait_for();check(page.locator('[data-live-preview] figcaption').inner_text()=='Caption <safe> & details','real server preview caption and layout')
    page.locator('[data-editor-form] button[value=save]').click();page.wait_for_function("document.querySelector('[data-editor-form] input[name=revision]').value === '1'")
    check(public.get(base+'/media/'+iid).status==404 and public.get(base+'/media/'+aid).status==404,'draft media remains private')
    page.locator('[data-editor-form] button[value=publish]').click();page.wait_for_function("document.querySelector('[data-editor-form] input[name=revision]').value === '2'")
    check(public.get(base+'/media/'+iid).body()==PNG,'published image exact bytes')
    downloaded=public.get(base+'/media/'+aid+'?download=1');check(downloaded.status==200 and downloaded.body()==b'Attachment content\n' and 'attachment;' in downloaded.headers['content-disposition'],'published attachment download')
    page.goto(base+'/docs/qa/1.0/images');page.locator('[data-image-zoom]').click();viewer=page.locator('.book-image-dialog');viewer.wait_for();check(viewer.locator('img').get_attribute('src')=='/media/'+iid,'Reader full-size viewer uses public URL');page.keyboard.press('Escape');viewer.wait_for(state='detached')
    page.goto(base+'/studio?edit=qa%2F1.0%2Fimages');page.get_by_role('textbox',name='Caption').wait_for();page.get_by_role('textbox',name='Caption').fill('Revised private caption')
    page.locator('[data-editor-form] button[value=save]').click();page.wait_for_function("Number(document.querySelector('[data-editor-form] input[name=revision]').value) >= 3")
    check('Revised private caption' not in public.get(base+'/docs/qa/1.0/images').text(),'draft edits leave public figure unchanged')
    page.locator('[data-insert-media=attachment]').click();dialog=page.locator('[data-book-media-picker]');dialog.locator('input[type=file]').set_input_files({'name':'new.txt','mimeType':'text/plain','buffer':b'New uploaded attachment\n'});dialog.get_by_role('button',name='Upload and insert').click();dialog.wait_for(state='detached');check(page.get_by_role('textbox',name='Attachment label').count()==2,'direct upload and insert in editor')
    check(not errors,'no JavaScript errors');browser.close();api.dispose();public.dispose()

def main():
    with tempfile.TemporaryDirectory(prefix='book-images-') as temp:
        work=Path(temp);password_hash=subprocess.check_output(['php','-r',f'echo password_hash("{PASSWORD}",PASSWORD_DEFAULT);'],text=True)
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        base=f'http://127.0.0.1:{port}';env=dict(os.environ,BOOK_DATA_DIR=str(work/'data'),BOOK_ADMIN_HASH=password_hash,BOOK_SECURE_COOKIE='0')
        with (work/'server.log').open('w+') as log:
            server=subprocess.Popen(['php','-d','upload_max_filesize=20M','-d','post_max_size=24M','-S',f'127.0.0.1:{port}','-t','public','public/router.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
            try:
                for _ in range(50):
                    try:
                        with socket.create_connection(('127.0.0.1',port),timeout=.2):break
                    except OSError:time.sleep(.1)
                with sync_playwright() as pw:exercise(pw,base)
            except Exception:
                log.flush();log.seek(0);print(log.read());raise
            finally:server.terminate();server.wait(timeout=5)
if __name__=='__main__':main()
