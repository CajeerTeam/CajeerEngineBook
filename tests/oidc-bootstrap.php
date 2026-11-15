<?php
// Test-only loader: exercise real App auth routes even on a minimal PHP/OpenSSL installation.
$root=dirname(__DIR__);
if(is_file($root.'/vendor/autoload.php'))require $root.'/bootstrap.php';
else spl_autoload_register(static function(string $class)use($root):void{
    foreach(['CajeerEngine\\'=>'core/','CajeerBook\\'=>'src/'] as $prefix=>$dir)if(str_starts_with($class,$prefix)){
        $p=$root.'/'.$dir.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($p))require $p;
    }
});
if(PHP_SAPI==='cli-server')(new CajeerBook\App($root))->run();
