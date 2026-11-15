<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{BookStore,PostgresContentStore,IdentityStore,Workspaces,MediaStore,InstallationBackup,CiPublishing,CiController,ReleaseArtifact,Visibility};

function ok(bool $v,string $label):void{if(!$v)throw new RuntimeException($label);echo "PASS $label\n";}
function deny(callable $fn,string $label):void{try{$fn();}catch(RuntimeException|DomainException|InvalidArgumentException){ok(true,$label);return;}throw new RuntimeException('Unexpected success: '.$label);}
function clean(string $p):void{if(!is_dir($p))return;foreach(new DirectoryIterator($p) as $f){if($f->isDot())continue;if($f->isDir()&&!$f->isLink())clean($f->getPathname());else unlink($f->getPathname());}rmdir($p);}
if(!getenv('BOOK_PG_DSN'))throw new RuntimeException('Real PostgreSQL required for this test.');
$dsn=getenv('BOOK_PG_DSN');$base=sys_get_temp_dir().'/book-pg-'.bin2hex(random_bytes(8));mkdir($base,0700);$root=$base.'/data';$ns=bin2hex(random_bytes(16));$restoreNs=bin2hex(random_bytes(16));$public=dirname(__DIR__).'/public';
putenv('BOOK_DATA_DIR='.$root);putenv('BOOK_PG_NAMESPACE='.$ns);putenv('BOOK_PG_DSN');
$actor=['id'=>str_repeat('a',32),'username'=>'owner','role'=>'owner'];$admins=[$actor['id']=>$actor];$token='';$scope='guide/1';$key=$scope.'/start';
try{
    // Build actual legacy data and a second, private workspace before any PostgreSQL setup.
    $identity=new IdentityStore($root);$identity->initialize('owner','Owner','owner@example.org','password-owner-123');$session=$identity->login('owner','password-owner-123','192.0.2.1')['token'];
    $org=$identity->createOrganization($session,'Organization','org');$w=$identity->createWorkspace($session,$org['id'],'Private','private');$workspace=Workspaces::directory($root,$w);
    $legacy=new BookStore($root);$legacy->materialize();$source=new BookStore($workspace,false);$source->setHistoryActor($actor);$media=new MediaStore($workspace,$source);
    $file=$base.'/attachment.txt';file_put_contents($file,'PostgreSQL attachment');$mid=$media->upload($file,'attachment.txt')['item']['id'];
    $input=['book'=>'guide','version'=>'1','path'=>'start','title'=>'Start','section'=>'Guide','order'=>10,'body'=>"# Start\n\n[File](/media/$mid)",'revision'=>0];
    $saved=$source->saveResult($input,true);$input['revision']=$saved['revision'];$input['body'].="\n\nDraft text";$source->saveResult($input,false);
    $source->change(function(&$d)use($actor,$scope,&$token){$token=CiPublishing::issue($d,$actor,$scope,'migration',7,true);});
    $call=fn($action)=>CiController::execute($source,$media,$admins,$token,$action);
    $state=$call(['action'=>'state']);$build=$call(['action'=>'build','expected'=>$state['fingerprint'],'request'=>'legacy-build']);
    $original=$source->read();$legacyData=$legacy->read();$originalBytes=file_get_contents($workspace.'/books.json');
    $source->checkReady();InstallationBackup::create($root,$base.'/before',$public);
    putenv('BOOK_PG_DSN='.$dsn);
    deny(fn()=>(new BookStore($workspace,false))->read(),'configured PostgreSQL cannot silently read JSON before migration');
    file_put_contents($media->blob($mid),'corrupt');clearstatcache(true,$media->blob($mid));
    deny(fn()=>PostgresContentStore::migrate($root),'corrupt legacy media prevents migration');
    $pdo=PostgresContentStore::connect();$q=$pdo->prepare('SELECT count(*) FROM cajeer_book.content_installation WHERE installation=?');$q->execute([$ns]);ok((int)$q->fetchColumn()===0&&!file_exists($root.'/postgres.required'),'failed multi-workspace migration rolls back all content and leaves no cutover marker');
    file_put_contents($media->blob($mid),'PostgreSQL attachment');clearstatcache(true,$media->blob($mid));
    $report=PostgresContentStore::migrate($root);ok(count($report)===2&&$report[$org['id'].'/'.$w['id']]['media']===1,'all registered scopes migrated together');
    ok($identity->current($session)!==null&&$identity->backup()['workspaces'][$w['id']]['id']===$w['id'],'direct migration preserves SQL identity and live session');
    unlink($root.'/identity/identity.json');ok($identity->current($session)!==null,'direct migration no longer reads file identity');
    $store=new BookStore($workspace,false);$pgMedia=new MediaStore($workspace,$store);
    ok($store->read()===$original&&(new BookStore($root))->read()===$legacyData,'exact PHP snapshot and legacy CI digest round-trip');
    $pdo=PostgresContentStore::connect();$q=$pdo->prepare('SELECT count(*) FROM cajeer_book.pages WHERE installation=? AND scope=?');$q->execute([$ns,$org['id'].'/'.$w['id']]);ok((int)$q->fetchColumn()===1,'page persisted as an independent PostgreSQL row');
    $q=$pdo->prepare('SELECT value::text FROM cajeer_book.publications WHERE installation=? AND scope=? AND record_key=?');$q->execute([$ns,$org['id'].'/'.$w['id'],$build['id']]);ok(json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR)['digest']===$build['digest'],'CI build persisted as independent PostgreSQL row');
    $call=fn($action)=>CiController::execute($store,$pgMedia,$admins,$token,$action);
    ok($call(['action'=>'status','id'=>$build['id']])['digest']===$build['digest'],'migrated CI build remains valid');
    $published=$call(['action'=>'publish','id'=>$build['id'],'digest'=>$build['digest']]);ok($published['status']==='published'&&str_contains(BookStore::published($store->read())[$key]['body'],'Draft text'),'real CI publication and history committed through PostgreSQL');
    ok(count($store->read()['history'])>count($original['history'])&&file_get_contents($workspace.'/books.json')===$originalBytes,'history updated without touching legacy JSON');
    $opened=$pgMedia->open($mid,false);ok($opened!==null&&stream_get_contents($opened[1])==='PostgreSQL attachment','published media served from immutable bytes using SQL metadata');fclose($opened[1]);
    $pgMedia->update($mid,1,'renamed.txt','attachments');ok($store->read()['media'][$mid]['name']==='renamed.txt','media metadata update persists through SQL');
    deny(fn()=>$pgMedia->delete($mid,2),'published and historical media cannot be deleted');
    $before=$store->read();
    $binding=explode(':',trim(file_get_contents($root.'/postgres.required')))[1];$rawPg=new PostgresContentStore($ns,$org['id'].'/'.$w['id'],$binding);
    deny(fn()=>$rawPg->transaction(true,function($old)use($rawPg){$new=$old;$new['books']['rollback']=['slug'=>'rollback','title'=>'Rollback','id'=>bin2hex(random_bytes(16))];$rawPg->write($old,$new);throw new DomainException('abort after SQL write');}),'exception after real SQL write rolls back');
    ok($store->read()===$before,'SQL rollback removes inserted row and preserves previous snapshot');
    deny(fn()=>$store->change(function(&$d){$d['pages']=[];throw new DomainException('rollback');}),'callback failure rolls back');ok($store->read()===$before,'rollback preserves entire content state');
    // Remove JSON to establish that neither reads nor writes use it after cutover.
    unlink($workspace.'/books.json');$store->createBook('sql-only','SQL only');ok(isset((new BookStore($workspace,false))->read()['books']['sql-only'])&&!file_exists($workspace.'/books.json'),'fresh connections read and write without JSON');
    $w2=$identity->createWorkspace($session,$org['id'],'New','new');$d2=Workspaces::directory($root,$w2);$s2=new BookStore($d2,false);$s2->createBook('guide','Isolated');ok(!file_exists($d2.'/books.json')&&$s2->read()['pages']===[]&&$s2->read()['books']['guide']['title']==='Isolated','new workspace starts directly in PostgreSQL with isolated data');
    ok($pgMedia->open(str_repeat('f',32),true)===null&&(new MediaStore($d2,$s2))->open($mid,true)===null,'no cross-workspace media lookup');
    $missing=$root.'/workspaces/'.$org['id'].'/'.str_repeat('f',32);deny(fn()=>(new BookStore($missing,false))->read(),'missing SQL scope fails closed');
    $code='require $argv[1]."/bootstrap.php"; $s=new CajeerBook\\BookStore($argv[2],false); echo "started\\n";flush(); $s->createBook("parallel","Parallel"); echo "done\\n";';
    $proc=null;$pipes=[];
    $store->change(function(&$d)use(&$proc,&$pipes,$code,$workspace){
        $proc=proc_open([PHP_BINARY,'-r',$code,dirname(__DIR__),$workspace],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('Child unavailable');fclose($pipes[0]);stream_set_timeout($pipes[1],10);
        ok(trim((string)fgets($pipes[1]))==='started','independent writer started');usleep(300000);ok(proc_get_status($proc)['running'],'SQL row lock blocks competing writer');
        $d['books']['first']=['slug'=>'first','title'=>'First','id'=>bin2hex(random_bytes(16))];
    });
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($proc);ok($status===0&&trim($out)==='done','parallel writer completes after commit: '.$err);
    $current=$store->read();ok(isset($current['books']['first'],$current['books']['parallel']),'no lost update after concurrent SQL writes');
    $stale=$input;$stale['revision']=1;deny(fn()=>$store->saveResult($stale,false),'optimistic stale page revision rejected');
    $identity->checkWorkspaceStores($root);ok(true,'readiness validates SQL and media');
    // A namespace cannot attach to another installation even if its initial content is identical.
    putenv('BOOK_DATA_DIR='.$base.'/collision');deny(fn()=>PostgresContentStore::migrate($base.'/collision'),'namespace reuse by another installation denied');putenv('BOOK_DATA_DIR='.$root);
    $backup=$base.'/after';InstallationBackup::create($root,$backup,$public);InstallationBackup::restore($backup,$base.'/restored',$public);
    putenv('BOOK_DATA_DIR='.$base.'/restored');putenv('BOOK_PG_NAMESPACE='.$restoreNs);PostgresContentStore::migrate($base.'/restored');
    $restored=new BookStore(Workspaces::directory($base.'/restored',$w),false);ok($restored->read()['ci']===$current['ci']&&$restored->read()['history']===$current['history'],'portable backup restored to new PostgreSQL namespace');
    putenv('BOOK_DATA_DIR='.$root);putenv('BOOK_PG_NAMESPACE='.$ns);putenv('BOOK_PG_DSN');deny(fn()=>(new BookStore($workspace,false))->read(),'missing DSN refuses stale file fallback after migration');putenv('BOOK_PG_DSN='.$dsn);
    putenv('BOOK_PG_NAMESPACE='.str_repeat('0',32));deny(fn()=>(new BookStore($workspace,false))->read(),'wrong namespace rejected before connection');putenv('BOOK_PG_NAMESPACE='.$ns);
    // No external services are needed for this connection refusal.
    putenv('BOOK_PG_DSN=pgsql:host=127.0.0.1;port=1;dbname=book;connect_timeout=1');deny(fn()=>(new BookStore($workspace,false))->read(),'database outage fails closed');putenv('BOOK_PG_DSN='.$dsn);
}finally{
    putenv('BOOK_PG_DSN='.$dsn);try{$pdo=PostgresContentStore::connect();$q=$pdo->prepare('DELETE FROM cajeer_book.content_installation WHERE installation IN (?,?)');$q->execute([$ns,$restoreNs]);}catch(Throwable $e){fwrite(STDERR,'Test cleanup: '.$e->getMessage()."\n");}clean($base);
}
