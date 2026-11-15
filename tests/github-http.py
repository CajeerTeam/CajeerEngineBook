#!/usr/bin/env python3
"""Real PHP HTTP/RBAC + controlled GitHub REST transport. --policy-only avoids CommonMark."""
import json, os, re, signal, socket, subprocess, tempfile, time, sys, concurrent.futures
from pathlib import Path
from importlib.util import spec_from_file_location, module_from_spec
ROOT=Path(__file__).resolve().parents[1]
spec=spec_from_file_location('workspace_http',ROOT/'tests/workspaces-http.py');w=module_from_spec(spec);spec.loader.exec_module(w)
Client,post,check,PASSWORD=w.Client,w.post,w.check,w.PASSWORD
FULL='--policy-only' not in sys.argv

def run(base,data,fixture):
    owner,editor,viewer,outside,guest=[Client(base) for _ in range(5)];owner.login()
    for name,c in [('writer',editor),('reader',viewer),('outside',outside)]:
        post(owner,'/account/users','create',username=name,name=name,email=name+'@example.org',password='password-'+name+'-123');c.login(name,'password-'+name+'-123')
    org=post(owner,'/organizations','create',name='Git team',slug='git-team')[0]['Location'];oid=org.split('/')[-1]
    other=post(outside,'/organizations','create',name='Other team',slug='other-team')[0]['Location'].split('/')[-1]
    def state():return json.loads((data/'identity/identity.json').read_text())
    def uid(name):return next(u['id'] for u in state()['users'].values() if u['username']==name)
    for name,c,role in [('writer',editor,'editor'),('reader',viewer,'viewer')]:
        _,html=post(owner,org,'invite',200,revision=str(state()['organizations'][oid]['revision']),username=name);token=re.search(rb'/organizations/join#([a-f0-9]{64})',html).group(1).decode();post(c,'/organizations/join','accept',token=token)
        if role!='viewer':post(owner,org,'role',revision=str(state()['organizations'][oid]['revision']),user=uid(name),role=role)
    post(owner,'/workspaces','create',organization=oid,name='Git',slug='git');post(owner,'/workspaces','create',organization=oid,name='Second',slug='second');post(outside,'/workspaces','create',organization=other,name='Other',slug='git')
    spaces={x['name']:x for x in state()['workspaces'].values()};a,b,o=spaces['Git'],spaces['Second'],spaces['Other'];prefix=lambda x:'/w/'+x['organization']+'/'+x['id'];pa,pb,po=map(prefix,(a,b,o));route=pa+'/studio/github';store=data/'workspaces'/oid/a['id'];db=lambda:json.loads((store/'books.json').read_text())
    def remote(files,delay=0,signal_path='',fail=False):
        tmp=fixture.with_suffix('.tmp');tmp.write_text(json.dumps({'files':files,'delay':delay,'signal':str(signal_path),'fail':fail}));tmp.replace(fixture)
    def preview(c=editor):
        _,html=post(c,route,'preview',200);return re.search(rb'name="preview_token" value="([^"]+)"',html).group(1).decode(),re.search(rb'name="commit" value="([a-f0-9]{40})"',html).group(1).decode(),html
    def apply(token,commit,resolution='safe',status=200,c=editor,**kw):return post(c,route,'apply',status,preview_token=token,commit=commit,resolution=resolution,**kw)
    assert b'Connect repository' in owner.request(route,200)[1] and b'Connect repository' not in editor.request(route,200)[1]
    viewer.request(route,403);outside.request(route,404);guest.request(route,401)
    for action in ['configure','rotate','disconnect']:post(editor,route,action,403)
    post(viewer,route,'preview',403);post(outside,route,'apply',404);post(editor,route,'publish',403);editor.request(route,403,{'action':'preview','csrf':'wrong'})
    post(editor,route,'preview',409);post(owner,route,'configure',422,repo='https://evil.example/a/b',branch='main',book='guide',version='1.0')
    check('GitHub forms, scoped role checks, CSRF and invalid repository rejected')
    remote({'docs/README.md':'# Home','docs/asset.txt':'private attachment'})
    token='github_pat_fixture_private_token';_,html=post(owner,route,'configure',200,repo='team/docs',branch='main',root='docs',book='guide',version='1.0',token=token,push='1')
    assert token.encode() not in html and token not in json.dumps(db()) and db()['github']['credential'] not in html.decode()
    assert b'team/docs' in editor.request(route,200)[1] and b'team/docs' not in editor.request(pb+'/studio/github',200)[1] and b'team/docs' not in outside.request(po+'/studio/github',200)[1]
    post(editor,pb+'/studio/github','preview',409);post(editor,route,'apply',409,preview_token='invalid',commit='0'*40,resolution='safe')
    check('actual connection, encrypted credential, workspace isolation and forged preview rejection')
    if not FULL:
        # A preexisting tracked page lets the real signed deletion transaction run without parser dependencies.
        code='require "bootstrap.php";$s=new CajeerBook\\BookStore($argv[1],false);$s->change(function(&$d){$key="guide/1.0/index";$p=["title"=>"Home","section"=>"Imported","order"=>10,"body"=>"# Home","media"=>[],"updated"=>gmdate("c")];$id=bin2hex(random_bytes(16));$d["pages"][$key]=["id"=>$id,"revision"=>1,"draft"=>$p,"published"=>$p];$d["github"]["pages"]["README.md"]=["id"=>$id,"key"=>$key,"sha"=>sha1("blob 6\\0# Home"),"base"=>CajeerBook\\GitHubSync::fingerprint($p)];});'
        subprocess.run(['php','-r',code,str(store)],cwd=ROOT,check=True);remote({'outside.md':'# Other'})
    else:
        t,commit,html=preview();assert b'create' in html;apply(t,commit);d=db();p=d['pages']['guide/1.0/index'];assert p['published'] is None and p['draft']['body']=='# Home' and d['github']['commit']==commit
        viewer.request(pa+'/api/v1/pages/guide/1.0/index',404);t,commit,_=preview();apply(t,commit);assert db()['pages']['guide/1.0/index']['revision']==p['revision']
        check('real CommonMark-backed initial sync, cache, private draft and no duplicate history')
        remote({'docs/README.md':'# Remote','docs/asset.txt':'private attachment'});post(editor,pa+'/studio','save',book='guide',version='1.0',path='index',title='Home',section='Imported',order='10',body='# Local',revision=str(p['revision']))
        t,commit,html=preview();assert b'conflict' in html and b'Compare local' in html;apply(t,commit,status=409);apply(t,commit,resolution='local');assert db()['pages']['guide/1.0/index']['draft']['body']=='# Local'
        rev=str(db()['github']['revision']);proposal=editor.request(route,200)[1];state_hash=re.search(rb'name="state" value="([a-f0-9]{64})"',proposal).group(1).decode();post(editor,route,'propose',200,revision=rev,confirm='1',state=state_hash);assert db()['github']['runs'][-1]['detail']=='https://github.com/team/docs/pull/17'
        check('HTTP conflict resolution and Pull Request creation using controlled GitHub API')
        remote({'outside.md':'# Other'})
    t,commit,html=preview();assert b'removed' in html;before=db();apply(t,commit,status=409);assert db()==before
    apply(t,commit,resolution='manual',status=409);apply(t,commit,resolution='manual',**{'decision['+__import__('hashlib').sha256(b'README.md').hexdigest()+']':'detach'})
    assert db()['pages']==before['pages'] and db()['github']['pages']==[] and db()['github']['commit']==commit
    apply(t,commit,resolution='local',status=409);check('real signed HTTP sync with explicit per-file deletion resolution, content retention and replay rejection')
    remote({'outside.md':'# Other'},fail=True);before=db();post(editor,route,'preview',409);failed=db();assert failed['github']['runs'][-1]['type']=='error' and failed['pages']==before['pages'] and failed['github']['commit']==before['github']['commit'];check('safe remote failure recorded without advancing cursor or changing pages')
    # Reconnect clean source, hold a remote fetch, downgrade Editor while it is in flight.
    post(owner,route,'disconnect',200,revision=str(db()['github']['revision']),confirm='1');post(owner,route,'configure',200,repo='team/docs',branch='main',root='docs',book='guide',version='1.0',token=token,push='0')
    started=fixture.with_suffix('.started');remote({'docs/README.md':'# Home'},delay=1800,signal_path=started)
    csrf=editor.csrf('/account')
    with concurrent.futures.ThreadPoolExecutor(1) as pool:
        req=pool.submit(editor.request,route,403,{'action':'preview','csrf':csrf})
        for _ in range(100):
            if started.exists():break
            time.sleep(.02)
        else:raise AssertionError('controlled remote fetch did not start')
        post(owner,'/workspaces','role',id=a['id'],revision=str(state()['workspaces'][a['id']]['revision']),user=uid('writer'),role='viewer');req.result(timeout=10)
    assert db()['github']['commit']=='';editor.request(route,403);check('identity lock released during download; revoked editor denied before preview/apply')

if __name__=='__main__':
    with tempfile.TemporaryDirectory(prefix='book-github-http-') as tmp:
        tmp=Path(tmp);data=tmp/'data';fixture=tmp/'github.json';fixture.write_text(json.dumps({'files':{'docs/README.md':'# Home'}}))
        env=dict(os.environ,BOOK_DATA_DIR=str(data),BOOK_TEST_GITHUB_FIXTURE=str(fixture),BOOK_GITHUB_KEY='ab'*32,BOOK_SECURE_COOKIE='0',BOOK_ADMIN_HASH=subprocess.check_output(['php','-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',PASSWORD],text=True),PHP_CLI_SERVER_WORKERS='4')
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        base=f'http://127.0.0.1:{port}'
        with (tmp/'server.log').open('w+') as log:
            proc=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','tests/github-http-router.php'],cwd=ROOT,env=env,stdout=log,stderr=log,start_new_session=True)
            try:
                for _ in range(100):
                    try:Client(base).request('/health/live',200);break
                    except OSError:time.sleep(.05)
                else:raise RuntimeError('PHP server failed to start')
                run(base,data,fixture)
            except BaseException:
                log.seek(0);print(log.read()[-5000:],file=sys.stderr);raise
            finally:os.killpg(proc.pid,signal.SIGTERM);proc.wait(timeout=10)
