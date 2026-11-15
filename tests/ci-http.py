#!/usr/bin/env python3
"""Real HTTP integration; --policy-only uses prevalidated plain Markdown bundle fixtures."""
import base64, importlib.util, json, os, re, socket, subprocess, sys, tempfile, time, urllib.error, urllib.request, zipfile, hashlib, uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('workspace_http',ROOT/'tests/workspaces-http.py'); w=importlib.util.module_from_spec(spec); spec.loader.exec_module(w)
Client,post,check,PASSWORD=w.Client,w.post,w.check,w.PASSWORD
FULL='--policy-only' not in sys.argv
POSTGRES='--postgres' in sys.argv

def exercise(base,data,env):
    owner,admin,editor,viewer,outsider,guest=[Client(base) for _ in range(6)];owner.login()
    for name,c in [('manager',admin),('writer',editor),('reader',viewer),('outsider',outsider)]:
        post(owner,'/account/users','create',username=name,name=name,email=name+'@example.org',password='password-'+name+'-123');c.login(name,'password-'+name+'-123')
    org=post(owner,'/organizations','create',name='CI Team',slug='ci-team')[0]['Location'];oid=org.split('/')[-1]
    def identity():
        if POSTGRES:
            return json.loads(subprocess.check_output(['php','-r','require "bootstrap.php"; echo json_encode((new CajeerBook\\IdentityStore(getenv("BOOK_DATA_DIR")))->backup(),JSON_THROW_ON_ERROR);'],cwd=ROOT,env=env,text=True))
        return json.loads((data/'identity/identity.json').read_text())
    def rev():return str(identity()['organizations'][oid]['revision'])
    def uid(name):return next(u['id'] for u in identity()['users'].values() if u['username']==name)
    for name,c in [('manager',admin),('writer',editor),('reader',viewer)]:
        _,h=post(owner,org,'invite',200,revision=rev(),username=name);post(c,'/organizations/join','accept',token=re.search(rb'/organizations/join#([a-f0-9]{64})',h).group(1).decode())
    for name,role in [('manager','admin'),('writer','editor')]:post(owner,org,'role',revision=rev(),user=uid(name),role=role)
    for name in ['Release','Other']:post(owner,'/workspaces','create',organization=oid,name=name,slug=name.lower())
    spaces={ws['name']:ws for ws in identity()['workspaces'].values()};wid=spaces['Release']['id'];prefix='/w/'+oid+'/'+wid;other='/w/'+oid+'/'+spaces['Other']['id'];route=prefix+'/studio/ci';endpoint=prefix+'/api/v1/ci';scope='guide/1';store=data/'workspaces'/oid/wid
    def db():
        if POSTGRES:return json.loads(php('echo json_encode((new CajeerBook\\BookStore($argv[1],false))->read(), JSON_THROW_ON_ERROR);'))
        return json.loads((store/'books.json').read_text())
    def api(payload,status=200,token='',path=endpoint,content_type='application/json',client=guest):
        req=urllib.request.Request(base+path,data=json.dumps(payload).encode(),headers={'Content-Type':content_type,**({'Authorization':'Bearer '+token} if token else {})})
        try:r=client.opener.open(req,timeout=20)
        except urllib.error.HTTPError as exc:r=exc
        with r:
            body=r.read();assert r.status==status,(payload.get('action'),status,r.status,body[:500]);return json.loads(body)
    def issue(client,scope,publish):
        _,h=post(client,route,'issue',200,scope=scope,label='pipeline',days='7',publish='1' if publish else '0');return re.search(rb'cbci_[a-f0-9]{32}\.[a-f0-9]{64}',h).group(0).decode()
    def php(code,*args):return subprocess.check_output(['php','-r','require "bootstrap.php";'+code,str(store),*args],cwd=ROOT,env=env,text=True)
    def make_build(token,request):
        state=api({'action':'state'},token=token)
        if FULL:return api({'action':'build','request':request,'expected':state['fingerprint']},token=token)
        # The fixture bypasses only the unavailable CommonMark build stage. Production has no such option.
        code='''$s=new CajeerBook\\BookStore($argv[1],false);$b=null;$s->change(function(&$d)use($argv,&$b){$t=$d['ci']['tokens'][$argv[2]];$p=CajeerBook\\CiPublishing::state($d,$t['scope']);$m=new ReflectionMethod(CajeerBook\\CiPublishing::class,'capture');$b=$m->invokeArgs(null,[&$d,$t['actor'],$t['scope'],$argv[3],'',$p,array_fill_keys(array_keys($p),[])]);});echo json_encode(CajeerBook\\CiPublishing::summary($b));'''
        return json.loads(php(code,token[5:].split('.')[0],request))
    owner.request(route,200);admin.request(route,200);editor.request(route,403);viewer.request(route,403);outsider.request(route,404);guest.request(route,401)
    owner.request(route,403,{'action':'issue','csrf':'bad'});post(editor,route,'issue',403,scope=scope,label='x',days='1');post(owner,route,'issue',422,scope='../bad',label='x',days='1')
    token=issue(owner,scope,True);read_token=issue(owner,scope,False);foreign_token=issue(owner,'other/1',True)
    assert token.encode() not in owner.request(route,200)[1] and token not in json.dumps(db()) and db()['schema']==6
    api({'action':'state'},401);api({'action':'state'},401,client=owner);api({'action':'state'},403,token=token[:-1]+('0' if token[-1]!='0' else '1'));api({'action':'state'},403,token=token,path=other+'/api/v1/ci');api({'action':'state'},415,token=token,content_type='text/plain');guest.request(endpoint,405)
    check('Studio RBAC, one-time token, API bearer-only auth, CSRF and cross-workspace isolation')
    # Real CLI sends a bearer request over the loopback test transport.
    cli_env=dict(env,BOOK_CI_URL=base+endpoint,BOOK_CI_TOKEN=token,BOOK_CI_ALLOW_HTTP_LOCAL='1')
    state=json.loads(subprocess.check_output([sys.executable,'bin/ci.py','state'],cwd=ROOT,env=cli_env,text=True));assert state['scope']==scope
    api({'action':'build','request':'wrong','expected':'0'*64},409,token=token);api({'action':'import','expected':state['fingerprint'],'files':{'../evil.md':'eA=='}},422,token=token)
    if FULL:
        docs=data.parent/'ci-source';docs.mkdir();(docs/'README.md').write_text('# Home\n\n[Next](next.md)\n\n[File](file.txt)\n');(docs/'next.md').write_text('# Next');(docs/'file.txt').write_text('CI attachment')
        imported=json.loads(subprocess.check_output([sys.executable,'bin/ci.py','import',str(docs),'--expected',state['fingerprint']],cwd=ROOT,env=cli_env,text=True));assert len(imported['rows'])==2 and imported['media_new']==1
        assert not json.loads(viewer.request(prefix+'/api/v1/pages',200)[1])
        (docs/'next.md').write_text('# New text');api({'action':'import','expected':imported['fingerprint'],'files':{p.name:base64.b64encode(p.read_bytes()).decode() for p in docs.iterdir()}},409,token=token)
    else:
        php('''$s=new CajeerBook\\BookStore($argv[1],false);$s->change(function(&$d){foreach(['index','next'] as $name){$p=['title'=>ucfirst($name),'section'=>'Guide','order'=>10,'body'=>'# '.ucfirst($name),'updated'=>gmdate('c'),'media'=>[]];$d['pages']['guide/1/'.$name]=['id'=>bin2hex(random_bytes(16)),'revision'=>1,'draft'=>$p,'published'=>null];}});''')
    check('CLI state, optimistic input fingerprint, safe upload path'+(' and real Markdown/media import' if FULL else ' (snapshot fixture)'))
    post(owner,prefix+'/studio/reviews','policy',revision='1',required='1');b=make_build(token,'release-1');bid=b['id'];digest=b['digest']
    api({'action':'status','id':bid},403,token=foreign_token);api({'action':'publish','id':bid,'digest':digest},409,token=token);api({'action':'publish','id':bid,'digest':digest},403,token=read_token);api({'action':'approve','id':bid,'digest':digest},422,token=token)
    assert digest.encode() in admin.request(route+'?id='+bid,200)[1];post(owner,route,'approve',403,id=bid,digest=digest);post(admin,route,'approve',200,id=bid,digest=digest,note='Checked release')
    result=api({'action':'publish','id':bid,'digest':digest},token=token);assert result['status']=='published' and len(json.loads(viewer.request(prefix+'/api/v1/pages',200)[1]))==2
    before=db()['pages'];assert api({'action':'publish','id':bid,'digest':digest},token=token)['status']=='published' and db()['pages']==before;outsider.request(prefix+'/api/v1/pages',404);guest.request(prefix+'/api/v1/pages',401)
    check('whole-version approval, independent reviewer, build-only token, idempotent atomic deployment and private Reader')
    if FULL:
        export_payload={'action':'export','id':bid,'digest':digest}
        api(export_payload,403,token=token) # private workspace
        post(owner,'/workspaces','visibility',id=wid,revision=str(identity()['workspaces'][wid]['revision']),visibility='public')
        api(export_payload,401);api(export_payload,403,token=read_token);api(export_payload,403,token=foreign_token);api(export_payload,403,token=token,path=other+'/api/v1/ci')
        api({**export_payload,'digest':'0'*64},409,token=token)
        output=data.parent/'release.zip'
        cmd=[sys.executable,'bin/ci.py','export',bid,digest,str(output)]
        result=json.loads(subprocess.check_output(cmd,cwd=ROOT,env=cli_env,text=True))
        assert hashlib.sha256(output.read_bytes()).hexdigest()==result['sha256'] and result['release']['build']==bid
        with zipfile.ZipFile(output) as archive:
            assert archive.testzip() is None and json.loads(archive.read('release.json'))['digest']==digest
            assert b'Home' in archive.read('assets/search-index.js') and 'books.json' not in archive.namelist()
            assert any(archive.read(n)==b'CI attachment' for n in archive.namelist() if n.startswith('media/'))
            site=data.parent/'release-site';archive.extractall(site)
        assert subprocess.run(cmd,cwd=ROOT,env=cli_env,capture_output=True).returncode==1
        if '--browser' in sys.argv:
            from playwright.sync_api import sync_playwright, expect
            with sync_playwright() as p:
                browser=p.chromium.launch();page=browser.new_page();page.context.set_offline(True)
                page.goto((site/'index.html').as_uri());page.locator('form.search input').fill('Next');page.locator('form.search button').click()
                expect(page.locator('[data-search-results] a').first).to_be_visible();page.locator('[data-search-results] a').first.click();expect(page.locator('main article')).to_be_visible();browser.close()
        post(owner,prefix+'/studio/books','visibility',slug='guide',visibility='private',revision='1')
        api(export_payload,409,token=token)
        post(owner,prefix+'/studio/books','visibility',slug='guide',visibility='inherit',revision='2')
        post(owner,'/workspaces','visibility',id=wid,revision=str(identity()['workspaces'][wid]['revision']),visibility='private')
        check('CI release artifact: scope, bearer permissions, visibility, real ZIP, checksum, media and atomic client')

    if FULL:
        b2=make_build(token,'release-2');post(admin,route,'approve',200,id=b2['id'],digest=b2['digest']);post(editor,prefix+'/studio','save',book='guide',version='1',path='next',title='Changed',section='Guide',body='# Changed',order='10',revision=str(db()['pages']['guide/1/next']['revision']))
    else:
        b2=make_build(token,'release-2');post(admin,route,'approve',200,id=b2['id'],digest=b2['digest']);php('''$s=new CajeerBook\\BookStore($argv[1],false);$s->change(function(&$d){$d['pages']['guide/1/next']['draft']['body']='# Changed';$d['pages']['guide/1/next']['revision']++;});''')
    assert api({'action':'status','id':b2['id']},token=token)['status']=='stale';api({'action':'publish','id':b2['id'],'digest':b2['digest']},409,token=token)
    post(owner,route,'rollback',200,id=bid,digest=digest,expected=api({'action':'state'},token=token)['fingerprint']);assert db()['pages']['guide/1/next']['draft']['body']==before['guide/1/next']['draft']['body']
    b3=make_build(token,'release-3');post(admin,route,'approve',200,id=b3['id'],digest=b3['digest']);post(owner,org,'role',revision=rev(),user=uid('manager'),role='editor');api({'action':'publish','id':b3['id'],'digest':b3['digest']},409,token=token);admin.request(route,403)
    check('stale bundle rejection, rollback to drafts, reviewer role revocation')
    # Token issuer access is checked on every request, independent of browser session.
    post(owner,org,'role',revision=rev(),user=uid('manager'),role='admin');admin_token=issue(admin,scope,True);assert api({'action':'state'},token=admin_token)['scope']==scope
    post(owner,org,'role',revision=rev(),user=uid('manager'),role='editor');api({'action':'state'},403,token=admin_token)
    post(owner,route,'revoke',200,id=token[5:].split('.')[0]);api({'action':'state'},403,token=token)
    check('immediate credential revocation and issuer role check')

if __name__=='__main__':
    with tempfile.TemporaryDirectory(prefix='book-ci-http-') as tmp:
        tmp=Path(tmp);data=tmp/'data';env=dict(os.environ,BOOK_DATA_DIR=str(data),BOOK_SECURE_COOKIE='0',BOOK_ADMIN_HASH=subprocess.check_output(['php','-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',PASSWORD],text=True))
        if POSTGRES:
            if not env.get('BOOK_PG_DSN'):raise RuntimeError('BOOK_PG_DSN required for PostgreSQL HTTP test')
            env['BOOK_PG_NAMESPACE']=uuid.uuid4().hex
            subprocess.run(['php','bin/postgres-content.php','migrate','--offline'],cwd=ROOT,env=env,check=True)
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        with (tmp/'server.log').open('w+') as log:
            server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','public/router.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
            try:
                base=f'http://127.0.0.1:{port}'
                for _ in range(60):
                    try:Client(base).request('/health/live',200);break
                    except OSError:time.sleep(.1)
                exercise(base,data,env)
            except Exception:log.seek(0);print(log.read());raise
            finally:
                server.terminate();server.wait(timeout=5)
                if POSTGRES:
                    subprocess.run(['php','-r','require "bootstrap.php";$p=CajeerBook\\PostgresContentStore::connect();$q=$p->prepare("DELETE FROM cajeer_book.content_installation WHERE installation=?");$q->execute([getenv("BOOK_PG_NAMESPACE")]);'],cwd=ROOT,env=env,check=True)
