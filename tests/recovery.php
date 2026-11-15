<?php
declare(strict_types=1);
$storage=in_array('--storage-only',$argv,true);$postgres=in_array('--postgres',$argv,true);$s3=in_array('--s3',$argv,true);
if($storage){spl_autoload_register(static function($c){foreach(['CajeerBook\\'=>'src/','CajeerEngine\\'=>'core/'] as $p=>$d)if(str_starts_with($c,$p)){$f=dirname(__DIR__).'/'.$d.str_replace('\\','/',substr($c,strlen($p))).'.php';if(is_file($f))require_once $f;}});}else require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{BookStore,IdentityStore,MediaStore,Workspaces,RecoveryManager,PostgresContentStore};
use CajeerEngine\Update\OperationFiles as Files;
function ok(bool $v,string $s):void{if(!$v)throw new RuntimeException($s);echo "PASS $s\n";}
function deny(callable $f,string $s):void{try{$f();}catch(RuntimeException|InvalidArgumentException|DomainException){ok(true,$s);return;}throw new RuntimeException('Accepted: '.$s);}
function clean(string $p):void{if(is_link($p)||is_file($p)){unlink($p);return;}if(!is_dir($p))return;foreach(new DirectoryIterator($p) as $f)if(!$f->isDot())clean($f->getPathname());rmdir($p);}
$dsn=getenv('BOOK_PG_DSN');$base=sys_get_temp_dir().'/book-recovery-'.bin2hex(random_bytes(8));mkdir($base,0700);$root=$base.'/source';$spool=$base.'/spool';$remote=$base.'/remote';$public=dirname(__DIR__).'/public';$ns=bin2hex(random_bytes(16));mkdir($remote,0700);umask(0077);
putenv('BOOK_OBSERVABILITY=1');putenv('BOOK_DATA_DIR='.$root);putenv('BOOK_PG_DSN');putenv('BOOK_PG_NAMESPACE='.$ns);putenv('BOOK_BACKUP_DIR='.$spool);putenv('BOOK_BACKUP_KEY_FILE='.$base.'/key');putenv('BOOK_BACKUP_INTERVAL=300');putenv('BOOK_BACKUP_RETENTION_DAYS=1');
if(!$s3){putenv('BOOK_BACKUP_S3_URI');putenv('BOOK_BACKUP_REMOTE_DIR='.$remote);}else{putenv('BOOK_BACKUP_REMOTE_DIR');if(!getenv('BOOK_BACKUP_S3_URI'))throw new RuntimeException('S3 test requires a real repository.');}
$key=bin2hex(random_bytes(32));Files::write($base.'/key',$key."\n");
try{
 $i=new IdentityStore($root);$owner=$i->initialize('owner','Owner','owner@example.org','password-owner-123');$token=$i->login('owner','password-owner-123','192.0.2.1')['token'];$org=$i->createOrganization($token,'Team','team');$w=$i->createWorkspace($token,$org['id'],'Private','private');
 $legacy=new BookStore($root);$legacy->change(fn(&$db)=>$db['pages']=[]);$dir=Workspaces::directory($root,$w);$books=new BookStore($dir,false);$books->createBook('guide','Private documentation');$books->setBookVisibility('guide','private',1);
 $input=$base.'/input.txt';$mediaText=str_repeat('media content unique and private\n',50000);file_put_contents($input,$mediaText);$media=new MediaStore($dir,$books);$mid=$media->upload($input,'attachment.txt')['item']['id'];
 if(!$storage){$books->setHistoryActor($owner);$books->saveResult(['book'=>'guide','version'=>'1','path'=>'start','title'=>'Start','section'=>'Guide','order'=>10,'body'=>"# Start\n\n[File](/media/$mid)",'revision'=>0],true);}
 if($postgres){if(!$dsn||!getenv('BOOK_BACKUP_DRILL_PG_DSN'))throw new RuntimeException('Source and drill PostgreSQL databases required.');putenv('BOOK_PG_DSN='.$dsn);PostgresContentStore::migrate($root);}
 $m=new RecoveryManager($public);ok($m->health(300)['status']==='unavailable','no verified capture is unhealthy');$a=$m->run();$id=$a['id'];$file=$spool.'/'.$id.'.cbk';
 ok($a['status']==='verified'&&$a['remote']===($s3?'s3':'filesystem')&&is_file($file),'scheduled backup verified after remote read-back and actual restore');
 ok($m->run()['status']==='not_due','persistent interval prevents duplicate backups');
 $telemetry=CajeerBook\Observability::backupSnapshot();ok($telemetry['capture']===$a['captured_at']&&$telemetry['verified']>=$a['verified_at']&&$telemetry['failures']===0&&$telemetry['pending']===0,'real verified backup publishes capture and verification timestamps');
 putenv('BOOK_MONITOR_BACKUP=1');$diag=(new CajeerBook\Diagnostics())->collect();ok($diag['checks']['backup']==='ok','diagnostics reads verified backup without repository credentials');putenv('BOOK_MONITOR_BACKUP');
 ok(!str_contains(file_get_contents($file),'media content unique')&&!str_contains(file_get_contents($file),'owner@example.org'),'backup does not expose plaintext identity or media');
 ok((fileperms($file)&0777)===0600&&glob($spool.'/.recovery-*')===[],'private artifact and staging cleanup');
 ok($m->status()['state']['last_id']===$id&&$m->status()['state']['pending']===null,'durable verified state');
 if($postgres){
  ok(isset($a['restore']['postgres_drill']['search'])&&$a['restore']['postgres_drill']['audit_streams']===3,'real PostgreSQL drill migration, search and audit');
  $old=getenv('BOOK_BACKUP_DRILL_PG_DSN');$ou=getenv('BOOK_BACKUP_DRILL_PG_USER');$op=getenv('BOOK_BACKUP_DRILL_PG_PASSWORD');
  putenv('BOOK_BACKUP_DRILL_PG_DSN='.$dsn.';application_name=alias_drill');putenv('BOOK_BACKUP_DRILL_PG_USER='.(getenv('BOOK_PG_USER')?:''));putenv('BOOK_BACKUP_DRILL_PG_PASSWORD='.(getenv('BOOK_PG_PASSWORD')?:''));
  try{deny(fn()=>$m->drill($id),'alternate DSN to source database rejected');}finally{putenv('BOOK_BACKUP_DRILL_PG_DSN='.$old);putenv('BOOK_BACKUP_DRILL_PG_USER='.$ou);putenv('BOOK_BACKUP_DRILL_PG_PASSWORD='.$op);}
 }
 ok($m->health(300)['status']==='ok','fresh verified capture is healthy');
 // Remove local copy: restore must read remote bytes, not the local repository or live source.
 unlink($file);$result=$m->restore($id,$base.'/restored',true);$restored=new BookStore(Workspaces::directory($base.'/restored',$w),false,true);$ri=new IdentityStore($base.'/restored',true);
 ok($result['users']===1&&$ri->backup()['organizations'][$org['id']]['id']===$org['id']&&$ri->current($token)===null,'remote-only restore retains identity and revokes old sessions');
 ok($restored->read()['books']['guide']['title']==='Private documentation'&&file_get_contents((new MediaStore(Workspaces::directory($base.'/restored',$w),$restored))->blob($mid))===$mediaText,'remote-only restore preserves private content and media');
 if(!$storage)ok(count($restored->read()['pages'])===1&&count($restored->read()['history'])>0,'published page and revisions restored');
 deny(fn()=>$m->restore($id,$root,true),'cannot overwrite source');deny(fn()=>$m->restore('../escape',$base.'/bad',true),'invalid recovery ID rejected');
 Files::write($base.'/key',str_repeat('0',64)."\n");deny(fn()=>$m->restore($id,$base.'/wrong-key',true),'wrong key rejected');ok(!file_exists($base.'/wrong-key'),'wrong key leaves no restore target');Files::write($base.'/key',$key."\n");
 if(!$s3){
  $remoteFile=$remote.'/'.$id.'.cbk';$original=file_get_contents($remoteFile);file_put_contents($remoteFile,substr($original,0,-1));deny(fn()=>$m->restore($id,$base.'/truncated',true),'truncated encrypted remote rejected');
  file_put_contents($remoteFile,$original."x");deny(fn()=>$m->restore($id,$base.'/trailing',true),'appended bytes rejected');file_put_contents($remoteFile,$original);
  // Restore local copy for retention and simulate an unavailable remote after manager initialization.
  Files::copy($remoteFile,$file);rename($remote,$base.'/offline');deny(fn()=>$m->run(true),'remote outage fails backup rather than claiming success');$pending=$m->status()['state']['pending'];$telemetry=CajeerBook\Observability::backupSnapshot();ok($telemetry['failures']>0&&$telemetry['pending']===1&&$telemetry['capture']===$a['captured_at'],'remote outage exposes pending and failures without claiming a fresh capture');ok(is_array($pending)&&is_file($spool.'/'.$pending['id'].'.cbk'),'verified encrypted local copy retained for retry');
  ok($m->run()['status']==='not_due','retry backoff is persistent');rename($base.'/offline',$remote);$b=$m->run(true);ok($b['id']===$pending['id']&&$b['sha256']===$pending['sha256'],'retry uploads same immutable local backup');
  $otherFile=$remote.'/'.$b['id'].'.cbk';$otherBytes=file_get_contents($otherFile);file_put_contents($otherFile,$original);deny(fn()=>$m->restore($b['id'],$base.'/swapped',true),'valid older encrypted artifact cannot be substituted for a different ID');file_put_contents($otherFile,$otherBytes);
  $r=Files::read($spool.'/'.$id.'.json');$r['verified_at']=time()-3*86400;Files::json($spool.'/'.$id.'.json',$r);$c=$m->run(true);ok(!is_file($file)&&is_file($remoteFile)&&is_file($spool.'/'.$b['id'].'.cbk')&&is_file($spool.'/'.$c['id'].'.cbk'),'retention keeps two newest local backups and never deletes remote objects');
 }
 $lock=fopen($spool.'/scheduler.lock','c+b');flock($lock,LOCK_EX);ok($m->health(300)['status']==='ok','RPO probe remains available while scheduler locked');deny(fn()=>$m->run(true),'concurrent scheduler excluded');fclose($lock);
 $saved=Files::read($spool.'/state.json');$aged=$saved;$aged['last_capture']=time()-301;Files::json($spool.'/state.json',$aged);ok($m->health(300)['status']==='unavailable','RPO uses capture time rather than completion');Files::json($spool.'/state.json',$saved);
 $stale=$spool.'/.recovery-'.str_repeat('a',32);mkdir($stale,0700);file_put_contents($stale.'/plaintext','crash residue');$m->status();ok(!file_exists($stale),'next locked operation cleans abandoned plaintext staging');
 echo "OK\n";
}finally{CajeerBook\Observability::flush();putenv('BOOK_OBSERVABILITY');if($postgres&&$dsn){putenv('BOOK_PG_DSN='.$dsn);try{$pdo=PostgresContentStore::connect();$q=$pdo->prepare('DELETE FROM cajeer_book.content_installation WHERE installation=?');$q->execute([$ns]);}catch(Throwable $e){fwrite(STDERR,'Cleanup: '.$e->getMessage()."\n");}}clean($base);}
