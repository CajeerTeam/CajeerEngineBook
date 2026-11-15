#!/usr/bin/env python3
"""Real PHP HTTP ingress, Studio forms, authorization, durable worker and controlled Git API.
--policy-only avoids CommonMark-backed first import, but runs real worker downloads and queue.
"""
import hashlib,hmac,json,os,re,signal,socket,subprocess,sys,tempfile,time,urllib.request,urllib.error
from pathlib import Path
from importlib.util import spec_from_file_location,module_from_spec
ROOT=Path(__file__).resolve().parents[1]
spec=spec_from_file_location('workspace_http',ROOT/'tests/workspaces-http.py');w=module_from_spec(spec);spec.loader.exec_module(w)
Client,post,check,PASSWORD=w.Client,w.post,w.check,w.PASSWORD
FULL='--policy-only' not in sys.argv
IN='incoming-webhook-secret-'+'x'*24;OUT='outgoing-webhook-secret-'+'y'*24
WORKER=r'''require "bootstrap.php";require "tests/github-fixture.php";require "tests/gitlab-fixture.php";
$f=json_decode(file_get_contents(getenv('BOOK_TEST_GITHUB_FIXTURE')),true);$gh=new GitFixture();$gh->set($f['files']);$gl=new GitLabFixture();$gl->set($f['files']);foreach([$gh,$gl] as $x){$x->delay=$f['delay']??0;$x->signal=$f['signal']??'';$x->fail=$f['fail']??false;}
$factory=fn($t,$c)=>($c['provider']??'github')==='gitlab'?$gl->client($t,$c['origin'],$c['repo']):$gh->client($t);
$root=getenv('BOOK_DATA_DIR');$worker=new CajeerBook\AutomationWorker($root,new CajeerBook\IdentityStore($root),$factory,function($r){file_put_contents(getenv('BOOK_TEST_GITHUB_FIXTURE').'.sent',json_encode($r)."\n",FILE_APPEND);return ['status'=>(int)(getenv('BOOK_TEST_DELIVERY_STATUS')?:204)];});echo $worker->tick();'''

def run(base,data,fixture,env):
    owner,admin,editor,outside,guest=[Client(base) for _ in range(5)];owner.login()
    for name,c in [('manager',admin),('writer',editor),('outsider',outside)]:
        post(owner,'/account/users','create',username=name,name=name,email=name+'@example.org',password='password-'+name+'-123');c.login(name,'password-'+name+'-123')
    org=post(owner,'/organizations','create',name='Automation team',slug='auto-team')[0]['Location'];oid=org.split('/')[-1]
    other=post(outside,'/organizations','create',name='Outside',slug='outside')[0]['Location'].split('/')[-1]
    def state():return json.loads((data/'identity/identity.json').read_text())
    def uid(name):return next(u['id'] for u in state()['users'].values() if u['username']==name)
    for name,c,role in [('manager',admin,'admin'),('writer',editor,'editor')]:
        _,html=post(owner,org,'invite',200,revision=str(state()['organizations'][oid]['revision']),username=name);secret=re.search(rb'/organizations/join#([a-f0-9]{64})',html).group(1).decode();post(c,'/organizations/join','accept',token=secret);post(owner,org,'role',revision=str(state()['organizations'][oid]['revision']),user=uid(name),role=role)
    post(owner,'/workspaces','create',organization=oid,name='GitHub',slug='github');post(owner,'/workspaces','create',organization=oid,name='GitLab',slug='gitlab');post(outside,'/workspaces','create',organization=other,name='Other',slug='other')
    spaces={x['name']:x for x in state()['workspaces'].values()};a,b,o=spaces['GitHub'],spaces['GitLab'],spaces['Other'];prefix=lambda x:'/w/'+x['organization']+'/'+x['id'];pa,pb,po=map(prefix,(a,b,o));route=pa+'/studio/automation';hook=pa+'/webhooks/github';store=data/'workspaces'/oid/a['id'];labstore=data/'workspaces'/oid/b['id'];db=lambda:json.loads((store/'books.json').read_text());labdb=lambda:json.loads((labstore/'books.json').read_text())
    def remote(files,provider='github',delay=0,signal_path=''):
        tmp=fixture.with_suffix('.tmp');tmp.write_text(json.dumps({'files':files,'provider':provider,'delay':delay,'signal':str(signal_path)}));tmp.replace(fixture)
    def commit(provider='github'):
        code='require "bootstrap.php";require "tests/github-fixture.php";require "tests/gitlab-fixture.php";$f=$argv[2]==="github"?new GitFixture():new GitLabFixture();$f->set(json_decode(file_get_contents($argv[1]),true)["files"]);echo $f->head;'
        return subprocess.check_output(['php','-r',code,str(fixture),provider],cwd=ROOT,text=True).strip()
    def worker(status=204,async_=False):
        e=dict(env,BOOK_TEST_DELIVERY_STATUS=str(status));args=['php','-r',WORKER]
        if async_:return subprocess.Popen(args,cwd=ROOT,env=e,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        return int(subprocess.check_output(args,cwd=ROOT,env=e,text=True))
    def mutate(path,code):subprocess.run(['php','-r','require "bootstrap.php";$s=new CajeerBook\\BookStore($argv[1],false);$s->change(function(&$d){'+code+'});',str(path)],cwd=ROOT,check=True)
    def event(path,payload,expected,provider='github',delivery='event-1',typ=None,signature=None):
        body=json.dumps(payload,separators=(',',':')).encode();typ=typ or ('push' if provider=='github' else 'Push Hook')
        if provider=='github':headers={'X-Hub-Signature-256':signature or 'sha256='+hmac.new(IN.encode(),body,hashlib.sha256).hexdigest(),'X-GitHub-Event':typ,'X-GitHub-Delivery':delivery}
        else:headers={'X-Gitlab-Token':signature or IN,'X-Gitlab-Event':typ,'X-Gitlab-Event-UUID':delivery}
        req=urllib.request.Request(base+path,data=body,headers=headers|{'Content-Type':'application/json'})
        try:r=guest.opener.open(req,timeout=10)
        except urllib.error.HTTPError as e:r=e
        with r:
            result=r.read();assert r.status==expected,(expected,r.status,result[:500]);return json.loads(result)
    remote({'docs/README.md':'# Home'})
    post(owner,pa+'/studio/git','configure',200,provider='github',repo='team/docs',branch='main',root='docs',book='guide',version='1.0')
    editor.request(route,403);outside.request(route,404);guest.request(route,401);admin.request(route,200);admin.request(route,403,{'action':'configure','csrf':'wrong'})
    post(editor,route,'configure',403,incoming=IN);post(admin,route,'configure',422,incoming=IN,destination='https://evil.example/hook',outgoing=OUT,**{'events[]':'document.changed'})
    _,html=post(admin,route,'configure',200,incoming=IN,destination='https://receiver.example.org/hook',outgoing=OUT,**{'events[]':'document.changed'})
    assert IN.encode() not in html and OUT.encode() not in html and db()['automation']['config']['in_secret'].encode() not in html and db()["schema"]==5 and hook.encode() in html
    check('Studio admin-only configuration, CSRF, origin allowlist, encrypted secret and scoped isolation')
    sha=commit();payload={'ref':'refs/heads/main','after':sha,'repository':{'full_name':'team/docs'}}
    event(hook,payload,403,signature='bad');event('/w/'+other+'/'+a['id']+'/webhooks/github',payload,404);event(po+'/webhooks/github',payload,409)
    event(hook,payload|{'repository':{'full_name':'outside/docs'}},403);assert event(hook,payload|{'ref':'refs/heads/feature'},200)['status']=='ignored'
    r=event(hook,payload,202);assert event(hook,payload,200)['job']==r['job'] and event(hook,payload,200,delivery='changed-header')['job']==r['job']
    event(hook,payload|{'after':'a'*40},409)
    pr={'action':'closed','repository':{'full_name':'team/docs'},'pull_request':{'merged':False,'base':{'ref':'main'},'merge_commit_sha':sha}}
    assert event(hook,pr,200,delivery='pr-open',typ='pull_request')['status']=='ignored'
    pr['pull_request']['merged']=True;mr=event(hook,pr,202,delivery='pr-merged',typ='pull_request')
    assert len(db()['automation']['jobs'])==2
    check('real signed ingress, branch/repository isolation, duplicate/replay rejection and merged PR trigger')
    if not FULL:mutate(store,'$d["github"]["commit"]="'+sha+'";')
    assert worker()==1 and worker()==1
    assert all(db()['automation']['jobs'][x]['state']=='succeeded' and db()['automation']['jobs'][x]['result_commit']==sha for x in [r['job'],mr['job']])
    if FULL:
        entry=db()['pages']['guide/1.0/index'];assert entry['draft']['body']=='# Home' and entry['published'] is None
        post(owner,pa+'/studio/reviews','policy',revision='1',required='1');post(owner,pa+'/studio','publish',409,book='guide',version='1.0',path='index',title='Home',section='Imported',body='# Home',order='10',revision=str(entry['revision']))
    while worker():pass
    # Real committed mutation and outgoing retry. Storage mode supplies an existing valid snapshot.
    if FULL:post(editor,pa+'/studio','save',book='guide',version='1.0',path='local',title='Private local',section='Guide',order='10',body='# Private secret text',revision='0')
    else:mutate(store,'$p=["title"=>"Private local","section"=>"Guide","order"=>10,"body"=>"# Private secret text","media"=>[],"updated"=>gmdate("c")];$d["pages"]["guide/1.0/local"]=["id"=>str_repeat("a",32),"revision"=>1,"draft"=>$p,"published"=>null];')
    jid=list(db()['automation']['jobs'])[-1];assert db()['automation']['jobs'][jid]['payload']['event']=='document.changed';assert worker(503)==1;j=db()['automation']['jobs'][jid];assert j['state']=='queued' and j['attempts']==1 and j['due']>time.time()
    sent=json.loads((Path(str(fixture)+'.sent')).read_text().splitlines()[-1]);assert sent['headers']['X-Cajeer-Delivery']==jid and 'Private' not in sent['body'] and sent['headers']['X-Cajeer-Signature']=='sha256='+hmac.new(OUT.encode(),(sent['headers']['X-Cajeer-Timestamp']+'.'+sent['body']).encode(),hashlib.sha256).hexdigest()
    mutate(store,'$d["automation"]["jobs"]["'+jid+'"]["due"]=time();');assert worker(400)==1 and db()['automation']['jobs'][jid]['state']=='dead';assert jid.encode() in admin.request(route+'?state=dead',200)[1];post(admin,route,'retry',200,id=jid);assert worker()==1 and db()['automation']['jobs'][jid]['state']=='succeeded'
    check('worker process restart, real outbox transaction, signed delivery, retry/backoff and dead-letter UI')
    # Revocation while worker downloads a different commit. No identity lock held across network.
    started=fixture.with_suffix('.started');remote({'docs/README.md':'# Updated remote'},delay=1800,signal_path=started);sha2=commit();x=event(hook,payload|{'after':sha2},202,delivery='revocation');before=db()['github']['commit'];proc=worker(async_=True)
    for _ in range(100):
        if started.exists():break
        time.sleep(.03)
    else:proc.kill();raise AssertionError('worker did not reach remote fetch')
    post(owner,org,'role',revision=str(state()['organizations'][oid]['revision']),user=uid('manager'),role='editor');stdout,stderr=proc.communicate(timeout=10);assert proc.returncode==0,(stdout,stderr)
    assert db()['github']['commit']==before and db()['automation']['jobs'][x['job']]['state']=='cancelled';event(hook,payload|{'after':sha2},409,delivery='after-revoke');admin.request(route,403)
    check('live revocation while network is in flight blocks import and future webhooks')
    # GitLab route and merge event are independent in another workspace.
    remote({'docs/README.md':'# GitLab'},provider='gitlab');post(owner,pb+'/studio/git','configure',200,provider='gitlab',origin='https://gitlab.com',repo='group/sub/docs',branch='main',root='docs',book='lab',version='1.0')
    post(owner,pb+'/studio/automation','configure',200,incoming=IN);labhook=pb+'/webhooks/gitlab';ls=commit('gitlab');lp={'ref':'refs/heads/main','after':ls,'project':{'id':42}}
    event(labhook,lp,403,provider='gitlab',signature='bad');event(labhook,lp|{'project':{'id':43}},403,provider='gitlab');lr=event(labhook,lp,202,provider='gitlab')
    lm={'project':{'id':42},'object_attributes':{'action':'merge','target_branch':'main','target_project_id':42,'merge_commit_sha':ls}};lmr=event(labhook,lm,202,provider='gitlab',delivery='mr',typ='Merge Request Hook');assert event(labhook,lm,200,provider='gitlab',delivery='mr',typ='Merge Request Hook')['job']==lmr['job']
    if not FULL:mutate(labstore,'$d["github"]["commit"]="'+ls+'";')
    assert worker()==1 and worker()==1;assert labdb()['automation']['jobs'][lr['job']]['state']=='succeeded' and labdb()['automation']['jobs'][lmr['job']]['state']=='succeeded';assert labdb()['schema']==5
    check('GitLab token, project ID, merged MR, independent queue and worker execution')

if __name__=='__main__':
    with tempfile.TemporaryDirectory(prefix='book-automation-http-') as td:
        td=Path(td);data=td/'data';fixture=td/'github.json';fixture.write_text(json.dumps({'files':{'docs/README.md':'# Home'},'provider':'github'}))
        password_hash=subprocess.check_output(['php','-r','echo password_hash($argv[1],PASSWORD_DEFAULT);',PASSWORD],text=True)
        env=dict(os.environ,BOOK_DATA_DIR=str(data),BOOK_TEST_GITHUB_FIXTURE=str(fixture),BOOK_ADMIN_HASH=password_hash,BOOK_SECURE_COOKIE='0',BOOK_GITHUB_KEY='ab'*32,BOOK_AUTOMATION_KEY='cd'*32,BOOK_WEBHOOK_ORIGINS='https://receiver.example.org',PHP_CLI_SERVER_WORKERS='3')
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        base=f'http://127.0.0.1:{port}'
        with (td/'server.log').open('w+') as log:
            proc=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','tests/github-http-router.php'],cwd=ROOT,env=env,stdout=log,stderr=log,start_new_session=True)
            try:
                for _ in range(100):
                    try:Client(base).request('/health/live',200);break
                    except OSError:time.sleep(.05)
                else:raise RuntimeError('server did not start')
                run(base,data,fixture,env)
            except BaseException:
                log.seek(0);print(log.read()[-6000:],file=sys.stderr);raise
            finally:os.killpg(proc.pid,signal.SIGTERM);proc.wait(timeout=10)
