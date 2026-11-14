<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{BookStore,MediaStore,MediaBackup,DataDirectory};
try{
    if($argc===3 && $argv[1]==='gc'){
        $dir=DataDirectory::resolve($argv[2],dirname(__DIR__).'/public');if(!is_dir($dir)||is_link($dir))throw new InvalidArgumentException('Data directory must exist');
        $books=new BookStore($dir);echo 'Removed '.(new MediaStore($dir,$books))->collectOrphans()." orphan file(s)\n";exit(0);
    }
    if($argc!==4||!in_array($argv[1],['backup','restore'],true))throw new InvalidArgumentException("Usage: php bin/media-backup.php backup DATA_DIR NEW_FILE.tar\n       php bin/media-backup.php restore FILE.tar NEW_DATA_DIR\n       php bin/media-backup.php gc DATA_DIR");
    if($argv[1]==='backup'){
        $dir=DataDirectory::resolve($argv[2],dirname(__DIR__).'/public');if(!is_dir($dir)||is_link($dir))throw new InvalidArgumentException('Data directory must exist');
        $books=new BookStore($dir);(new MediaBackup($dir,$books,new MediaStore($dir,$books)))->create(DataDirectory::resolve(dirname($argv[3]),dirname(__DIR__).'/public').'/'.basename($argv[3]));
    }else MediaBackup::restore($argv[2],DataDirectory::resolve($argv[3],dirname(__DIR__).'/public'));
    echo "OK\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
