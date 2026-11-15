<?php
declare(strict_types=1);
use CajeerBook\{PostgresContentStore,InstallationBackup};
try{
    require dirname(__DIR__).'/bootstrap.php';
    $root=PostgresContentStore::configuredRoot();$cmd=$argv[1]??'';
    if($cmd==='migrate'&&$argc===3&&$argv[2]==='--offline')$result=PostgresContentStore::migrate($root);
    elseif($cmd==='check'&&$argc===2){if(!getenv('BOOK_PG_DSN'))throw new RuntimeException('BOOK_PG_DSN required.');$result=InstallationBackup::audit($root,dirname(__DIR__).'/public');}
    else throw new InvalidArgumentException('Usage: php bin/postgres-content.php migrate --offline | check');
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
