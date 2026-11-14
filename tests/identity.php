<?php
declare(strict_types=1);
use CajeerBook\IdentityStore;
require dirname(__DIR__).'/core/Update/OperationFiles.php';require dirname(__DIR__).'/src/IdentityStore.php';
function ok(bool $b,string $label):void{if(!$b)throw new RuntimeException($label);echo "PASS $label\n";}
function rejected(callable $f,string $label):void{try{$f();}catch(InvalidArgumentException|DomainException|RuntimeException){ok(true,$label);return;}throw new RuntimeException('Unexpected success: '.$label);}
function cleanup(string $dir):void{if(!is_dir($dir))return;foreach(new DirectoryIterator($dir) as $f){if($f->isDot())continue;if($f->isDir()&&!$f->isLink())cleanup($f->getPathname());else unlink($f->getPathname());}rmdir($dir);}
$dir=sys_get_temp_dir().'/book-identity-'.bin2hex(random_bytes(8));mkdir($dir,0700);
try{
 $s=new IdentityStore($dir);$legacy='legacy-password-123';$hash=password_hash($legacy,PASSWORD_DEFAULT);
 ok(!$s->initialized(),'empty installation has no default account');
 ok($s->login('admin','wrong','127.0.0.1',$hash)['status']==='invalid'&&!$s->initialized(),'bad legacy password cannot initialize');
 $a=$s->login('ADMIN',$legacy,'127.0.0.1',$hash);ok($a['status']==='ok'&&$a['user']['owner']&&$s->initialized(),'authenticated legacy migration creates owner');
 $token=$a['token'];$owner=$a['user']['id'];$path=$dir.'/identity/identity.json';$raw=file_get_contents($path);
 ok(!str_contains($raw,$token)&&!str_contains($raw,$legacy)&&str_contains($raw,hash('sha256',$token)),'only token digest and password hash stored');
 ok((fileperms($path)&0777)===0600,'identity file private permissions');
 rejected(fn()=>$s->initialize('newowner','Owner','owner@example.com','new-password-123'),'second initialization rejected');
 $b=$s->login('admin',$legacy,'127.0.0.1');ok($b['user']['id']===$owner&&$b['token']!==$token,'independent session and stable user ID');
 $s->profile($token,'Updated Owner','owner@example.com');ok($s->current($b['token'])['user']['name']==='Updated Owner','profile persisted and shared');
 $member=$s->createUser($token,'writer','Writer','writer@example.com','writer-password-123');ok(!$member['owner'],'ordinary user created without Studio authority');
 rejected(fn()=>$s->createUser($token,'WRITER','Duplicate','other@example.com','another-password-123'),'case insensitive username unique');
 rejected(fn()=>$s->createUser($token,'other','Duplicate','WRITER@example.com','another-password-123'),'email unique');
 rejected(fn()=>$s->createUser($token,'other','Other','other@example.com','short'),'weak password rejected');
 $m=$s->login('writer','writer-password-123','127.0.0.2');ok($m['status']==='ok','member independent login');
 rejected(fn()=>$s->users($m['token']),'member cannot list accounts');
 rejected(fn()=>$s->createUser($m['token'],'other','Other','other@example.com','another-password-123'),'member cannot create users');
 $s->revokeSession($m['token'],$b['session']['id']);ok($s->current($b['token'])!==null,'cannot revoke another user session');
 $s->revokeSession($token,$b['session']['id']);ok($s->current($b['token'])===null&&$s->current($token)!==null,'revoke chosen own session');
 $s->setEnabled($token,$member['id'],false);ok($s->current($m['token'])===null&&$s->login('writer','writer-password-123','127.0.0.2')['status']==='invalid','disabled user immediately denied');
 rejected(fn()=>$s->setEnabled($token,$owner,false),'cannot disable only owner');
 $s->setEnabled($token,$member['id'],true);$m=$s->login('writer','writer-password-123','127.0.0.2');ok($m['status']==='ok','reactivation requires new session');
 $old=$s->issueReset('writer');$reset=$s->issueReset('writer');ok($s->reset($old,'recovered-password-123','127.0.0.2')==='invalid','issuing new reset invalidates previous');
 ok(!str_contains(file_get_contents($path),$reset),'reset token digest only');
 ok($s->reset($reset,'recovered-password-123','127.0.0.2')==='ok'&&$s->current($m['token'])===null,'reset changes password and revokes sessions');
 ok($s->reset($reset,'recovered-password-456','127.0.0.2')==='invalid','reset single use');
 ok($s->login('writer','writer-password-123','127.0.0.2')['status']==='invalid','old password denied');
 $m=$s->login('writer','recovered-password-123','127.0.0.2');ok($m['status']==='ok','recovered password works');
 $r=$s->changePassword($token,$legacy,'owner-new-password-123','127.0.0.1');ok($r['status']==='ok'&&$s->current($token)===null,'password change rotates and revokes sessions');
 ok($s->login('admin',$legacy,'127.0.0.1',$hash)['status']==='invalid','legacy env no bypass after migration');
 $token=$r['token'];$sessionKey=hash('sha256',$token);$db=json_decode(file_get_contents($path),true);$db['sessions'][$sessionKey]['seen']=time()-28801;CajeerEngine\Update\OperationFiles::json($path,$db);ok($s->current($token)===null,'idle timeout enforced server-side');
 $fresh=$s->login('admin','owner-new-password-123','127.0.0.1');$db=json_decode(file_get_contents($path),true);$db['sessions'][hash('sha256',$fresh['token'])]['expires']=time()-1;CajeerEngine\Update\OperationFiles::json($path,$db);ok($s->current($fresh['token'])===null,'absolute expiry enforced server-side');
 $expired=$s->issueReset('writer');$db=json_decode(file_get_contents($path),true);$db['resets'][hash('sha256',$expired)]['expires']=time()-1;CajeerEngine\Update\OperationFiles::json($path,$db);ok($s->reset($expired,'some-other-password-123','127.0.0.2')==='invalid','recovery expiration enforced');
 for($i=0;$i<5;$i++)ok($s->login('notfound','incorrect','192.0.2.1')['status']==='invalid','generic failed login '.($i+1));
 ok($s->login('notfound','incorrect','192.0.2.2')['status']==='limited','account rate limit across IPs');
 $copy=new IdentityStore($dir.'/copy');$copy->restore($s->backup());ok($copy->current($m['token'])===null&&$copy->login('writer','recovered-password-123','192.0.2.3')['status']==='ok','backup restores accounts but not live sessions');
 rejected(fn()=>$copy->restore($s->backup()),'restore cannot overwrite initialized identity');
 $new=new IdentityStore($dir.'/fresh');$u=$new->initialize('owner','Owner','owner@example.net','fresh-password-123');ok($u['owner']&&$new->login('owner','fresh-password-123','192.0.2.4')['status']==='ok','CLI initialization path works without legacy hash');
 $new->checkReady();ok(glob($dir.'/fresh/identity/.ready-*')===[],'identity readiness probe cleaned');
 mkdir($dir.'/symlink');symlink($dir.'/identity',$dir.'/symlink/identity');rejected(fn()=>(new IdentityStore($dir.'/symlink'))->initialized(),'symlink identity storage rejected');
}finally{cleanup($dir);}
