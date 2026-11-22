<?php
declare(strict_types=1);
/** Exercises actual IdentityStore/ActivityJournal owner gates without Composer. */
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{
    foreach(['CajeerBook\\'=>'src/','CajeerEngine\\'=>'core/'] as $prefix=>$dir){
        if(str_starts_with($class,$prefix)){$file=$root.'/'.$dir.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($file))require_once $file;return;}
    }
});
function test(bool $ok,string $title):void{if(!$ok)throw new RuntimeException('FAIL '.$title);echo 'PASS '.$title."\n";}
function denied(Closure $fn,string $title):void{try{$fn();}catch(DomainException){echo 'PASS '.$title."\n";return;}throw new RuntimeException('FAIL '.$title);}
$dir=sys_get_temp_dir().'/book-update-access-'.bin2hex(random_bytes(8));mkdir($dir,0700,true);
try{
    $identity=new CajeerBook\IdentityStore($dir);
    $owner=$identity->initialize('owner','Owner','owner@example.org','Owner-Password!112');
    $login=$identity->login('owner','Owner-Password!112','127.0.0.1');
    test($login['status']==='ok','Local owner session established');
    $token=$login['token'];$csrf=$login['session']['csrf'];
    denied(fn()=>$identity->updateCenterAuthorize($token,'bad'),'Wrong CSRF blocked before network');
    $identity->updateCenterAuthorize($token,$csrf);
    $identity->updateCenterAction($token,$csrf,'check','1.1.3');
    $identity->updateCenterAction($token,$csrf,'prepare','1.1.3');
    test(true,'Owner check and preparation actions committed');
    $users=$identity->users($token);
    $created=$identity->createUser($token,'editor','Editor','editor@example.org','Editor-Password!112');
    $other=$identity->login('editor','Editor-Password!112','127.0.0.2');
    test($other['status']==='ok','Non-owner session established');
    denied(fn()=>$identity->updateCenterAuthorize($other['token'],$other['session']['csrf']),'Non-owner denied update access');
    denied(fn()=>$identity->updateCenterAction($other['token'],$other['session']['csrf'],'prepare','1.1.3'),'Non-owner cannot forge update audit');
    for($i=1;$i<10;$i++)$identity->updateCenterAuthorize($token,$csrf);
    denied(fn()=>$identity->updateCenterAuthorize($token,$csrf),'Durable update-check quota enforced');
    $identity->logout($token);
    denied(fn()=>$identity->updateCenterAction($token,$csrf,'check','1.1.3'),'Revoked session cannot record actions');
    echo "Update Center owner gate tests completed.\n";
}finally{
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $entry){if($entry->isDir()&&!$entry->isLink())rmdir($entry->getPathname());else unlink($entry->getPathname());}
    rmdir($dir);
}
