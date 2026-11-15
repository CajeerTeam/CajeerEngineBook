<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{AuditLog as A,AuditArchive,IdentityStore,BookStore,PostgresContentStore,Workspaces,ActivityJournal};
function ok(bool $v,string $s):void{if(!$v)throw new RuntimeException($s);echo "PASS $s\n";}
function deny(callable $f,string $s):void{try{$f();}catch(Throwable){ok(true,$s);return;}throw new RuntimeException('Accepted: '.$s);}
function clean(string $p):void{if(!is_dir($p))return;foreach(new DirectoryIterator($p) as $f){if($f->isDot())continue;if($f->isDir()&&!$f->isLink())clean($f->getPathname());else unlink($f->getPathname());}rmdir($p);}
$dsn=getenv('BOOK_PG_DSN');if(!$dsn)throw new RuntimeException('Real PostgreSQL required.');$base=sys_get_temp_dir().'/book-audit-'.bin2hex(random_bytes(8));mkdir($base,0700);$root=$base.'/data';$ns=bin2hex(random_bytes(16));putenv('BOOK_PG_DSN');putenv('BOOK_DATA_DIR='.$root);putenv('BOOK_PG_NAMESPACE='.$ns);
try{
 $s=new IdentityStore($root);$owner=$s->initialize('owner','Owner','owner@example.org','owner-password-123');$ot=$s->login('owner','owner-password-123','192.0.2.1')['token'];$u=$s->createUser($ot,'manager','Manager','manager@example.org','manager-password-123');$mt=$s->login('manager','manager-password-123','192.0.2.2')['token'];$other=$s->createUser($ot,'other','Other','other@example.org','other-password-123');$xt=$s->login('other','other-password-123','192.0.2.3')['token'];
 $o=$s->createOrganization($ot,'Team','team');$oid=$o['id'];$invite=$s->mutateOrganization($ot,$oid,1,'invite',['username'=>'manager'])['token'];$s->acceptInvitation($invite,$mt,'192.0.2.2');$s->mutateOrganization($ot,$oid,3,'role',['user'=>$u['id'],'role'=>'admin']);$w=$s->createWorkspace($ot,$oid,'One','one');$wid=$w['id'];$scope=$oid.'/'.$wid;
 $ox=$s->createOrganization($xt,'Other','other');$wx=$s->createWorkspace($xt,$ox['id'],'Other','other');
 putenv('BOOK_PG_DSN='.$dsn);PostgresContentStore::migrate($root);[$pdo,$id]=A::connection($root);ok($id===$ns&&A::verify($pdo,$id)['identity']['head']===1,'new SQL migration initializes audit streams');
 A::migrate($root);ok(A::verify($pdo,$id)['identity']['head']===1,'audit migration idempotent');
 // Old event precedes all current writes in this stream; archive cutoff cannot skip newer events.
 $pdo->beginTransaction();$old=A::event('page.save',$owner,str_repeat('a',32));$old['at']=gmdate('c',time()-3*86400);A::append($pdo,$id,$scope,[$old]);$pdo->commit();
 $store=new BookStore(Workspaces::directory($root,$w),false);$store->setHistoryActor($owner);$store->createBook('guide','Guide private title');$page=['title'=>'Hello','section'=>'Guide','order'=>10,'body'=>'Secret Markdown body','updated'=>gmdate('c'),'media'=>[]];$key='guide/1/start';$pid=bin2hex(random_bytes(16));$store->change(function(&$d)use($key,$page,$pid){$d['pages'][$key]=['id'=>$pid,'draft'=>$page,'published'=>$page,'published_key'=>$key,'revision'=>1];},'publish');
 $s->login('owner','bad-password','192.0.2.4');$s->profile($ot,'Owner Updated','owner@example.org');
 $foreign=new BookStore(Workspaces::directory($root,$wx),false);$foreign->setHistoryActor($other);$foreign->createBook('other','Other');
 $events=$s->enterpriseAudit($ot,['workspace'=>$wid],50)['events'];ok(count($events)>=3&&in_array('page.publish',array_column(array_column($events,'event'),'action'),true),'real content writes generate durable scoped events');
 ok(!str_contains(json_encode($events),'Secret Markdown')&&!str_contains(json_encode($events),'Guide private title'),'ledger excludes arbitrary content');
 $failed=$s->enterpriseAudit($ot,['action'=>'login.failed'],50)['events'];ok(count($failed)===1&&$failed[0]['event']['outcome']==='denied','failed password attempt recorded');
 ok($s->enterpriseAudit($mt,['workspace'=>$wx['id']])['events']===[]&&$s->enterpriseAudit($xt,['workspace'=>$wid])['events']===[],'cross-tenant filters cannot disclose rows');
 $first=$s->enterpriseAudit($mt,['workspace'=>$wid],1);$second=$s->enterpriseAudit($mt,['workspace'=>$wid,'before'=>$first['next']],1);ok(count($first['events'])===1&&count($second['events'])===1&&$first['events'][0]['cursor']!==$second['events'][0]['cursor'],'keyset pagination does not duplicate events');
 deny(fn()=>$s->enterpriseAudit($mt,[],5000,true,'bad'),'export CSRF enforced');$csrf=$s->current($mt)['session']['csrf'];$s->enterpriseAudit($mt,['workspace'=>$wid],5000,true,$csrf);ok(count($s->enterpriseAudit($ot,['action'=>'audit.export'])['events'])===1,'authorized export itself audited');
 $s->assignWorkspaceRole($ot,$wid,1,$u['id'],'none');ok($s->enterpriseAudit($mt,['workspace'=>$wid])['events']===[],'workspace restriction applies immediately to past events');
 $before=A::verify($pdo,$id)[$scope];deny(fn()=>$store->change(function(&$d){ActivityJournal::record($d,null,'page.save','Discarded');throw new DomainException('rollback');},'save'),'failed business mutation rolls back');ok(A::verify($pdo,$id)[$scope]===$before,'failed transaction adds no audit record');
 // Force failure after audit append, inside the SQL content transaction.
 $pg=PostgresContentStore::forDirectory(Workspaces::directory($root,$w));deny(fn()=>$pg->transaction(true,function($db)use($pg){$pg->audit([A::event('test.rollback')]);throw new DomainException('rollback');}),'explicit SQL rollback');ok(A::verify($pdo,$id)[$scope]===$before,'audit head and event rollback together');
 // ActivityJournal trimming does not remove the durable audit event.
 $store->change(function(&$d){for($i=0;$i<1020;$i++)ActivityJournal::record($d,null,'page.save','event');});$after=A::verify($pdo,$id)[$scope];ok($after['head']===$before['head']+1020&&count($store->read()['activity'])<=1000,'durable journal survives notification trimming');
 $q=$pdo->prepare('UPDATE cajeer_book.audit_event SET payload=payload WHERE installation=? AND stream=?');deny(fn()=>$q->execute([$id,$scope]),'SQL UPDATE rejected');$q=$pdo->prepare('DELETE FROM cajeer_book.audit_event WHERE installation=? AND stream=?');deny(fn()=>$q->execute([$id,$scope]),'unarchived DELETE rejected');
 $archive=$base.'/old.jsonl';$a=AuditArchive::prune($root,$scope,1,$archive);ok($a['archived']===1&&AuditArchive::verify($archive)['hash']===$a['anchor_hash'],'real retention archives only eligible prefix');$check=A::verify($pdo,$id)[$scope];ok($check['anchor']===1&&$check['head']===$after['head']+1,'retention advances anchor and appends audit event');
 deny(fn()=>AuditArchive::prune($root,$scope,1,$archive),'archive no-overwrite');ok(AuditArchive::prune($root,$scope,1,$base.'/nothing.jsonl')['archived']===0&&!file_exists($base.'/nothing.jsonl'),'recent data not deleted');
 $new=$s->createWorkspace($ot,$oid,'New','new');ok(isset(A::verify($pdo,$id)[$oid.'/'.$new['id']]),'new workspace creates ledger atomically');
}finally{putenv('BOOK_PG_DSN='.$dsn);try{$pdo=PostgresContentStore::connect();$q=$pdo->prepare('DELETE FROM cajeer_book.content_installation WHERE installation=?');$q->execute([$ns]);}catch(Throwable $e){fwrite(STDERR,'Cleanup: '.$e->getMessage()."\n");}clean($base);}
