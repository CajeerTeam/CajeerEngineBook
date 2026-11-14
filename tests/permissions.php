<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/Update/OperationFiles.php';
require dirname(__DIR__).'/src/IdentityStore.php';
require dirname(__DIR__).'/src/BookStore.php';
require dirname(__DIR__).'/src/PageTree.php';
use CajeerBook\{IdentityStore,Permissions,Workspaces,OrganizationAccessException};
use CajeerEngine\Update\OperationFiles as Files;
function check(bool $v,string $label):void{if(!$v)throw new RuntimeException($label);echo "PASS $label\n";}
function deny(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException('Unexpected success: '.$label);}
function cleanup(string $dir):void{foreach(new DirectoryIterator($dir) as $f){if($f->isDot())continue;if($f->isDir()&&!$f->isLink())cleanup($f->getPathname());else unlink($f->getPathname());}rmdir($dir);}
$root=sys_get_temp_dir().'/book-rbac-'.bin2hex(random_bytes(8));mkdir($root,0700);
try{
 $s=new IdentityStore($root);$owner=$s->initialize('owner','Owner','owner@example.org','owner-password-123');$ot=$s->login('owner','owner-password-123','192.0.2.1')['token'];
 $users=[];$tokens=[];foreach(['manager','writer','reader','outsider'] as $i=>$name){$users[$name]=$s->createUser($ot,$name,$name,$name.'@example.org','password-'.$name.'-123')['id'];$tokens[$name]=$s->login($name,'password-'.$name.'-123','192.0.2.'.($i+2))['token'];}
 $org=$s->createOrganization($ot,'Example','example')['id'];$rev=fn()=>$s->organization($ot,$org)['revision'];
 foreach(['manager','writer','reader'] as $name){$i=$s->mutateOrganization($ot,$org,$rev(),'invite',['username'=>$name]);$s->acceptInvitation($i['token'],$tokens[$name],'192.0.2.8');}
 $a=$s->createWorkspace($ot,$org,'Alpha','alpha');$b=$s->createWorkspace($ot,$org,'Beta','beta');$id=$a['id'];$bid=$b['id'];
 $path=$root.'/identity/identity.json';$db=Files::read($path);$db['schema']=2;Files::json($path,$db);
 $role=fn($token,$wid)=>$s->withWorkspace($token,$org,$wid,fn($ctx,$w)=>$w['role']??'none');
 check($role($tokens['writer'],$id)==='viewer'&&Files::read($path)['schema']===4,'schema 2 migration keeps member read access and session');
 $assign=fn($actor,$target,$r)=>$s->mutateOrganization($actor,$org,$rev(),'role',['user'=>$users[$target],'role'=>$r]);
 $assign($ot,'manager','admin');$assign($ot,'writer','editor');
 check($role($tokens['manager'],$id)==='admin'&&$role($tokens['writer'],$id)==='editor'&&$role($tokens['reader'],$id)==='viewer','four effective roles');
 check($role($tokens['outsider'],$id)==='none','nonmember no role');
 deny(fn()=>$assign($tokens['writer'],'writer','admin'),'editor cannot self promote');
 deny(fn()=>$assign($tokens['manager'],'writer','admin'),'admin cannot appoint another admin');
 deny(fn()=>$assign($tokens['manager'],'manager','viewer'),'admin cannot change own role');
 deny(fn()=>$assign($ot,'writer','owner'),'owner role only via transfer');
 deny(fn()=>$s->mutateOrganization($tokens['manager'],$org,$rev(),'transfer',['user'=>$users['manager']]),'admin cannot transfer owner');
 deny(fn()=>$s->mutateOrganization($tokens['manager'],$org,$rev(),'remove',['user'=>$owner['id']]),'admin cannot remove owner');
 deny(fn()=>$s->mutateOrganization($ot,$org,1,'role',['user'=>$users['writer'],'role'=>'viewer']),'stale role revision rejected');
 $wrev=fn($wid)=>array_values(array_filter($s->workspaces($ot),fn($w)=>$w['id']===$wid))[0]['revision'];
 $scope=fn($actor,$wid,$target,$r)=>$s->assignWorkspaceRole($actor,$wid,$wrev($wid),$users[$target],$r);
 $scope($ot,$id,'writer','viewer');check($role($tokens['writer'],$id)==='viewer'&&$role($tokens['writer'],$bid)==='editor','workspace restriction does not affect sibling');
 $scope($tokens['manager'],$id,'writer','none');check($role($tokens['writer'],$id)==='none'&&count($s->workspaces($tokens['writer']))===1,'deny hides workspace and revokes content');
 deny(fn()=>$scope($tokens['manager'],$id,'reader','editor'),'workspace role cannot exceed organization role');
 deny(fn()=>$scope($tokens['manager'],$id,'manager','admin'),'admin cannot grant own scope');
 $scope($ot,$id,'manager','none');check($role($tokens['manager'],$id)==='none'&&$role($tokens['manager'],$bid)==='admin','owner can scope administrator');
 deny(fn()=>$scope($tokens['manager'],$id,'writer','inherit'),'restricted admin cannot manage excluded space');
 deny(fn()=>$s->mutateWorkspace($tokens['manager'],$id,$wrev($id),'rename','Attack','attack'),'restricted admin cannot rename excluded space');
 $scope($ot,$id,'manager','admin');$scope($tokens['manager'],$id,'writer','inherit');check($role($tokens['writer'],$id)==='editor','inherit restores ceiling');
 $scope($ot,$id,'writer','none');$assign($tokens['manager'],'writer','viewer');check($role($tokens['writer'],$id)==='none'&&$role($tokens['writer'],$bid)==='viewer','demotion preserves explicit denial');
 $assign($ot,'writer','editor');check($role($tokens['writer'],$id)==='none','promotion preserves explicit denial');
 deny(fn()=>$s->assignWorkspaceRole($ot,$id,1,$users['writer'],'inherit'),'stale workspace revision rejected');
 $scope($ot,$id,'writer','inherit');$scope($ot,$bid,'writer','viewer');
 $backup=$s->backup();$restore=new IdentityStore($root.'/restored');$restore->restore($backup);$rt=$restore->login('writer','password-writer-123','192.0.2.9')['token'];check($backup['schema']===4&&$restore->withWorkspace($rt,$org,$bid,fn($c,$w)=>$w['role'])==='viewer','backup preserves organization and scoped role');
 $bad=$backup;$bad['workspaces'][$bid]['roles'][$users['reader']]='admin';deny(fn()=>$s->assignWorkspaceRole($ot,$bid,$wrev($bid),$users['reader'],'admin'),'workspace privilege escalation rejected');
 try{(new IdentityStore($root.'/bad'))->restore($bad);throw new LogicException('Accepted invalid backup');}catch(RuntimeException){check(true,'invalid persisted assignment rejected');}
 $s->mutateOrganization($ot,$org,$rev(),'remove',['user'=>$users['writer']]);check($role($tokens['writer'],$id)==='none'&&$role($tokens['writer'],$bid)==='none','removed member loses every scope');
 $i=$s->mutateOrganization($ot,$org,$rev(),'invite',['username'=>'writer']);$s->acceptInvitation($i['token'],$tokens['writer'],'192.0.2.8');check($role($tokens['writer'],$bid)==='viewer'&& !isset($s->backup()['workspaces'][$bid]['roles'][$users['writer']]),'reinvitation never resurrects old grants');
 $legacy=$s->createWorkspace($ot,$org,'Legacy','legacy',true);check($s->withLegacyStudio($tokens['manager'],fn($c,$a,$r)=>$a&&$r==='admin'),'legacy adoption uses organization permissions');
 $s->assignWorkspaceRole($ot,$legacy['id'],1,$users['manager'],'viewer');check($s->withLegacyStudio($tokens['manager'],fn($c,$a,$r)=>!$a&&$r==='viewer'),'legacy scope restrictions apply to original Studio URL');
 $s->mutateOrganization($ot,$org,$rev(),'transfer',['user'=>$users['manager']]);check($role($tokens['manager'],$id)==='owner'&&$role($ot,$id)==='viewer','transfer drops old owner privileges and clears restrictions on new owner');
 foreach(Permissions::ROLES as $r){$edit=in_array($r,['owner','admin','editor'],true);$admin=in_array($r,['owner','admin'],true);
   foreach([['/studio','GET','',$edit],['/studio','POST','save',$edit],['/studio','POST','copy',$edit],['/studio','POST','publish',$admin],['/studio','POST','unpublish',$admin],['/studio','POST','delete',$admin],['/studio/tree','POST','move',$edit],['/studio/tree','POST','duplicate',$edit],['/studio/tree','POST','publish',$admin],['/studio/media','POST','upload',$edit],['/studio/media','POST','update',$edit],['/studio/media','POST','delete',$admin],['/studio/media/file/abc','GET','',$edit],['/studio/media/picker','GET','',$edit],['/studio/editor/render','POST','',$edit],['/studio/export','GET','',$admin],['/studio/media/backup','HEAD','',$admin],['/studio/books','POST','create',$edit],['/studio','POST','invented',false],['/studio/new-route','POST','save',false]] as [$p,$m,$a,$allow])check(Permissions::studio($r,$p,$m,$a)===$allow,"$r $m $p $a");
 }
 // Real process contends with an in-flight content authorization, then revokes access.
 $manager=$tokens['manager'];$s->mutateOrganization($manager,$org,$s->organization($manager,$org)['revision'],'role',['user'=>$users['writer'],'role'=>'editor']);
 $worker='require $argv[1]."/core/Update/OperationFiles.php";require $argv[1]."/src/IdentityStore.php";$s=new CajeerBook\\IdentityStore($argv[2]);echo "started\\n";flush();$s->mutateOrganization($argv[3],$argv[4],(int)$argv[5],"role",["user"=>$argv[6],"role"=>"viewer"]);echo "done\\n";';
 $revision=$s->organization($manager,$org)['revision'];$proc=null;$pipes=[];$s->withWorkspace($tokens['writer'],$org,$id,function($ctx,$w)use(&$proc,&$pipes,$worker,$root,$manager,$org,$revision,$users){
   check($w['role']==='editor','in-flight request has authorized role');$proc=proc_open([PHP_BINARY,'-r',$worker,dirname(__DIR__),$root,$manager,$org,(string)$revision,$users['writer']],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('Worker unavailable');fclose($pipes[0]);check(trim(fgets($pipes[1]))==='started','parallel downgrade started');usleep(100000);check(proc_get_status($proc)['running'],'downgrade waits for content operation');
 });$output=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($proc);check($status===0&&trim($output)==='done'&&$role($tokens['writer'],$id)==='viewer','downgrade commits and subsequent request denied editing');
}finally{cleanup($root);}
