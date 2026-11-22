<?php
// Test-only loader: exercise real App auth routes even on a minimal PHP/OpenSSL installation.
$root=dirname(__DIR__);
if(is_file($root.'/vendor/autoload.php'))require $root.'/bootstrap.php';
else spl_autoload_register(static function(string $class)use($root):void{
    foreach(['CajeerEngine\\'=>'core/','CajeerBook\\'=>'src/'] as $prefix=>$dir)if(str_starts_with($class,$prefix)){
        $p=$root.'/'.$dir.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($p))require $p;
    }
});
if(PHP_SAPI==='cli-server'){
    // Keep the test HTTP router faithful to production: the built-in PHP server
    // needs an explicit return false to serve static JS/CSS assets itself.
    // Only allow actual files inside the public assets tree, never PHP or paths
    // with traversal segments, backslashes or encoded separators.
    $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
    if(is_string($path)
       && preg_match('~\A/assets/(?:[a-z0-9][a-z0-9._-]*/){0,3}[a-z0-9][a-z0-9._-]*\.(?:js|css)\z~D',$path)
       && is_file($root.'/public'.$path))return false;
    if(getenv('BOOK_OBSERVABILITY_EXPORTER')==='1')CajeerBook\Diagnostics::serve();
    else (new CajeerBook\App($root))->run();
}
