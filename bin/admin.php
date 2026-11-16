#!/usr/bin/env php
<?php
declare(strict_types=1);
use CajeerBook\AdminReport;
$root=dirname(__DIR__);
$cmd=$argv[1]??'';$flags=array_slice($argv,2);
if(!in_array($cmd,['report','check'],true)||array_diff($flags,['--deep','--oidc','--monitoring'])||count($flags)!==count(array_unique($flags))){
    fwrite(STDERR,"Usage: php bin/admin.php report|check [--deep] [--oidc] [--monitoring]\n");exit(2);
}
// Report dependency failures even when Composer is unavailable. Never install dependencies implicitly.
ini_set('display_errors','0');ini_set('log_errors','0');
try{
    if(is_file($root.'/vendor/autoload.php'))require $root.'/bootstrap.php';
    else spl_autoload_register(static function(string $class)use($root):void{foreach(['CajeerBook\\'=>'src/','CajeerEngine\\'=>'core/'] as $prefix=>$dir)if(str_starts_with($class,$prefix)){$file=$root.'/'.$dir.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($file))require_once $file;return;}});
    $result=(new AdminReport())->collect($root,in_array('--deep',$flags,true),in_array('--oidc',$flags,true),in_array('--monitoring',$flags,true));
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
    exit($cmd==='check'&&$result['status']!=='ok'?1:0);
}catch(Throwable){fwrite(STDERR,"Administrator report unavailable; check PHP and installation files.\n");exit(1);}
