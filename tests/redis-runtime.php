<?php
declare(strict_types=1);
/** Real PostgreSQL + Redis integration. Child processes are independent worker instances. */
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{AutomationQueue as Q,AutomationWorker,BookStore,IdentityStore,PostgresContentStore,RedisRuntime,Workspaces};
function ok(bool $v,string $s):void{if(!$v)throw new RuntimeException($s);echo "PASS $s\n";}
function clean(string $p):void{if(!is_dir($p))return;foreach(new DirectoryIterator($p) as $f){if($f->isDot())continue;if($f->isDir()&&!$f->isLink())clean($f->getPathname());else unlink($f->getPathname());}rmdir($p);}
if(($argv[1]??'')==='--wait'){echo json_encode(RedisRuntime::forRoot(getenv('BOOK_DATA_DIR'))->wait(),JSON_THROW_ON_ERROR)."\n";exit(0);}
if(($argv[1]??'')==='--worker'){
    $root=getenv('BOOK_DATA_DIR');$dir=$argv[2];$scope=$argv[3];$barrier=($argv[4]??'')==='barrier';
    $sender=function(array $request)use($dir,$barrier){$id=$request['headers']['X-Cajeer-Delivery'];file_put_contents($dir.'/'.$id.'.start',"sent\n",FILE_APPEND|LOCK_EX);
        if($barrier){$deadline=microtime(true)+10;while(count(glob($dir.'/*.start'))<2&&microtime(true)<$deadline)usleep(25000);if(count(glob($dir.'/*.start'))<2)throw new RuntimeException('Parallel worker barrier timed out');}
        return ['status'=>204];};
    echo (new AutomationWorker($root,new IdentityStore($root),null,$sender))->tick([$scope])."\n";exit(0);
}
function launch(array $args,?array $env=null):array{
    $p=proc_open([PHP_BINARY,__FILE__,...$args],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__),$env);if(!is_resource($p))throw new RuntimeException('Cannot start worker');fclose($pipes[0]);return [$p,$pipes];
}
function finish(array $child):string{[$p,$pipes]=$child;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0)throw new RuntimeException('Child failed: '.$err);return trim($out);}
$dsn=getenv('BOOK_PG_DSN');$host=getenv('BOOK_REDIS_HOST');if(!$dsn||!$host)throw new RuntimeException('Real PostgreSQL and Redis required.');
$root=sys_get_temp_dir().'/book-redis-'.bin2hex(random_bytes(8));$ns=bin2hex(random_bytes(16));$log=$root.'/test-deliveries';
putenv('BOOK_OBSERVABILITY=1');putenv('BOOK_DATA_DIR='.$root);putenv('BOOK_PG_NAMESPACE='.$ns);putenv('BOOK_PG_DSN');putenv('BOOK_REDIS_HOST');putenv('BOOK_AUTOMATION_KEY='.str_repeat('ab',32));putenv('BOOK_WEBHOOK_ORIGINS=https://receiver.example.org');
try{
    $identity=new IdentityStore($root);$owner=$identity->initialize('owner','Owner','owner@example.org','password-owner-123');$token=$identity->login('owner','password-owner-123','192.0.2.1')['token'];$org=$identity->createOrganization($token,'Team','team');$workspaces=[];
    foreach(['alpha','beta'] as $slug)$workspaces[]=$identity->createWorkspace($token,$org['id'],ucfirst($slug),$slug);
    putenv('BOOK_PG_DSN='.$dsn);PostgresContentStore::migrate($root);putenv('BOOK_REDIS_HOST='.$host);
    $redis=RedisRuntime::forRoot($root);$redis->check();ok(true,'real Redis connection, Lua, cache and stream readiness');
    $b=PostgresContentStore::identityBinding($root);$prefix='book:044:'.hash('sha256',$b[0].':'.$b[1]).':';
    $raw=new Redis();$raw->connect($host,(int)(getenv('BOOK_REDIS_PORT')?:6379));if($password=getenv('BOOK_REDIS_PASSWORD'))$raw->auth(getenv('BOOK_REDIS_USER')?[getenv('BOOK_REDIS_USER'),$password]:$password);
    $n=0;$compute=function()use(&$n){return ['n'=>++$n];};
    ok($redis->remember('test',['same'],$compute)===['n'=>1]&&$redis->remember('test',['same'],$compute)===['n'=>1]&&$n===1,'shared cache hit bypasses computation');
    $other=RedisRuntime::forBinding(bin2hex(random_bytes(16)),bin2hex(random_bytes(16)));ok($other->remember('test',['same'],$compute)===['n'=>2],'cache is installation-bound');
    $key=$prefix.'cache:test:'.hash('sha256',json_encode(['same'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));$raw->set($key,str_repeat('0',64).':{"n":999}');
    ok($redis->remember('test',['same'],$compute)===['n'=>3],'tampered cache entry is ignored and repaired');
    $subject=bin2hex(random_bytes(8));ok($redis->limit('test',$subject,2,2)['allowed']&&$redis->limit('test',$subject,2,2)['allowed']&&!$redis->limit('test',$subject,2,2)['allowed'],'atomic fixed-window limit');sleep(3);ok($redis->limit('test',$subject,2,2)['allowed'],'rate limit expires');
    $stores=[];$scopes=[];foreach($workspaces as $w){$dir=Workspaces::directory($root,$w);$stores[]=new BookStore($dir,false);$scopes[]=$w['organization'].'/'.$w['id'];}
    mkdir($log,0700); // test output only, never part of a portable backup
    $out=str_repeat('out-secret-',4);$ids=[];
    foreach($stores as $store){$store->change(fn(&$d)=>Q::configure($d,$owner['id'],'','','https://receiver.example.org/hook',$out,['document.changed']));$store->change(fn(&$d)=>Q::enqueueEvent($d,'document.changed'));$ids[]=array_key_last($store->read()['automation']['jobs']);}
    $hints=$redis->wait();ok(count(array_intersect($scopes,$hints))===2&&$redis->stats()['stream_length']>=2,'committed jobs emit Redis stream wakeups with workspace hints');
    ok(json_decode(finish(launch(['--wait'])),true)===[],'consumer group does not replay already delivered hints on another process');
    $redis->notify($scopes[0]);ok(json_decode(finish(launch(['--wait'])),true)===[$scopes[0]],'a second process receives a new Redis group notification');
    $before=$redis->stats()['metrics']['notifications'];$pg=PostgresContentStore::forDirectory(Workspaces::directory($root,$workspaces[0]));
    try{$pg->transaction(true,function($old)use($pg){$new=$old;Q::enqueueEvent($new,'document.changed');$pg->write($old,$new);throw new DomainException('rollback');});throw new RuntimeException('Expected rollback');}catch(DomainException){}
    ok($redis->stats()['metrics']['notifications']===$before&&count($stores[0]->read()['automation']['jobs'])===1,'rolled-back SQL job emits no Redis notification');
    $a=launch(['--worker',$log,$scopes[0],'barrier']);$c=launch(['--worker',$log,$scopes[1],'barrier']);$n=(int)finish($a)+(int)finish($c);
    ok($n===2,'two independent workers process distinct scopes concurrently');
    foreach($stores as $i=>$store){$j=$store->read()['automation']['jobs'][$ids[$i]];ok($j['state']==='succeeded'&&$j['attempts']===1&&file_get_contents($log.'/'.$ids[$i].'.start')==="sent\n",'one delivery and one SQL completion per scope');}
    $s=$stores[0];$s->change(fn(&$d)=>Q::enqueueEvent($d,'document.changed'));$claimed=null;$s->change(function(&$d)use(&$claimed){$claimed=Q::claim($d,time());});$s->change(fn(&$d)=>Q::enqueueEvent($d,'document.changed'));$later=array_key_last($s->read()['automation']['jobs']);
    ok(finish(launch(['--worker',$log,$scopes[0]]))==='0','unexpired claim is not reclaimed or bypassed by another worker');
    $s->change(function(&$d)use($claimed,$later){$d['automation']['jobs'][$claimed['id']]['lease_until']=time()-1;$d['automation']['jobs'][$later]['due']=time()+60;});
    ok(finish(launch(['--worker',$log,$scopes[0]]))==='1'&&$s->read()['automation']['jobs'][$claimed['id']]['attempts']===2,'expired claim recovered with a fresh fenced lease');
    try{$s->change(fn(&$d)=>Q::finish($d,$claimed,'succeeded'));throw new RuntimeException('Stale completion accepted');}catch(DomainException){ok(true,'stale worker cannot finish re-leased job');}
    // Drop the entire notification stream; SQL jobs remain runnable even with Redis inaccessible.
    $s->change(fn(&$d)=>$d['automation']['jobs'][$later]['due']=time());$raw->del($prefix.'jobs');$env=getenv();$env['BOOK_REDIS_PORT']='1';$env['BOOK_REDIS_HOST']='127.0.0.1';
    ok(finish(launch(['--worker',$log,$scopes[0]],$env))==='1','SQL polling processes durable job with Redis stream deleted and connection unavailable');
    $j=array_values($s->read()['automation']['jobs']);ok(count(array_filter($j,fn($r)=>$r['state']!=='succeeded'))===0,'all jobs remain durably completed after Redis outage');
    $stats=$redis->stats()['metrics'];ok(($stats['cache_hits']??0)>=1&&($stats['rate_denied']??0)>=1&&($stats['jobs_processed']??0)>=2,'operator metrics reflect real cache, rate and worker activity');
    CajeerBook\Observability::flush();putenv('BOOK_MONITOR_WORKERS=1');$diagnostics=(new CajeerBook\Diagnostics())->collect();$metrics=$diagnostics['metrics'];
    ok($diagnostics['checks']['postgres']==='ok'&&$diagnostics['checks']['redis']==='ok'&&$diagnostics['checks']['queue']==='ok'&&$diagnostics['checks']['search']==='ok'&&$diagnostics['checks']['audit']==='ok','real SQL and Redis probes expose independent component health');
    ok($metrics['queue_jobs{state="succeeded"}']>=4&&$metrics['redis_cache_hits_total']>=1&&$metrics['worker_jobs_processed_total']>=4&&$metrics['redis_memory_used_bytes']>0,'actual queue, Redis resource and worker metrics');
    ok(($metrics['operation_duration_seconds_count{operation="sql_content_lock",outcome="ok"}']??0)>0&&($metrics['operation_duration_seconds_count{operation="sql_identity_query",outcome="ok"}']??0)>0,'real SQL lock and query timing');
    $raw->close();
}finally{
    CajeerBook\Observability::flush();putenv('BOOK_OBSERVABILITY');
    putenv('BOOK_PG_DSN='.$dsn);putenv('BOOK_REDIS_HOST='.$host);try{$pdo=PostgresContentStore::connect();$q=$pdo->prepare('DELETE FROM cajeer_book.content_installation WHERE installation=?');$q->execute([$ns]);}catch(Throwable $e){fwrite(STDERR,'Cleanup: '.$e->getMessage()."\n");}clean($root);
}
