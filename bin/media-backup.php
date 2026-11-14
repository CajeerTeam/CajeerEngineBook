<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\{BookStore,MediaStore,MediaBackup};
try{
    if($argc===3 && $argv[1]==='gc'){
        $dir=$argv[2];if(!is_dir($dir)||is_link($dir))throw new InvalidArgumentException('Data directory must exist');
        $books=new BookStore($dir);echo 'Removed '.(new MediaStore($dir,$books))->collectOrphans()." orphan file(s)\n";exit(0);
    }
    if($argc!==4||!in_array($argv[1],['backup','restore'],true))throw new InvalidArgumentException("Usage: php bin/media-backup.php backup DATA_DIR NEW_FILE.tar\n       php bin/media-backup.php restore FILE.tar NEW_DATA_DIR\n       php bin/media-backup.php gc DATA_DIR");
    if($argv[1]==='backup'){
        $dir=$argv[2];if(!is_dir($dir)||is_link($dir))throw new InvalidArgumentException('Data directory must exist');
        $books=new BookStore($dir);(new MediaBackup($dir,$books,new MediaStore($dir,$books)))->create($argv[3]);
    }else MediaBackup::restore($argv[2],$argv[3]);
    echo "OK\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
