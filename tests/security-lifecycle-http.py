#!/usr/bin/env python3
"""Actual Studio rotation and machine authentication on independent PHP processes."""
import hashlib, hmac, http.client, json, os, re, socket, subprocess, tempfile, time, urllib.parse
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]

def check(condition, name):
    if not condition:
        raise AssertionError(name)
    print('PASS '+name, flush=True)

def request(port, path, method='GET', fields=None, cookie=None, headers=None):
    h=dict(headers or {})
    if cookie: h['Cookie']='book_session='+cookie
    body=None
    if fields is not None:
        body=urllib.parse.urlencode(fields);h['Content-Type']='application/x-www-form-urlencoded'
    c=http.client.HTTPConnection('127.0.0.1',port,timeout=10)
    try:
        c.request(method,path,body,h);r=c.getresponse();return r.status,r.read()
    finally:c.close()

def machine(port,path,body,headers):
    c=http.client.HTTPConnection('127.0.0.1',port,timeout=10)
    try:
        c.request('POST',path,body,headers);r=c.getresponse();return r.status,r.read()
    finally:c.close()

def main():
    with tempfile.TemporaryDirectory(prefix='book-lifecycle-http-') as temp:
        env={k:v for k,v in os.environ.items() if not k.startswith('BOOK_')}
        env.update(BOOK_DATA_DIR=temp+'/data',BOOK_SECURE_COOKIE='0',BOOK_AUTOMATION_KEY='ab'*32,BOOK_WEBHOOK_ORIGINS='https://receiver.example.org')
        setup=r'''require "tests/oidc-bootstrap.php";require "tests/github-fixture.php";
        $root=getenv('BOOK_DATA_DIR');$s=new CajeerBook\IdentityStore($root);$u=$s->initialize('owner','Owner','owner@example.org','password-owner-123');$l=$s->login('owner','password-owner-123','192.0.2.1');$o=$s->createOrganization($l['token'],'Team','team');$w=$s->createWorkspace($l['token'],$o['id'],'Docs','docs');$dir=CajeerBook\Workspaces::directory($root,$w);$store=new CajeerBook\BookStore($dir,false);$store->change(fn(&$d)=>$d['pages']=[]);$f=new GitFixture();$f->set(['docs/README.md'=>'# Docs']);$git=new CajeerBook\GitHubSync($store,new CajeerBook\MarkdownImport($store,new CajeerBook\MediaStore($dir,$store)),fn($t,$c)=>$f->client($t));$git->configure(['repo'=>'team/docs','branch'=>'main','root'=>'docs','book'=>'guide','version'=>'1','push'=>'0','token'=>'']);$store->change(fn(&$d)=>CajeerBook\AutomationQueue::configure($d,$u['id'],'',str_repeat('i',32),'https://receiver.example.org/hook',str_repeat('o',32),['document.changed']));$member=$s->createUser($l['token'],'member','Member','member@example.org','password-member-123');$ml=$s->login('member','password-member-123','192.0.2.2');echo json_encode(['prefix'=>'/w/'.$o['id'].'/'.$w['id'],'session'=>$l['token'],'csrf'=>$l['session']['csrf'],'member'=>$member['id'],'member_session'=>$ml['token']]);'''
        data=json.loads(subprocess.check_output(['php','-r',setup],cwd=ROOT,env=env));prefix=data['prefix'];session=data['session'];csrf=data['csrf'];procs=[];logs=[]
        try:
            ports=[]
            for i in range(2):
                with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
                log=open(Path(temp)/f'node-{i}.log','w+');logs.append(log);procs.append(subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','tests/oidc-bootstrap.php'],cwd=ROOT,env=env,stdout=log,stderr=log));ports.append(port)
                for _ in range(100):
                    try:
                        if request(port,'/login')[0]==200:break
                    except OSError:pass
                    time.sleep(.05)
                else:raise RuntimeError('PHP unavailable')
            a,b=ports;auto=prefix+'/studio/automation';ci=prefix+'/studio/ci';hook=prefix+'/webhooks/github'
            status,html=request(a,auto,cookie=session);check(status==200 and b'Rotate signing credentials' in html,'actual webhook rotation form')
            def field(name,html):return re.search(rb'name="'+name.encode()+rb'" value="([a-f0-9]+)"',html).group(1).decode()
            params={'csrf':csrf,'action':'rotate','expected':field('expected',html),'stamp':field('stamp',html),'kind':'in','secret':'n'*32,'overlap':'60'}
            check(request(a,auto,'POST',params|{'csrf':'wrong'},session)[0]==403,'rotation CSRF enforced')
            check(request(a,auto,'POST',params,session)[0]==200,'webhook signing rotation via Studio')
            def ping(secret):
                body=b'{}';return machine(b,hook,body,{'Content-Type':'application/json','X-GitHub-Event':'ping','X-GitHub-Delivery':'delivery','X-Hub-Signature-256':'sha256='+hmac.new(secret.encode(),body,hashlib.sha256).hexdigest()})[0]
            check(ping('i'*32)==200 and ping('n'*32)==200,'both signing keys accepted on second node')
            check(request(a,auto,'POST',params,session)[0]==409,'stale rotation form rejected')
            _,html=request(a,auto,cookie=session);check(request(a,auto,'POST',{'csrf':csrf,'action':'retire','expected':field('expected',html),'stamp':field('stamp',html)},session)[0]==200 and ping('i'*32)==403 and ping('n'*32)==200,'retire old webhook secret on both nodes')
            status,html=request(a,ci,'POST',{'csrf':csrf,'action':'issue','scope':'guide/1','label':'pipeline','days':'1','publish':'0'},session);old=re.search(rb'cbci_[a-f0-9]{32}\.[a-f0-9]{64}',html).group(0).decode();check(status==200,'actual CI token issuance')
            status,html=request(a,ci,'POST',{'csrf':csrf,'action':'rotate','id':old[5:37],'days':'2','overlap':'0'},session);new=re.search(rb'cbci_[a-f0-9]{32}\.[a-f0-9]{64}',html).group(0).decode()
            def api(token):return machine(b,prefix+'/api/v1/ci',b'{"action":"state"}',{'Content-Type':'application/json','Authorization':'Bearer '+token})[0]
            check(status==200 and api(old)==403 and api(new)==200,'CI rotation revokes old token on second node')
            check(request(a,'/account',cookie=data['member_session'])[0]==200,'member session active before owner revoke')
            status,_=request(a,'/account/users','POST',{'csrf':csrf,'action':'revoke-sessions','id':data['member']},session)
            check(status==303 and request(b,'/account',cookie=data['member_session'])[0]==401,'owner revocation applies on second node')
        except Exception:
            for log in logs:log.seek(0);print(log.read())
            raise
        finally:
            for p in procs:p.terminate();p.wait(timeout=5)
            for log in logs:log.close()
if __name__=='__main__':main()
