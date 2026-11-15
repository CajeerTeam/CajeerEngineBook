<?php
declare(strict_types=1);
require __DIR__.'/oidc-bootstrap.php';
use CajeerBook\{CustomDomains as D,IdentityStore,PostgresContentStore,OrganizationAccessException};
function ok(bool $v,string $label):void{if(!$v)throw new RuntimeException($label);echo "PASS $label\n";}
function deny(callable $fn,string $label):void{try{$fn();}catch(InvalidArgumentException|DomainException $e){ok(true,$label);return;}throw new RuntimeException('Unexpected success: '.$label);}
function clean(string $p):void{if(!is_dir($p))return;foreach(new DirectoryIterator($p) as $f){if($f->isDot())continue;if($f->isDir()&&!$f->isLink())clean($f->getPathname());else unlink($f->getPathname());}rmdir($p);}
$root=sys_get_temp_dir().'/book-domains-'.bin2hex(random_bytes(8));mkdir($root,0700);$pg=in_array('--postgres',$argv,true);$dsn=getenv('BOOK_PG_DSN');$ns=bin2hex(random_bytes(16));
putenv('BOOK_DATA_DIR='.$root);putenv('BOOK_PRIMARY_HOST=book.example.com');putenv('BOOK_PG_DSN');putenv('BOOK_PG_NAMESPACE='.$ns);putenv('BOOK_OIDC_CONFIG');putenv('BOOK_OIDC_REQUIRED');
try{
    ok(D::name('Docs.Example.net')==='docs.example.net'&&D::requestHost('Docs.Example.net:443')==='docs.example.net','canonical hostname and default port');
    foreach(['https://docs.example.net','docs.example.net:444','*.example.net','127.0.0.1','foo.local','a..example.net','a.example.net.','a.example.net@evil.net','a.example.net,evil.net','foo_bar.example.net','a.example.net/','a.123','foo\r\n.example.net'] as $bad)deny(fn()=>D::name($bad),'reject invalid host '.$bad);
    $r=D::record();$host='docs.example.net';$txt=D::challenge($r);$name=D::dnsName($host);
    ok(D::proof($host,$r['token'],fn($n)=>[['host'=>$n,'type'=>'TXT','entries'=>[substr($txt,0,16),substr($txt,16)]]])===true,'exact TXT proof with split DNS strings');
    ok(D::proof($host,$r['token'],fn($n)=>[['host'=>'wrong.example.net','type'=>'TXT','txt'=>$txt]])===false,'foreign TXT owner rejected');
    ok(D::proof($host,$r['token'],fn($n)=>[['host'=>$n,'type'=>'TXT','txt'=>$txt.'x']])===false,'TXT substring rejected');
    ok(D::proof($host,$r['token'],fn($n)=>false)===null,'DNS error distinct from missing proof');
    $s=new IdentityStore($root);$owner=$s->initialize('owner','Owner','owner@example.com','password-owner-123');$a=$s->login('owner','password-owner-123','192.0.2.1');$at=$a['token'];$csrf=$a['session']['csrf'];
    $outsider=$s->createUser($at,'outsider','Outsider','other@example.com','password-other-123');$b=$s->login('outsider','password-other-123','192.0.2.2');$bt=$b['token'];$bc=$b['session']['csrf'];
    $org=$s->createOrganization($at,'Org A','org-a');$other=$s->createOrganization($bt,'Org B','org-b');$w=$s->createWorkspace($at,$org['id'],'Space A','space-a');$x=$s->createWorkspace($bt,$other['id'],'Space B','space-b');$id=$w['id'];$xi=$x['id'];
    if($pg){if(!$dsn)throw new RuntimeException('PostgreSQL required');putenv('BOOK_PG_DSN='.$dsn);PostgresContentStore::migrate($root);}
    $get=fn($wid)=>$s->backup()['workspaces'][$wid];$rev=fn($wid)=>$get($wid)['revision'];
    $mut=fn($action,$h='docs.example.net')=>$s->mutateDomain($at,$csrf,$id,$h,$action,$rev($id));
    $find=fn($h='docs.example.net')=>$s->withDomain($h,fn($w)=>$w['id']??null);
    deny(fn()=>$mut('add'),'private workspace cannot claim domain');
    $s->mutateWorkspace($at,$id,$rev($id),'visibility','public','');$s->mutateWorkspace($bt,$xi,$rev($xi),'visibility','public','');
    deny(fn()=>$s->mutateDomain($bt,$bc,$id,$host,'add',$rev($id)),'cross-tenant management denied');
    deny(fn()=>$s->mutateDomain($at,'wrong',$id,$host,'add',$rev($id)),'CSRF enforced inside transaction');
    deny(fn()=>$mut('add','book.example.com'),'primary host reserved');
    $mut('add');ok($find()===null,'pending domain not routable');
    deny(fn()=>$s->mutateDomain($bt,$bc,$xi,$host,'add',$rev($xi)),'global uniqueness across tenants');
    $lookup=fn($n)=>[['host'=>$n,'type'=>'TXT','txt'=>D::challenge($get($id)['domains'][$host])]];
    ok($s->verifyDomain($at,$csrf,$id,$host,$rev($id),$lookup)&&$find()===$id,'verified domain routes to exact workspace');
    ok($get($id)['primary_domain']===$host,'first verified domain becomes canonical');
    deny(fn()=>$s->verifyDomain($at,$csrf,$id,$host,$rev($id),$lookup),'DNS retry throttled');
    $alias='alias.example.net';$mut('add',$alias);$s->verifyDomain($at,$csrf,$id,$alias,$rev($id),fn($n)=>[['host'=>$n,'type'=>'TXT','txt'=>D::challenge($get($id)['domains'][$alias])]]);ok(D::canonical($get($id),$alias)===$host,'alias uses selected primary canonical');
    $mut('primary',$alias);ok(D::canonical($get($id),$host)===$alias,'canonical selection changes transactionally');
    $s->mutateWorkspace($at,$id,$rev($id),'visibility','private','');ok($find()===null&&$find($alias)===null,'privacy suspends both domains');$s->mutateWorkspace($at,$id,$rev($id),'visibility','public','');
    $s->mutateWorkspace($at,$id,$rev($id),'archive','','');ok($find()===null,'archive suspends domain');$s->mutateWorkspace($at,$id,$rev($id),'restore','','');
    $mut('disable');ok($find()===null,'disable immediately blocks routing');$mut('reset');ok($find()===null,'rotated challenge requires fresh verification');
    $old=$get($id)['domains'][$host]['token'];
    deny(fn()=>$s->verifyDomain($at,$csrf,$id,$host,$rev($id),function($n)use($mut,$old){$mut('reset');return [['host'=>$n,'type'=>'TXT','txt'=>'cajeer-book='.$old]];}),'concurrent challenge rotation rejects stale DNS result');
    ok($find()===null&&$get($id)['domains'][$host]['token']!==$old,'stale result did not activate new binding');
    $s->verifyDomain($at,$csrf,$id,$host,$rev($id),$lookup);
    $age=new ReflectionMethod(IdentityStore::class,'transaction');$age->invoke($s,function(&$db)use($id,$host){$db['workspaces'][$id]['domains'][$host]['checked']=time()-D::RECHECK-1;});
    $result=$s->recheckDomains(fn($n)=>[]);ok($result['failed']===1&&$find()===null,'periodic missing TXT revokes routing');
    $age->invoke($s,function(&$db)use($id,$alias){$db['workspaces'][$id]['domains'][$alias]['verified']=time()-D::LEASE-1;});ok($find($alias)===null,'expired proof fails closed');
    $backup=$s->backup();$copy=$backup;D::resetRestored($copy);ok($copy['workspaces'][$id]['domains'][$alias]['verified']===0&&$copy['workspaces'][$id]['domains'][$alias]['token']!==$backup['workspaces'][$id]['domains'][$alias]['token'],'restore invalidates proof and rotates token');
    $mut('remove');$s->mutateDomain($bt,$bc,$xi,$host,'add',$rev($xi));ok($find()===null&&$get($xi)['domains'][$host]['token']!==$old,'transfer requires a new tenant-specific proof');
    ok(!D::readerPath('/studio')&&!D::readerPath('/w/'.$org['id'].'/'.$id.'/')&&!D::readerPath('/api/v1/ci')&&D::readerPath('/l/ru/docs/guide/1/start'),'custom reader route allowlist');
    if($pg){$pdo=PostgresContentStore::connect();$q=$pdo->prepare('SELECT value::text FROM cajeer_book.identity_workspaces WHERE installation=? AND record_key=?');$q->execute([$ns,$xi]);ok(str_contains((string)$q->fetchColumn(),$host),'domain registry persisted in actual SQL workspace row');}
}finally{if($pg&&$dsn&&getenv('BOOK_PG_DSN')){$pdo=PostgresContentStore::connect();$q=$pdo->prepare('DELETE FROM cajeer_book.content_installation WHERE installation=?');$q->execute([$ns]);}clean($root);}
