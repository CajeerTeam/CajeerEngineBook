#!/usr/bin/env python3
"""Real multi-user HTTP isolation. Default runs real CommonMark saves/rendering.
--storage-only seeds a valid snapshot through BookStore for environments without vendor dependencies.
"""
import json, os, re, socket, subprocess, tempfile, time, sys, urllib.request, urllib.error, tarfile, io
from pathlib import Path
from importlib.util import spec_from_file_location, module_from_spec
ROOT=Path(__file__).resolve().parents[1]
spec=spec_from_file_location('identity_http',ROOT/'tests/identity-http.py');m=module_from_spec(spec);spec.loader.exec_module(m)
Client, check, PASSWORD=m.Client,m.check,m.PASSWORD
FULL='--storage-only' not in sys.argv

def post(c,path,action,status=303,**fields):
    return c.request(path,status,{'csrf':c.csrf('/account'),'action':action}|fields)

def upload(c,path,content=b'workspace isolated attachment'):
    boundary='BookWorkspaceBoundary987654321'
    chunks=[]
    for name,value in {'csrf':c.csrf('/account'),'action':'upload','folder':''}.items():
        chunks.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
    chunks.append(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="test.txt"\r\nContent-Type: text/plain\r\n\r\n'.encode()+content+b'\r\n')
    chunks.append(f'--{boundary}--\r\n'.encode())
    req=urllib.request.Request(c.base+path,data=b''.join(chunks),headers={'Content-Type':f'multipart/form-data; boundary={boundary}','X-Book-Media':'1'})
    with c.opener.open(req,timeout=10) as r:
        assert r.status==200;return json.loads(r.read())['item']['id']

def exercise(base,data,env):
    guest,a,b,reader=[Client(base) for _ in range(4)]
    legacy=json.loads(guest.request('/api/v1/pages',200)[1]);a.login()
    for username in ['writer','reader']:
        post(a,'/account/users','create',username=username,name=username,email=username+'@example.org',password='password-'+username+'-123')
    b.login('writer','password-writer-123');reader.login('reader','password-reader-123')
    ao=post(a,'/organizations','create',name='Alpha',slug='alpha-team')[0]['Location'].split('/')[-1]
    bo=post(b,'/organizations','create',name='Beta',slug='beta-team')[0]['Location'].split('/')[-1]
    for client,org,name in [(a,ao,'Alpha Workspace'),(b,bo,'Beta Workspace'),(a,ao,'Second Workspace')]:
        post(client,'/workspaces','create',organization=org,name=name,slug='second' if name.startswith('Second') else 'shared')
    state=json.loads((data/'identity/identity.json').read_text());ws={w['name']:w for w in state['workspaces'].values()};wa,wb,wc=[ws[n] for n in ['Alpha Workspace','Beta Workspace','Second Workspace']]
    def prefix(w):return '/w/'+w['organization']+'/'+w['id']
    pa,pb,pc=map(prefix,(wa,wb,wc))
    def dbpath(w):return data/'workspaces'/w['organization']/w['id']/'books.json'
    assert all(json.loads(dbpath(w).read_text())['pages']==[] for w in (wa,wb,wc))
    assert b'Beta Workspace' not in a.request('/workspaces',200)[1]
    guest.request(pa+'/',401);a.request(pb+'/',404);b.request(pa+'/api/v1/pages',404);a.request('/w/'+ao+'/'+wb['id']+'/',404)
    check('real workspace creation, empty stores, no cross-organization or wrong-parent access')
    for c,p,title in [(a,pa,'Alpha Book'),(b,pb,'Beta Book'),(a,pc,'Second Book')]:
        post(c,p+'/studio/books','create',slug='guide',title=title)
        _,html=c.request(p+'/studio/books',200);assert title.encode() in html and f'action="{p}/studio/books"'.encode() in html
        _,html=c.request(p+'/studio?book=guide',200);assert f'data-workspace-prefix="{p}"'.encode() in html and f'action="{p}/studio"'.encode() in html and b'name="book" value="guide"' in html
    post(a,pa+'/studio/books','create',409,slug='guide',title='Duplicate')
    assert len({json.loads(dbpath(w).read_text())['books']['guide']['id'] for w in (wa,wb,wc)})==3
    check('same book slug in three scopes; scoped navigation and forms')
    ma=upload(a,pa+'/studio/media');mb=upload(b,pb+'/studio/media');assert ma!=mb
    a.request(pa+'/studio/media/file/'+ma,200);a.request(pa+'/studio/media/file/'+mb,404);a.request(pb+'/studio/media/file/'+mb,404)
    a.request(pa+'/media/'+ma,404);guest.request(pa+'/studio/media/file/'+ma,401)
    _,picker=a.request(pa+'/studio/media/picker',200);assert ma in picker.decode() and mb not in picker.decode()
    check('multipart uploads, deduplication and private media picker isolated')
    key='guide/1.0/start'
    def publish(c,p,w,title,mid=None):
        body='# '+title+'\n\n[Next](/docs/guide/1.0/start)\n'+(f'\n[Download](/media/{mid}?download=1)\n' if mid else '')
        if FULL:
            fields=dict(book='guide',version='1.0',path='start',title=title,section='Guide',order='10',body=body,revision='0')
            post(c,p+'/studio','publish',**fields)
        else:
            # Seed a valid published snapshot via real BookStore, no fake renderer or HTTP handler.
            code='require "bootstrap.php";$s=new CajeerBook\\BookStore($argv[1],false);$s->change(function(&$d)use($argv){$p=["title"=>$argv[2],"section"=>"Guide","order"=>10,"body"=>$argv[3],"updated"=>gmdate("c"),"media"=>$argv[4]!==""?[$argv[4]]:[]];$d["pages"]["guide/1.0/start"]=["id"=>bin2hex(random_bytes(16)),"revision"=>1,"draft"=>$p,"published"=>$p,"published_key"=>"guide/1.0/start"];});'
            subprocess.run(['php','-r',code,str(dbpath(w).parent),title,body,mid or ''],cwd=ROOT,check=True)
    publish(a,pa,wa,'Alpha Secret',ma);publish(b,pb,wb,'Beta Secret',mb);publish(a,pc,wc,'Second Secret')
    for c,p,title in [(a,pa,'Alpha Secret'),(b,pb,'Beta Secret'),(a,pc,'Second Secret')]:
        pages=json.loads(c.request(p+'/api/v1/pages',200)[1]);assert list(pages)==[key] and pages[key]['title']==title
        assert json.loads(c.request(p+'/api/v1/pages/'+key,200)[1])['title']==title
        hits=json.loads(c.request(p+'/api/v1/search?q=Secret',200)[1]);assert len(hits)==1 and hits[0]['url']==p+'/docs/'+key
        if FULL:
            _,html=c.request(p+'/docs/'+key,200);assert f'href="{p}/docs/{key}"'.encode() in html
    assert json.loads(guest.request('/api/v1/pages',200)[1])==legacy
    a.request(pa+'/media/'+ma+'?download=1',200);a.request(pa+'/media/'+mb,404);a.request('/media/'+ma,404);guest.request(pa+'/media/'+ma,401)
    check('published snapshots, search, media and legacy catalog isolated'+(' with actual Markdown render' if FULL else ' (storage fixture)'))
    # Invite a member into Alpha. They can read published content but cannot use any Studio route.
    _,html=post(a,'/organizations/'+ao,'invite',200,revision='1',username='reader');token=re.search(rb'/organizations/join#([a-f0-9]{64})',html).group(1).decode();post(reader,'/organizations/join','accept',token=token)
    reader.request(pa+'/api/v1/pages/'+key,200);reader.request(pa+'/media/'+ma,200);reader.request(pa+'/studio',403);reader.request(pa+'/studio/media/picker',403);reader.request(pa+'/studio/export',403);reader.request(pa+'/studio/media/backup',403)
    post(reader,pa+'/studio/books','create',403,slug='attack',title='Attack');reader.request(pb+'/api/v1/pages',404)
    check('member read-only access; draft, mutation and backup endpoints denied')
    # Workspace rename keeps stable URL; archive hides both published and private routes.
    post(a,'/workspaces','rename',id=wa['id'],revision='1',name='Alpha Renamed',slug='alpha-renamed');a.request(pa+'/api/v1/pages',200)
    post(a,'/workspaces','archive',id=wa['id'],revision='1',status=409)
    post(a,'/workspaces','archive',id=wa['id'],revision='2');reader.request(pa+'/api/v1/pages',404);a.request(pa+'/studio',404)
    post(a,'/workspaces','restore',id=wa['id'],revision='3');reader.request(pa+'/api/v1/pages',200)
    check('workspace revision conflicts, rename, archive and restore')
    # Real tree mutation/redirects use scoped route (no parser for tree operation).
    _,tree=a.request(pa+'/studio/tree?scope=guide%2F1.0',200);tt=re.search(rb'name="token" value="([a-f0-9]+)"',tree).group(1).decode()
    post(a,pa+'/studio/tree','move',scope='guide/1.0',token=tt,source=key,target='',position='root',slug='moved')
    a.request(pa+'/api/v1/pages/'+key,200)
    _,tree=a.request(pa+'/studio/tree?scope=guide%2F1.0',200);tt=re.search(rb'name="token" value="([a-f0-9]+)"',tree).group(1).decode();post(a,pa+'/studio/tree','publish',scope='guide/1.0',token=tt)
    h,_=reader.request(pa+'/api/v1/pages/'+key,308);assert h['Location']==pa+'/api/v1/pages/guide/1.0/moved';b.request(pb+'/api/v1/pages/'+key,200)
    check('tree move and published redirect stay within workspace')
    _,raw=a.request(pa+'/studio/media/backup',200)
    with tarfile.open(fileobj=io.BytesIO(raw)) as tar:
        assert 'media/'+ma+'.blob' in tar.getnames() and 'media/'+mb+'.blob' not in tar.getnames();assert json.load(tar.extractfile('books.json'))['books']['guide']['title']=='Alpha Book'
    check('workspace TAR contains only scoped books and media')
    uid=next(u['id'] for u in json.loads((data/'identity/identity.json').read_text())['users'].values() if u['username']=='reader')
    post(a,'/organizations/'+ao,'transfer',revision='3',user=uid);a.request(pa+'/studio',403);reader.request(pa+'/studio',200)
    post(reader,'/organizations/'+ao,'remove',revision='4',user=next(u['id'] for u in state['users'].values() if u['username']=='admin'));a.request(pa+'/api/v1/pages',404);a.request(pa+'/media/'+ma,404)
    check('ownership transfer and removal revoke prior content authority')
    # Bind legacy without moving data; installation owner stays independent of other organizations.
    lo=post(a,'/organizations','create',name='Legacy',slug='legacy-team')[0]['Location'].split('/')[-1]
    post(a,'/workspaces','adopt',organization=lo,name='Legacy documentation',slug='legacy');assert json.loads(guest.request('/api/v1/pages',200)[1])==legacy;a.request('/studio',200)
    check('legacy adoption preserves original public URL and Studio')
    # Operator backup restores registry separately and scoped content into matching stable path.
    backup=data.parent/'identity.json';subprocess.run(['php','bin/identity.php','backup',str(backup)],cwd=ROOT,env=env,check=True)
    archive=data.parent/'workspace.tar';subprocess.run(['php','bin/workspaces.php','backup',wa['id'],str(archive)],cwd=ROOT,env=env,check=True)
    if FULL:
        restored=dict(env,BOOK_DATA_DIR=str(data.parent/'restored'));subprocess.run(['php','bin/identity.php','restore',str(backup)],cwd=ROOT,env=restored,check=True);subprocess.run(['php','bin/workspaces.php','restore',wa['id'],str(archive)],cwd=ROOT,env=restored,check=True)
        restoredpath=data.parent/'restored'/'workspaces'/ao/wa['id']/'books.json';assert json.loads(restoredpath.read_text())['pages']['guide/1.0/moved']['published']['title']=='Alpha Secret';check('operator restore with real CommonMark validation')

def main():
    with tempfile.TemporaryDirectory(prefix='book-workspaces-http-') as tmp:
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
