<?php
declare(strict_types=1);
require __DIR__.'/oidc-bootstrap.php';
use CajeerBook\{AutomationQueue as Q,AutomationWorker,BookStore,IdentityStore,RedisTopology,RedisRuntime,StorageProbe,WorkerLoop,Workspaces};
function ok(bool $v,string $s):void{if(!$v)throw new RuntimeException($s);echo "PASS $s\n";}
function deny(callable $f,string $s):void{try{$f();}catch(RuntimeException|InvalidArgumentException|DomainException){ok(true,$s);return;}throw new RuntimeException('Unexpected success: '.$s);}
function clean(string $p):void{if(!is_dir($p))return;foreach(new DirectoryIterator($p) as $f){if($f->isDot())continue;if($f->isDir()&&!$f->isLink())clean($f->getPathname());else unlink($f->getPathname());}rmdir($p);}
function launch(array $args):array{$p=proc_open([PHP_BINARY,__FILE__,...$args],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($p))throw new RuntimeException('Child launch failed');fclose($pipes[0]);return [$p,$pipes];}
function finish(array $child,?int $expect=0):string{[$p,$pipes]=$child;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$rc=proc_close($p);if($expect!==null&&$rc!==$expect)throw new RuntimeException('Child exit '.$rc.': '.$err);return trim($out);}
function waitFile(string $path):void{$end=microtime(true)+8;while(!is_file($path)&&microtime(true)<$end)usleep(20000);if(!is_file($path))throw new RuntimeException('Child did not reach barrier');}
if(($argv[1]??'')==='--hold'){(new StorageProbe(getenv('BOOK_DATA_DIR')))->lock($argv[2],false,3);exit;}
if(($argv[1]??'')==='--worker'){
    $r=getenv('BOOK_DATA_DIR');$dir=$argv[2];$sender=function($req)use($dir){file_put_contents($dir.'/started',$req['headers']['X-Cajeer-Delivery']);$end=microtime(true)+10;while(!is_file($dir.'/release')&&microtime(true)<$end)usleep(20000);if(!is_file($dir.'/release'))throw new RuntimeException('Barrier timed out');return ['status'=>204];};
    $w=new AutomationWorker($r,new IdentityStore($r),null,$sender);$loop=new WorkerLoop();$loop->run(fn($h,$stop)=>$w->tick($h,$stop),fn()=>($loop->pause(1)??[]));exit;
}
$root=sys_get_temp_dir().'/book-reliability-'.bin2hex(random_bytes(8));mkdir($root,0700);$child=null;
try{
    foreach(['BOOK_PG_DSN','BOOK_REDIS_HOST','BOOK_REDIS_SENTINELS','BOOK_OIDC_CONFIG','BOOK_OIDC_REQUIRED'] as $key)putenv($key);
    putenv('BOOK_DATA_DIR='.$root);putenv('BOOK_REDIS_SENTINELS=s1.example:26379,s2.example:26379,s3.example:26379');$c=RedisTopology::config();
    ok(RedisRuntime::enabled()&&count($c['nodes'])===3,'Sentinel-only configuration enables runtime');
    ok(RedisTopology::discover($c,fn($h)=>$h==='s1.example'?['old',6379]:['new','6379'])===['new',6379],'majority selects new primary over stale Sentinel');
    deny(fn()=>RedisTopology::discover($c,fn($h)=>$h==='s1.example'?['old',6379]:($h==='s2.example'?['new',6379]:null)),'no majority refuses stale primary');
    deny(fn()=>RedisTopology::discover($c,fn()=>['https://attacker.example',6379]),'invalid advertised endpoint refused');
    putenv('BOOK_REDIS_SENTINELS=s1:26379,s1:26379,s2:26379');deny(fn()=>RedisTopology::config(),'duplicate Sentinel nodes rejected');
    putenv('BOOK_REDIS_SENTINELS=s1:26379,s2:26379');deny(fn()=>RedisTopology::config(),'even or insufficient Sentinel quorum rejected');
    // No socket is needed: check() is rejected before dialing while the retry deadline is active.
    // This reproduces the hot polling loop from the real Sentinel failover gate.
    $runtime=RedisRuntime::forBinding(str_repeat('c',32),str_repeat('d',32));
    $deadline=new ReflectionProperty(RedisRuntime::class,'retryAt');$until=hrtime(true)/1e9+60;
    $deadline->setValue($runtime,$until);
    for($attempt=0;$attempt<8;$attempt++){
        try{$runtime->check();throw new LogicException('Unexpected Redis success during cooldown');}catch(RuntimeException){}
    }
    ok($deadline->getValue($runtime)===$until,'frequent Redis retries cannot extend active cooldown');
    // The invalid Sentinel config fails locally after the deadline, even without ext-redis.
    $expired=hrtime(true)/1e9-1;$deadline->setValue($runtime,$expired);
    deny(fn()=>$runtime->check(),'new Redis connection failure after cooldown is rejected');
    ok($deadline->getValue($runtime)>hrtime(true)/1e9,'new Redis failure starts a fresh bounded cooldown');
    putenv('BOOK_REDIS_SENTINELS');
    $probe=new StorageProbe($root);$id=$probe->create();$probe->verify($id);$child=launch(['--hold',$id]);$line=fgets($child[1][1]);ok(trim((string)$line)==='LOCKED','independent process holds shared storage lock');$probe->lock($id,true);ok(true,'cross-process POSIX exclusion verified');finish($child);$child=null;$probe->remove($id);deny(fn()=>$probe->verify($id),'removed storage probe cannot be reused');
    putenv('BOOK_AUTOMATION_KEY='.str_repeat('ab',32));putenv('BOOK_WEBHOOK_ORIGINS=https://receiver.example.org');
    $i=new IdentityStore($root);$owner=$i->initialize('owner','Owner','owner@example.org','password-owner-123');$token=$i->login('owner','password-owner-123','192.0.2.1')['token'];$org=$i->createOrganization($token,'Team','team');$stores=[];
    foreach(['alpha','beta'] as $slug){$w=$i->createWorkspace($token,$org['id'],ucfirst($slug),$slug);$s=new BookStore(Workspaces::directory($root,$w),false);$s->change(fn(&$d)=>Q::configure($d,$owner['id'],'','','https://receiver.example.org/hook',str_repeat('secret-',6),['document.changed']));$s->change(fn(&$d)=>Q::enqueueEvent($d,'document.changed'));$stores[]=$s;}
    $jobs=fn()=>array_map(fn($s)=>array_values($s->read()['automation']['jobs'])[0],$stores);
    $barrier=$root.'/barrier';mkdir($barrier,0700);$child=launch(['--worker',$barrier]);waitFile($barrier.'/started');$first=file_get_contents($barrier.'/started');proc_terminate($child[0],SIGTERM);usleep(150000);file_put_contents($barrier.'/release','go');finish($child);$child=null;
    $rows=$jobs();ok(count(array_filter($rows,fn($j)=>$j['state']==='succeeded'))===1&&count(array_filter($rows,fn($j)=>$j['state']==='queued'))===1,'SIGTERM finishes current job without claiming next scope');
    unlink($barrier.'/started');unlink($barrier.'/release');$child=launch(['--worker',$barrier]);waitFile($barrier.'/started');$second=file_get_contents($barrier.'/started');$rows=$jobs();$running=array_values(array_filter($rows,fn($j)=>$j['state']==='running'))[0];proc_terminate($child[0],SIGKILL);finish($child,null);$child=null;
    $seen=[];$w=new AutomationWorker($root,$i,null,function($req)use(&$seen){$seen[]=$req['headers']['X-Cajeer-Delivery'];return ['status'=>204];});ok($w->tick()===1&&$seen===[$second]&&$first!==$second,'killed worker job reclaimed with same delivery id');
    $rows=$jobs();ok(count(array_filter($rows,fn($j)=>$j['state']==='succeeded'))===2&&max(array_column($rows,'attempts'))===2,'durable queue completes after crash without lost job');
    foreach($stores as $s)if(isset($s->read()['automation']['jobs'][$running['id']]))deny(fn()=>$s->change(fn(&$d)=>Q::finish($d,$running,'succeeded')),'old lease cannot overwrite recovered completion');
}finally{if($child!==null){proc_terminate($child[0],SIGKILL);finish($child,null);}clean($root);}
