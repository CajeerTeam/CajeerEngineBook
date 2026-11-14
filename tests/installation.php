<?php
declare(strict_types=1);
$storage=in_array('--storage-only',$argv,true);
if($storage){spl_autoload_register(static function($c){foreach(['CajeerBook\\'=>'src/','CajeerEngine\\'=>'core/'] as $p=>$d)if(str_starts_with($c,$p)){$f=dirname(__DIR__).'/'.$d.str_replace('\\','/',substr($c,strlen($p))).'.php';if(is_file($f))require_once $f;}});}else require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{BookStore,MediaStore,IdentityStore,InstallationBackup,Workspaces,Visibility,PageTree,ReviewWorkflow,ActivityJournal};
use CajeerEngine\Update\OperationFiles as Files;
function ok(bool $v,string $s):void{if(!$v)throw new RuntimeException($s);echo "PASS $s\n";}
function deny(callable $fn,string $s):void{try{$fn();}catch(RuntimeException|InvalidArgumentException|DomainException){ok(true,$s);return;}throw new RuntimeException('Accepted: '.$s);}
function clean(string $p):void{if(!is_dir($p)||is_link($p))return;foreach(new DirectoryIterator($p) as $f){if($f->isDot())continue;if($f->isDir()&&!$f->isLink())clean($f->getPathname());else unlink($f->getPathname());}rmdir($p);}
$base=sys_get_temp_dir().'/book-installation-'.bin2hex(random_bytes(8));mkdir($base,0700);mkdir($base.'/public');$public=$base.'/public';$root=$base.'/source';mkdir($root,0700);
try{
 $i=new IdentityStore($root);$owner=$i->initialize('admin','Admin','admin@example.org','password-admin-123');$t=$i->login('admin','password-admin-123','192.0.2.1')['token'];
 $other=$i->createUser($t,'reviewer','Reviewer','reviewer@example.org','password-reviewer-123');$ot=$i->login('reviewer','password-reviewer-123','192.0.2.2')['token'];
 $o=$i->createOrganization($t,'Team A','team-a');$oid=$o['id'];$b=$i->createOrganization($ot,'Team B','team-b');$bid=$b['id'];
 $invite=$i->mutateOrganization($t,$oid,1,'invite',['username'=>'reviewer'])['token'];$i->acceptInvitation($invite,$ot,'192.0.2.2');$i->mutateOrganization($t,$oid,$i->organization($t,$oid)['revision'],'role',['user'=>$other['id'],'role'=>'admin']);
 $a=$i->createWorkspace($t,$oid,'Space','space');$otherSpace=$i->createWorkspace($ot,$bid,'Space','space');$legacyStore=new BookStore($root);$legacyStore->change(fn(&$db)=>$db['pages']=[]);$legacy=$i->createWorkspace($t,$oid,'Legacy','legacy',true);
 $i->mutateWorkspace($t,$a['id'],1,'visibility','public','');$i->assignWorkspaceRole($t,$a['id'],2,$other['id'],'editor');
 $ad=Workspaces::directory($root,$a);$bd=Workspaces::directory($root,$otherSpace);$as=new BookStore($ad,false);$bs=new BookStore($bd,false);$as->createBook('guide','Guide A');$bs->createBook('guide','Guide B');$as->setBookVisibility('guide','private',1);
 $input=$base.'/input.txt';file_put_contents($input,'Private content A');$am=new MediaStore($ad,$as);$bm=new MediaStore($bd,$bs);$mid=$am->upload($input,'attachment.txt')['item']['id'];$bidMedia=$bm->upload($input,'attachment.txt')['item']['id'];ok($mid!==$bidMedia,'same media in independent stores has independent IDs');
 if(!$storage){
   $as->setHistoryActor($owner);$body='# Secret guide' . "\n\n[Attachment](/media/$mid)";
   $as->save(['book'=>'guide','version'=>'1.0','path'=>'start','title'=>'Private A','section'=>'Guide','order'=>10,'body'=>$body,'revision'=>0],true);
   $as->save(['book'=>'guide','version'=>'1.0','path'=>'start','title'=>'Private A','section'=>'Guide','order'=>10,'body'=>$body."\n\nChanged draft",'revision'=>1],false);
   $bs->save(['book'=>'guide','version'=>'1.0','path'=>'start','title'=>'Private B','section'=>'Guide','order'=>10,'body'=>'Other organization','revision'=>0],true);
   $legacyStore->setHistoryActor($owner);$legacyStore->save(['book'=>'legacy','version'=>'1.0','path'=>'start','title'=>'Legacy','section'=>'Guide','order'=>1,'body'=>'Old content','revision'=>0],true);
 }
 $i->mutateWorkspace($ot,$otherSpace['id'],1,'archive','','');
 $bundle=$base.'/backup';$before=file_get_contents($root.'/identity/identity.json');InstallationBackup::create($root,$bundle,$public);ok(file_get_contents($root.'/identity/identity.json')===$before,'backup does not rewrite source identity');
 $m=Files::read($bundle.'/manifest.json');ok(count($m['files'])===4&&isset($m['files'][$a['id'].'.tar'],$m['files'][$otherSpace['id'].'.tar']),'backup includes legacy and archived space');
 $ib=Files::read($bundle.'/identity.json');ok(!isset($ib['sessions'],$ib['invitations'],$ib['resets'])&&!str_contains(file_get_contents($bundle.'/identity.json'),$t),'backup excludes live credentials');
 ok((fileperms($bundle)&0777)===0700&&(fileperms($bundle.'/identity.json')&0777)===0600,'private backup permissions');
 $target=$base.'/restored';InstallationBackup::restore($bundle,$target,$public);$r=new IdentityStore($target);$rb=$r->backup();ok($rb===$ib,'exact identity, roles, policies and audit round trip');ok($r->current($t)===null&&$r->login('admin','password-admin-123','192.0.2.3')['status']==='ok','restored sessions revoked, password works');
 $ra=new BookStore(Workspaces::directory($target,$a),false);$rbStore=new BookStore(Workspaces::directory($target,$otherSpace),false);ok($ra->read()===$as->read()&&$rbStore->read()===$bs->read(),'workspace metadata exact round trip');ok((new BookStore($target,false))->read()===$legacyStore->read(),'legacy content exact round trip');
 $rm=new MediaStore(Workspaces::directory($target,$a),$ra);ok(Files::hash($rm->blob($mid))===Files::hash($am->blob($mid))&&!file_exists($rm->blob($bidMedia)),'media bytes and isolation survive restore');
 $report=InstallationBackup::audit($target,$public);ok(count($report['stores'])===3&&$report['users']===2,'installation audit covers all registered stores');
 deny(fn()=>InstallationBackup::restore($bundle,$target,$public),'existing restore target rejected');deny(fn()=>InstallationBackup::create($root,$root.'/nested',$public),'backup inside installation rejected');deny(fn()=>InstallationBackup::create($root,$public.'/leak',$public),'backup in public rejected');
 $bad=$base.'/bad';mkdir($bad,0700);foreach(array_keys($m['files']) as $name)copy($bundle.'/'.$name,$bad.'/'.$name);copy($bundle.'/manifest.json',$bad.'/manifest.json');file_put_contents($bad.'/'.$a['id'].'.tar','tampered',FILE_APPEND);deny(fn()=>InstallationBackup::restore($bad,$base.'/failed',$public),'tampered TAR rejected');ok(!file_exists($base.'/failed')&&glob($base.'/.installation-*')===[],'failed restore leaves no destination or staging');
 $wrong=$m;unset($wrong['files'][$otherSpace['id'].'.tar']);Files::json($bad.'/manifest.json',$wrong);deny(fn()=>InstallationBackup::restore($bad,$base.'/missing',$public),'missing registered workspace rejected');
 $wrong=$m;$wrong['files']['../escape.tar']=['size'=>1,'sha256'=>str_repeat('a',64)];Files::json($bad.'/manifest.json',$wrong);deny(fn()=>InstallationBackup::restore($bad,$base.'/traversal',$public),'manifest path traversal rejected');
 unlink($bad.'/identity.json');symlink($bundle.'/identity.json',$bad.'/identity.json');Files::json($bad.'/manifest.json',$m);deny(fn()=>InstallationBackup::restore($bad,$base.'/symlink',$public),'symlink bundle member rejected');
 // Valid old identity schema is migrated in the destination without rewriting the source JSON.
 $old=$base.'/old';mkdir($old,0700);$oldIdentity=new IdentityStore($old);$oldIdentity->initialize('oldadmin','Old Admin','old@example.org','old-password-123');$oldJson=Files::read($old.'/identity/identity.json');$oldJson['schema']=1;unset($oldJson['organizations'],$oldJson['workspaces'],$oldJson['invitations'],$oldJson['activity']);Files::json($old.'/identity/identity.json',$oldJson);BookStore::writeDatabase($old.'/books.json',['schema'=>1,'pages'=>[]]);$bytes=file_get_contents($old.'/identity/identity.json');
 InstallationBackup::create($old,$base.'/old-backup',$public);InstallationBackup::restore($base.'/old-backup',$base.'/upgraded',$public);ok(file_get_contents($old.'/identity/identity.json')===$bytes&&(new IdentityStore($base.'/upgraded'))->backup()['schema']===4,'identity schema 1 migrates in destination, source unchanged');
 $pre=$base.'/pre-identity';mkdir($pre,0700);BookStore::writeDatabase($pre.'/books.json',['schema'=>1,'pages'=>[]]);InstallationBackup::create($pre,$base.'/pre-backup',$public);InstallationBackup::restore($base.'/pre-backup',$base.'/pre-upgraded',$public);ok(!(new IdentityStore($base.'/pre-upgraded'))->initialized()&&(new BookStore($base.'/pre-upgraded',false))->read()['schema']===1,'0.2.0 pre-identity content migration supports new owner setup');
 // An invalid published key must not make a private book appear under another book.
 $snap=['title'=>'Private','section'=>'Guide','order'=>1,'body'=>'Secret','updated'=>gmdate('c'),'media'=>[]];$broken=['schema'=>1,'pages'=>['private/1.0/start'=>['id'=>str_repeat('a',32),'draft'=>$snap,'published'=>$snap,'revision'=>1,'published_key'=>'public/1.0/start']]];Files::json($base.'/broken.json',$broken);mkdir($base.'/broken',0700);rename($base.'/broken.json',$base.'/broken/books.json');deny(fn()=>(new BookStore($base.'/broken',false))->read(),'cross-book published path rejected before Reader projection');
 rename($root.'/books.json',$root.'/books.saved');deny(fn()=>InstallationBackup::create($root,$base.'/lost',$public),'adopted missing legacy store refuses seed fallback');ok(!file_exists($base.'/lost'),'failed backup is not published');rename($root.'/books.saved',$root.'/books.json');
 $orphan=$root.'/workspaces/'.$oid.'/'.str_repeat('f',32);mkdir($orphan,0700);deny(fn()=>InstallationBackup::create($root,$base.'/orphan-backup',$public),'unregistered workspace cannot be silently omitted');rmdir($orphan);
 echo "OK\n";
}finally{clean($base);}
