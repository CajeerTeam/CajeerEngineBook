<?php
declare(strict_types=1);
try {
    require dirname(__DIR__).'/bootstrap.php';
    (new CajeerBook\App(dirname(__DIR__)))->run();
} catch (Throwable $e) {
    error_log('Book bootstrap failed: '.get_class($e).': '.$e->getMessage());
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    echo 'Service unavailable';
}
