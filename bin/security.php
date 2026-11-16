<?php
declare(strict_types=1);
use CajeerBook\{AutomationQueue,BookStore,GitHubSync,IdentityStore,PostgresContentStore,SecretKeys,Workspaces};
$root=dirname(__DIR__);
try{
    // The application autoloader is sufficient for storage-only credential maintenance.
    if(is_file($root.'/vendor/autoload.php'))require $root.'/bootstrap.php';
    else spl_autoload_register(static function(string $class)use($root):void{foreach(['CajeerBook\\'=>'src/','CajeerEngine\\'=>'core/'] as $prefix=>$dir)if(str_starts_with($class,$prefix)){$path=$root.'/'.$dir.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($path))require_once $path;}});
    $cmd=$argv[1]??'';$emit=static fn(array $data)=>print(json_encode($data,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n");
    if($cmd==='keys'&&$argc===2){$out=[];foreach(['BOOK_GITHUB_KEY','BOOK_AUTOMATION_KEY','BOOK_BACKUP_KEY'] as $env){if(!getenv($env.'_FILE')&&!getenv($env))continue;$keys=SecretKeys::ring($env,$env==='BOOK_BACKUP_KEY');$out[$env]=['active'=>array_key_first($keys),'retained'=>array_keys($keys)];}$emit($out);exit;}
    if(!($cmd==='verify'&&$argc===2)&&!($cmd==='rewrap'&&$argc===3&&$argv[2]==='--offline'))throw new InvalidArgumentException('Usage: php bin/security.php keys | verify | rewrap --offline');
    $dir=PostgresContentStore::configuredRoot();$identity=new IdentityStore($dir);$results=$identity->installationSnapshot(function($db)use($cmd,$dir){
        if(!$db['users'])throw new DomainException('Installation must be initialized.');$stores=['legacy'=>new BookStore($dir,Workspaces::legacy($db)===null)];
        foreach($db['workspaces'] as $w)if(!$w['legacy'])$stores[$w['organization'].'/'.$w['id']]=new BookStore(Workspaces::directory($dir,$w),false);
        $out=[];foreach($stores as $scope=>$store){
            $changed=false;if($cmd==='rewrap')$store->change(function(&$state)use(&$changed){$git=GitHubSync::rewrap($state);$automation=AutomationQueue::rewrap($state);$changed=$git||$automation;});
            $store->snapshot(function($state){GitHubSync::verifyKey($state);AutomationQueue::verifyKey($state);});$out[]=['scope'=>$scope,'changed'=>$changed,'active_keys_verified'=>true];
        }return $out;
    });$emit(['status'=>'ok','scopes'=>$results]);
}catch(Throwable $e){fwrite(STDERR,"Security maintenance failed: ".$e->getMessage()."\n");exit(1);}
