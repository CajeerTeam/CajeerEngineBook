<?php
declare(strict_types=1);
/** 1.0.2 regression scenarios; no Composer dependencies or external services required. */
$base=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($base):void{
    foreach(['CajeerBook\\'=>'src/','CajeerEngine\\'=>'core/'] as $prefix=>$dir){
        if(str_starts_with($class,$prefix)){
            $file=$base.'/'.$dir.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
            if(is_file($file))require_once $file;
        }
    }
});
use CajeerBook\{BookStore,IdentityStore,ImportFiles,MarkdownImport,MediaStore,MediaBackup};
use CajeerEngine\Update\OperationFiles as Files;
function check102(bool $value,string $message):void{if(!$value)throw new RuntimeException('FAILED: '.$message);echo "PASS $message\n";}
function deny102(callable $action,string $message):void{
    try{$action();}catch(InvalidArgumentException|DomainException|RuntimeException){check102(true,$message);return;}
    throw new RuntimeException('Unexpected success: '.$message);
}
function clean102(string $path):void{
    if(is_link($path)||is_file($path)){unlink($path);return;}
    if(!is_dir($path))return;
    foreach(new DirectoryIterator($path) as $file)if(!$file->isDot())clean102($file->getPathname());
    rmdir($path);
}
$directory=sys_get_temp_dir().'/book-regressions-102-'.bin2hex(random_bytes(8));mkdir($directory,0700);
try{
    // Successful shared-IP sign-ins must not consume the failed-authentication allowance.
    $identity=new IdentityStore($directory.'/identity-case');$password='owner-password-for-102';
    $identity->initialize('owner','Owner','owner@example.invalid',$password);
    for($n=0;$n<31;$n++){
        $result=$identity->login('owner',$password,'198.51.100.22');
        check102($result['status']==='ok','valid login '.($n+1).' succeeds behind the same proxy');
    }
    for($n=0;$n<5;$n++)check102($identity->login('owner','invalid-password','203.0.113.14')['status']==='invalid','invalid login '.($n+1).' is recorded');
    check102($identity->login('owner',$password,'203.0.113.14')['status']==='limited','account brute-force bucket blocks further logins');
    check102($identity->login('owner',$password,'198.51.100.22')['status']==='limited','account bucket is enforced across IP addresses');
    // Restore valid auth using the other account's unaffected bucket and a new scope.
    $separate=new IdentityStore($directory.'/reset-case');$separate->initialize('owner','Owner','owner2@example.invalid',$password);
    $token=$separate->login('owner',$password,'192.0.2.1')['token'];
    $reset=$separate->issueReset('owner');check102($separate->reset($reset,'password-updated-for-102','192.0.2.1')==='ok','password reset succeeds');
    check102($separate->current($token)===null&&$separate->login('owner',$password,'192.0.2.1')['status']==='invalid','reset revokes prior session and password');
    check102($separate->login('owner','password-updated-for-102','192.0.2.1')['status']==='ok','new password is operational');

    // An import must not replace a preexisting blob even if the preview raced an external writer.
    $store=new BookStore($directory.'/books');$media=new MediaStore($directory.'/books',$store);
    $store->materialize();$importer=new MarkdownImport($store,$media);$set=new ImportFiles();
    $set->addBytes('attachment.txt','attachment-byte-content');
    $before=$store->read();$id=bin2hex(random_bytes(16));$source=$set->files()['attachment.txt'];
    $asset=MediaStore::inspect($source,'attachment.txt')+['id'=>$id,'folder'=>'','created'=>gmdate('c'),'updated'=>gmdate('c'),'revision'=>1,'source'=>$source];
    $plan=['assets'=>[$id=>$asset],'docs'=>[['key'=>'guide/1/index','action'=>'create','doc'=>['title'=>'Title','section'=>'Imported','order'=>10,'body'=>'# Title','updated'=>gmdate('c'),'media'=>[]]]],'rows'=>[],'warnings'=>[],'media_new'=>1,'media_total'=>1];
    check102(count($plan['assets'])===1,'real attachment metadata enters the transactional import planner');
    $blob=$media->blob($id);
    Files::write($blob,'other-writer-bytes');
    deny102(fn()=>$importer->commit('guide',fn($db)=>$plan),'import rejects existing destination without replacement');
    check102(file_get_contents($blob)==='other-writer-bytes'&&$store->read()===$before,'failed import retains preexisting bytes and rolls back all records');
    Files::remove($blob);
    $importer->commit('guide',fn($db)=>$plan);
    check102(file_get_contents($blob)==='attachment-byte-content'&&count($store->read()['media'])===1,'import commits authenticated attachment to private store');
    $entry=$store->read()['pages']['guide/1/index'];
    check102($entry['published']===null,'import remains draft only and does not publish');

    // A validated TAR restores; unknown members cannot be silently ignored.
    $database=['schema'=>1,'pages'=>[],'media'=>[],'books'=>[]];
    foreach([false,true] as $extra){
        $archive=$directory.($extra?'/foreign.tar':'/valid.tar');
        $tar=new PharData($archive,0,null,Phar::TAR);
        $tar->addFromString('books.json',json_encode($database,JSON_THROW_ON_ERROR));
        if($extra)$tar->addFromString('hidden-secrets.txt','unindexed attachment');
        unset($tar);
        $target=$directory.($extra?'/reject':'/restored');
        if($extra){deny102(fn()=>MediaBackup::restore($archive,$target),'restore rejects non-manifest TAR member');check102(!file_exists($target),'rejected TAR does not publish a restore target');}
        else{MediaBackup::restore($archive,$target);check102((new BookStore($target,false,true))->read()['pages']===[],'minimal valid TAR restores a real content store');}
    }
    echo "OK 1.0.2 offline regressions\n";
}finally{clean102($directory);}
