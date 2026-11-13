<?php
declare(strict_types=1);
$autoload = __DIR__.'/vendor/autoload.php';
if (!is_file($autoload)) {
    throw new RuntimeException('Missing Composer dependencies. Run composer install --no-dev in the project root.');
}
require_once $autoload;
spl_autoload_register(static function(string $class): void {
    foreach (['CajeerEngine\\'=>'core/', 'CajeerBook\\'=>'src/'] as $prefix=>$dir) {
        if (str_starts_with($class,$prefix)) {
            $file=__DIR__.'/'.$dir.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
            if (is_file($file)) require $file;
        }
    }
});
