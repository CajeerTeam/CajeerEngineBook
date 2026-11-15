<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{DataDirectory,IdentityStore,Workspaces,BookStore,MediaStore,MediaBackup};
use CajeerEngine\Update\OperationFiles as Files;
try{
    $root=dirname(__DIR__);$data=DataDirectory::resolve(getenv('BOOK_DATA_DIR')?:$root.'/var',$root.'/public');
    $registry=(new IdentityStore($data))->operatorWorkspaces();$cmd=$argv[1]??'';
    if($cmd==='list'&&$argc===2){echo json_encode($registry,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";exit(0);}
    if($argc!==4||!in_array($cmd,['backup','restore'],true)||!Workspaces::id($argv[2]))throw new InvalidArgumentException("Usage: php bin/workspaces.php list\n       php bin/workspaces.php backup WORKSPACE_ID NEW_FILE.tar\n       php bin/workspaces.php restore WORKSPACE_ID FILE.tar (offline, empty destination)\n");
    $w=null;foreach($registry as $item)if($item['id']===$argv[2]){$w=$item;break;}
    if(!$w||$w['legacy'])throw new InvalidArgumentException('Unknown or legacy workspace; use media-backup.php for legacy');
    $dir=Workspaces::directory($data,$w);$archive=DataDirectory::resolve(dirname($argv[3]),$root.'/public').'/'.basename($argv[3]);
    if($cmd==='backup'){$books=new BookStore($dir,false);(new MediaBackup($dir,$books,new MediaStore($dir,$books)))->create($archive);}
    else{if(getenv('BOOK_PG_DSN')||CajeerBook\PostgresContentStore::marker($data)!==null)throw new RuntimeException('PostgreSQL workspace restore requires full installation restore into a new directory and migration.');Files::dir(dirname($dir));MediaBackup::restore($archive,$dir);}
    echo "OK\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
