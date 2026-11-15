<?php
declare(strict_types=1);
use CajeerBook\PostgresContentStore;
try{
    require dirname(__DIR__).'/bootstrap.php';
    $root=PostgresContentStore::configuredRoot();
    if($argc===3&&$argv[1]==='reindex'&&$argv[2]==='--offline')$result=PostgresContentStore::reindex($root);
    elseif($argc===2&&$argv[1]==='check')$result=PostgresContentStore::searchStatus($root);
    else throw new InvalidArgumentException('Usage: php bin/search.php reindex --offline | check (stop writers before reindex)');
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
