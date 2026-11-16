<?php
declare(strict_types=1);
use CajeerBook\PhysicalRecovery;
try{
    require dirname(__DIR__).'/bootstrap.php';umask(0077);$cmd=$argv[1]??'';
    $r=match(true){
        $cmd==='capture'&&$argc===3=>PhysicalRecovery::capture($argv[2]),
        $cmd==='inspect'&&$argc===3=>PhysicalRecovery::inspect($argv[2]),
        $cmd==='retry'&&$argc===3=>PhysicalRecovery::inspect($argv[2],true),
        $cmd==='restore'&&$argc===5&&$argv[4]==='--isolated'=>PhysicalRecovery::restore($argv[2],$argv[3]),
        default=>throw new InvalidArgumentException('Usage: php bin/pitr.php capture BACKUP_LABEL | inspect ID | retry ID | restore ID NEW_DATA_DIR --isolated')};
    echo json_encode($r,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
}catch(Throwable $e){fwrite(STDERR,'PITR operation failed: '.$e->getMessage()."\n");exit(1);}
