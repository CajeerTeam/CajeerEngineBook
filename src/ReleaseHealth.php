<?php
declare(strict_types=1);
namespace CajeerBook;

final class ReleaseHealth
{
    public const MERMAID_VERSION = '11.17.2';
    public static function check(string $root): void
    {
        if (PHP_VERSION_ID < 80401 || !extension_loaded('mbstring') || !extension_loaded('fileinfo') || !extension_loaded('phar') || !class_exists(\League\CommonMark\Parser\MarkdownParser::class)) {
            throw new \RuntimeException('Required PHP runtime dependencies are missing');
        }
        $assets=$root.'/public/assets/mermaid';
        foreach (['VERSION','mermaid.min.js','LICENSE.txt'] as $file) {
            if (!is_file($assets.'/'.$file) || !is_readable($assets.'/'.$file)) throw new \RuntimeException('Local Mermaid assets are missing');
        }
        if (trim((string)file_get_contents($assets.'/VERSION'))!==self::MERMAID_VERSION || filesize($assets.'/mermaid.min.js')<100000) throw new \RuntimeException('Incorrect Mermaid asset version');
    }
}
