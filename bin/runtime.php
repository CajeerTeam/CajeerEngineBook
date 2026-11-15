<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{PostgresContentStore,RedisRuntime,IdentityStore};
if($argc!==2||!in_array($argv[1],['check','status'],true)){fwrite(STDERR,"Usage: php bin/runtime.php check|status\n");exit(2);}
try{
    $root=PostgresContentStore::configuredRoot();$binding=PostgresContentStore::identityBinding($root);
    if(!$binding)throw new RuntimeException('PostgreSQL required.');
    $pdo=PostgresContentStore::runtimeConnect();$q=$pdo->prepare('SELECT binding FROM cajeer_book.content_installation WHERE installation=?');$q->execute([$binding[0]]);if($q->fetchColumn()!==$binding[1])throw new RuntimeException('Installation binding mismatch.');
    (new IdentityStore($root))->checkReady();
    $redis=RedisRuntime::forRoot($root);if(!$redis)throw new RuntimeException('Redis not configured.');$redis->check();
    if($argv[1]==='check'){echo "Distributed runtime ready\n";exit(0);}
    $q=$pdo->prepare("SELECT value->>'state' AS state,count(*) AS count FROM cajeer_book.automation_jobs WHERE installation=? GROUP BY state ORDER BY state");$q->execute([$binding[0]]);$counts=[];foreach($q as $r)$counts[$r['state']]=(int)$r['count'];
    echo json_encode(['status'=>'ok','queue'=>$counts,'redis'=>$redis->stats()],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";
}catch(Throwable $e){fwrite(STDERR,'Runtime unavailable: '.$e->getMessage()."\n");exit(1);}
