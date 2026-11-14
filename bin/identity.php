<?php
declare(strict_types=1);
use CajeerBook\{DataDirectory,IdentityStore};
use CajeerEngine\Update\OperationFiles as Files;
$root=dirname(__DIR__);
require $root.'/core/Update/OperationFiles.php';require $root.'/src/DataDirectory.php';require $root.'/src/IdentityStore.php';
try{
    $dir=DataDirectory::resolve(getenv('BOOK_DATA_DIR')?:$root.'/var',$root.'/public');$store=new IdentityStore($dir);
    $command=$argv[1]??'';
    if($command==='init'&&count($argv)===5){
        // Password from stdin, never argv or environment. Caller should disable terminal echo.
        $password=stream_get_contents(STDIN,1025);if($password===false||strlen($password)>1024)throw new InvalidArgumentException('Invalid password input');
        $user=$store->initialize($argv[2],$argv[4],$argv[3],rtrim($password,"\r\n"));echo 'Initialized owner '.$user['username']."\n";
    }elseif($command==='reset'&&count($argv)===3){echo $store->issueReset($argv[2])."\n";}
    elseif(in_array($command,['backup','restore'],true)&&count($argv)===3){
        $parent=DataDirectory::resolve(dirname($argv[2]),$root.'/public');$name=basename($argv[2]);if($name===''||$name==='.'||$name==='..'||preg_match('/[\x00-\x1f\x7f\\\\]/',$name))throw new InvalidArgumentException('Invalid backup path');
        $path=$parent.'/'.$name;
        if($command==='backup'){
            if(!is_dir($parent)||file_exists($path)||is_link($path))throw new InvalidArgumentException('Backup requires a new file in an existing directory');
            $backup=$store->backup();if($backup['users']===[])throw new RuntimeException('Identity not initialized');
            $temp=$parent.'/.identity-backup-'.bin2hex(random_bytes(12));try{Files::json($temp,$backup);if(!link($temp,$path))throw new RuntimeException('Cannot publish backup');Files::syncDir($parent);}finally{if(is_file($temp))unlink($temp);}
        }else{if(!is_file($path)||is_link($path)||filesize($path)>4*1024*1024)throw new InvalidArgumentException('Invalid identity backup');$store->restore(Files::read($path));}
        echo 'Identity '.$command." complete.\n";
    }else{fwrite(STDERR,"Usage (BOOK_DATA_DIR selects installation):\n  php bin/identity.php init USERNAME EMAIL 'Display name' < password-input\n  php bin/identity.php reset USERNAME\n  php bin/identity.php backup /private/new-file.json\n  php bin/identity.php restore /private/file.json\n");exit(2);}
}catch(Throwable $e){fwrite(STDERR,'Identity operation failed: '.$e->getMessage()."\n");exit(1);}
