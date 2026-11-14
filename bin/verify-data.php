<?php
declare(strict_types=1);
use CajeerBook\{BookStore,DataDirectory,MediaStore,StoreAudit};
try {
    require dirname(__DIR__).'/bootstrap.php';
    if($argc<2||$argc>3||($argc===3&&$argv[2]!=='--repair-media-references'))throw new InvalidArgumentException('Usage: php bin/verify-data.php DATA_DIR [--repair-media-references]');
    $dir=DataDirectory::resolve($argv[1],dirname(__DIR__).'/public');
    if(!is_dir($dir))throw new InvalidArgumentException('Data directory must exist');
    $store=new BookStore($dir);$repair=$argc===3;
    $result=(new StoreAudit($store,new MediaStore($dir,$store)))->run($repair);
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";
    exit(!$repair&&$result['reference_mismatches']>0?2:0);
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
