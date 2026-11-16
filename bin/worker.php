<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{AutomationWorker,DataDirectory,IdentityStore,RedisRuntime,Observability,WorkerLoop};
if($argc!==2||!in_array($argv[1],['--once','--loop'],true)){fwrite(STDERR,"Usage: php bin/worker.php --once|--loop\n");exit(2);}
try{
    $root=dirname(__DIR__);$dir=DataDirectory::resolve(getenv('BOOK_DATA_DIR')?:$root.'/var',$root.'/public');
    $worker=new AutomationWorker($dir,new IdentityStore($dir));$redis=RedisRuntime::forRoot($dir);
    if($argv[1]==='--once'){echo 'Processed '.$worker->tick()." job(s)\n";exit(0);}
    $loop=new WorkerLoop();$loop->run(fn($hints,$stop)=>$worker->tick($hints,$stop),function()use($redis,$loop){if($redis)return $redis->wait();$loop->pause(2);return [];});
}catch(Throwable $e){Observability::log('worker.loop_failure',['error_type'=>get_class($e)]);fwrite(STDERR,"Worker unavailable\n");exit(1);}
