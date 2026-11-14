<?php
declare(strict_types=1);
use CajeerBook\InstallationBackup;
try{
    require dirname(__DIR__).'/bootstrap.php';
    $root=dirname(__DIR__);$public=$root.'/public';$data=getenv('BOOK_DATA_DIR')?:$root.'/var';$cmd=$argv[1]??'';
    if($cmd==='check'&&$argc===2)echo json_encode(InstallationBackup::audit($data,$public),JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";
    elseif($cmd==='materialize-legacy-seed'&&$argc===3&&$argv[2]==='--confirm-seed-only'){InstallationBackup::materializeLegacySeed($data,$public);echo "Legacy demo seed materialized.\n";}
    elseif($cmd==='backup'&&$argc===3){InstallationBackup::create($data,$argv[2],$public);echo "Installation backup complete.\n";}
    elseif($cmd==='migrate'&&$argc===4){InstallationBackup::create($data,$argv[3],$public);InstallationBackup::restore($argv[3],$argv[2],$public);echo "Migration complete. Original data and backup retained.\n";}
    elseif($cmd==='restore'&&$argc===3){InstallationBackup::restore($argv[2],$data,$public);echo "Installation restored. Sign in again.\n";}
    else throw new InvalidArgumentException("Usage (BOOK_DATA_DIR selects installation):\n  php bin/installation.php check\n  php bin/installation.php backup NEW_DIRECTORY\n  BOOK_DATA_DIR=NEW_DIRECTORY php bin/installation.php restore BACKUP_DIRECTORY\n  php bin/installation.php migrate NEW_DATA_DIRECTORY NEW_BACKUP_DIRECTORY\n  php bin/installation.php materialize-legacy-seed --confirm-seed-only");
}catch(Throwable $e){fwrite(STDERR,'Installation operation failed: '.$e->getMessage()."\n");exit(1);}
