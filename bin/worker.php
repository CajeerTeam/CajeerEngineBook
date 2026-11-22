<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{AutomationWorker,DataDirectory,IdentityStore,RedisRuntime,Observability,WorkerLoop,SearchIndexer};
if($argc!==2||!in_array($argv[1],['--once','--loop','--check'],true)){fwrite(STDERR,"Usage: php bin/worker.php --once|--loop|--check\n");exit(2);}
try{
    $root=dirname(__DIR__);$dir=DataDirectory::resolve(getenv('BOOK_DATA_DIR')?:$root.'/var',$root.'/public');
    $identity=new IdentityStore($dir);$redis=RedisRuntime::forRoot($dir);
    if($argv[1]==='--check'){$identity->checkReady();if($redis)$redis->check();echo "Worker dependencies ready\n";exit(0);}
    $worker=new AutomationWorker($dir,$identity);
    $index=static function()use($dir):int{try{return SearchIndexer::tick($dir);}catch(Throwable $e){Observability::log('search.indexing_pending',['error_type'=>get_class($e)]);return 0;}};
    if($argv[1]==='--once'){$jobs=$worker->tick();$vectors=$index();echo 'Processed '.$jobs.' job(s), indexed '.$vectors." document(s)\n";exit(0);}
    $loop=new WorkerLoop();$loop->run(fn($hints,$stop)=>$worker->tick($hints,$stop)+$index(),function()use($redis,$loop){if($redis)return $redis->wait();$loop->pause(2);return [];});
}catch(Throwable $e){Observability::log('worker.loop_failure',['error_type'=>get_class($e)]);fwrite(STDERR,"Worker unavailable\n");exit(1);}
