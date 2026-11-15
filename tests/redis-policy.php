<?php
declare(strict_types=1);
/** No external services needed. Tests only disabled/degraded runtime and request-key policy. */
$base=dirname(__DIR__);spl_autoload_register(function($class)use($base){foreach(['CajeerBook\\'=>'src/','CajeerEngine\\'=>'core/'] as $p=>$d)if(str_starts_with($class,$p)){$f=$base.'/'.$d.str_replace('\\','/',substr($class,strlen($p))).'.php';if(is_file($f))require_once $f;}});
use CajeerBook\RedisRuntime;
function ok(bool $v,string $s):void{if(!$v)throw new RuntimeException($s);echo "PASS $s\n";}
function deny(callable $f,string $s):void{try{$f();}catch(RuntimeException|InvalidArgumentException){ok(true,$s);return;}throw new RuntimeException('Unexpected success: '.$s);}
$dir=sys_get_temp_dir().'/book-redis-policy-'.bin2hex(random_bytes(8));mkdir($dir,0700);
try{
    putenv('BOOK_REDIS_HOST');putenv('BOOK_PG_DSN');putenv('BOOK_DATA_DIR='.$dir);
    ok(!RedisRuntime::enabled()&&RedisRuntime::forRoot($dir)===null,'Redis disabled preserves file mode');
    putenv('BOOK_REDIS_HOST=127.0.0.1');putenv('BOOK_REDIS_PORT=1');
    deny(fn()=>RedisRuntime::forRoot($dir),'configured Redis requires migrated PostgreSQL identity');
    $_SERVER['REMOTE_ADDR']='2001:db8::1';$_SERVER['HTTP_X_FORWARDED_FOR']='192.0.2.1';$_SERVER['HTTP_X_BOOK_CLIENT_IP']='192.0.2.2';$ip=RedisRuntime::clientIp();
    $_SERVER['REMOTE_ADDR']='2001:0db8:0:0:0:0:0:1';ok(RedisRuntime::clientIp()===$ip&&$ip===bin2hex(inet_pton('2001:db8::1')),'canonical IP and untrusted forwarded headers ignored');
    $_SERVER['REMOTE_ADDR']='invalid';ok(RedisRuntime::clientIp()==='unknown','malformed address has bounded fallback bucket');
    $r=RedisRuntime::forBinding(str_repeat('a',32),str_repeat('b',32));$n=0;
    $compute=function()use(&$n){return ['count'=>++$n];};
    ok($r->remember('test',['a'],$compute)===['count'=>1]&&$r->remember('test',['a'],$compute)===['count'=>2],'cache outage recomputes authoritative data without stale fallback');
    deny(fn()=>$r->limit('api','ip',2,60),'unavailable limiter fails closed for caller');
    deny(fn()=>$r->limit('invalid/bucket','ip',2,60),'invalid bucket rejected');
    deny(fn()=>$r->check(),'readiness refuses unavailable Redis');
    $r->notify('legacy');$r->heartbeat(1);ok(true,'queue notifications and metrics are best effort on outage');
    deny(fn()=>$r->stats(),'operator status does not claim Redis is healthy');
}finally{putenv('BOOK_REDIS_HOST');putenv('BOOK_REDIS_PORT');rmdir($dir);}
