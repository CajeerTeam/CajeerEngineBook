<?php
declare(strict_types=1);
/** Disposable corpus, through real identity/content/media transactions. Never use a production root. */
require __DIR__.'/oidc-bootstrap.php';
use CajeerBook\{BookStore,IdentityStore,Workspaces,MediaStore,Locale,PostgresContentStore,Observability};
if(PHP_SAPI!=='cli'||getenv('BOOK_BENCHMARK_DISPOSABLE')!=='1')throw new RuntimeException('Use performance-benchmark.py on a disposable installation.');
$root=getenv('BOOK_DATA_DIR');if(!$root||!is_dir($root)||!is_file($root.'/.benchmark-disposable'))throw new RuntimeException('Missing disposable root.');
$action=$argv[1]??'';
if($action==='prepare'){
    $pdo=PostgresContentStore::connect();$name=$pdo->query('SELECT current_database()')->fetchColumn();
    if(!preg_match('/\Abook_benchmark(?:_[a-z0-9_]+)?\z/D',(string)$name))throw new RuntimeException('Use a dedicated disposable database named book_benchmark or book_benchmark_*');
    if($pdo->query("SELECT to_regclass('cajeer_book.content_installation') IS NOT NULL")->fetchColumn()&&$pdo->query('SELECT 1 FROM cajeer_book.content_installation LIMIT 1')->fetchColumn()!==false)throw new RuntimeException('Benchmark database already contains an installation.');
    $settings=$pdo->query("SELECT current_setting('server_version_num') AS version,current_setting('shared_buffers') AS shared_buffers,current_setting('work_mem') AS work_mem,current_setting('max_connections') AS max_connections")->fetch(PDO::FETCH_ASSOC);
    unset($pdo);PostgresContentStore::migrate($root);echo json_encode($settings,JSON_THROW_ON_ERROR);exit;
}
if($action==='analyze'){
    $pdo=PostgresContentStore::runtimeConnect();$pdo->exec('ANALYZE cajeer_book.content_record, cajeer_book.pages, cajeer_book.content_section, cajeer_book.search_document, cajeer_book.identity_workspaces, cajeer_book.audit_event');exit;
}
if($action==='ci-fixture'){
    // Separate unpublished batch; the measured Reader corpus is left unchanged.
    $store=new BookStore($root);$store->change(function(&$db){
        $db['books']['benchmark-ci']=['slug'=>'benchmark-ci','id'=>bin2hex(random_bytes(16)),'title'=>'Benchmark CI'];
        for($i=0;$i<100;$i++){$key=sprintf('benchmark-ci/1/topic-%03d',$i);$title='CI benchmark topic '.$i;$p=['title'=>$title,'section'=>'CI benchmark','order'=>$i,'body'=>"# $title\n\nCI benchmark publication $i.\n\n## Deployment\n\nValidate the release before publishing.",'updated'=>gmdate('c'),'media'=>[]];$db['pages'][$key]=['id'=>bin2hex(random_bytes(16)),'revision'=>1,'draft'=>$p,'published'=>null];}
    });exit;
}
if($action==='stats'){
    $pdo=PostgresContentStore::runtimeConnect();
    $out=['postgres'=>$pdo->query("SELECT blks_read,blks_hit,temp_bytes,xact_commit,xact_rollback,deadlocks FROM pg_stat_database WHERE datname=current_database()")->fetch(PDO::FETCH_ASSOC)];
    $out['postgres']+= $pdo->query("SELECT count(*) AS connections,count(*) FILTER (WHERE wait_event_type='Lock') AS lock_waiters FROM pg_stat_activity WHERE datname=current_database()")->fetch(PDO::FETCH_ASSOC);
    if(CajeerBook\RedisRuntime::enabled()){$r=CajeerBook\RedisRuntime::forRoot($root);$out['redis']=['stats'=>$r->stats(),'resources'=>$r->resources()];}
    echo json_encode($out,JSON_THROW_ON_ERROR);exit;
}
if($action==='profile'){
    $pdo=PostgresContentStore::runtimeConnect();$binding=PostgresContentStore::installationBinding($root);$id=$binding[0];
    $q=$pdo->prepare("SELECT content_scope FROM cajeer_book.identity_workspaces WHERE installation=? AND slug='bench-000'");$q->execute([$id]);$scope=$q->fetchColumn();if(!is_string($scope))throw new RuntimeException('Missing benchmark scope.');
    $pdo->beginTransaction();try{
        $pdo->exec("SET LOCAL statement_timeout='120s'");
        $q=$pdo->prepare('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) SELECT section,record_key,position,value::text AS value FROM cajeer_book.content_record WHERE installation=? AND scope=? ORDER BY section,position');$q->execute([$id,$scope]);$content=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
        $search=['broad'=>CajeerBook\PostgresSearch::explain($pdo,[$id,$scope],'public','none',CajeerBook\Search::options(['q'=>'Configuration','book'=>'guide','version'=>'1','locale'=>'en','limit'=>'10']),false),'selective'=>CajeerBook\PostgresSearch::explain($pdo,[$id,$scope],'public','none',CajeerBook\Search::options(['q'=>'"benchw000p0001"','book'=>'guide','limit'=>'10']),false)];
        $q=$pdo->prepare('SELECT count(*) FROM cajeer_book.search_document WHERE installation=? AND is_public');$q->execute([$id]);$published=(int)$q->fetchColumn();
        $db=$pdo->query("SELECT pg_database_size(current_database()) AS bytes,blks_read,blks_hit,temp_bytes,xact_commit,xact_rollback FROM pg_stat_database WHERE datname=current_database()")->fetch(PDO::FETCH_ASSOC);
        $relations=$pdo->query("SELECT relname,pg_total_relation_size(relid) AS bytes,seq_scan,idx_scan FROM pg_stat_user_tables WHERE schemaname='cajeer_book' ORDER BY relname")->fetchAll(PDO::FETCH_ASSOC);
        $q=$pdo->prepare("SELECT record_key FROM cajeer_book.media WHERE installation=? AND scope=? ORDER BY position LIMIT 1");$q->execute([$id,$scope]);$mid=$q->fetchColumn();
        $pdo->rollBack();$out=['published_documents'=>$published,'content_load_plan'=>$content,'search_plans'=>$search,'database'=>$db,'relations'=>$relations];
        if(is_string($mid))$out['optimized_read_plans']=(new PostgresContentStore($id,$scope,$binding[1]))->profilePublished('guide/1/topic-0000',$mid);
        if(CajeerBook\RedisRuntime::enabled()){$r=CajeerBook\RedisRuntime::forRoot($root);$out['redis']=['stats'=>$r->stats(),'resources'=>$r->resources()];}
        echo json_encode($out,JSON_THROW_ON_ERROR);exit;
    }finally{if($pdo->inTransaction())$pdo->rollBack();}
}
if($action==='metrics'){echo json_encode(Observability::snapshot(),JSON_THROW_ON_ERROR);exit;}
if($action==='cleanup'){
    if(getenv('BOOK_PG_DSN')&&is_file($root.'/postgres.required')&&($b=PostgresContentStore::installationBinding($root))){$p=PostgresContentStore::runtimeConnect();$q=$p->prepare('DELETE FROM cajeer_book.content_installation WHERE installation=? AND binding=?');$q->execute($b);if($q->rowCount()!==1)throw new RuntimeException('Benchmark namespace cleanup failed.');}exit;
}
if($action!=='seed'||$argc!==5)throw new RuntimeException('Invalid corpus command.');
foreach(array_slice($argv,2) as $v)if(!ctype_digit($v))throw new RuntimeException('Invalid corpus argument.');
$total=(int)$argv[2];$per=(int)$argv[3];$seed=(int)$argv[4];
if($total<1||$per<1||$per>1000||$total>$per*Workspaces::LIMIT||$seed>2147483647)throw new RuntimeException('Corpus exceeds current quotas or root is not fresh.');
$start=hrtime(true);$s=new IdentityStore($root);if($s->initialized())throw new RuntimeException('Benchmark root already initialized.');$password=bin2hex(random_bytes(24));$owner=$s->initialize('benchowner','Benchmark Owner','bench@example.invalid',$password);$auth=$s->login('benchowner',$password,'192.0.2.1');$token=$auth['token'];$csrf=$auth['session']['csrf'];
$org=$s->createOrganization($token,'Benchmark Organization','benchmark-org');
$topics=['Authentication','Database replication','HTTP caching','Deployment','Error handling','Monitoring','Search indexing','Backup recovery','API integration','Access control'];
$id=fn(string $key)=>substr(hash('sha256',"benchmark:$seed:$key"),0,32);
$manifest=[];$translations=0;$bytes=0;$history=0;$remaining=$total;
$make=function(int $w,int $i,string $media)use($topics,$seed):array{
    $topic=$topics[($seed+$i+$w)%count($topics)];$marker=sprintf('benchw%03dp%04d',$w,$i);
    $title="$topic — $marker";$body="# $title\n\nThis guide explains $topic for a distributed documentation installation. The reference identifier is $marker.\n\n## Configuration\n\n";
    $body.="| Setting | Value |\n| --- | --- |\n| timeout | 30 seconds |\n| retries | 3 |\n\n```json\n{\"enabled\":true,\"timeout\":30,\"retries\":3}\n```\n\n";
    for($n=0;$n<4+($i%8);$n++){$other=$topics[($seed+$i+$n)%count($topics)];$body.="## Step ".($n+1)."\n\nReview $other before applying the configuration. Verify authorization, check the response status, preserve the original settings, and record the result. Use a separate environment for testing. Restart only the affected service and verify the health endpoint.\n\n";}
    if($i===0)$body.="[Sample attachment](/media/$media)\n";
    return ['title'=>$title,'section'=>$topic,'order'=>$i,'body'=>$body,'updated'=>'2025-01-01T00:00:00+00:00','media'=>$i===0?[$media]:[]];
};
for($w=0;$remaining>0;$w++){
    // Large corpus construction can outlive the normal idle timeout. Keep the real session alive.
    if(!$s->current($token)){$auth=$s->login('benchowner',$password,'192.0.2.1');if($auth['status']!=='ok')throw new RuntimeException('Benchmark authentication expired.');$token=$auth['token'];$csrf=$auth['session']['csrf'];}
    $n=min($per,$remaining);$remaining-=$n;$slug=sprintf('bench-%03d',$w);
    $workspace=$s->createWorkspace($token,$org['id'],'Benchmark '.$w,$slug);$s->mutateWorkspace($token,$workspace['id'],1,'visibility','public','');
    $dir=Workspaces::directory($root,$workspace);$store=new BookStore($dir,false);$store->setHistoryActor($owner);
    $file=$root.'/sample.txt';file_put_contents($file,'Benchmark attachment '.$w);$media=(new MediaStore($dir,$store))->upload($file,'sample.txt','')['item']['id'];unlink($file);
    $store->change(function(&$db)use($w,$n,$id,$make,$media,&$translations,&$bytes){
        $db['books']['guide']=['slug'=>'guide','id'=>$id("book:$w"),'title'=>'Benchmark Guide'];
        for($i=0;$i<$n;$i++){
            $version=(string)(1+intdiv($i,100)%2);$key=sprintf('guide/%s/topic-%04d',$version,$i);$p=$make($w,$i,$media);$pageId=$id("$w:$i");
            if($i%10===1){$sourceKey=sprintf('guide/%s/topic-%04d',$version,$i-1);$source=$db['pages'][$sourceKey];$p['title']='Руководство '.$p['title'];$p['body']="## Настройка\n\nДокументация: проверка доступа и восстановления.\n\n".$p['body'];$p['locale']='ru';$p['translation_group']=$source['id'];$p['source_digest']=Locale::digest($source['published']);$translations++;}
            $bytes+=strlen($p['body']);$db['pages'][$key]=['id'=>$pageId,'revision'=>1,'draft'=>$p,'published'=>$p,'published_key'=>$key];
        }
        // Privacy controls when space remains; full scopes use the legacy control scope below.
        if($n<=998){$db['books']['secret']=['slug'=>'secret','id'=>$id("secret:$w"),'title'=>'Private Benchmark','visibility'=>'private'];foreach(['secret/1/private'=>true,'guide/1/draft'=>false] as $key=>$published){$p=['title'=>'Hidden benchmark','section'=>'Hidden','order'=>0,'body'=>'hiddenbenchmark secret control','updated'=>'2025-01-01T00:00:00+00:00','media'=>[]];$db['pages'][$key]=['id'=>$id("$w:$key"),'revision'=>1,'draft'=>$p,'published'=>$published?$p:null];if($published)$db['pages'][$key]['published_key']=$key;}}
    });
    // Real history capture on a published page without changing the public snapshot.
    $store->change(function(&$db){$db['pages']['guide/1/topic-0000']['draft']['body'].="\n\nUnpublished benchmark revision.";$db['pages']['guide/1/topic-0000']['revision']++;},'save');$history++;
    $manifest[]=['prefix'=>'/w/'.$org['id'].'/'.$workspace['id'],'pages'=>$n,'media'=>$media,'controls'=>$n<=998];
    fwrite(STDERR,"Seeded workspace $w ($n public pages)\n");
}
// Legacy scope is separate from the 100 registered workspaces; keep negative controls here.
$legacy=new BookStore($root);$legacy->change(function(&$db)use($id){$db['pages']=[];$db['books']=['guard'=>['slug'=>'guard','id'=>$id('guard'),'title'=>'Guard'],'private'=>['slug'=>'private','id'=>$id('private'),'title'=>'Private','visibility'=>'private']];foreach(['guard/1/draft'=>false,'private/1/hidden'=>true] as $key=>$published){$p=['title'=>'Hidden benchmark','section'=>'Guard','order'=>1,'body'=>'hiddenbenchmark secret control','updated'=>'2025-01-01T00:00:00+00:00','media'=>[]];$db['pages'][$key]=['id'=>$id($key),'revision'=>1,'draft'=>$p,'published'=>$published?$p:null];if($published)$db['pages'][$key]['published_key']=$key;}});
// Start the measured workload with a fresh real session, not an aged seed session.
$s->logout($token);$auth=$s->login('benchowner',$password,'192.0.2.1');if($auth['status']!=='ok')throw new RuntimeException('Benchmark login failed.');$token=$auth['token'];$csrf=$auth['session']['csrf'];
echo json_encode(['published_pages'=>$total,'workspace_count'=>count($manifest),'per_workspace'=>$per,'translations'=>$translations,'body_bytes'=>$bytes,'history_samples'=>$history,'seed'=>$seed,'seconds'=>(hrtime(true)-$start)/1e9,'workspaces'=>$manifest,'token'=>$token,'csrf'=>$csrf],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
