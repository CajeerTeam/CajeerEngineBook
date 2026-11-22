<?php
declare(strict_types=1);
// Run only as the unprivileged application user via the operator's timer/cron.
$root=dirname(__DIR__);
require $root.'/bootstrap.php';
use CajeerBook\{DataDirectory,UpdateCenter,UpdateScheduler};
try {
    if(PHP_SAPI!=='cli'||!in_array($argv[1]??'',['tick','status'],true))throw new InvalidArgumentException('Usage: php bin/update-scheduler.php tick|status');
    if(function_exists('posix_geteuid')&&posix_geteuid()===0)throw new RuntimeException('Do not run the update scheduler as root');
    $data=DataDirectory::resolve(getenv('BOOK_DATA_DIR')?:$root.'/var',$root.'/public');
    $scheduler=new UpdateScheduler(new UpdateCenter($root,$data),$data);
    $result=$argv[1]==='tick'?$scheduler->tick():$scheduler->snapshot();
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
} catch(Throwable $e) {
    // Avoid leaking GitHub API details, paths, keys, or email addresses.
    fwrite(STDERR,"Update scheduler failed (".get_class($e).")\n");exit(1);
}
