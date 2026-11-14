#!/usr/bin/env python3
"""Real multipart imports, review workflow and scoped isolation. --policy-only checks routes without vendor/zip."""
import io, json, os, re, socket, subprocess, sys, tempfile, time, urllib.request, urllib.error, zipfile
from pathlib import Path
from importlib.util import spec_from_file_location, module_from_spec
ROOT=Path(__file__).resolve().parents[1]
spec=spec_from_file_location('workspace_http',ROOT/'tests/workspaces-http.py');w=module_from_spec(spec);spec.loader.exec_module(w)
Client,post,check,PASSWORD=w.Client,w.post,w.check,w.PASSWORD
FULL='--policy-only' not in sys.argv

def multipart(c,path,files,action='preview',mode='files',status=200,csrf=None,**fields):
    boundary='BookImportBoundary'+os.urandom(8).hex()
    data={'csrf':c.csrf('/account') if csrf is None else csrf,'action':action,'mode':mode,'count':str(len(files)),'book':'guide','version':'1.0','strategy':'update'}|fields
    chunks=[]
    for key,value in data.items():chunks.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
    for name,body in files:chunks.append(f'--{boundary}\r\nContent-Disposition: form-data; name="files[]"; filename="{name}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode()+body+b'\r\n')
    chunks.append(f'--{boundary}--\r\n'.encode())
    req=urllib.request.Request(c.base+path,data=b''.join(chunks),headers={'Content-Type':f'multipart/form-data; boundary={boundary}','X-Book-Import':'1'})
    try:r=c.opener.open(req,timeout=30)
    except urllib.error.HTTPError as e:r=e
    with r:
        content=r.read();assert r.status==status,(path,status,r.status,content[:500]);return json.loads(content) if r.headers.get_content_type()=='application/json' else content

def run(base,data):
    owner,editor,admin,viewer,outside,guest=[Client(base) for _ in range(6)];owner.login()
    for name,c in [('writer',editor),('manager',admin),('reader',viewer),('outside',outside)]:
        post(owner,'/account/users','create',username=name,name=name,email=name+'@example.org',password='password-'+name+'-123');c.login(name,'password-'+name+'-123')
    org=post(owner,'/organizations','create',name='Import team',slug='import-team')[0]['Location'];oid=org.split('/')[-1]
    other=post(outside,'/organizations','create',name='Other team',slug='other-team')[0]['Location'].split('/')[-1]
    def state():return json.loads((data/'identity/identity.json').read_text())
    def uid(name):return next(u['id'] for u in state()['users'].values() if u['username']==name)
    for name,c,role in [('writer',editor,'editor'),('manager',admin,'admin'),('reader',viewer,'viewer')]:
        _,html=post(owner,org,'invite',200,revision=str(state()['organizations'][oid]['revision']),username=name);token=re.search(rb'/organizations/join#([a-f0-9]{64})',html).group(1).decode();post(c,'/organizations/join','accept',token=token)
        if role!='viewer':post(owner,org,'role',revision=str(state()['organizations'][oid]['revision']),user=uid(name),role=role)
    post(owner,'/workspaces','create',organization=oid,name='Import',slug='import');post(owner,'/workspaces','create',organization=oid,name='Second',slug='second');post(outside,'/workspaces','create',organization=other,name='Other',slug='import')
    spaces={x['name']:x for x in state()['workspaces'].values()};a,b,o=spaces['Import'],spaces['Second'],spaces['Other']
    prefix=lambda x:'/w/'+x['organization']+'/'+x['id'];pa,pb,po=map(prefix,(a,b,o));route=pa+'/studio/import';studio=pa+'/studio';review=studio+'/reviews'
    def db():return json.loads((data/'workspaces'/oid/a['id']/'books.json').read_text())
    editor.request(route,200);viewer.request(route,403);outside.request(route,404);guest.request(route,401)
    assert b'data-markdown-import' in editor.request(route,200)[1]
    assert b'/assets/markdown-import.js' in editor.request(route,200)[1]
    files=[('README.md',b'# Hello')]
    multipart(viewer,route,files,status=403);multipart(outside,route,files,status=404);multipart(editor,route,files,csrf='invalid',status=403);multipart(editor,route,files,action='publish',status=403)
    check('real import form, Editor authorization, Viewer/foreign/guest and CSRF denied')
    multipart(editor,route,files,count='2',status=422);multipart(editor,route,[('evil.php',b'<?php echo 1;')],status=422)
    multipart(editor,route,[('root/../x.md',b'# Evil')],mode='directory',status=422)
    multipart(editor,route,[('README.md',b'---\ntitle: !unsafe\n---\n')],status=422)
    multipart(editor,route,files,action='apply',token='invalid',status=409)
    assert db()['pages']==[] and not db().get('media')
    check('invalid multipart counts, unsupported type, traversal, front matter and forged confirmation rejected without writes')
    if not FULL:return
    # A genuine ZIP archive contains docs, hierarchy, relative image and text asset, rich Markdown.
    raw=io.BytesIO()
    with zipfile.ZipFile(raw,'w',zipfile.ZIP_DEFLATED) as z:
        z.writestr('README.md','# Import home\n\n[Next](nested/README.md#start)\n\n[Attachment](files/info.txt?download=1)\n\n```mermaid\nflowchart LR\nA --> B\n```\n')
        z.writestr('nested/README.md','# Start\n\n[Home](../README.md)\n\n| A | B |\n|---|---|\n|1|2|\n')
        z.writestr('files/info.txt','private attachment bytes')
    zipfiles=[('docs.zip',raw.getvalue())];p=multipart(editor,route,zipfiles,mode='zip');assert len(p['rows'])==2 and p['media_new']==1 and db()['pages']==[]
    multipart(editor,pb+'/studio/import',zipfiles,mode='zip',action='apply',token=p['token'],status=409)
    multipart(admin,route,zipfiles,mode='zip',action='apply',token=p['token'],status=409)
    multipart(editor,route,zipfiles,mode='zip',action='apply',token=p['token']);pages=db()['pages'];home=pages['guide/1.0/index'];mid=home['draft']['media'][0]
    assert home['published'] is None and '/docs/guide/1.0/nested#start' in home['draft']['body'] and len(db()['media'])==1
    viewer.request(pa+'/api/v1/pages/guide/1.0/index',404);viewer.request(pa+'/media/'+mid,404);editor.request(pa+'/studio/media/file/'+mid,200);outside.request(pa+'/studio/media/file/'+mid,404)
    check('actual ZIP import creates linked drafts and private media; signed plan cannot cross scope/session')
    # Directory input uses full_path; selected folder root is removed.
    folder=[('Selected/root.md',b'# Root\n\n[Child](sub/child.md)'),('Selected/sub/child.md',b'# Child')];p=multipart(editor,route,folder,mode='directory',book='folder');multipart(editor,route,folder,mode='directory',book='folder',action='apply',token=p['token']);assert 'folder/1.0/root' in db()['pages'] and '/docs/folder/1.0/sub/child' in db()['pages']['folder/1.0/root']['draft']['body']
    check('actual multipart directory upload keeps nested paths without selected root')
    # Review policy must not be bypassed by import; publish through independent Admin.
    post(owner,review,'policy',revision='1',required='1');p=multipart(editor,route,files,book='other');multipart(editor,route,files,book='other',action='apply',token=p['token']);assert db()['pages']['other/1.0/index']['published'] is None
    post(editor,studio,'publish',403,book='guide',version='1.0',path='index',revision='1');h,_=post(editor,review,'submit',kind='page',target='guide/1.0/index',expected='1',reviewer=uid('manager'));rid=h['Location'].split('=')[-1];post(admin,review,'approve',id=rid,revision='1');post(admin,review,'publish',id=rid,revision='2')
    viewer.request(pa+'/docs/guide/1.0/index',200);assert viewer.request(pa+'/media/'+mid,200)[1]==b'private attachment bytes';outside.request(pa+'/media/'+mid,404);guest.request(pa+'/media/'+mid,401)
    check('imported draft enters existing independent review; only approved publication grants media access')
    # Update only drafts, preserve original publication and historical ID.
    published=db()['pages']['guide/1.0/index']['published'];p=multipart(editor,route,files);multipart(editor,route,files,action='apply',token=p['token']);new=db()['pages']['guide/1.0/index'];assert new['id']==home['id'] and new['published']==published and new['draft']['title']=='Hello'
    p=multipart(editor,route,files);post(editor,studio+'/books','create',slug='changed',title='Changed');multipart(editor,route,files,action='apply',token=p['token'],status=409)
    p=multipart(editor,route,files);post(owner,'/workspaces','role',id=a['id'],revision='1',user=uid('writer'),role='viewer');multipart(editor,route,files,action='apply',token=p['token'],status=403)
    check('update preserves publication and stable ID; stale plans and revoked Editor rejected')

if __name__=='__main__':
    with tempfile.TemporaryDirectory(prefix='book-import-http-') as tmp:
        data=Path(tmp)/'data';env=dict(os.environ,BOOK_DATA_DIR=str(data),BOOK_SECURE_COOKIE='0',BOOK_ADMIN_HASH=subprocess.check_output(['php','-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',PASSWORD],text=True))
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        base=f'http://127.0.0.1:{port}'
        with (Path(tmp)/'server.log').open('w+') as log:
            proc=subprocess.Popen(['php','-d','upload_max_filesize=20M','-d','post_max_size=72M','-d','max_file_uploads=200','-S',f'127.0.0.1:{port}','-t','public','public/router.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
            try:
                for _ in range(100):
                    try:Client(base).request('/health/live',200);break
                    except OSError:time.sleep(.05)
                else:raise RuntimeError('PHP server failed to start')
                run(base,data)
            except BaseException:
                log.seek(0);print(log.read()[-6000:]);raise
            finally:proc.terminate();proc.wait(timeout=5)
