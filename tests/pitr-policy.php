<?php
declare(strict_types=1);
require __DIR__.'/oidc-bootstrap.php';
use CajeerBook\{PhysicalRecovery as P,RecoveryEnvelope as E,RecoveryManager,MediaBackup,MediaStore,BookStore};
use CajeerEngine\Update\OperationFiles as Files;
function ok(bool $v,string $s):void{if(!$v)throw new RuntimeException($s);echo "PASS $s\n";}
function deny(callable $f,string $s):void{try{$f();}catch(Throwable){ok(true,$s);return;}throw new RuntimeException('Unexpected success: '.$s);}
function clean(string $p):void{if(is_link($p)||is_file($p)){unlink($p);return;}if(is_dir($p)){foreach(new DirectoryIterator($p) as $f)if(!$f->isDot())clean($f->getPathname());rmdir($p);}}
$id='20261116T010000Z-'.str_repeat('a',32);$m=['format'=>1,'id'=>$id,'point'=>P::point($id),'backup'=>'20261116-000000F','system_id'=>'7850000000000000000','database'=>'book','namespace'=>str_repeat('b',32),'binding'=>str_repeat('c',32),'lsn'=>'0/3000200','wal'=>'000000010000000000000003','timeline'=>1,'captured_at'=>time()];
ok(P::validate($m,$id)===$m,'valid metadata and unique point');
ok(P::label('20261116-000000F_20261116-001000D')==='20261116-000000F_20261116-001000D','differential label');
foreach(['point'=>'other','backup'=>'--force','system_id'=>'1;DROP','timeline'=>2,'wal'=>'0000000100000000000000ZZ','lsn'=>'0/ABC;','namespace'=>str_repeat('b',31),'binding'=>'bad','captured_at'=>0,'database'=>'a/b'] as $key=>$value)deny(fn()=>P::validate(array_replace($m,[$key=>$value]),$id),'reject invalid '.$key);
deny(fn()=>P::point('../etc/passwd'),'reject unsafe ID');
$base=sys_get_temp_dir().'/pitr-policy-'.bin2hex(random_bytes(8));mkdir($base,0700);$root=$base.'/app';$spool=$base.'/spool';$remote=$base.'/remote';$bundle=$base.'/bundle';foreach([$root,$spool,$remote,$bundle] as $p)mkdir($p,0700);
try{
    foreach(['BOOK_PG_DSN','BOOK_REDIS_HOST','BOOK_REDIS_SENTINELS','BOOK_BACKUP_S3_URI'] as $k)putenv($k);
    putenv('BOOK_DATA_DIR='.$root);putenv('BOOK_PITR_DIR='.$spool);putenv('BOOK_BACKUP_REMOTE_DIR='.$remote);Files::write($base.'/key',bin2hex(random_bytes(32))."\n");putenv('BOOK_BACKUP_KEY_FILE='.$base.'/key');
    // Real file-store snapshot and TAR, not an SQL simulation. The envelope authenticates metadata and media bytes.
    $s=new BookStore($root);$s->materialize();(new MediaBackup($root,$s,new MediaStore($root,$s)))->create($bundle.'/legacy.tar');Files::json($bundle.'/identity.json',['schema'=>4]);Files::json($bundle.'/manifest.json',['format'=>1,'pitr'=>$m]);
    $key=E::key();E::seal($bundle,$spool.'/'.$id.'.cbk',$key,$id);sodium_memzero($key);
    ok(P::inspect($id,true)===$m,'retry uploads real encrypted sidecar and authenticates remote readback');
    unlink($spool.'/'.$id.'.cbk');ok(P::inspect($id)===$m,'remote-only authenticated sidecar inspection');
    $file=$remote.'/'.$id.'.cbk';$f=fopen($file,'r+b');fseek($f,-1,SEEK_END);$b=fread($f,1);fseek($f,-1,SEEK_END);fwrite($f,chr(ord($b)^1));fclose($f);deny(fn()=>P::inspect($id),'tampered encrypted sidecar rejected');
    deny(fn()=>P::inspect('20261116T010000Z-'.str_repeat('d',32)),'missing remote sidecar rejected');
    putenv('BOOK_BACKUP_REMOTE_DIR');deny(fn()=>P::inspect($id),'local-only PITR repository rejected');
    putenv('BOOK_BACKUP_REMOTE_DIR='.$remote);putenv('BOOK_PITR_DIR='.$root);deny(fn()=>P::inspect($id),'spool cannot overlap live data');
}finally{clean($base);}
