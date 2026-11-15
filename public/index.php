<?php
declare(strict_types=1);
try {
    require dirname(__DIR__).'/bootstrap.php';
    if (getenv('BOOK_OBSERVABILITY_EXPORTER')==='1') { CajeerBook\Diagnostics::serve(); return; }
    (new CajeerBook\App(dirname(__DIR__)))->run();
} catch (Throwable $e) {
    require_once dirname(__DIR__).'/src/Observability.php';
    $id=bin2hex(random_bytes(16));CajeerBook\Observability::startRequest($id);
    header('X-Request-ID: '.$id);
    CajeerBook\Observability::log('bootstrap.failure',['error_type'=>get_class($e)]);
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    echo 'Service unavailable';
    CajeerBook\Observability::finishRequest();
}
