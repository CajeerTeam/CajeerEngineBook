<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{AutomationWorker,DataDirectory,IdentityStore};
if($argc!==2||!in_array($argv[1],['--once','--loop'],true)){fwrite(STDERR,"Usage: php bin/worker.php --once|--loop\n");exit(2);}
$root=dirname(__DIR__);$dir=DataDirectory::resolve(getenv('BOOK_DATA_DIR')?:$root.'/var',$root.'/public');$worker=new AutomationWorker($dir,new IdentityStore($dir));
do{try{$n=$worker->tick();if($argv[1]==='--once'){echo "Processed $n job(s)\n";break;}if(!$n)sleep(2);}catch(Throwable $e){error_log('Automation worker: '.get_class($e));if($argv[1]==='--once')exit(1);sleep(5);}}while(true);
