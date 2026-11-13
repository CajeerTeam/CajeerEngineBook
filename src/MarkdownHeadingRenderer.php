<?php
declare(strict_types=1);
namespace CajeerBook;

use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Renderer\Block\HeadingRenderer;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

/** Keep 0.1.0 section-N bookmarks while new TOCs use content-derived IDs. */
final class MarkdownHeadingRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        Heading::assertInstanceOf($node);
        $legacy = $node->data->get('book_legacy_anchor', null);
        $alias = is_string($legacy) ? '<span class="heading-anchor" id="'.Markdown::e($legacy).'" aria-hidden="true"></span>' : '';
        return $alias.(string) (new HeadingRenderer())->render($node, $childRenderer);
    }
}
