<?php
declare(strict_types=1);
try {
    require dirname(__DIR__).'/bootstrap.php';
    (new CajeerBook\App(dirname(__DIR__)))->run();
} catch (Throwable $e) {
    error_log((string)$e);
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Service unavailable';
}
