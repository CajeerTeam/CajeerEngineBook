<?php
declare(strict_types=1);
$root=dirname(__DIR__);spl_autoload_register(static function($class)use($root){foreach(['CajeerEngine\\'=>'core/','CajeerBook\\'=>'src/'] as $prefix=>$dir)if(str_starts_with($class,$prefix)){$file=$root.'/'.$dir.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($file))require_once $file;return;}});
use CajeerBook\{IdentityStore,PostgresIdentityStore};
use CajeerEngine\Update\OperationFiles as Files;
function ok(bool $v,string $s):void{if(!$v)throw new RuntimeException($s);echo "PASS $s\n";}
function deny(callable $f,string $s):void{try{$f();}catch(RuntimeException|DomainException|InvalidArgumentException){ok(true,$s);return;}throw new RuntimeException('Accepted: '.$s);}
function clean(string $p):void{if(!is_dir($p))return;foreach(new DirectoryIterator($p) as $f){if($f->isDot())continue;if($f->isDir()&&!$f->isLink())clean($f->getPathname());else unlink($f->getPathname());}rmdir($p);}
$dir=sys_get_temp_dir().'/identity-policy-'.bin2hex(random_bytes(8));mkdir($dir,0700);putenv('BOOK_DATA_DIR='.$dir);putenv('BOOK_PG_DSN');putenv('BOOK_PG_NAMESPACE');
try{
    $s=new IdentityStore($dir);$owner=$s->initialize('owner','Owner','owner@example.org','owner-password-123');$t=$s->login('owner','owner-password-123','192.0.2.1')['token'];
    $a=$s->createUser($t,'writer','Writer','writer@example.org','writer-password-123');$b=$s->createUser($t,'reader','Reader','reader@example.org','reader-password-123');
    $at=$s->login('writer','writer-password-123','192.0.2.2')['token'];$bt=$s->login('reader','reader-password-123','192.0.2.3')['token'];
    $o=$s->createOrganization($t,'Team','team');$oid=$o['id'];
    foreach([['writer',$at],['reader',$bt]] as [$name,$token]){$i=$s->mutateOrganization($t,$oid,$s->organization($t,$oid)['revision'],'invite',['username'=>$name]);$s->acceptInvitation($i['token'],$token,'192.0.2.2');}
    // Role insertion order intentionally differs from member order.
    foreach([[$b['id'],'editor'],[$a['id'],'admin']] as [$id,$role])$s->mutateOrganization($t,$oid,$s->organization($t,$oid)['revision'],'role',['user'=>$id,'role'=>$role]);
    $w=$s->createWorkspace($t,$oid,'Private','private');$s->assignWorkspaceRole($t,$w['id'],1,$b['id'],'none');$s->assignWorkspaceRole($t,$w['id'],2,$a['id'],'viewer');
    $reset=$s->issueReset('reader');$invite=$s->mutateOrganization($t,$oid,$s->organization($t,$oid)['revision'],'invite',['username'=>'newuser'])['token'];
    $before=(new IdentityStore($dir,true))->migrationSnapshot(fn($d)=>$d);
    $split=new ReflectionMethod(PostgresIdentityStore::class,'split');$assemble=new ReflectionMethod(PostgresIdentityStore::class,'assemble');
    [$meta,$rows]=$split->invoke(null,$before);
    ok($assemble->invoke(null,$meta,$rows)===$before,'identity SQL encoding round-trips exact keys, order, roles and credentials');
    ok(count($rows['users'])===3&&count($rows['memberships'])===3&&count($rows['workspace_roles'])===2&&$rows['organizations'][$oid]['members']===[]&&$rows['workspaces'][$w['id']]['roles']===[],'membership and workspace assignments are independent SQL records');
    ok(isset($rows['sessions'][hash('sha256',$t)],$rows['resets'][hash('sha256',$reset)],$rows['invitations'][hash('sha256',$invite)])&&!str_contains(json_encode($rows),$t),'credential digests, not bearer tokens, are preserved');
    $bad=$rows;$bad['organizations'][$oid]['members'][$a['id']]=time();deny(fn()=>$assemble->invoke(null,$meta,$bad),'reject duplicate membership source');
    $bad=$rows;$bad['workspace_roles'][$w['id'].':'.$a['id']]['organization']=str_repeat('f',32);deny(fn()=>$assemble->invoke(null,$meta,$bad),'reject mismatched workspace role scope');
    $optional=$before;$optional['activity']=null;$optional['organizations'][$oid]['roles']=null;$optional['workspaces'][$w['id']]['roles']=null;[$m,$r]=$split->invoke(null,$optional);ok($assemble->invoke(null,$m,$r)===$optional,'optional legacy null fields preserved');
    $file=$dir.'/identity/identity.json';$bytes=file_get_contents($file);$old=$before;$old['schema']=3;foreach($old['workspaces'] as &$ws)unset($ws['visibility']);unset($ws);Files::json($file,$old);
    $upgraded=(new IdentityStore($dir,true))->migrationSnapshot(fn($d)=>$d);ok($upgraded['schema']===4&&$upgraded['workspaces'][$w['id']]['visibility']==='private','legacy identity schema is upgraded before migration');
    Files::write($file,$bytes);
    $ns=str_repeat('a',32);putenv('BOOK_PG_NAMESPACE='.$ns);putenv('BOOK_PG_DSN=pgsql:host=127.0.0.1;port=1;dbname=book;connect_timeout=1');
    deny(fn()=>$s->initialized(),'configured SQL refuses legacy identity before migration');
    Files::write($dir.'/postgres.required',$ns.':'.str_repeat('b',32)."\n");deny(fn()=>$s->current($t),'0.4.1 marker alone does not authorize file fallback');
    Files::write($dir.'/postgres.identity.required',$ns.':'.str_repeat('b',32)."\n");deny(fn()=>$s->current($t),'SQL outage does not fall back to valid file session');
    putenv('BOOK_PG_DSN');deny(fn()=>$s->initialized(),'cutover marker blocks identity file fallback');
    ok(file_get_contents($file)===$bytes,'failed SQL access leaves migration source unchanged');
    unlink($dir.'/postgres.required');deny(fn()=>$s->initialized(),'identity marker independently blocks stale file fallback');unlink($dir.'/postgres.identity.required');Files::write($dir.'/postgres.migrating',$ns.':'.str_repeat('b',32)."\n");deny(fn()=>$s->initialized(),'pending migration blocks identity fallback');
}finally{putenv('BOOK_PG_DSN');clean($dir);}
