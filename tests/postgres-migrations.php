<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{DatabaseMigrations as M,PostgresContentStore as P,PostgresIdentityStore,IdentityStore,BookStore,Workspaces,MediaStore,AuditLog,Search,Visibility};
use CajeerEngine\Update\OperationFiles as Files;
function ok(bool $v,string $label):void{if(!$v)throw new RuntimeException($label);echo "PASS $label\n";}
function deny(callable $fn,string $label):void{try{$fn();}catch(RuntimeException|InvalidArgumentException $e){ok(true,$label);return;}throw new RuntimeException('Unexpected success: '.$label);}
function clean(string $p):void{if(!is_dir($p))return;foreach(new DirectoryIterator($p) as $f){if($f->isDot())continue;if($f->isDir()&&!$f->isLink())clean($f->getPathname());else unlink($f->getPathname());}rmdir($p);}
function copyTree(string $src,string $dest):void{mkdir($dest,0700);foreach(new DirectoryIterator($src) as $f){if($f->isDot())continue;if($f->isLink())throw new RuntimeException('Unsafe fixture');$to=$dest.'/'.$f->getFilename();if($f->isDir())copyTree($f->getPathname(),$to);else{if(!copy($f->getPathname(),$to))throw new RuntimeException('Copy failed');chmod($to,0600);}}}
$dsn=getenv('BOOK_PG_DSN');if(!$dsn||getenv('BOOK_MIGRATION_TEST')!=='1')throw new RuntimeException('Use a disposable book_migrations database, BOOK_MIGRATION_TEST=1.');
$pdo=P::connect();if($pdo->query('SELECT current_database()')->fetchColumn()!=='book_migrations'||$pdo->query("SELECT to_regnamespace('cajeer_book') IS NOT NULL")->fetchColumn()===true)throw new RuntimeException('Migration tests require a fresh dedicated book_migrations database.');
$dir=sys_get_temp_dir().'/book-migration-'.bin2hex(random_bytes(12));$ns=bin2hex(random_bytes(16));$binding=bin2hex(random_bytes(16));
try{
    $pre=M::preflight($pdo);ok($pre['current']===0&&$pre['dry_run_rolled_back']&&!$pdo->query("SELECT to_regnamespace('cajeer_book') IS NOT NULL")->fetchColumn(),'fresh preflight executes and rolls back schema and index');
    $fresh=M::apply($pdo);ok($fresh['to']===6&&$fresh['changes'][0]['operation']==='apply'&&M::status($pdo)['compatible'],'fresh schema applied and verified');
    // The dedicated test DB is empty: independently reproduce the pre-0.5.2 SQL schema.
    $pdo->exec('DROP SCHEMA cajeer_book CASCADE');
    foreach(['postgres-content.sql','postgres-identity.sql','postgres-audit.sql'] as $f)$pdo->exec(file_get_contents(dirname(__DIR__).'/sql/'.$f));
    putenv('BOOK_PG_DSN');putenv('BOOK_DATA_DIR='.$dir);putenv('BOOK_PG_NAMESPACE='.$ns);copyTree(__DIR__.'/fixtures/upgrade-050',$dir);
    $identity=new IdentityStore($dir,true);$db=$identity->migrationSnapshot(fn($d)=>$d);$w=array_values($db['workspaces'])[0];$scope=$w['organization'].'/'.$w['id'];$wd=Workspaces::directory($dir,$w);
    $content=(new BookStore($wd,false,true))->read();$legacy=(new BookStore($dir,true,true))->read();
    putenv('BOOK_PG_DSN='.$dsn);$pdo->beginTransaction();$q=$pdo->prepare('INSERT INTO cajeer_book.content_installation(installation,binding) VALUES(?,?)');$q->execute([$ns,$binding]);
    (new P($ns,'legacy',$binding))->initializeInTransaction($pdo,$legacy);(new P($ns,$scope,$binding))->initializeInTransaction($pdo,$content);
    (new PostgresIdentityStore($ns,$binding))->import($pdo,$db);AuditLog::initialize($pdo,$ns);AuditLog::append($pdo,$ns,$scope,[AuditLog::event('page.publish',null,str_repeat('a',32))]);$pdo->commit();
    Files::write($dir.'/postgres.required',$ns.':'.$binding."\n");Files::write($dir.'/postgres.identity.required',$ns.':'.$binding."\n");
    $snapshot=function()use($pdo,$ns){$out=[];foreach(['content_store','content_section','content_record','identity_state','identity_users','identity_organizations','identity_workspaces','identity_memberships','identity_workspace_roles','search_state','search_document','audit_stream','audit_event'] as $table){$q=$pdo->prepare('SELECT row_to_json(t)::text FROM cajeer_book.'.$table.' t WHERE installation=? ORDER BY 1');$q->execute([$ns]);$out[$table]=$q->fetchAll(PDO::FETCH_COLUMN);}return $out;};
    $before=$snapshot();ok(M::status($pdo)['legacy_candidate'],'unregistered old SQL schema detected');
    deny(fn()=>P::runtimeConnect(),'unregistered schema blocks runtime');
    // A mismatched object must not be silently repaired/stamped by baseline adoption.
    $pdo->exec('DROP INDEX cajeer_book.audit_event_actor');deny(fn()=>M::apply($pdo),'damaged legacy index blocks adoption');
    ok(!$pdo->query("SELECT to_regclass('cajeer_book.schema_migrations') IS NOT NULL")->fetchColumn(),'failed adoption leaves no registry');
    $pdo->exec('CREATE INDEX audit_event_actor ON cajeer_book.audit_event(installation,actor_id,at_epoch DESC,event_id DESC)');
    ok(M::preflight($pdo)['preflight']==='passed'&&$snapshot()===$before&&!$pdo->query("SELECT to_regclass('cajeer_book.audit_event_outcome') IS NOT NULL")->fetchColumn(),'legacy dry-run leaves all data and schema unchanged');
    $result=M::apply($pdo);ok($result['to']===6&&$result['changes'][0]['operation']==='adopt'&&$snapshot()===$before,'baseline adopted and actual index built without data changes');
    ok(M::apply($pdo)['changes']===[]&&M::status($pdo)['compatible'],'repeat migration no-op');
    $runtime=P::runtimeConnect();deny(fn()=>M::rollback($pdo,1),'runtime session pins schema across separate connection');$runtime=null;
    $pdo->query('SELECT pg_advisory_lock(1128416305,1)')->fetchColumn();$other=P::connect();deny(fn()=>M::apply($other),'another migrator excluded');deny(fn()=>P::runtimeConnect(),'runtime fails closed while migration lock held');$pdo->query('SELECT pg_advisory_unlock(1128416305,1)')->fetchColumn();$other=null;
    $status=M::rollback($pdo,1);ok($status['to']===1&&$snapshot()===$before,'reversible down preserves content, identity, search and audit bytes');deny(fn()=>P::runtimeConnect(),'rolled-back schema rejects new runtime');deny(fn()=>(new BookStore($wd,false))->read(),'rolled-back schema rejects real content read');deny(fn()=>(new IdentityStore($dir))->checkReady(),'rolled-back schema fails identity readiness');deny(fn()=>M::rollback($pdo,0),'irreversible baseline rollback refused');
    // Force a real SQL failure after DDL, when recording the migration history.
    $pdo->exec("CREATE FUNCTION cajeer_book.test_migration_fail() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'injected ledger failure'; END \$\$; CREATE TRIGGER test_migration_fail BEFORE INSERT ON cajeer_book.schema_migration_history FOR EACH ROW EXECUTE FUNCTION cajeer_book.test_migration_fail()");
    deny(fn()=>M::apply($pdo),'history failure rolls back DDL and ledger together');ok(M::status($pdo)['current']===1&&!$pdo->query("SELECT to_regclass('cajeer_book.audit_event_outcome') IS NOT NULL")->fetchColumn()&&$snapshot()===$before,'failed upgrade leaves schema/data unchanged');
    $pdo->exec('DROP TRIGGER test_migration_fail ON cajeer_book.schema_migration_history; DROP FUNCTION cajeer_book.test_migration_fail()');
    ok(M::apply($pdo)['to']===6,'failed migration can be retried');
    $pdo->exec("UPDATE cajeer_book.schema_migrations SET checksum=repeat('a',64) WHERE version=2");deny(fn()=>P::runtimeConnect(),'tampered checksum blocks runtime');deny(fn()=>M::apply($pdo),'tampered ledger blocks migration');$q=$pdo->prepare('UPDATE cajeer_book.schema_migrations SET checksum=? WHERE version=2');$q->execute([M::catalog()[2]['checksum']]);
    $pdo->exec('DROP INDEX cajeer_book.audit_event_actor');deny(fn()=>M::status($pdo),'verify detects baseline catalog drift');$pdo->exec('CREATE INDEX audit_event_actor ON cajeer_book.audit_event(installation,actor_id,at_epoch DESC,event_id DESC)');
    ok(M::status($pdo)['compatible'],'verified restored baseline');
    $pdo->exec("UPDATE cajeer_book.schema_migration_history SET checksum=repeat('b',64) WHERE version=2");deny(fn()=>M::status($pdo),'history drift detected');$q=$pdo->prepare('UPDATE cajeer_book.schema_migration_history SET checksum=? WHERE version=2');$q->execute([M::catalog()[2]['checksum']]);
    $store=new BookStore($wd,false);ok($store->read()===$content&&Visibility::pages($store->read(),'public','none')===[],'real upgraded private content remains private');
    $i=new IdentityStore($dir);ok($i->login('owner','fixture-password-050','192.0.2.1')['status']==='ok','existing SQL identity authenticates');
    $store->createBook('new-book','New book');ok(isset($store->read()['books']['new-book']),'runtime writes after successful migration');
    ok(AuditLog::verify($pdo,$ns)[$scope]['head']>=1,'real audit chain preserved');
    ok((int)$pdo->query('SELECT count(*) FROM cajeer_book.schema_migration_history')->fetchColumn()===16,'history retains baseline adoption, apply, rollback, reapply');
    unset($store,$i); // release runtime PDO before offline schema change
    $v6=$snapshot();ok(M::rollback($pdo,2)['to']===2&&$snapshot()===$v6,'schema rollback removes embeddings, analytics and read indexes');
    ok(M::preflight($pdo)['preflight']==='passed'&&M::status($pdo)['current']===2,'schema 2 upgrade preflight rolls back index builds');
    ok(M::apply($pdo)['to']===6&&$snapshot()===$v6,'real populated schema 2 upgraded to 6 without changing data');
}finally{if($pdo->inTransaction())$pdo->rollBack();clean($dir);}
