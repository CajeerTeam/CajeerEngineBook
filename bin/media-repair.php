<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{BookStore,DataDirectory,IdentityStore,MediaStore,Workspaces};
try{
    if($argc!==4||!preg_match('~\A(?:legacy|[a-f0-9]{32}/[a-f0-9]{32})\z~D',$argv[1]))throw new InvalidArgumentException('Usage: php bin/media-repair.php legacy|ORG/WORKSPACE MEDIA_ID VERIFIED_SOURCE');
    $root=DataDirectory::resolve(getenv('BOOK_DATA_DIR')?:dirname(__DIR__).'/var',dirname(__DIR__).'/public');$scope=$argv[1];
    (new IdentityStore($root))->installationSnapshot(function($db)use($root,$scope,$argv){
        $dir=$root;$seed=Workspaces::legacy($db)===null;
        if($scope!=='legacy'){[$org,$id]=explode('/',$scope);$w=$db['workspaces'][$id]??null;if(!$w||$w['organization']!==$org||$w['legacy'])throw new RuntimeException('Workspace unavailable');$dir=Workspaces::directory($root,$w);$seed=false;}
        $store=new BookStore($dir,$seed);(new MediaStore($dir,$store))->repair($argv[2],$argv[3]);
    });
    echo "Verified media repaired\n";
}catch(Throwable $e){fwrite(STDERR,"Media repair failed: ".$e->getMessage()."\n");exit(1);}
