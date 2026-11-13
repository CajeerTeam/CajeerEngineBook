<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Markdown.php';
use CajeerBook\Markdown;

function verify(bool $condition, string $name): void {
    if (!$condition) throw new RuntimeException($name);
    echo "PASS $name\n";
}
$url = new ReflectionMethod(Markdown::class, 'safeUrl');
$key = 'book/1.0/path/start';
foreach ([
    ['https://example.org/a',false,'https://example.org/a'],
    ['mailto:a@example.org',false,'mailto:a@example.org'],
    ['mailto:a@example.org',true,null],
    ['javascript:alert(1)',false,null],
    ['JaVaScRiPt:alert(1)',false,null],
    ["java\nscript:alert(1)",false,null],
    ['data:image/svg+xml,evil',true,null],
    ['file:///etc/passwd',false,null],
    ['//evil.example',false,null],
    ['\\evil.example',false,null],
    ['https:///evil.example',false,null],
    ['http:evil.example',false,null],
    [' https://example.org',false,null],
    ['../guide#test',false,'/docs/book/1.0/path/../guide#test'],
    ['#test',false,'#test'],
    ['?lang=en',false,'/docs/book/1.0/path/start?lang=en'],
    ['img.png',true,'/docs/book/1.0/path/img.png'],
    ['/assets/book.css',false,'/assets/book.css'],
    ['',false,'/docs/book/1.0/path/start'],
] as [$input,$image,$expected]) {
    verify($url->invoke(null,$input,$image,$key) === $expected, 'URL policy '.json_encode($input));
}
$legacy = new ReflectionMethod(Markdown::class,'legacyAnchors');
verify($legacy->invoke(null,"## First\r\n\r\n```php\r\n# code\r\n```\r\n\r\nSetext\r\n------\r\n## Second") === [1=>'section-1',9=>'section-2'], 'legacy numbering preserves original headings and skips code');
Markdown::validate(str_repeat('a',Markdown::MAX_BYTES));
verify(true,'maximum size accepted');
foreach ([str_repeat('a',Markdown::MAX_BYTES+1),"\xff"] as $invalid) {
    try { Markdown::validate($invalid); throw new RuntimeException('Invalid Markdown accepted'); }
    catch (InvalidArgumentException) { verify(true,'invalid size or UTF-8 rejected'); }
}
