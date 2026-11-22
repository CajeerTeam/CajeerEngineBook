<?php

declare(strict_types=1);

use CajeerBook\EngineCore;
use CajeerEngine\Runtime\ServiceContainer;
use CajeerEngine\Template\CajeerTemplateEngine;
use CajeerEngine\Template\TemplateSandbox;
use CajeerEngine\Update\OperationFiles;

$root = dirname(__DIR__);
require_once $root . '/src/EngineCore.php';
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'CajeerEngine\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = $root . '/core/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

function assertEngine(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException($description);
    }
    echo "PASS {$description}\n";
}

function rejectEngine(callable $operation, string $description): void
{
    try {
        $operation();
    } catch (Throwable) {
        echo "PASS {$description}\n";
        return;
    }
    throw new RuntimeException($description . ': operation unexpectedly succeeded');
}

function cleanupEngine(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (new DirectoryIterator($path) as $item) {
        if (!$item->isDot()) {
            cleanupEngine($item->getPathname());
        }
    }
    rmdir($path);
}

EngineCore::verify($root);
assertEngine(trim((string) file_get_contents($root . '/core/ENGINE_VERSION')) === EngineCore::VERSION, 'CajeerEngine 2.1.0 upstream source hashes and version');

$container = new ServiceContainer();
$created = 0;
$container->set('book', static function (ServiceContainer $services) use (&$created): object {
    $created++;
    return (object) ['ready' => $services->has('book')];
});
assertEngine($container->get('book')->ready && $container->get('book') === $container->get('book') && $created === 1, 'service container resolves shared Book dependencies');
$container->set('new', static function () use (&$created): int { return ++$created; }, false);
assertEngine($container->get('new') !== $container->get('new'), 'service container honors non-shared services');

$tmp = sys_get_temp_dir() . '/cajeer-book-engine-' . bin2hex(random_bytes(8));
OperationFiles::dir($tmp);
try {
    $templates = $tmp . '/templates';
    OperationFiles::dir($templates);
    file_put_contents($templates . '/page.cjr', '<h1>{{ $title }}</h1>@if ($left < $right)<p>Less</p>@endif');
    $engine = new CajeerTemplateEngine(new TemplateSandbox($templates), $templates);
    $rendered = $engine->renderName('page', ['title' => '<script>', 'left' => 1, 'right' => 3]);
    assertEngine($rendered === '<h1>&lt;script&gt;</h1><p>Less</p>', '2.1 CJR runtime renders Book templates with escaping and conditions');
    rejectEngine(static fn () => $engine->render($tmp . '/outside.cjr'), 'template sandbox denies files outside template root');

    $write = $tmp . '/nested/secure/value.json';
    OperationFiles::write($write, '{"ready":true}', 0600);
    assertEngine(OperationFiles::read($write) === ['ready' => true] && OperationFiles::hash($write) === hash('sha256', '{"ready":true}'), 'durable nested file writes and JSON reads');
    rejectEngine(static fn () => OperationFiles::path($tmp, '../escape'), 'filesystem helper rejects parent traversal');

    $safe = $tmp . '/safe';
    $outside = $tmp . '/outside';
    OperationFiles::dir($safe);
    OperationFiles::dir($outside);
    $link = $safe . '/link';
    if (@symlink($outside, $link)) {
        rejectEngine(static fn () => OperationFiles::dir($link . '/not-created'), '2.1 directory helper rejects symlink ancestors');
        assertEngine(!is_dir($outside . '/not-created'), 'symlink ancestor cannot redirect directory creation');
        rejectEngine(static fn () => OperationFiles::write($link . '/not-created.json', '{}'), '2.1 file writes reject symlink ancestors');
        assertEngine(!is_file($outside . '/not-created.json'), 'symlink ancestor cannot redirect file write');
    } elseif (DIRECTORY_SEPARATOR === '/') {
        throw new RuntimeException('Unable to create symlink on POSIX runner');
    } else {
        echo "SKIP symlink exercise: operating system has no unprivileged symlink support\n";
    }

    $copyRoot = $tmp . '/copy';
    foreach (['Runtime/ServiceContainer.php', 'Template/CajeerTemplateEngine.php', 'Template/TemplateCompiler.php', 'Template/TemplateExpressionEvaluator.php', 'Template/TemplateSandbox.php', 'Update/OperationFiles.php'] as $relative) {
        $source = $root . '/core/' . $relative;
        $target = $copyRoot . '/core/' . $relative;
        OperationFiles::dir(dirname($target));
        copy($source, $target);
    }
    copy($root . '/core/ENGINE_VERSION', $copyRoot . '/core/ENGINE_VERSION');
    EngineCore::verify($copyRoot);
    file_put_contents($copyRoot . '/core/Update/OperationFiles.php', "\n", FILE_APPEND);
    rejectEngine(static fn () => EngineCore::verify($copyRoot), 'readiness rejects modified upstream class');
    copy($root . '/core/Update/OperationFiles.php', $copyRoot . '/core/Update/OperationFiles.php');
    file_put_contents($copyRoot . '/core/ENGINE_VERSION', "1.3.0\n");
    rejectEngine(static fn () => EngineCore::verify($copyRoot), 'readiness rejects stale 1.3.0 version');
} finally {
    cleanupEngine($tmp);
}
echo "OK CajeerEngine 2.1.0 embedded Book core\n";
