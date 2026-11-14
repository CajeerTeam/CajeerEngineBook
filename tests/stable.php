<?php
declare(strict_types=1);
/** Release invariants independent of Markdown dependencies, using real stores and locks. */
spl_autoload_register(static function(string $c):void{foreach(['CajeerBook\\'=>'src/','CajeerEngine\\'=>'core/'] as $p=>$d)if(str_starts_with($c,$p)){$f=dirname(__DIR__).'/'.$d.str_replace('\\','/',substr($c,strlen($p))).'.php';if(is_file($f))require_once $f;}});
use CajeerBook\{IdentityStore,BookStore,InstallationBackup,Workspaces};
use CajeerEngine\Update\OperationFiles as Files;
function ok(bool $v,string $s):void{if(!$v)throw new RuntimeException($s);echo "PASS $s\n";}
function deny(callable $fn,string $s):void{try{$fn();}catch(RuntimeException|DomainException|InvalidArgumentException){ok(true,$s);return;}throw new RuntimeException('Accepted: '.$s);}
function clean(string $p):void{if(!is_dir($p)||is_link($p))return;foreach(new DirectoryIterator($p) as $f){if($f->isDot())continue;if($f->isDir()&&!$f->isLink())clean($f->getPathname());else unlink($f->getPathname());}rmdir($p);}
$base=sys_get_temp_dir().'/book-stable-'.bin2hex(random_bytes(8));mkdir($base,0700);mkdir($base.'/public',0700);$root=$base.'/data';mkdir($root,0700);$public=$base.'/public';
try{
 $s=new IdentityStore($root);$pass='stable-owner-password-123';$owner=$s->initialize('admin','Admin','admin@example.org',$pass);$t=$s->login('admin',$pass,'192.0.2.1')['token'];$anchor=$root.'/identity.required';$path=$root.'/identity/identity.json';
 ok(file_get_contents($anchor)===$owner['id']."\n"&&(fileperms($anchor)&0777)===0600,'durable private installation identity anchor');
 $o=$s->createOrganization($t,'Stable team','stable-team');BookStore::writeDatabase($root.'/books.json',['schema'=>1,'pages'=>[]]);$legacy=$s->createWorkspace($t,$o['id'],'Legacy','legacy',true);$s->mutateWorkspace($t,$legacy['id'],1,'visibility','private','');
 // Simulate an older release without the anchor; first read upgrades it before exposing content.
 unlink($anchor);$bytes=file_get_contents($path);ok($s->withLegacy('',fn($ctx,$role,$w,$visible)=>!$visible),'old private legacy remains closed');ok(file_get_contents($anchor)===$owner['id']."\n"&&file_get_contents($path)===$bytes,'anchor migration preserves identity JSON');
 $db=Files::read($path);$db['sessions'][hash('sha256',$t)]['seen']=time()-400;Files::json($path,$db);
 $s->withLegacy($t,function($ctx,$role,$w,$visible)use($path,$t){ok(Files::read($path)['sessions'][hash('sha256',$t)]['seen']>time()-10,'session heartbeat committed before content callback');ok($visible&&$role==='owner','legacy owner still authorized');});
 $backup=$base.'/backup';InstallationBackup::create($root,$backup,$public);$restored=$base.'/restored';InstallationBackup::restore($backup,$restored,$public);ok(file_get_contents($restored.'/identity.required')===file_get_contents($anchor),'full restore recreates bound anchor');
 rename($path,$path.'.saved');deny(fn()=>$s->withLegacy('',fn()=>true),'missing identity cannot expose private legacy');deny(fn()=>$s->login('admin',$pass,'192.0.2.2',password_hash($pass,PASSWORD_DEFAULT)),'old bootstrap secret cannot recreate owner');deny(fn()=>InstallationBackup::create($root,$base.'/lost-backup',$public),'backup refuses missing initialized identity');
 // A syntactically valid empty identity cannot downgrade the installation either.
 Files::json($path,['schema'=>4,'users'=>[],'sessions'=>[],'resets'=>[],'attempts'=>[],'organizations'=>[],'invitations'=>[],'workspaces'=>[]]);deny(fn()=>$s->initialized(),'empty replacement identity rejected');unlink($path);rename($path.'.saved',$path);
 $other=$base.'/other';$otherStore=new IdentityStore($other);$otherStore->initialize('admin','Other','other@example.org',$pass);$original=file_get_contents($path);Files::write($path,file_get_contents($other.'/identity/identity.json'));deny(fn()=>$s->initialized(),'identity from another installation rejected');Files::write($path,$original);
 rename($root.'/identity',$root.'/identity.saved');deny(fn()=>$s->withLegacy('',fn()=>true),'loss of entire identity directory fails closed');clean($root.'/identity');rename($root.'/identity.saved',$root.'/identity');
 $w=$s->createWorkspace($t,$o['id'],'Space','space');$s->checkWorkspaceStores($root);ok(true,'readiness checks legacy and registered workspace under identity lock');
 $orphan=$root.'/workspaces/'.$o['id'].'/'.str_repeat('a',32);mkdir($orphan,0700);deny(fn()=>$s->checkWorkspaceStores($root),'readiness rejects unregistered workspace data');rmdir($orphan);
 $folder=Workspaces::directory($root,$w);rename($folder.'/books.json',$folder.'/books.saved');deny(fn()=>$s->checkWorkspaceStores($root),'readiness rejects missing workspace metadata');rename($folder.'/books.saved',$folder.'/books.json');
 // A pre-anchor deployment with workspace directories but lost identity is not a new install.
 $lost=$base.'/lost';mkdir($lost,0700);mkdir($lost.'/workspaces',0700);deny(fn()=>(new IdentityStore($lost))->initialized(),'orphan workspace directory prevents bootstrap without anchor');
 $fresh=$base.'/fresh';$new=new IdentityStore($fresh);ok(!$new->initialized()&&!file_exists($fresh.'/identity.required'),'fresh installation remains uninitialized');$new->initialize('admin','Fresh','fresh@example.org',$pass);ok(is_file($fresh.'/identity.required'),'new initialization writes anchor');
 echo "OK\n";
}finally{clean($base);}
