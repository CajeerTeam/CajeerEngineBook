#!/usr/bin/env python3
"""End-to-end privacy regression. --storage-only uses genuine BookStore snapshots for offline PHP environments."""
import base64, importlib.util, io, json, os, re, socket, subprocess, sys, tarfile, tempfile, time, urllib.request, urllib.error
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('workspace_http',ROOT/'tests/workspaces-http.py');w=importlib.util.module_from_spec(spec);spec.loader.exec_module(w)
Client, post, check, upload, PASSWORD=w.Client,w.post,w.check,w.upload,w.PASSWORD
FULL='--storage-only' not in sys.argv

def head(c,path,status):
    try:r=c.opener.open(urllib.request.Request(c.base+path,method='HEAD'),timeout=10)
    except urllib.error.HTTPError as e:r=e
    with r:assert r.status==status,(path,r.status,status);assert not r.read();return r.headers

def image(c,path):
    # Valid 16x16 PNG. Original or derived thumbnail must have the same authorization.
    png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAAGUlEQVR4nGNkYPjPQApgIkn1qIZRDUNKAwA+MAEfWiW9ygAAAABJRU5ErkJggg==')
    boundary='BookPrivacyBoundary123456'
    body=b''
    for name,value in {'csrf':c.csrf('/account'),'action':'upload','folder':''}.items():
        body+=f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode()
    body+=f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="private.png"\r\nContent-Type: image/png\r\n\r\n'.encode()+png+f'\r\n--{boundary}--\r\n'.encode()
    with c.opener.open(urllib.request.Request(c.base+path,data=body,headers={'Content-Type':f'multipart/form-data; boundary={boundary}','X-Book-Media':'1'}),timeout=10) as r:return json.load(r)['item']['id']

def exercise(base,data,env):
    guest,owner,viewer,outsider,editor=[Client(base) for _ in range(5)]
    owner.login()
    for username,c in [('reader',viewer),('outsider',outsider),('writer',editor)]:
        post(owner,'/account/users','create',username=username,name=username,email=username+'@example.org',password='password-'+username+'-123');c.login(username,'password-'+username+'-123')
    org=post(owner,'/organizations','create',name='Privacy Team',slug='privacy-team')[0]['Location'].split('/')[-1]
    def state():return json.loads((data/'identity/identity.json').read_text())
    def orev():return str(state()['organizations'][org]['revision'])
    def uid(name):return next(u['id'] for u in state()['users'].values() if u['username']==name)
    for username,c in [('reader',viewer),('writer',editor)]:
        _,html=post(owner,'/organizations/'+org,'invite',200,revision=orev(),username=username);token=re.search(rb'/organizations/join#([a-f0-9]{64})',html).group(1).decode();post(c,'/organizations/join','accept',token=token)
    post(owner,'/organizations/'+org,'role',revision=orev(),user=uid('writer'),role='editor')
    post(owner,'/workspaces','create',organization=org,name='Mixed Library',slug='mixed')
    ws=next(w for w in state()['workspaces'].values() if not w['legacy']);wid=ws['id'];prefix='/w/'+org+'/'+wid;folder=data/'workspaces'/org/wid
    def wrev():return str(state()['workspaces'][wid]['revision'])
    def visibility(value,status=303,actor=owner,revision=None):return post(actor,'/workspaces','visibility',status,id=wid,revision=revision or wrev(),visibility=value)
    def bookvis(slug,value,revision='1',actor=owner,status=303):return post(actor,prefix+'/studio/books','visibility',status,slug=slug,visibility=value,revision=revision)
    for slug,title in [('public','Public Manual'),('secret','Secret Book')]:post(owner,prefix+'/studio/books','create',slug=slug,title=title)
    priv=image(owner,prefix+'/studio/media');shared=upload(owner,prefix+'/studio/media',b'shared public file');draft=upload(owner,prefix+'/studio/media',b'draft only file')
    def save(slug,title,body,path='start',publish=True):
        if FULL:
            post(owner,prefix+'/studio','publish' if publish else 'save',book=slug,version='1.0',path=path,title=title,section='Guide',order='10',body=body,revision='0')
        else:
            # Only the parser-dependent save step is replaced; all visibility and HTTP operations are real.
            ids=[i for i in (priv,shared,draft) if '/media/'+i in body]
            code='require "bootstrap.php";$s=new CajeerBook\\BookStore($argv[1],false);$s->change(function(&$d)use($argv){$p=["title"=>$argv[3],"section"=>"Guide","order"=>10,"body"=>$argv[4],"updated"=>gmdate("c"),"media"=>json_decode($argv[5],true)];$d["pages"][$argv[2]]=["id"=>bin2hex(random_bytes(16)),"revision"=>1,"draft"=>$p,"published"=>$argv[6]==="1"?$p:null,"published_key"=>$argv[2]];});'
            subprocess.run(['php','-r',code,str(folder),f'{slug}/1.0/{path}',title,body,json.dumps(ids),'1' if publish else '0'],cwd=ROOT,check=True)
    save('public','Public Page','Public searchable text\n\n[Shared](/media/'+shared+')\n\n[Old private URL](/docs/secret/1.0/start)')
    save('secret','Secret Page','TOPSECRET searchable text\n\n![Private](/media/'+priv+'?thumb=1)\n\n[Shared](/media/'+shared+')')
    save('public','Draft Confidential','Draft only text\n\n[Draft](/media/'+draft+')',path='draft',publish=False)
    guest.request(prefix+'/',401);outsider.request(prefix+'/api/v1/pages',404);viewer.request(prefix+'/api/v1/pages',200)
    bookvis('secret','private');bookvis('public','public');guest.request(prefix+'/api/v1/pages',401)
    check('default private workspace; public book cannot override private workspace')
    visibility('public');visibility('private',409,revision='1');visibility('private',403,actor=editor);bookvis('secret','public','2',actor=editor,status=403)
    check('visibility management requires Admin/Owner and current revisions')
    for c in (guest,outsider):
        h,raw=c.request(prefix+'/api/v1/pages',200);assert 'private' in h['Cache-Control'] and 'no-store' in h['Cache-Control'];assert list(json.loads(raw))==['public/1.0/start']
        h,raw=c.request(prefix+'/',200);assert b'Public Manual' in raw and b'Secret Book' not in raw and b'Secret Page' not in raw
        for p in ['/api/v1/pages/secret/1.0/start','/docs/secret/1.0/start','/api/v1/pages/public/1.0/draft','/docs/public/1.0/draft']:
            h,raw=c.request(prefix+p,404);assert b'TOPSECRET' not in raw and b'Secret Page' not in raw and b'Secret Book' not in raw;assert 'noindex' in h['X-Robots-Tag']
        assert json.loads(c.request(prefix+'/api/v1/search?q=TOPSECRET',200)[1])==[]
        assert b'Secret Page' not in c.request(prefix+'/search?q=TOPSECRET',200)[1]
        assert len(json.loads(c.request(prefix+'/api/v1/search?q=searchable',200)[1]))==1
        for q in ('','?thumb=1','?download=1'):
            c.request(prefix+'/media/'+priv+q,404);c.request(prefix+'/media/'+draft+q,404);c.request(prefix+'/media/'+shared+q,200)
        head(c,prefix+'/media/'+priv,404);head(c,prefix+'/api/v1/pages/secret/1.0/start',404)
        c.request(prefix+'/studio',401 if c is guest else 404);c.request(prefix+'/studio/media/file/'+priv,401 if c is guest else 404)
    check('guest and outsider see only public books; Reader, API, search, media, HEAD and Studio cannot leak private data')
    h,raw=viewer.request(prefix+'/api/v1/pages/secret/1.0/start',200);assert b'TOPSECRET' in raw and 'noindex' in h['X-Robots-Tag']
    assert len(json.loads(viewer.request(prefix+'/api/v1/pages',200)[1]))==2
    viewer.request(prefix+'/media/'+priv+'?thumb=1',200);viewer.request(prefix+'/media/'+draft,404);viewer.request(prefix+'/studio/media/file/'+priv,403)
    if FULL:
        h,raw=viewer.request(prefix+'/docs/secret/1.0/start',200);assert b'TOPSECRET' in raw and (prefix+'/media/'+priv).encode() in raw
        _,raw=guest.request(prefix+'/docs/public/1.0/start',200);assert b'Public searchable text' in raw and b'TOPSECRET' not in raw
        # Same render engine for a draft, protected by RBAC even in a public workspace.
        _,raw=post(editor,prefix+'/studio/editor/render','',200,mode='preview',body='[Preview](/media/'+draft+')',book='public',version='1.0',path='draft');assert (prefix+'/studio/media/file/'+draft).encode() in raw
    check('Viewer reads private published books, never drafts; authorized published media'+(' and real Reader/Preview' if FULL else ' (stored snapshots)'))
    # Move a private page and publish its tree: redirect target must not be disclosed to outsiders.
    def tree_token():return re.search(rb'name="token" value="([a-f0-9]+)"',owner.request(prefix+'/studio/tree?scope=secret%2F1.0',200)[1]).group(1).decode()
    post(owner,prefix+'/studio/tree','move',scope='secret/1.0',source='secret/1.0/start',target='',position='root',slug='moved',token=tree_token())
    post(owner,prefix+'/studio/tree','publish',scope='secret/1.0',token=tree_token())
    for route in ('/docs/','/api/v1/pages/'):
        h,_=guest.request(prefix+route+'secret/1.0/start?x=1',404);assert 'Location' not in h
        h,_=viewer.request(prefix+route+'secret/1.0/start?x=1',308);assert h['Location']==prefix+route+'secret/1.0/moved?x=1' and 'private' in h['Cache-Control']
    if FULL:
        _,raw=guest.request(prefix+'/docs/public/1.0/start',200);assert (prefix+'/docs/secret/1.0/moved').encode() not in raw
    check('private historical URLs do not redirect or reveal target to anonymous users')
    bookvis('secret','public','2');guest.request(prefix+'/media/'+priv,200);guest.request(prefix+'/api/v1/pages/secret/1.0/moved',200)
    bookvis('secret','private','3');guest.request(prefix+'/media/'+priv,404);guest.request(prefix+'/api/v1/pages/secret/1.0/moved',404);bookvis('secret','public','3',status=409)
    visibility('private');guest.request(prefix+'/api/v1/pages',401);outsider.request(prefix+'/media/'+shared,404);viewer.request(prefix+'/media/'+priv,200)
    visibility('public');guest.request(prefix+'/media/'+shared,200)
    post(owner,'/workspaces','role',id=wid,revision=wrev(),user=uid('reader'),role='none');viewer.request(prefix+'/api/v1/pages',404);viewer.request(prefix+'/media/'+priv,404)
    post(owner,'/workspaces','role',id=wid,revision=wrev(),user=uid('reader'),role='inherit');viewer.request(prefix+'/media/'+priv,200)
    post(owner,'/organizations/'+org,'remove',revision=orev(),user=uid('reader'));viewer.request(prefix+'/media/'+priv,404);viewer.request(prefix+'/media/'+shared,200)
    check('visibility changes, explicit denial and membership revocation immediately affect pages and shared media')
    # Legacy reader must use the same filtering without a scoped URL; adoption allows workspace privacy.
    legacy=json.loads(guest.request('/api/v1/pages',200)[1]);assert legacy
    legacybook=next(iter(legacy)).split('/')[0]
    post(owner,'/studio/books','visibility',slug=legacybook,visibility='private',revision='1');assert not any(k.startswith(legacybook+'/') for k in json.loads(guest.request('/api/v1/pages',200)[1]))
    owner.request('/api/v1/pages/'+next(iter(legacy)),200)
    post(owner,'/workspaces','adopt',organization=org,name='Legacy',slug='legacy');lw=next(w for w in state()['workspaces'].values() if w['legacy']);assert lw['visibility']=='public'
    post(owner,'/workspaces','visibility',id=lw['id'],revision=str(lw['revision']),visibility='private');guest.request('/api/v1/pages',401);guest.request('/docs/'+next(iter(legacy)),401);outsider.request('/api/v1/pages',404);owner.request('/api/v1/pages',200);guest.request('/login',200)
    check('legacy book privacy and legacy workspace privacy block all original URLs without disabling login')
    post(owner,'/workspaces','archive',id=wid,revision=wrev());guest.request(prefix+'/',401);owner.request(prefix+'/api/v1/pages',404)
    check('archive hides even a public workspace')
    # Restore the space, and confirm its content TAR carries book visibility and only scoped content.
    post(owner,'/workspaces','restore',id=wid,revision=wrev());_,raw=owner.request(prefix+'/studio/media/backup',200)
    with tarfile.open(fileobj=io.BytesIO(raw)) as tar:
        db=json.load(tar.extractfile('books.json'));assert db['books']['secret']['visibility']=='private' and db['books']['public']['visibility']=='public';assert 'media/'+priv+'.blob' in tar.getnames()
    post(owner,'/organizations/'+org,'archive',revision=orev());guest.request(prefix+'/',401);owner.request(prefix+'/api/v1/pages',404);guest.request('/api/v1/pages',401);owner.request('/api/v1/pages',404)
    post(owner,'/organizations/'+org,'restore',revision=orev());guest.request(prefix+'/',200);owner.request('/api/v1/pages',200)
    check('book policies preserved in TAR; organization archive hides both scoped and legacy content')
    backup=data.parent/'identity-backup.json';subprocess.run(['php','bin/identity.php','backup',str(backup)],cwd=ROOT,env=env,check=True)
    saved=json.loads(backup.read_text());assert saved['schema']==4 and saved['workspaces'][wid]['visibility']=='public' and saved['workspaces'][lw['id']]['visibility']=='private'
    restored=dict(env,BOOK_DATA_DIR=str(data.parent/'restored'));subprocess.run(['php','bin/identity.php','restore',str(backup)],cwd=ROOT,env=restored,check=True)
    assert json.loads((data.parent/'restored/identity/identity.json').read_text())['workspaces']==saved['workspaces']
    check('identity backup/restore preserves visibility policies')

def main():
    with tempfile.TemporaryDirectory(prefix='book-privacy-http-') as tmp:
        tmp=Path(tmp);data=tmp/'data';env=dict(os.environ,BOOK_DATA_DIR=str(data),BOOK_SECURE_COOKIE='0',BOOK_ADMIN_HASH=subprocess.check_output(['php','-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',PASSWORD],text=True))
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        base=f'http://127.0.0.1:{port}'
        with (tmp/'server.log').open('w+') as log:
            server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','public/router.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
            try:
                for _ in range(60):
                    try:Client(base).request('/health/live',200);break
                    except OSError:time.sleep(.1)
                exercise(base,data,env)
            except Exception:log.seek(0);print(log.read());raise
            finally:server.terminate();server.wait(timeout=5)
if __name__=='__main__':main()
