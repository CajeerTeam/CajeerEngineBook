<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{BookStore,PostgresContentStore,IdentityStore,Workspaces,Search,PageTree};
function ok(bool $v,string $label):void{if(!$v)throw new RuntimeException($label);echo "PASS $label\n";}
function deny(callable $fn,string $label):void{try{$fn();}catch(RuntimeException|DomainException){ok(true,$label);return;}throw new RuntimeException('Unexpected success: '.$label);}
function clean(string $p):void{if(!is_dir($p))return;foreach(new DirectoryIterator($p) as $f){if($f->isDot())continue;if($f->isDir()&&!$f->isLink())clean($f->getPathname());else unlink($f->getPathname());}rmdir($p);}
$dsn=getenv('BOOK_PG_DSN');if(!$dsn)throw new RuntimeException('Real PostgreSQL required.');$root=sys_get_temp_dir().'/book-search-pg-'.bin2hex(random_bytes(8));$ns=bin2hex(random_bytes(16));
putenv('BOOK_DATA_DIR='.$root);putenv('BOOK_PG_NAMESPACE='.$ns);putenv('BOOK_PG_DSN');
try{
    $identity=new IdentityStore($root);$identity->initialize('owner','Owner','owner@example.org','password-owner-123');$token=$identity->login('owner','password-owner-123','192.0.2.1')['token'];
    $org=$identity->createOrganization($token,'Team','team');$w=$identity->createWorkspace($token,$org['id'],'Search','search');$dir=Workspaces::directory($root,$w);$s=new BookStore($dir,false);$scope=$org['id'].'/'.$w['id'];
    $save=fn($book,$path,$title,$body,$rev=0,$publish=true,$version='1')=>$s->saveResult(['book'=>$book,'version'=>$version,'path'=>$path,'title'=>$title,'section'=>'Guide','order'=>10,'body'=>$body,'revision'=>$rev],$publish);
    $save('guide','a','Quasar','Policies and документация. **Red fox**. [Link label](https://example.org/hiddenurltoken).');
    $save('guide','b','Other','Quasar. Red swift fox.');$save('guide','c','Hidden draft','Quasar draftonlytoken',0,false);$save('secret','a','Private Quasar','Quasar secrettoken');$s->setBookVisibility('secret','private',1);
    putenv('BOOK_PG_DSN='.$dsn);PostgresContentStore::migrate($root);$s=new BookStore($dir,false);
    $search=fn($q,$role='none',$visibility='public',$options=[])=>$s->search($visibility,$role,Search::options(['q'=>$q]+$options),true);
    $keys=fn($r)=>array_column($r['hits'],'key');
    $r=$search('quasar');ok($keys($r)===['guide/1/a','guide/1/b']&&$r['total']===2&&count($r['pages'])===2&&$r['engine']==='postgresql','indexed title ranks above body; private/draft excluded from hits, counts and navigation');
    ok($search('quasar','viewer','private')['total']===3&&$search('quasar','none','private')['total']===0,'member access and private workspace ceiling');
    ok($keys($search('"red fox"'))===['guide/1/a']&&$keys($search('quas'))===['guide/1/a','guide/1/b'],'quoted phrase and technical prefix');
    ok($keys($search('"quasar"'))===['guide/1/a','guide/1/b']&&$search('"quas"')['total']===0,'single quoted lexeme is exact without prefix or typo fallback');
    ok($search('"quasar guide"')['total']===0,'multiword phrase cannot cross title and section boundary');
    ok($keys($search('quasarr'))===['guide/1/a']&&$search('quasarr')['match']==='similar','trigram typo fallback honors book privacy');
    ok($keys($search('policy'))===['guide/1/a']&&$keys($search('документации'))===['guide/1/a'],'English and Russian stemming');
    ok($search('hiddenurltoken')['total']===0&&$search('draftonlytoken','owner')['total']===0&&$search('secrettoken')['total']===0,'URL attributes, unpublished text and private book excluded');
    ok($keys($search('quasar','none','public',['limit'=>'1','offset'=>'1','book'=>'guide','version'=>'1']))===['guide/1/b']&&$search('quasar','none','public',['offset'=>'99'])['total']===2,'filters, deterministic pagination and total beyond last page');
    $pdo=PostgresContentStore::connect();$q=$pdo->prepare('SELECT indexname FROM pg_indexes WHERE schemaname=\'cajeer_book\' AND tablename=\'search_document\' AND indexdef LIKE \'%USING gin%\'');$q->execute();ok(count($q->fetchAll())===5,'three full-text and two trigram GIN indexes');
    $pdo->beginTransaction();$pdo->exec('SET LOCAL enable_seqscan=off');$plan=$pdo->query("EXPLAIN SELECT page_key FROM cajeer_book.search_document WHERE simple_vector @@ to_tsquery('pg_catalog.simple','quasar')")->fetchAll(PDO::FETCH_COLUMN);$pdo->rollBack();ok(str_contains(implode(' ',$plan),'search_simple_gin'),'PostgreSQL can execute an index-backed term lookup');
    $save('guide','a','Quasar','replacementtoken',1,false);ok($search('replacementtoken')['total']===0&&$search('policy')['total']===1,'draft save leaves published index intact');
    $save('guide','a','Quasar','replacementtoken',2,true);ok($search('replacementtoken')['total']===1&&$search('policy')['total']===0,'publish replaces indexed content immediately');
    $s->setBookVisibility('secret','public',2);ok($search('secrettoken')['total']===1,'public visibility transition updates index');$s->setBookVisibility('secret','private',3);ok($search('secrettoken')['total']===0,'private transition revokes hits and count');
    $s->change(function(&$db){PageTree::move($db,'guide/1/a','', 'root','moved',PageTree::token($db));},'move');
    ok($keys($search('replacementtoken'))===['guide/1/a'],'draft rename retains published path');$s->change(function(&$db){PageTree::publish($db,'guide/1',PageTree::token($db));},'publish-tree');ok($keys($search('replacementtoken'))===['guide/1/moved'],'published tree rename replaces old key');
    $s->remove('guide/1/moved',$s->read()['pages']['guide/1/moved']['revision'],true);ok($search('replacementtoken')['total']===0,'unpublish deletes search document');
    $other=$identity->createWorkspace($token,$org['id'],'Other','other');$os=new BookStore(Workspaces::directory($root,$other),false);ok($os->search('public','owner',Search::options(['q'=>'quasar']))['total']===0,'new workspace index initialized and isolated');
    $binding=explode(':',trim(file_get_contents($root.'/postgres.required')))[1];$pg=new PostgresContentStore($ns,$scope,$binding);
    deny(fn()=>$pg->transaction(true,function($before)use($pg){$after=$before;$after['pages']['guide/1/b']['published']['body']='rollbackneedle';$pg->write($before,$after);throw new DomainException('rollback');}),'rollback after SQL content and index update');ok($search('rollbackneedle')['total']===0&&$search('quasar')['total']===1,'index rollback preserves previous published content');
    // Simulate a pre-index installation / damaged derived index without changing source content.
    $q=$pdo->prepare('DELETE FROM cajeer_book.search_state WHERE installation=?');$q->execute([$ns]);$q=$pdo->prepare('DELETE FROM cajeer_book.search_document WHERE installation=?');$q->execute([$ns]);
    deny(fn()=>$search('quasar'),'missing index fails closed instead of scanning stale JSON');deny(fn()=>$s->createBook('blocked','Blocked'),'writes refuse stale index before migration');
    $report=PostgresContentStore::reindex($root);ok($report[$scope]===2&&$report===PostgresContentStore::searchStatus($root),'offline rebuild backfills all current scopes and reports verified counts');ok($search('quasar')['total']===1&&$search('quasar','owner')['total']===2,'rebuild preserves private policy and current published snapshot');
}finally{putenv('BOOK_PG_DSN='.$dsn);try{$pdo=PostgresContentStore::connect();$q=$pdo->prepare('DELETE FROM cajeer_book.content_installation WHERE installation=?');$q->execute([$ns]);}catch(Throwable $e){fwrite(STDERR,'Cleanup: '.$e->getMessage()."\n");}clean($root);}
