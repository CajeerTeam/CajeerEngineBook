#!/usr/bin/env python3
"""Real Reader/API search, publication and RBAC; --postgres runs the same workflow on SQL."""
import importlib.util, json, os, re, socket, subprocess, sys, tempfile, time, urllib.parse, uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('workspace_http',ROOT/'tests/workspaces-http.py');w=importlib.util.module_from_spec(spec);spec.loader.exec_module(w)
Client,post,check,PASSWORD=w.Client,w.post,w.check,w.PASSWORD
PG='--postgres' in sys.argv

def exercise(base,data,env):
    guest,owner,viewer,outsider=[Client(base) for _ in range(4)];owner.login()
    for name,c in [('reader',viewer),('outsider',outsider)]:
        post(owner,'/account/users','create',username=name,name=name,email=name+'@example.org',password='password-'+name+'-123');c.login(name,'password-'+name+'-123')
    def identity():
        if PG:return json.loads(subprocess.check_output(['php','-r','require "bootstrap.php";echo json_encode((new CajeerBook\\IdentityStore(getenv("BOOK_DATA_DIR")))->backup(),JSON_THROW_ON_ERROR);'],cwd=ROOT,env=env,text=True))
        return json.loads((data/'identity/identity.json').read_text())
    org=post(owner,'/organizations','create',name='Search Team',slug='search-team')[0]['Location'].split('/')[-1]
    _,html=post(owner,'/organizations/'+org,'invite',200,revision='1',username='reader');post(viewer,'/organizations/join','accept',token=re.search(rb'/organizations/join#([a-f0-9]{64})',html).group(1).decode())
    for name in ['Primary','Other']:post(owner,'/workspaces','create',organization=org,name=name,slug=name.lower())
    spaces={w['name']:w for w in identity()['workspaces'].values()};wid=spaces['Primary']['id'];prefix='/w/'+org+'/'+wid;other='/w/'+org+'/'+spaces['Other']['id']
    def wrev():return str(identity()['workspaces'][wid]['revision'])
    def publish(book,path,title,body,rev='0',action='publish',p=prefix):
        post(owner,p+'/studio',action,book=book,version='1',path=path,title=title,body=body,section='Guide',order='10',revision=rev)
    def search(c,q,status=200,p=prefix,**options):
        h,b=c.request(p+'/api/v1/search?'+urllib.parse.urlencode({'q':q}|options),status)
        if status!=200:return h,b
        assert h['X-Search-Engine']==('postgresql' if PG else 'portable') and 'no-store' in h['Cache-Control']
        return h,json.loads(b)
    def keys(c,q,**kw):return [r['key'] for r in search(c,q,**kw)[1]]
    publish('guide','a','Quasar','**Red fox**. Policies. документация. [label](https://example.org/hiddenurltoken). `<script>alert(123)</script>`')
    publish('guide','b','Other','Quasar. Red swift fox.')
    publish('secret','a','Private Quasar','Quasar secrettoken')
    publish('guide','draft','Draft Quasar','draftonlytoken',action='save')
    publish('guide','a','Foreign Quasar','foreignneedle',p=other)
    post(owner,prefix+'/studio/books','visibility',slug='secret',visibility='private',revision='1')
    search(guest,'quasar',401);search(outsider,'quasar',404)
    assert len(keys(viewer,'quasar'))==3 and keys(viewer,'draftonlytoken')==[]
    post(owner,'/workspaces','visibility',id=wid,revision=wrev(),visibility='public')
    for c in [guest,outsider]:
        h,hits=search(c,'quasar');assert h['X-Total-Count']=='2' and [r['key'] for r in hits]==['guide/1/a','guide/1/b']
        assert hits[0]['url']==prefix+'/docs/guide/1/a' and all('snippet' in r and 'score' in r for r in hits)
        assert keys(c,'secrettoken')==[] and keys(c,'draftonlytoken')==[] and keys(c,'foreignneedle')==[]
        assert keys(c,'"red fox"')==['guide/1/a'] and keys(c,'quas')==['guide/1/a','guide/1/b']
        assert keys(c,'hiddenurltoken')==[]
        h,hits=search(c,'quasar',limit='1',offset='1',book='guide',version='1');assert h['X-Total-Count']=='2' and [r['key'] for r in hits]==['guide/1/b']
        assert search(c,'quasar',offset='99')[0]['X-Total-Count']=='2' and keys(c,'quasar',offset='99')==[]
        _,html=c.request(prefix+'/search?q=quasar&limit=1',200);assert b'2 results' in html and b'Private Quasar' not in html and b'Next' in html
        _,html=c.request(prefix+'/search?q=alert',200);assert b'<script>alert(123)</script>' not in html
        search(c,'quasar',422,limit='51');search(c,'quasar',422,book='../bad')
    _,html=owner.request(prefix+'/studio/search?q=quasar&audience=public',200);assert b'2 results' in html and b'Private Quasar' not in html
    _,html=owner.request(prefix+'/studio/search?q=quasar&audience=member',200);assert b'3 results' in html and b'Private Quasar' in html
    viewer.request(prefix+'/studio/search',403);guest.request(prefix+'/studio/search',401)
    check('Reader/API ranking, phrases, prefixes, filters, pagination, escaped snippets, Inspector RBAC and public isolation')
    if PG:
        assert keys(guest,'policy')==['guide/1/a'] and keys(guest,'документации')==['guide/1/a']
        h,hits=search(guest,'quasarr');assert h['X-Search-Match']=='similar' and [r['key'] for r in hits]==['guide/1/a']
        check('PostgreSQL English/Russian morphology and authorized trigram typo fallback')
    publish('guide','a','Quasar','newneedle',rev='1',action='save');assert keys(guest,'newneedle')==[] and keys(guest,'"red fox"')==['guide/1/a']
    publish('guide','a','Quasar','newneedle',rev='2');assert keys(guest,'newneedle')==['guide/1/a'] and keys(guest,'"red fox"')==[]
    post(owner,prefix+'/studio','unpublish',key='guide/1/a',revision='3');assert keys(guest,'newneedle')==[]
    post(owner,prefix+'/studio/books','visibility',slug='secret',visibility='public',revision='2');assert keys(guest,'secrettoken')==['secret/1/a']
    post(owner,prefix+'/studio/books','visibility',slug='secret',visibility='private',revision='3');assert keys(guest,'secrettoken')==[] and keys(viewer,'secrettoken')==['secret/1/a']
    uid=next(u['id'] for u in identity()['users'].values() if u['username']=='reader')
    post(owner,'/workspaces','role',id=wid,revision=wrev(),user=uid,role='none');search(viewer,'secrettoken',404)
    post(owner,'/workspaces','role',id=wid,revision=wrev(),user=uid,role='inherit');assert keys(viewer,'secrettoken')==['secret/1/a']
    post(owner,'/workspaces','visibility',id=wid,revision=wrev(),visibility='private');search(guest,'quasar',401);search(outsider,'quasar',404)
    post(owner,'/workspaces','archive',id=wid,revision=wrev());search(owner,'quasar',404)
    check('draft/publish/unpublish, visibility, explicit role denial and archive immediately change search access')

def main():
    with tempfile.TemporaryDirectory(prefix='book-search-http-') as tmp:
        tmp=Path(tmp);data=tmp/'data';env=dict(os.environ,BOOK_DATA_DIR=str(data),BOOK_SECURE_COOKIE='0',BOOK_ADMIN_HASH=subprocess.check_output(['php','-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',PASSWORD],text=True))
        if PG:
            if not env.get('BOOK_PG_DSN'):raise RuntimeError('BOOK_PG_DSN required')
            env['BOOK_PG_NAMESPACE']=uuid.uuid4().hex
            subprocess.run(['php','bin/postgres-content.php','migrate','--offline'],cwd=ROOT,env=env,check=True)
        else:
            for key in ('BOOK_PG_DSN','BOOK_PG_NAMESPACE'):env.pop(key,None)
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
            finally:
                server.terminate();server.wait(timeout=5)
                if PG:subprocess.run(['php','-r','require "bootstrap.php";$p=CajeerBook\\PostgresContentStore::connect();$q=$p->prepare("DELETE FROM cajeer_book.content_installation WHERE installation=?");$q->execute([getenv("BOOK_PG_NAMESPACE")]);'],cwd=ROOT,env=env,check=True)
if __name__=='__main__':main()
