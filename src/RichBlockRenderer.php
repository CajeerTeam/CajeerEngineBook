<?php
declare(strict_types=1);
namespace CajeerBook;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

/** Only pre-rendered safe components override the ordinary fenced-code renderer. */
final class RichBlockRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): ?string
    {
        return $node->data->get('book_rich_html',null);
    }
}
