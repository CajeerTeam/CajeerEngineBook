#!/usr/bin/env python3
"""Real App routes with two PHP processes; no public DNS or ACME account required."""
import http.client, json, os, re, socket, subprocess, sys, tempfile, time, uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
EDGE='a'*64
MAIN='book.example.com'; A='docs.example.net'; B='docs.example.org'; ALIAS='alias.example.net'
PG='--postgres' in sys.argv; FULL='--full' in sys.argv

def check(label): print('PASS '+label,flush=True)

def req(port,host,path,status,method='GET',body=None,headers=None):
    c=http.client.HTTPConnection('127.0.0.1',port,timeout=10)
    h={'Host':host,'X-Book-Edge-Proto':'https','X-Book-Edge-Secret':EDGE};h.update(headers or {})
    if body is not None:
        from urllib.parse import urlencode
        body=urlencode(body);h['Content-Type']='application/x-www-form-urlencoded'
    c.request(method,path,body,headers=h);r=c.getresponse();data=r.read();response=dict(r.getheaders());c.close()
    assert r.status==status,(host,path,r.status,status,data[:400]);return response,data

SEED=r'''
require 'tests/oidc-bootstrap.php';
use CajeerBook\{IdentityStore,BookStore,Workspaces,CustomDomains,MediaStore};
$root=getenv('BOOK_DATA_DIR');$s=new IdentityStore($root);$s->initialize('owner','Owner','owner@example.com','password-owner-123');$auth=$s->login('owner','password-owner-123','192.0.2.1');$token=$auth['token'];$csrf=$auth['session']['csrf'];
$org=$s->createOrganization($token,'Organization','organization');$ids=[];
foreach(['docs.example.net'=>'Alpha','docs.example.org'=>'Beta'] as $host=>$title){
 $w=$s->createWorkspace($token,$org['id'],$title,strtolower($title));$id=$w['id'];$ids[]=$id;$s->mutateWorkspace($token,$id,1,'visibility','public','');
 $store=new BookStore(Workspaces::directory($root,$w),false);$store->createBook('guide',$title);$store->createBook('secret','Private '.$title);$store->setBookVisibility('secret','private',1);
 $file=$root.'/upload.txt';file_put_contents($file,'Public media '.$title);$media=new MediaStore(Workspaces::directory($root,$w),$store);$m=$media->upload($file,'sample.txt','')['item'];unlink($file);$mediaIds[]=$m['id'];
 $store->change(function(&$d)use($title,$m){foreach(['guide/1/start'=>$title,'secret/1/start'=>'Private '.$title,'guide/1/draft'=>'Draft '.$title] as $key=>$text){$p=['title'=>$text,'section'=>'Guide','order'=>10,'body'=>$text.' searchable text'.(str_starts_with($key,'guide/1/start')?' [File](/media/'.$m['id'].')':''),'updated'=>gmdate('c'),'media'=>str_starts_with($key,'guide/1/start')?[$m['id']]:[]];$d['pages'][$key]=['id'=>bin2hex(random_bytes(16)),'revision'=>1,'draft'=>$p,'published'=>str_ends_with($key,'draft')?null:$p];if(!str_ends_with($key,'draft'))$d['pages'][$key]['published_key']=$key;}},'save');
 $s->mutateDomain($token,$csrf,$id,$host,'add',2);$r=$s->backup()['workspaces'][$id]['domains'][$host];$s->verifyDomain($token,$csrf,$id,$host,3,fn($n)=>[['type'=>'TXT','host'=>$n,'txt'=>CustomDomains::challenge($r)]]);
}
$id=$ids[0];$s->mutateDomain($token,$csrf,$id,'alias.example.net','add',4);$r=$s->backup()['workspaces'][$id]['domains']['alias.example.net'];$s->verifyDomain($token,$csrf,$id,'alias.example.net',5,fn($n)=>[['type'=>'TXT','host'=>$n,'txt'=>CustomDomains::challenge($r)]]);
echo json_encode(['ids'=>$ids,'org'=>$org['id'],'token'=>$token,'csrf'=>$csrf,'media'=>$mediaIds],JSON_THROW_ON_ERROR);
'''

def exercise(ports,state,env):
    p,q=ports;wid,other=state['ids'];org=state['org'];auth={'Cookie':'__Host-book_session='+state['token']};scope='/w/'+org+'/'+wid
    # No account cookie can change the anonymous host policy.
    for port,host,title in [(p,A,'Alpha'),(q,B,'Beta'),(q,ALIAS,'Alpha')]:
        _,data=req(port,host,'/api/v1/pages',200,headers=auth);pages=json.loads(data);assert list(pages)==['guide/1/start'] and pages['guide/1/start']['title']==title
        req(port,host,'/api/v1/pages/secret/1/start',404,headers=auth);req(port,host,'/api/v1/pages/guide/1/draft',404)
        req(port,host,scope+'/api/v1/pages',404,headers=auth);req(port,host,'/studio',404,headers=auth);req(port,host,'/login',404,headers=auth);req(port,host,'/api/v1/ci',405,'POST',{},auth);req(port,host,'/domains',404,headers=auth)
    check('two PHP nodes, identical paths isolated by host, cookie cannot expose private or draft content')
    for host in ['unknown.example.net','docs.example.net.evil.net','docs.example.net:8080','docs.example.net.']:
        req(p,host,'/api/v1/pages',421,headers={'X-Forwarded-Host':A})
    req(q,MAIN,'/health/live',200);req(q,A,'/api/v1/pages',200,headers={'X-Forwarded-Host':B})
    check('unknown Host fails closed; forwarded host ignored')
    _,sitemap=req(p,A,'/sitemap.xml',200);assert b'https://docs.example.net/docs/guide/1/start' in sitemap and b'secret' not in sitemap and b'draft' not in sitemap
    h,_=req(p,ALIAS,'/sitemap.xml',302);assert h['Location']=='https://'+A+'/sitemap.xml'
    _,robots=req(q,A,'/robots.txt',200);assert b'https://docs.example.net/sitemap.xml' in robots
    _,media=req(p,A,'/media/'+state['media'][0],200);assert media==b'Public media Alpha'
    req(q,B,'/media/'+state['media'][0],404)
    check('sitemap, canonical alias and media isolation')
    if FULL:
        _,html=req(q,ALIAS,'/docs/guide/1/start',200);assert b'rel="canonical" href="https://docs.example.net/docs/guide/1/start"' in html and b'href="/login"' not in html
        _,hits=req(p,A,'/api/v1/search?q=Alpha',200);assert [i['key'] for i in json.loads(hits)]==['guide/1/start']
        _,hits=req(q,B,'/api/v1/search?q=Alpha',200);assert json.loads(hits)==[]
        check('real Markdown canonical and tenant-filtered search')
        from zipfile import ZipFile
        archive=Path(env['BOOK_DATA_DIR']).parent/'site.zip'
        subprocess.run(['php','bin/static-export.php',str(archive),'--workspace='+wid],cwd=ROOT,env=env,check=True)
        with ZipFile(archive) as z: assert json.loads(z.read('source.json'))['source_origin']=='https://'+A
        check('public static ZIP records source domain without changing offline links')
    # Internal CA permission requests only (local test simulates gateway source address).
    req(p,'book','/.well-known/book-domain-ask?domain='+A,204);req(q,'book','/.well-known/book-domain-ask?domain=unknown.example.net',403)
    req(p,A,'/.well-known/book-domain-ask?domain='+A,404)
    req(p,A,'/.well-known/book-domain-host',204);req(p,'unknown.example.net','/.well-known/book-domain-host',421)
    h,_=req(p,A,'/api/v1/pages?x=1',308,headers={'X-Book-Edge-Proto':'http'});assert h['Location']=='https://'+A+'/api/v1/pages?x=1'
    req(p,MAIN,'/login',403,'POST',{}, {'X-Book-Edge-Proto':'http'})
    req(p,A,'/api/v1/pages',308,headers={'X-Book-Edge-Secret':'wrong'})
    check('certificate permission gate and HTTPS enforcement')
    # Management forms on primary host, with real session and CSRF.
    _,html=req(p,MAIN,'/domains?id='+wid,200,headers=auth);assert b'_cajeer-book.docs.example.net' in html and b'Custom Domains' in html
    rev=re.search(rb'name="revision" value="(\d+)"',html).group(1).decode()
    form={'id':wid,'host':A,'action':'disable','revision':rev}
    req(p,MAIN,'/domains',403,'POST',form,auth)
    form['csrf']=state['csrf'];req(p,MAIN,'/domains',303,'POST',form,auth)
    req(q,A,'/api/v1/pages',421);req(q,'book','/.well-known/book-domain-ask?domain='+A,403);req(q,B,'/api/v1/pages',200)
    check('CSRF-protected disable invalidates other node and TLS permission immediately')
    # Workspace visibility changes through existing transaction path.
    code=r'''require 'tests/oidc-bootstrap.php';$s=new CajeerBook\IdentityStore(getenv('BOOK_DATA_DIR'));$w=$s->backup()['workspaces'][$argv[2]];$s->mutateWorkspace($argv[1],$argv[2],$w['revision'],'visibility','private','');'''
    subprocess.run(['php','-r',code,state['token'],wid],cwd=ROOT,env=env,check=True)
    req(p,ALIAS,'/api/v1/pages',421);req(q,'book','/.well-known/book-domain-ask?domain='+ALIAS,403);req(p,B,'/api/v1/pages',200)
    check('private workspace immediately suspends aliases without affecting other tenant')

def main():
    with tempfile.TemporaryDirectory(prefix='book-domains-http-') as tmp:
        env=dict(os.environ,BOOK_DATA_DIR=tmp+'/data',BOOK_PRIMARY_HOST=MAIN,BOOK_DOMAIN_EDGE_SECRET=EDGE,BOOK_DOMAIN_ASK_IP='127.0.0.1',BOOK_SECURE_COOKIE='0')
        for k in ['BOOK_REDIS_HOST','BOOK_REDIS_URL','BOOK_OIDC_CONFIG','BOOK_OIDC_REQUIRED','BOOK_ADMIN_HASH']:env.pop(k,None)
        if PG:
            assert env.get('BOOK_PG_DSN') and FULL;env['BOOK_PG_NAMESPACE']=uuid.uuid4().hex
            subprocess.run(['php','bin/postgres-content.php','migrate','--offline'],cwd=ROOT,env=env,check=True)
        else:
            for k in ['BOOK_PG_DSN','BOOK_PG_NAMESPACE']:env.pop(k,None)
        state=json.loads(subprocess.check_output(['php','-r',SEED],cwd=ROOT,env=env))
        ports=[];servers=[];logs=[]
        try:
            for _ in range(2):
                with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
                ports.append(port);log=open(tmp+f'/server-{port}.log','w+');logs.append(log)
                server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','public/router.php' if FULL else 'tests/oidc-bootstrap.php'],cwd=ROOT,env=env,stdout=log,stderr=log);servers.append(server)
                for _ in range(60):
                    try:req(port,MAIN,'/health/live',200);break
                    except OSError:time.sleep(.1)
                else:raise RuntimeError('PHP server did not start')
            exercise(ports,state,env)
        except Exception:
            for log in logs:log.seek(0);print(log.read())
            raise
        finally:
            for server in servers:server.terminate();server.wait(timeout=5)
            for log in logs:log.close()
            if PG:subprocess.run(['php','-r','require "bootstrap.php";$p=CajeerBook\\PostgresContentStore::connect();$q=$p->prepare("DELETE FROM cajeer_book.content_installation WHERE installation=?");$q->execute([getenv("BOOK_PG_NAMESPACE")]);'],cwd=ROOT,env=env,check=True)
if __name__=='__main__':main()
