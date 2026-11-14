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
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Block\Paragraph;
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
    public static function render(string $md, ?string $pageKey = null, bool $editor = false, bool $studio = false, ?string $linkBase = null, array $linkMap = [], ?string $richPrefix = null, ?int &$sharedBudget = null, int $richDepth = 0, bool $allowNested = false): array
    {
        self::validate($md);
        $environment = self::environment();
        $document = (new MarkdownParser($environment))->parse($md);
        $legacy = $richPrefix === null ? self::legacyAnchors($md) : [];
        $sharedBudget ??= RichBlocks::MAX_BUDGET; $richBudget =& $sharedBudget; $richIndex = 0; $fragmentLinks = [];
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
                $node->data->set('attributes.id', ($richPrefix ?? '').$id);
                if (isset($legacy[$node->getStartLine()])) {
                    $node->data->set('book_legacy_anchor', $legacy[$node->getStartLine()]);
                }
                $toc[] = ['id' => ($richPrefix ?? '').$id, 'title' => $title !== '' ? $title : 'Untitled section', 'level' => $node->getLevel()];
            } elseif ($node instanceof Link || $node instanceof Image) {
                $originalUrl = $node->getUrl();
                $url = self::safeUrl($originalUrl, $node instanceof Image, $pageKey, $linkBase);
                if ($url !== null && $node instanceof Link && $linkMap) $url = PageTree::rewriteLink($url,$linkMap);
                if ($editor && $node instanceof Link && $url !== null) $node->data->set('attributes.data-book-source-href', $originalUrl);
                // Invalid URLs remain inert. The renderer never sees an unsafe destination.
                if (($studio || $editor) && $url !== null) $url=self::previewMediaUrl($url);
                // Standalone images remain standard Markdown images; presentation metadata
                // is carried in their optional title. Only canonical local attachment links
                // acquire a download query, AFTER reference recognition and preview rewriting.
                $parent = $node->parent();
                if ($url !== null && $parent instanceof Paragraph && $parent->parent() instanceof Document
                    && $parent->firstChild() === $node && $parent->lastChild() === $node) {
                    if ($node instanceof Image) {
                        $options = MediaMarkup::imageOptions($node->getTitle());
                        // Invalid reserved metadata must not be normalized by Visual mode.
                        $title = $node->getTitle();
                        if ($originalUrl !== '' && !preg_match('/^[#?]/', $originalUrl)
                            && ($options !== null || !str_starts_with($title ?? '', MediaMarkup::IMAGE_PREFIX))) {
                            $parent->data->set('book_media_block', ['kind'=>'image', 'url'=>$originalUrl,
                                'previewUrl'=>$url, 'alt'=>self::headingText($node)] + ($options ?? ['width'=>100,'align'=>'center','caption'=>$title ?? '']));
                            if ($options !== null) $node->setTitle(null);
                        }
                    } elseif ($node->getTitle() === MediaMarkup::ATTACHMENT_TITLE && preg_match('~\A/media/[a-f0-9]{32}\z~D',$originalUrl) === 1) {
                        $parent->data->set('book_media_block', ['kind'=>'attachment','url'=>$originalUrl,'label'=>self::headingText($node)]);
                        $node->setTitle(null);
                        $url .= '?download=1';
                        $node->data->set('attributes.download', '');
                    }
                }
                $node->setUrl($url ?? '#');
                if ($richPrefix !== null && $node instanceof Link && $url !== null && str_starts_with($url,'#')) $fragmentLinks[]=$node;
                if ($url === null) $node->data->set('book_editor_unsafe', true);
                if ($node instanceof Image && $url === null) {
                    $invalidImages[] = $node;
                } elseif ($node instanceof Image) {
                    $node->data->set('attributes.loading', 'lazy');
                    $node->data->set('attributes.decoding', 'async');
                    $node->data->set('attributes.referrerpolicy', 'no-referrer');
                }
            } elseif ($node instanceof FencedCode) {
                $info = trim($node->getInfo() ?? '');
                $component = ($richPrefix === null || $allowNested) && $richDepth < RichBlocks::MAX_DEPTH && $node->parent() instanceof Document ? RichBlocks::parse($info,$node->getLiteral()) : null;
                if ($component !== null && RichBlocks::cost($component) <= $richBudget) {
                    $richBudget -= RichBlocks::cost($component); ++$richIndex;
                    $bodyHtml=[]; $bodyEditors=[];
                    foreach (RichBlocks::bodies($component) as $i=>$body) {
                        $inner=self::render($body,$pageKey,$editor,$studio,$linkBase,$linkMap,($richPrefix ?? 'book:rich:').$richIndex.':'.$i.':',$richBudget,$richDepth+1,RichBlocks::interactive($component) && RichBlocks::kind($component)!=='code-tabs');
                        $bodyHtml[]=$inner['html'];
                        if ($editor) $bodyEditors[]=$inner['blocks'];
                    }
                    $resolve=static function(string $url,bool $image) use ($pageKey,$linkBase,$linkMap,$studio,$editor): ?string {
                        $url=self::safeUrl($url,$image,$pageKey,$linkBase);
                        if ($url!==null && !$image && $linkMap) $url=PageTree::rewriteLink($url,$linkMap);
                        if ($url!==null && ($studio || $editor)) $url=self::previewMediaUrl($url);
                        return $url;
                    };
                    $node->data->set('book_rich_html',RichBlocks::html($component,$bodyHtml,$resolve));
                    $node->data->set('book_rich_data',$component);
                    if ($editor) $node->data->set('book_rich_editors',$bodyEditors);
                }
                $language = $node->getInfoWords()[0] ?? '';
                if (strtolower(trim($node->getInfo() ?? '')) === 'mermaid') $node->data->set('attributes.data-book-mermaid', 'true');
                if (preg_match('/\A[a-zA-Z0-9_+.-]{1,64}\z/D', $language)) {
                    $node->data->set('attributes.data-language', $language);
                }
            }
        }
        if ($richPrefix !== null) {
            $anchors=array_fill_keys(array_column($toc,'id'),true);
            foreach ($fragmentLinks as $link) {
                $target=$richPrefix.rawurldecode(substr($link->getUrl(),1));
                if (isset($anchors[$target])) $link->setUrl('#'.$target);
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

    private static function environment(): Environment
    {
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
        $environment->addRenderer(Paragraph::class, new MediaParagraphRenderer(), 100);
        $environment->addRenderer(FencedCode::class, new RichBlockRenderer(), 100);
        return $environment;
    }

    private static function mediaId(string $url): ?string
    {
        return preg_match('~\A/media/([a-f0-9]{32})(?:[?#].*)?\z~D',$url,$m) ? $m[1] : null;
    }

    private static function previewMediaUrl(string $url): string
    {
        return self::mediaId($url)!==null ? '/studio/media/file/'.substr($url,7) : $url;
    }

    /** Only actual CommonMark links/images (including reference links), never code or prose. */
    public static function mediaReferences(string $md,string $pageKey,bool $allowRich=true, ?int &$sharedBudget=null, int $depth=0): array
    {
        // Canonical local media paths are the supported authoring contract.
        // Parse all documents so entity-escaped/reference destinations are handled too.
        self::validate($md);
        $document=(new MarkdownParser(self::environment()))->parse($md);
        $ids=[];$walker=$document->walker();$sharedBudget ??= RichBlocks::MAX_BUDGET;$budget =& $sharedBudget;
        while($event=$walker->next()){
            if(!$event->isEntering()) continue;
            $node=$event->getNode();
            if ($allowRich && $depth < RichBlocks::MAX_DEPTH && $node instanceof FencedCode && $node->parent() instanceof Document) {
                $component=RichBlocks::parse(trim($node->getInfo()??''),$node->getLiteral());
                if ($component!==null && RichBlocks::cost($component)<=$budget) {
                    $budget-=RichBlocks::cost($component);
                    foreach (RichBlocks::bodies($component) as $body) foreach (self::mediaReferences($body,$pageKey,RichBlocks::interactive($component) && RichBlocks::kind($component)!=='code-tabs',$budget,$depth+1) as $id) $ids[$id]=true;
                    foreach ($component['cards']??[] as $card) foreach (['href','image'] as $field) {
                        $url=self::safeUrl($card[$field],$field==='image',$pageKey);
                        if ($url!==null && ($id=self::mediaId($url))!==null) $ids[$id]=true;
                    }
                }
            }
            if($node instanceof Link || $node instanceof Image){
                $url=self::safeUrl($node->getUrl(),$node instanceof Image,$pageKey);
                if($url!==null && ($id=self::mediaId($url))!==null) $ids[$id]=true;
            }
        }
        return array_keys($ids);
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
    private static function safeUrl(string $url, bool $image, ?string $pageKey, ?string $linkBase = null): ?string
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
        if ($linkBase !== null) $page = '/docs/'.$linkBase;
        return substr($page, 0, strrpos($page, '/') + 1).$url;
    }
}
