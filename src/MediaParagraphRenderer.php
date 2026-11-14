<?php
declare(strict_types=1);
namespace CajeerBook;

use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

/** Decorate only recognized standalone media blocks; default paragraphs are unchanged. */
final class MediaParagraphRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): ?string
    {
        $media = $node->data->get('book_media_block', null);
        if ($media === null) return null;
        $html = $childRenderer->renderNodes($node->children());
        return $media['kind'] === 'image' ? MediaMarkup::imageHtml($html, $media) : MediaMarkup::attachmentHtml($html);
    }
}
