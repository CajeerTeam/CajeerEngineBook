<?php
declare(strict_types=1);
use CajeerBook\IdentityStore;
use CajeerEngine\Update\OperationFiles as Files;
require dirname(__DIR__).'/core/Update/OperationFiles.php';require dirname(__DIR__).'/src/IdentityStore.php';
function check(bool $v,string $label):void{if(!$v)throw new RuntimeException($label);echo "PASS $label\n";}
function deny(callable $f,string $label):void{try{$f();}catch(InvalidArgumentException|DomainException|RuntimeException){check(true,$label);return;}throw new RuntimeException('Unexpected success: '.$label);}
function clean(string $dir):void{if(!is_dir($dir))return;foreach(new DirectoryIterator($dir) as $f){if($f->isDot())continue;if($f->isDir()&&!$f->isLink())clean($f->getPathname());else unlink($f->getPathname());}rmdir($dir);}
$dir=sys_get_temp_dir().'/book-org-'.bin2hex(random_bytes(8));mkdir($dir,0700);
try{
 $s=new IdentityStore($dir);$a=$s->initialize('admin','Admin','admin@example.com','password-admin-123');$at=$s->login('admin','password-admin-123','192.0.2.1')['token'];
 $b=$s->createUser($at,'writer','Writer','writer@example.com','password-writer-123');$bt=$s->login('writer','password-writer-123','192.0.2.2')['token'];
 $c=$s->createUser($at,'outsider','Outsider','outsider@example.com','password-outsider-123');$ct=$s->login('outsider','password-outsider-123','192.0.2.3')['token'];
 $path=$dir.'/identity/identity.json';$old=Files::read($path);$old['schema']=1;unset($old['organizations'],$old['invitations']);Files::json($path,$old);
 check($s->current($at)['user']['id']===$a['id']&&Files::read($path)['schema']===2,'v1 migration preserves user ID and session');
 $o=$s->createOrganization($at,'Engineering','Engineering');$id=$o['id'];$rev=fn()=>$s->organization($at,$id)['revision'];
 check($o['slug']==='engineering'&&$o['role']==='owner'&&$o['member_count']===1,'creation and canonical slug');
 deny(fn()=>$s->createOrganization($bt,'Duplicate','ENGINEERING'),'case insensitive unique slug');
 deny(fn()=>$s->createOrganization($bt,'Bad','../bad'),'invalid slug');
 $other=$s->createOrganization($ct,'Other','other-team');check(count($s->organizations($at))===1&&$s->organization($ct,$id)===null&&$s->organization($at,$other['id'])===null,'organization isolation including installation owner');
 deny(fn()=>$s->mutateOrganization($ct,$id,1,'rename',['name'=>'Attack','slug'=>'attack']),'outsider mutation denied');
 $r=$s->mutateOrganization($at,$id,$rev(),'invite',['username'=>'writer']);$token=$r['token'];check(!str_contains(file_get_contents($path),$token),'invitation digest only');
 check($s->acceptInvitation($token,'','192.0.2.4','Wrong','wrong@example.com','password-wrong-123')['status']==='signin','existing account requires login');
 check($s->acceptInvitation($token,$ct,'192.0.2.3')['status']==='invalid','wrong account denied');
 $r2=$s->mutateOrganization($at,$id,$rev(),'invite',['username'=>'writer']);check($s->acceptInvitation($token,$bt,'192.0.2.2')['status']==='invalid','replacement invalidates old token');
 check($s->acceptInvitation($r2['token'],$bt,'192.0.2.2')['status']==='ok','existing account accepts');
 check($s->acceptInvitation($r2['token'],$bt,'192.0.2.2')['status']==='invalid','invitation one time');
 check($s->organization($bt,$id)['role']==='member'&&$s->organization($bt,$id)['member_count']===2,'membership persisted');
 deny(fn()=>$s->mutateOrganization($bt,$id,$rev(),'invite',['username'=>'newbie']),'member cannot invite');
 deny(fn()=>$s->mutateOrganization($at,$id,1,'rename',['name'=>'Stale','slug'=>'stale']),'stale revision rejected');
 deny(fn()=>$s->mutateOrganization($at,$id,$rev(),'leave',[]),'owner cannot leave');
 deny(fn()=>$s->mutateOrganization($at,$id,$rev(),'remove',['user'=>$a['id']]),'owner cannot be removed');
 $r=$s->mutateOrganization($at,$id,$rev(),'invite',['username'=>'newbie']);$new=$r['token'];
 deny(fn()=>$s->acceptInvitation($new,'','192.0.2.5','New','writer@example.com','password-newbie-123'),'duplicate email signup atomic rejection');
 check($s->acceptInvitation($new,'','192.0.2.5','New','new@example.com','password-newbie-123')['status']==='ok','new invited account created atomically');
 $nt=$s->login('newbie','password-newbie-123','192.0.2.5')['token'];check($s->organization($nt,$id)['member_count']===3&&!$s->current($nt)['user']['owner'],'new account only organization member');
 $r=$s->mutateOrganization($at,$id,$rev(),'invite',['username'=>'future']);$i=$s->organization($at,$id)['invitations'][0];
 check($s->organization($bt,$id)['invitations']===[],'member cannot see pending invites');
 $s->mutateOrganization($at,$id,$rev(),'revoke',['invitation'=>$i['id']]);check($s->acceptInvitation($r['token'],'','192.0.2.6','Future','future@example.com','password-future-123')['status']==='invalid','revocation enforced');
 $r=$s->mutateOrganization($at,$id,$rev(),'invite',['username'=>'future']);$db=Files::read($path);$key=hash('sha256',$r['token']);$db['invitations'][$key]['created']=time()-604801;$db['invitations'][$key]['expires']=time()-1;Files::json($path,$db);
 check($s->acceptInvitation($r['token'],'','192.0.2.6','Future','future@example.com','password-future-123')['status']==='invalid','expiry enforced');
 // Separate PHP processes contend on the same real identity lock, not mocked transactions.
 $r=$s->mutateOrganization($at,$id,$rev(),'invite',['username'=>'raceuser']);
 $worker='require $argv[1]."/core/Update/OperationFiles.php";require $argv[1]."/src/IdentityStore.php";$s=new CajeerBook\IdentityStore($argv[2]);echo $s->acceptInvitation($argv[3],"","192.0.2.9","Race User","race@example.com","password-race-123")["status"];';
 $cmd=[PHP_BINARY,'-r',$worker,dirname(__DIR__),$dir,$r['token']];$procs=[];
 for($j=0;$j<2;$j++){$proc=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('Cannot start worker');fclose($pipes[0]);$procs[]=[$proc,$pipes];}
 $results=[];foreach($procs as [$proc,$pipes]){$results[]=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($proc)!==0)throw new RuntimeException($err);}sort($results);
 check($results===['invalid','ok'],'concurrent redemption commits exactly once');
 $raceId=null;foreach($s->organization($at,$id)['members'] as $m)if($m['username']==='raceuser')$raceId=$m['id'];check($raceId!==null,'concurrent registration persists exactly one member');$s->mutateOrganization($at,$id,$rev(),'remove',['user'=>$raceId]);
 $s->mutateOrganization($at,$id,$rev(),'transfer',['user'=>$b['id']]);check($s->organization($at,$id)['role']==='member'&&$s->organization($bt,$id)['role']==='owner','atomic transfer');
 deny(fn()=>$s->setEnabled($at,$b['id'],false),'cannot disable sole organization owner');
 deny(fn()=>$s->mutateOrganization($at,$id,$rev(),'archive',[]),'former owner cannot manage');
 $br=fn()=>$s->organization($bt,$id)['revision'];$s->mutateOrganization($bt,$id,$br(),'remove',['user'=>$a['id']]);check($s->organization($at,$id)===null,'removal immediately revokes org access even installation owner');
 $s->mutateOrganization($nt,$id,$s->organization($nt,$id)['revision'],'leave',[]);check($s->organization($nt,$id)===null,'member leaves');
 $r=$s->mutateOrganization($bt,$id,$br(),'invite',['username'=>'future']);$s->mutateOrganization($bt,$id,$br(),'archive',[]);check($s->organization($bt,$id)['archived']&&$s->acceptInvitation($r['token'],'','192.0.2.6','Future','future@example.com','password-future-123')['status']==='invalid','archive revokes invitations');
 deny(fn()=>$s->mutateOrganization($bt,$id,$br(),'invite',['username'=>'future']),'archived organization cannot invite');
 $s->mutateOrganization($bt,$id,$br(),'restore',[]);check(!$s->organization($bt,$id)['archived'],'restore organization');
 $r=$s->mutateOrganization($bt,$id,$br(),'invite',['username'=>'future']);$backup=$s->backup();check(!isset($backup['invitations'])&&!isset($backup['sessions'])&&count($backup['organizations'])===2,'backup includes organizations not credentials');
 $copy=new IdentityStore($dir.'/copy');$copy->restore($backup);$copyToken=$copy->login('writer','password-writer-123','192.0.2.7')['token'];check($copy->organization($copyToken,$id)['owner']===$b['id']&&$copy->acceptInvitation($r['token'],'','192.0.2.7','Future','future@example.com','password-future-123')['status']==='invalid','restore preserves memberships not invitations');
 $legacy=new IdentityStore($dir.'/legacy');$legacy->restore(['schema'=>1,'users'=>$backup['users']]);check($legacy->organizations($legacy->login('writer','password-writer-123','192.0.2.8')['token'])===[],'legacy identity backup compatible');
 deny(fn()=>$s->mutateOrganization($bt,$id,$br(),'delete',['confirm'=>'engineering']),'delete requires archive');
 $s->mutateOrganization($bt,$id,$br(),'archive',[]);deny(fn()=>$s->mutateOrganization($bt,$id,$br(),'delete',['confirm'=>'wrong']),'delete requires exact slug');
 $s->mutateOrganization($bt,$id,$br(),'delete',['confirm'=>'engineering']);check($s->organization($bt,$id)===null&&$s->organization($ct,$other['id'])!==null,'delete only selected organization');
 $s->setEnabled($at,$b['id'],false);check($s->current($bt)===null,'former owner account can be disabled');
}finally{clean($dir);}
