<?php
declare(strict_types=1);
use CajeerBook\{AuditLog,AuditArchive,AuditSnapshot,PostgresContentStore};
try{
    require dirname(__DIR__).'/bootstrap.php';
    $cmd=$argv[1]??'';
    if($cmd==='verify-archive'&&$argc===3)$result=AuditArchive::verify($argv[2]);
    elseif($cmd==='verify-snapshot'&&$argc===3)$result=AuditSnapshot::verify($argv[2]);
    else{
        $root=PostgresContentStore::configuredRoot();
        if($cmd==='migrate'&&$argc===3&&$argv[2]==='--offline'){AuditLog::migrate($root);$result=['status'=>'ready'];}
        elseif($cmd==='check'&&$argc===2){[$pdo,$id]=AuditLog::connection($root);$result=['installation'=>$id,'streams'=>AuditLog::verify($pdo,$id)];}
        elseif($cmd==='snapshot'&&$argc===3)$result=AuditSnapshot::create($root,$argv[2]);
        elseif($cmd==='archive'&&$argc===5&&preg_match('/\A[1-9][0-9]{0,4}\z/D',$argv[3]))$result=AuditArchive::prune($root,$argv[2],(int)$argv[3],$argv[4]);
        else throw new InvalidArgumentException('Usage: php bin/audit.php migrate --offline | check | archive STREAM RETENTION_DAYS /private/new.jsonl | verify-archive FILE | snapshot /private/new.jsonl | verify-snapshot FILE');
    }
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
