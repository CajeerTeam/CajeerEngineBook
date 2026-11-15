<?php
declare(strict_types=1);
// The committed fixture is generated with the actual 0.5.0 source (not by the new code).
// To regenerate in a disposable checkout: php tests/upgrade-050.php --seed OLD_ROOT NEW_DIR
$root=dirname(__DIR__);
$seed=($argv[1]??'')==='--seed';
$pg=in_array('--postgres',$argv,true);$storageOnly=in_array('--storage-only',$argv,true);if($pg&&$storageOnly)throw new RuntimeException('SQL upgrade requires full dependencies');
require ($seed?$argv[2]:$root).'/tests/oidc-bootstrap.php';
use CajeerBook\{IdentityStore,BookStore,Workspaces,MediaStore,PostgresContentStore,InstallationBackup,Visibility};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function copyTree(string $src,string $dest):void{if(!mkdir($dest,0700))throw new RuntimeException('Cannot create fixture copy');foreach(new DirectoryIterator($src) as $f){if($f->isDot())continue;if($f->isLink())throw new RuntimeException('Unsafe fixture');$target=$dest.'/'.$f->getFilename();if($f->isDir())copyTree($f->getPathname(),$target);elseif(!copy($f->getPathname(),$target)||!chmod($target,0600))throw new RuntimeException('Fixture copy failed');}}
function clean(string $dir):void{if(!is_dir($dir))return;foreach(new DirectoryIterator($dir) as $f){if($f->isDot())continue;if($f->isDir()&&!$f->isLink())clean($f->getPathname());else unlink($f->getPathname());}rmdir($dir);}
$dsn=getenv('BOOK_PG_DSN');putenv('BOOK_PG_DSN');putenv('BOOK_REDIS_HOST');
if($seed){
    if(trim(file_get_contents($argv[2].'/VERSION'))!=='0.5.0')throw new RuntimeException('Expected source 0.5.0');
    $dir=$argv[3];if(file_exists($dir)||!mkdir($dir,0700,true))throw new RuntimeException('Use a new fixture directory');putenv('BOOK_DATA_DIR='.$dir);
    $identity=new IdentityStore($dir);$owner=$identity->initialize('owner','Fixture Owner','owner@example.invalid','fixture-password-050');
    $token=$identity->login('owner','fixture-password-050','192.0.2.1')['token'];$org=$identity->createOrganization($token,'Upgrade Team','upgrade-team');$w=$identity->createWorkspace($token,$org['id'],'Upgrade Docs','upgrade-docs');
    $store=new BookStore(Workspaces::directory($dir,$w),false);$store->setHistoryActor($owner);$store->createBook('guide','Upgrade Guide');$store->setBookVisibility('guide','private',1);
    $file=$dir.'/fixture.txt';file_put_contents($file,"Upgrade fixture attachment\n");$media=new MediaStore(Workspaces::directory($dir,$w),$store);$mid=$media->upload($file,'fixture.txt')['item']['id'];unlink($file);
    $store->change(function(&$db)use($mid){$p=['title'=>'Upgrade guide','section'=>'Guide','order'=>10,'body'=>"Upgrade baseline 050\n\n[Attachment](/media/$mid)",'updated'=>gmdate('c'),'media'=>[$mid]];$draft=$p;$draft['body'].="\n\nUnpublished upgrade secret";$key='guide/1/start';$db['pages'][$key]=['id'=>bin2hex(random_bytes(16)),'revision'=>1,'draft'=>$draft,'published'=>$p,'published_key'=>$key];},'publish');
    (new BookStore($dir))->materialize();$identity->logout($token);echo "Created synthetic 0.5.0 fixture\n";exit;
}
$base=sys_get_temp_dir().'/book-upgrade-'.bin2hex(random_bytes(8));mkdir($base,0700);$dir=$base.'/data';$ns=bin2hex(random_bytes(16));
try{
    copyTree($root.'/tests/fixtures/upgrade-050',$dir);putenv('BOOK_DATA_DIR='.$dir);putenv('BOOK_PG_NAMESPACE='.$ns);
    $i=new IdentityStore($dir);$before=$i->backup();$w=array_values($before['workspaces'])[0];$wd=Workspaces::directory($dir,$w);$s=new BookStore($wd,false);$original=$s->read();$key='guide/1/start';$mid=array_key_first($original['media']);
    if($pg){if(!$dsn)throw new RuntimeException('PostgreSQL required');putenv('BOOK_PG_DSN='.$dsn);PostgresContentStore::migrate($dir);$s=new BookStore($wd,false);check($s->read()===$original,'0.5.0 content survives SQL migration');}
    $login=$i->login('owner','fixture-password-050','192.0.2.2');check($login['status']==='ok'&&$i->backup()['workspaces'][$w['id']]===$w,'0.5.0 user, password and workspace survive upgrade');
    check(Visibility::pages($s->read(),'public','none')===[],'0.5.0 private book remains private');
    $published=BookStore::published($s->read())[$key];check(str_contains($published['body'],'Upgrade baseline 050')&&!str_contains($published['body'],'Unpublished upgrade secret'),'0.5.0 published and draft snapshots stay separate');
    check(file_get_contents((new MediaStore($wd,$s))->blob($mid))==="Upgrade fixture attachment\n",'0.5.0 media preserved');
    $s->change(function(&$db)use($key){$db['pages'][$key]['draft']['body'].="\nNew 051 revision";$db['pages'][$key]['revision']++;},'save');
    check(str_contains($s->read()['pages'][$key]['draft']['body'],'New 051 revision')&&BookStore::published($s->read())[$key]===$published,'0.5.1 writes to upgraded store without changing publication');
    if($storageOnly){echo "Storage-only diagnostic: full backup/restore requires CommonMark.\n";return;}
    InstallationBackup::create($dir,$base.'/backup',$root.'/public');InstallationBackup::restore($base.'/backup',$base.'/restored',$root.'/public');
    $r=new BookStore(Workspaces::directory($base.'/restored',$w),false,true);check($r->read()['pages'][$key]['draft']['body']===$s->read()['pages'][$key]['draft']['body'],'upgraded data survives portable backup and restore');
}finally{if($pg&&$dsn){putenv('BOOK_PG_DSN='.$dsn);$pdo=PostgresContentStore::connect();$q=$pdo->prepare('DELETE FROM cajeer_book.content_installation WHERE installation=?');$q->execute([$ns]);}clean($base);}
