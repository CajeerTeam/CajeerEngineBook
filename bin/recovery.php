<?php
declare(strict_types=1);
use CajeerBook\RecoveryManager;
try{
    require dirname(__DIR__).'/bootstrap.php';
    umask(0077);
    $manager=new RecoveryManager(dirname(__DIR__).'/public');$cmd=$argv[1]??'';
    $emit=static fn(array $r)=>print(json_encode($r,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
    if($cmd==='run'&&$argc===2)$emit($manager->run(true));
    elseif($cmd==='tick'&&$argc===2)$emit($manager->run());
    elseif($cmd==='status'&&$argc===2)$emit($manager->status());
    elseif($cmd==='health'&&$argc===3&&ctype_digit($argv[2])){$r=$manager->health((int)$argv[2]);$emit($r);exit($r['status']==='ok'?0:1);}
    elseif($cmd==='drill'&&($argc===3||($argc===4&&$argv[3]==='--remote')))$emit($manager->drill($argv[2],$argc===4));
    elseif($cmd==='restore'&&($argc===4||($argc===5&&$argv[4]==='--remote')))$emit($manager->restore($argv[2],$argv[3],$argc===5));
    elseif($cmd==='loop'&&$argc===2){while(true){try{$r=$manager->run();if($r['status']!=='not_due')$emit($r);}catch(Throwable $e){fwrite(STDERR,'Recovery tick failed: '.$e->getMessage()."\n");}sleep(60);}}
    else throw new InvalidArgumentException("Usage: php bin/recovery.php run | tick | status | health MAX_AGE_SECONDS | loop | drill ID [--remote] | restore ID NEW_DIRECTORY [--remote]");
}catch(Throwable $e){fwrite(STDERR,'Recovery failed: '.$e->getMessage()."\n");exit(1);}
