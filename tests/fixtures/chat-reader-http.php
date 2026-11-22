<?php
declare(strict_types=1);
namespace CajeerBook {
    // Test router substitutes only CommonMark, which is not available in lean CI.
    // HTTP routing, access control, retrieval, quotas and SSE remain production code.
    final class Markdown {
        public static function e(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
        public static function render(string $md,?string $key=null,bool $editor=false,bool $studio=false,?string $linkBase=null,array $linkMap=[],?string $richPrefix=null,?int &$sharedBudget=null,int $richDepth=0,bool $allowNested=false,?\Closure $exportUrl=null):array {
            return ['html'=>'<p>'.self::e($md).'</p>','toc'=>[]];
        }
    }
}
namespace { require __DIR__.'/../oidc-bootstrap.php'; }
