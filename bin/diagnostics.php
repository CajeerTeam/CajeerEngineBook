<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\Diagnostics;
if($argc!==2||!in_array($argv[1],['status','check','metrics'],true)){fwrite(STDERR,"Usage: php bin/diagnostics.php status|check|metrics\n");exit(2);}
try{$d=(new Diagnostics())->collect();echo $argv[1]==='metrics'?Diagnostics::prometheus($d):json_encode($d,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";exit($argv[1]==='check'&&$d['status']!=='ok'?1:0);}catch(Throwable $e){fwrite(STDERR,"Diagnostics unavailable\n");exit(1);}
