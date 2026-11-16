<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{DataDirectory,StorageProbe};
try{
    $root=DataDirectory::resolve(getenv('BOOK_DATA_DIR')?:dirname(__DIR__).'/var',dirname(__DIR__).'/public');$probe=new StorageProbe($root);$action=$argv[1]??'';
    if($action==='create'&&$argc===2){echo $probe->create()."\n";exit(0);}
    if($argc===3&&in_array($action,['verify','busy','remove'],true)){match($action){'verify'=>$probe->verify($argv[2]),'busy'=>$probe->lock($argv[2],true),'remove'=>$probe->remove($argv[2])};echo "OK\n";exit(0);}
    if($action==='hold'&&$argc===4&&ctype_digit($argv[3])){$probe->lock($argv[2],false,(int)$argv[3]);exit(0);}
    throw new InvalidArgumentException('Usage: storage-probe.php create | verify ID | hold ID SECONDS | busy ID | remove ID');
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
