#!/usr/bin/env python3
"""Two independent PHP HTTP nodes against one PostgreSQL, Redis and media directory.
The third node exercises degraded Redis behavior; all use real routes and sessions.
"""
import importlib.util, json, os, socket, subprocess, tempfile, time, uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('workspace_http',ROOT/'tests/workspaces-http.py')
w=importlib.util.module_from_spec(spec);spec.loader.exec_module(w)
Client,post,check,PASSWORD=w.Client,w.post,w.check,w.PASSWORD

def php(env,code,*args):
    return subprocess.check_output(['php','-r','require "bootstrap.php";'+code,*args],cwd=ROOT,env=env,text=True)

def start(env,tmp,name):
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    log=(tmp/(name+'.log')).open('w+')
    p=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','public/router.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
    base=f'http://127.0.0.1:{port}'
    for _ in range(100):
        try:
            Client(base).request('/health/live',200);return p,log,base
        except OSError:time.sleep(.1)
    p.terminate();p.wait(timeout=5);log.seek(0);raise RuntimeError('Node did not start: '+log.read())

def set_count(env,bucket,n,seconds):
    # Seed only a disposable installation's near-limit counter; HTTP requests perform actual Lua increments.
    php(env,'''$b=CajeerBook\\PostgresContentStore::identityBinding(getenv("BOOK_DATA_DIR"));$p="book:044:".hash("sha256",$b[0].":".$b[1]).":";
    $r=new Redis();$r->connect(getenv("BOOK_REDIS_HOST"),(int)(getenv("BOOK_REDIS_PORT")?:6379));if($pw=getenv("BOOK_REDIS_PASSWORD"))$r->auth(getenv("BOOK_REDIS_USER")?[getenv("BOOK_REDIS_USER"),$pw]:$pw);
    $r->setex($p."rate:".$argv[1].":".hash("sha256",bin2hex(inet_pton("127.0.0.1"))),(int)$argv[3],$argv[2]);''',bucket,str(n),str(seconds))

def exercise(a,b,env):
    owner=Client(a);owner.login();owner.base=b;owner.request('/account',200)
    post(owner,'/account/users','create',username='reader',name='Reader',email='reader@example.org',password='password-reader-123')
    member=Client(b);member.login('reader','password-reader-123');member.base=a;member.request('/account',200)
    identity=lambda:json.loads(php(env,'echo json_encode((new CajeerBook\\IdentityStore(getenv("BOOK_DATA_DIR")))->backup(),JSON_THROW_ON_ERROR);'))
    uid=next(x['id'] for x in identity()['users'].values() if x['username']=='reader')
    owner.base=a;post(owner,'/account/users','disable',id=uid);member.base=b;member.request('/account',401)
    check('session issued by node A is valid on B; account disable immediately revokes session on B')
    org=post(owner,'/organizations','create',name='Distributed Team',slug='distributed-team')[0]['Location'].split('/')[-1]
    post(owner,'/workspaces','create',organization=org,name='Docs',slug='docs');space=next(x for x in identity()['workspaces'].values() if x['name']=='Docs');wid=space['id'];prefix='/w/'+org+'/'+wid
    post(owner,'/workspaces','visibility',id=wid,revision=str(space['revision']),visibility='public')
    mid=w.upload(owner,prefix+'/studio/media',b'shared media from first node')
    def publish(body,rev):
        post(owner,prefix+'/studio','publish',book='guide',version='1',path='start',title='Redis Guide',section='Guide',order='10',revision=str(rev),body=body)
    owner.base=b;publish('# Redis Guide\n\noldneedle [Download](/media/'+mid+')',0)
    ga,gb=Client(a),Client(b);doc=prefix+'/docs/guide/1/start';search=prefix+'/api/v1/search?q=oldneedle'
    for g in (ga,gb):
        assert b'oldneedle' in g.request(doc,200)[1]
        assert [x['key'] for x in json.loads(g.request(search,200)[1])]==['guide/1/start']
        assert g.request(prefix+'/media/'+mid,200)[1]==b'shared media from first node'
    report=json.loads(subprocess.check_output(['php','bin/runtime.php','status'],cwd=ROOT,env=env,text=True))
    assert report['redis']['metrics']['cache_hits']>=2
    check('real shared Markdown/search cache hits across nodes; media uploaded to A is published and served by B')
    owner.base=a;post(owner,prefix+'/studio/books','visibility',slug='guide',visibility='private',revision='1')
    for g in (ga,gb):
        assert json.loads(g.request(search,200)[1])==[];g.request(doc,404);g.request(prefix+'/media/'+mid,404)
    owner.base=b;assert b'oldneedle' in owner.request(doc,200)[1]
    post(owner,prefix+'/studio/books','visibility',slug='guide',visibility='public',revision='2');publish('# Redis Guide\n\nnewneedle [Download](/media/'+mid+')',1)
    for g in (ga,gb):
        assert b'newneedle' in g.request(doc,200)[1]
        assert json.loads(g.request(search,200)[1])==[]
        assert len(json.loads(g.request(prefix+'/api/v1/search?q=newneedle',200)[1]))==1
    check('book privacy and publish invalidate old cached search and rendered content on both nodes')
    # Near-limit setup avoids 300 full DB requests in CI. Actual HTTP uses one shared counter.
    set_count(env,'api',298,60)
    ga.request(prefix+'/api/v1/pages',200);gb.request(prefix+'/api/v1/pages',200)
    h,_=ga.request(prefix+'/api/v1/pages',429,headers={'X-Forwarded-For':'198.51.100.1','X-Book-Client-IP':'198.51.100.2'});assert int(h['Retry-After'])>0
    set_count(env,'ci',119,60);ga.request('/api/v1/ci',415,{});gb.request('/api/v1/ci',429,{})
    set_count(env,'webhook',299,60);ga.request('/webhooks/github',415,{});gb.request('/webhooks/github',429,{})
    set_count(env,'login',30,900);ga.request('/login',429,{'username':'admin','password':'invalid'})
    check('cross-node Redis limits for API, CI, webhooks and login; spoofed forwarded headers do not bypass')
    return doc,prefix

def main():
    if not os.environ.get('BOOK_PG_DSN') or not os.environ.get('BOOK_REDIS_HOST'):raise RuntimeError('Real PostgreSQL and Redis required')
    with tempfile.TemporaryDirectory(prefix='book-redis-http-') as name:
        tmp=Path(name);env=dict(os.environ,BOOK_DATA_DIR=str(tmp/'data'),BOOK_PG_NAMESPACE=uuid.uuid4().hex,BOOK_SECURE_COOKIE='0',BOOK_ADMIN_HASH=subprocess.check_output(['php','-r','echo password_hash($argv[1],PASSWORD_DEFAULT);',PASSWORD],text=True))
        nodes=[]
        try:
            subprocess.run(['php','bin/postgres-content.php','migrate','--offline'],cwd=ROOT,env=env,check=True)
            nodes.append(start(env,tmp,'a'));nodes.append(start(env,tmp,'b'))
            doc,prefix=exercise(nodes[0][2],nodes[1][2],env)
            bad=dict(env,BOOK_REDIS_HOST='127.0.0.1',BOOK_REDIS_PORT='1');nodes.append(start(bad,tmp,'degraded'))
            guest=Client(nodes[-1][2]);assert b'newneedle' in guest.request(doc,200)[1]
            guest.request(prefix+'/api/v1/pages',503);guest.request('/health/ready',503);guest.request('/health/live',200)
            # Redis login limiter is supplemental; the durable SQL account/IP limiter still runs.
            guest.login();guest.request('/account',200)
            check('Redis outage: authorized Reader uses PostgreSQL, API fails closed, SQL login and liveness remain available')
        except Exception:
            for _,log,_ in nodes:log.seek(0);print(log.read())
            raise
        finally:
            for p,log,_ in nodes:p.terminate();p.wait(timeout=5);log.close()
            php(env,'$p=CajeerBook\\PostgresContentStore::connect();$q=$p->prepare("DELETE FROM cajeer_book.content_installation WHERE installation=?");$q->execute([getenv("BOOK_PG_NAMESPACE")]);')
if __name__=='__main__':main()
