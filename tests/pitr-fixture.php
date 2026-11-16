<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{IdentityStore,BookStore,Workspaces,PostgresContentStore,MediaStore,PhysicalRecovery};
$root=PostgresContentStore::configuredRoot();$i=new IdentityStore($root);$cmd=$argv[1]??'';
function check(bool $v,string $label):void{if(!$v)throw new RuntimeException($label);fwrite(STDERR,"PASS $label\n");}
if($cmd==='seed'){
    PostgresContentStore::migrate($root);$login=$i->login('owner','fixture-password-050','192.0.2.1');check($login['status']==='ok','initial owner session');echo $login['token'];return;
}
$w=array_values($i->backup()['workspaces'])[0];$s=new BookStore(Workspaces::directory($root,$w),false);$key='guide/1/start';
if(in_array($cmd,['before','after'],true)){$s->change(function(&$db)use($key,$cmd){$db['pages'][$key]['draft']['body'].="\n\nPITR-$cmd";$db['pages'][$key]['revision']++;},'save');return;}
if($cmd==='verify'){
    $db=$s->read();check(str_contains($db['pages'][$key]['draft']['body'],'PITR-before')&&!str_contains($db['pages'][$key]['draft']['body'],'PITR-after'),'WAL replay includes pre-point commit, excludes later commit');
    $media=new MediaStore(Workspaces::directory($root,$w),$s);check(file_get_contents($media->blob(array_key_first($db['media'])))==="Upgrade fixture attachment\n",'original media restored');
    $pdo=PostgresContentStore::runtimeConnect();$q=$pdo->prepare('SELECT count(*) FROM cajeer_book.identity_sessions WHERE installation=?');$q->execute([PostgresContentStore::namespace()]);check((int)$q->fetchColumn()===0,'all previous sessions revoked');
    check($i->login('owner','fixture-password-050','192.0.2.2')['status']==='ok','owner can sign in on recovered cluster');return;
}
throw new RuntimeException('Invalid fixture action');
