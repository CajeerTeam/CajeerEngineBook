<?php
declare(strict_types=1);
namespace CajeerBook;

require_once __DIR__ . '/EngineCore.php';

final class ReleaseHealth
{
    public const MERMAID_VERSION = '11.17.2';
    /** Reject insecure/inconsistent operator settings without depending on a live IdP. */
    public static function configuration(): void
    {
        $primary=CustomDomains::primary();$providers=Oidc::configs();
        if(Oidc::required()&&$providers===[])throw new \RuntimeException('Required OIDC provider is not configured.');
        if(($primary!==null||$providers!==[])&&getenv('BOOK_SECURE_COOKIE')==='0')throw new \RuntimeException('HTTPS domains and OIDC require secure cookies.');
        if($primary!==null)foreach($providers as $oidc)if($oidc['redirect_uri']!=='https://'.$primary.Oidc::CALLBACK)throw new \RuntimeException('OIDC callback must use the primary HTTPS domain.');
        RedisTopology::config();
        if(RedisRuntime::enabled()&&!getenv('BOOK_PG_DSN'))throw new \RuntimeException('Redis requires PostgreSQL.');
    }
    public static function check(string $root): void
    {
        EngineCore::verify($root);
        if (PHP_VERSION_ID < 80401 || !extension_loaded('mbstring') || !extension_loaded('fileinfo') || !extension_loaded('phar') || !extension_loaded('zip') || !extension_loaded('openssl') || !class_exists(\Symfony\Component\Yaml\Yaml::class) || !class_exists(\League\CommonMark\Parser\MarkdownParser::class)) {
            throw new \RuntimeException('Required PHP runtime dependencies are missing');
        }
        self::configuration();
        foreach (['runtime.mjs','runtime.py','Runtime.php'] as $file) {
            if (!is_readable($root.'/resources/sdk/'.$file)) throw new \RuntimeException('SDK runtime files are missing');
        }
        foreach (['book.css','book.js','studio-editor.js','media.js','studio-media.js','page-tree.js','organizations.js','markdown-import.js','api-playground.js','diagrams.js','diagram-frame.js','static.css','static-search.js'] as $file) {
            $path=$root.'/public/assets/'.$file;
            if (!is_file($path)||!is_readable($path)||filesize($path)===0) throw new \RuntimeException('Required browser asset missing: '.$file);
        }
        $assets=$root.'/public/assets/mermaid';
        foreach (['VERSION','mermaid.min.js','LICENSE.txt'] as $file) {
            if (!is_file($assets.'/'.$file) || !is_readable($assets.'/'.$file)) throw new \RuntimeException('Local Mermaid assets are missing');
        }
        if (trim((string)file_get_contents($assets.'/VERSION'))!==self::MERMAID_VERSION || filesize($assets.'/mermaid.min.js')<100000) throw new \RuntimeException('Incorrect Mermaid asset version');
    }
}
