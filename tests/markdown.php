<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerBook\Markdown;

function expect(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS $message\n";
}
function contains(string $needle, string $html, string $message): void {
    expect(str_contains($html, $needle), $message.' ('.$needle.')');
}

$md = <<<'MD'
# **Hello** `World` &amp; Friends

Setext title
------------

## Hello World & Friends

### Привет мир

## Section-1

## Section

## Section

> A quote
>
> - parent
>   - nested **strong** and *emphasis*

1. first
   1. child
2. second

- [x] Complete
- [ ] Pending

~~obsolete~~, **bold**, *italic*, and `literal <x>`.

| Name | Value |
| :--- | ---: |
| **PHP** | `8.4` |

Visit https://example.com/docs and www.example.com, or user@example.com.

[Reference][ref] [Relative](../guide#setup) [Anchor](#hello-world-friends) [Query](?lang=en)

[ref]: https://example.org "Reference title"

![Example *alt*](images/example.png "Image title")

~~~php
<?php echo "<unsafe>";
# not a heading
~~~

    indented code

Line one  
Line two

---
MD;
$r = Markdown::render($md, 'test/1.0/section/start');
$html = $r['html'];
expect(array_column($r['toc'], 'title') === ['Hello World & Friends','Setext title','Hello World & Friends','Привет мир','Section-1','Section','Section'], 'plain heading text and Setext TOC');
expect(array_column($r['toc'], 'id') === ['hello-world-friends','setext-title','hello-world-friends-2','привет-мир','heading-section-1','heading-section','heading-section-2'], 'unique Unicode and duplicate heading IDs');
expect(array_column($r['toc'], 'level') === [1,2,2,3,2,2,2], 'heading levels');
contains('<h1 id="hello-world-friends">', $html, 'native H1');
contains('id="section-1"', $html, 'legacy alias first ATX');
contains('id="section-2"', $html, 'Setext does not shift legacy aliases');
contains('<blockquote>', $html, 'blockquote');
expect(substr_count($html, '<ul>') >= 2 && substr_count($html, '<ol>') >= 2, 'nested lists');
contains('<strong>strong</strong>', $html, 'nested inline formatting');
contains('<em>emphasis</em>', $html, 'emphasis');
contains('type="checkbox"', $html, 'GFM task list');
expect(str_contains($html, 'checked') && str_contains($html, 'disabled'), 'read-only task list checkbox');
contains('<del>obsolete</del>', $html, 'GFM strikethrough');
contains('<table>', $html, 'GFM table');
contains('class="markdown-table"', $html, 'table scroll container');
contains('align="right"', $html, 'GFM table alignment');
contains('href="https://example.com/docs"', $html, 'GFM bare URL');
contains('href="http://www.example.com"', $html, 'GFM www autolink');
contains('href="mailto:user@example.com"', $html, 'GFM email autolink');
contains('title="Reference title"', $html, 'reference link title');
contains('href="/docs/test/1.0/section/../guide#setup"', $html, 'relative link canonical Reader context');
contains('href="#hello-world-friends"', $html, 'fragment link');
contains('href="/docs/test/1.0/section/start?lang=en"', $html, 'query-only link canonical Reader context');
contains('src="/docs/test/1.0/section/images/example.png"', $html, 'relative image canonical Reader context');
contains('alt="Example alt"', $html, 'image alt text');
contains('loading="lazy"', $html, 'lazy image');
contains('class="language-php"', $html, 'fenced code language');
contains('data-language="php"', $html, 'legacy code language metadata');
contains('&lt;?php', $html, 'escaped code');
contains('<pre><code>indented code', $html, 'indented code');
contains('<br', $html, 'hard line break');
contains('<hr', $html, 'thematic break');
expect(!str_contains($html, '<unsafe>') && count($r['toc']) === 7, 'code is not executable and does not enter TOC');

$unsafe = <<<'MD'
<script>alert(1)</script>

<img src=x onerror=alert(1)>

[bad](javascript:alert%281%29) [encoded](jav&#x61;script:alert%281%29) [data](data:text/html,boom)

[protocol relative](//example.com) [file](file:///etc/passwd) [vb](vbscript:evil)

![unsafe](data:image/svg+xml,evil) ![evil](javascript:evil) ![ok](https://example.org/logo.png)

## after image

[good](https://example.org) [mail](mailto:a@example.org)
MD;
$r = Markdown::render($unsafe, 'test/1.0/start');
$html = $r['html'];
expect(!preg_match('/<(script|iframe|svg)\b/i', $html), 'raw executable HTML escaped');
expect(!preg_match('/\b(?:href|src)="(?:javascript:|vbscript:|data:|file:|\/\/)/i', $html), 'unsafe URL schemes are inert');
expect(!preg_match('/<img[^>]*\bonerror\s*=/i', $html), 'no active event attributes');
expect(substr_count($html, '<img ') === 1, 'unsafe images replaced by text');
contains('src="https://example.org/logo.png"', $html, 'safe image after unsafe images');
contains('href="https://example.org"', $html, 'safe link after unsafe images');
expect($r['toc'][0]['id'] === 'after-image', 'walker visits headings after invalid images');

$legacy = Markdown::render("# One\n\nSetext\n======\n\n```\n# not a heading\n```\n\n## Two\n");
contains('id="section-1"', $legacy['html'], 'old first heading bookmark');
contains('id="section-2"', $legacy['html'], 'old second heading bookmark');
expect(count($legacy['toc']) === 3, 'legacy and new headings coexist');
expect(Markdown::render('## Two')['toc'][0]['id'] === 'two', 'IDs stable across unrelated renders');
expect(Markdown::render('')['toc'] === [], 'empty document');
foreach (['handbook/1.0/welcome','handbook/1.0/getting-started','handbook/1.0/authoring'] as $key) {
    $seed = require dirname(__DIR__).'/data/seed.php';
    $page = $seed['pages'][$key]['published'];
    $r = Markdown::render($page['body'], $key);
    expect($r['html'] !== '' && $r['toc'] !== [], '0.1.0 seed compatible: '.$key);
}
