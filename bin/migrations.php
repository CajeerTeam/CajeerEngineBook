<?php
declare(strict_types=1);
use CajeerBook\{DatabaseMigrations,PostgresContentStore};
// Schema operations need only PHP PDO; Composer is not necessary for recovery diagnostics.
require_once dirname(__DIR__).'/src/DatabaseMigrations.php';
require_once dirname(__DIR__).'/src/PostgresContentStore.php';
spl_autoload_register(static function(string $class):void{foreach(['CajeerBook\\'=>'src/','CajeerEngine\\'=>'core/'] as $prefix=>$dir)if(str_starts_with($class,$prefix)){$path=dirname(__DIR__).'/'.$dir.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($path))require_once $path;return;}});
try{
    $cmd=$argv[1]??'';
    $read=in_array($cmd,['status','plan','preflight','verify'],true)&&$argc===2;
    $apply=$cmd==='apply'&&$argc===3&&$argv[2]==='--offline';
    $rollback=$cmd==='rollback'&&$argc===4&&$argv[2]==='1'&&$argv[3]==='--offline';
    if(!$read&&!$apply&&!$rollback)throw new InvalidArgumentException('Usage: php bin/migrations.php status | plan | preflight | verify | apply --offline | rollback 1 --offline');
    $user=getenv('BOOK_MIGRATION_PG_USER');$password=getenv('BOOK_MIGRATION_PG_PASSWORD');
    if(($user===false)!==($password===false)||$user==='')throw new InvalidArgumentException('Set both BOOK_MIGRATION_PG_USER and BOOK_MIGRATION_PG_PASSWORD, or neither.');
    $pdo=PostgresContentStore::connect($user===false?null:$user,$password===false?null:$password);
    // Refuse changes to a different database if the local installation has a binding.
    $root=PostgresContentStore::configuredRoot();$marker=PostgresContentStore::marker($root);
    if($marker!==null){$binding=PostgresContentStore::identityBinding($root);$q=$pdo->prepare('SELECT binding FROM cajeer_book.content_installation WHERE installation=?');$q->execute([$binding[0]]);if($q->fetchColumn()!==$binding[1])throw new RuntimeException('Database binding mismatch.');}
    elseif($apply||$rollback||$cmd==='preflight'){
        // A blank data directory is only sufficient to prepare an empty database, not
        // to change an existing installation without its matching binding marker.
        if($pdo->query("SELECT to_regclass('cajeer_book.content_installation') IS NOT NULL")->fetchColumn()===true&&$pdo->query('SELECT 1 FROM cajeer_book.content_installation LIMIT 1')->fetchColumn()!==false)throw new RuntimeException('A matching installed data directory is required for schema changes on a populated database.');
    }
    $result=$apply?DatabaseMigrations::apply($pdo):($rollback?DatabaseMigrations::rollback($pdo,1):($cmd==='preflight'?DatabaseMigrations::preflight($pdo):DatabaseMigrations::status($pdo)));
    $exit=0;
    if($cmd==='verify'&&!$result['compatible'])$exit=1;
    if($cmd==='preflight'&&(!$result['exclusive_available']||($result['current']===0&&!$result['database_create_privilege'])))$exit=1;
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";exit($exit);
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
