<?php
declare(strict_types=1);
namespace CajeerBook;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;

/** Shared, non-executable Markdown renderer for Reader and Studio. */
final class Markdown
{
    public const MAX_BYTES = 262144;
    public const MAX_NESTING = 64;
    public const MAX_DELIMITERS = 1000;

    public static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function validate(string $md): void
    {
        if (strlen($md) > self::MAX_BYTES || !preg_match('//u', $md)) {
            throw new \InvalidArgumentException('Markdown must be valid UTF-8 and at most 256 KiB');
        }
    }

    /** @return array{html:string,toc:list<array{id:string,title:string,level:int}>,blocks?:list<array{source:string,html:string,kind:string}>} */
    public static function render(string $md, ?string $pageKey = null, bool $editor = false): array
    {
        self::validate($md);
        // A fresh environment per document prevents state leaking between conversions.
        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => self::MAX_NESTING,
            'max_delimiters_per_line' => self::MAX_DELIMITERS,
            'table' => [
                'wrap' => ['enabled' => true, 'attributes' => ['class' => 'markdown-table']],
                'max_autocompleted_cells' => 10000,
            ],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $environment->addRenderer(Heading::class, new MarkdownHeadingRenderer(), 100);
        $document = (new MarkdownParser($environment))->parse($md);
        $legacy = self::legacyAnchors($md);
        $toc = [];
        $used = ['main' => true, 'theme' => true];
        $nextSuffix = [];
        $invalidImages = [];
        $walker = $document->walker();
        while ($event = $walker->next()) {
            if (!$event->isEntering()) continue;
            $node = $event->getNode();
            if ($node instanceof Heading) {
                $title = self::headingText($node);
                // Slugs are independent of unrelated headings and reserve legacy/chrome IDs.
                $slug = mb_strtolower($title, 'UTF-8');
                $slug = trim(preg_replace('/[^\p{L}\p{N}\p{M}]+/u', '-', $slug) ?? '', '-');
                $base = $slug === '' ? 'section' : mb_substr($slug, 0, 100, 'UTF-8');
                if ($base === 'section' || preg_match('/\Asection-[0-9]+\z/D', $base)) $base = 'heading-'.$base;
                $id = $base;
                $n = $nextSuffix[$base] ?? 2;
                while (isset($used[$id])) $id = $base.'-'.$n++;
                $nextSuffix[$base] = $n;
                $used[$id] = true;
                $node->data->set('attributes.id', $id);
                if (isset($legacy[$node->getStartLine()])) {
                    $node->data->set('book_legacy_anchor', $legacy[$node->getStartLine()]);
                }
                $toc[] = ['id' => $id, 'title' => $title !== '' ? $title : 'Untitled section', 'level' => $node->getLevel()];
            } elseif ($node instanceof Link || $node instanceof Image) {
                $originalUrl = $node->getUrl();
                $url = self::safeUrl($originalUrl, $node instanceof Image, $pageKey);
                if ($editor && $node instanceof Link && $url !== null) $node->data->set('attributes.data-book-source-href', $originalUrl);
                // Invalid URLs remain inert. The renderer never sees an unsafe destination.
                $node->setUrl($url ?? '#');
                if ($url === null) $node->data->set('book_editor_unsafe', true);
                if ($node instanceof Image && $url === null) {
                    $invalidImages[] = $node;
                } elseif ($node instanceof Image) {
                    $node->data->set('attributes.loading', 'lazy');
                    $node->data->set('attributes.decoding', 'async');
                    $node->data->set('attributes.referrerpolicy', 'no-referrer');
                }
            } elseif ($node instanceof FencedCode) {
                $language = $node->getInfoWords()[0] ?? '';
                if (preg_match('/\A[a-zA-Z0-9_+.-]{1,64}\z/D', $language)) {
                    $node->data->set('attributes.data-language', $language);
                }
            }
        }
        // Do not detach nodes while a NodeWalker is traversing the tree.
        foreach (array_reverse($invalidImages) as $image) {
            $image->replaceWith(new Text(self::headingText($image)));
        }
        
        $renderer = new HtmlRenderer($environment);
        $result = ['html' => (string) $renderer->renderDocument($document), 'toc' => $toc];
        if ($editor) $result['blocks'] = StudioEditor::blocks($md, $document, $renderer, $result['html']);
        return $result;
    }

    /** Plain text, not rendered HTML, from the inline tree (including image alt text). */
    private static function headingText(\League\CommonMark\Node\Node $node): string
    {
        $text = '';
        $walker = $node->walker();
        while ($event = $walker->next()) {
            if (!$event->isEntering()) continue;
            $child = $event->getNode();
            if ($child instanceof Text || $child instanceof Code) $text .= $child->getLiteral();
            elseif ($child instanceof Newline) $text .= ' ';
        }
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /** Match the 0.1.0 ATX-heading counter, but only attach aliases to actual AST headings. */
    private static function legacyAnchors(string $md): array
    {
        $legacy = [];
        $fence = false;
        $n = 0;
        foreach (preg_split('/\r\n|\n|\r/', $md) ?: [] as $index => $line) {
            if ($fence) {
                if (preg_match('/^\s*```\s*$/D', $line)) $fence = false;
                continue;
            }
            if (preg_match('/^\s*```[a-zA-Z0-9_-]*\s*$/D', $line)) {
                $fence = true;
                continue;
            }
            if (preg_match('/^#{1,6}\s+.+$/u', $line)) $legacy[$index + 1] = 'section-'.(++$n);
        }
        return $legacy;
    }

    /** Browser-safe URL policy. Relative paths use the canonical Reader URL in both views. */
    private static function safeUrl(string $url, bool $image, ?string $pageKey): ?string
    {
        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $url) || $url !== trim($url, ' ') || str_starts_with($url, '//')) return null;
        if (preg_match('/\A([a-z][a-z0-9+.-]*):/i', $url, $match)) {
            $scheme = strtolower($match[1]);
            if (!in_array($scheme, $image ? ['http', 'https'] : ['http', 'https', 'mailto'], true)) return null;
            if ($scheme !== 'mailto' && !preg_match('~\Ahttps?://[^/?#]+~i', $url)) return null;
            return $url;
        }
        if ($pageKey === null || str_starts_with($url, '/')) return $url;
        // Fragment links stay local in Studio preview. Query-only and path-relative links
        // target the same page/parent as in Reader, not /studio?edit=...
        if (!$image && str_starts_with($url, '#')) return $url;
        $page = '/docs/'.$pageKey;
        if ($url === '' || str_starts_with($url, '?') || str_starts_with($url, '#')) return $page.$url;
        return substr($page, 0, strrpos($page, '/') + 1).$url;
    }
}
