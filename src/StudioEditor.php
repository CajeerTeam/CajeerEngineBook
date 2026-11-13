<?php
declare(strict_types=1);
namespace CajeerBook;

use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\HtmlRenderer;

/** Source-preserving visual editor: only explicitly supported AST blocks may be rewritten. */
final class StudioEditor
{
    private const EDITABLE = [
        'League\\CommonMark\\Node\\Block\\Paragraph',
        'League\\CommonMark\\Extension\\CommonMark\\Node\\Block\\Heading',
        'League\\CommonMark\\Extension\\CommonMark\\Node\\Block\\BlockQuote',
        'League\\CommonMark\\Extension\\CommonMark\\Node\\Block\\ListBlock',
        'League\\CommonMark\\Extension\\CommonMark\\Node\\Block\\ListItem',
        'League\\CommonMark\\Extension\\CommonMark\\Node\\Block\\FencedCode',
        'League\\CommonMark\\Extension\\CommonMark\\Node\\Block\\IndentedCode',
        'League\\CommonMark\\Extension\\CommonMark\\Node\\Block\\ThematicBreak',
        'League\\CommonMark\\Node\\Inline\\Text',
        'League\\CommonMark\\Node\\Inline\\Newline',
        'League\\CommonMark\\Extension\\CommonMark\\Node\\Inline\\Code',
        'League\\CommonMark\\Extension\\CommonMark\\Node\\Inline\\Emphasis',
        'League\\CommonMark\\Extension\\CommonMark\\Node\\Inline\\Strong',
        'League\\CommonMark\\Extension\\CommonMark\\Node\\Inline\\Link',
        'League\\CommonMark\\Extension\\Strikethrough\\Strikethrough',
    ];

    /** @return list<array{source:string,html:string,kind:string}> */
    public static function blocks(string $source, Document $document, HtmlRenderer $renderer, string $html): array
    {
        // Keep line terminators and gaps, including reference definitions not in the AST.
        preg_match_all('/[^\r\n]*(?:\r\n|\r|\n|$)/', $source, $matches);
        $lines = $matches[0];
        if (end($lines) === '') array_pop($lines);
        $out = [];
        $cursor = 0;
        foreach ($document->children() as $node) {
            $start = ($node->getStartLine() ?? 0) - 1;
            $end = $node->getEndLine() ?? 0;
            if ($start < $cursor || $end <= $start || $end > count($lines)) {
                // Never guess source boundaries when the parser cannot provide them.
                return [['source' => $source, 'html' => $html, 'kind' => 'protected']];
            }
            if ($start > $cursor) {
                $out[] = ['source' => implode('', array_slice($lines, $cursor, $start - $cursor)), 'html' => '', 'kind' => 'gap'];
            }
            $raw = implode('', array_slice($lines, $start, $end - $start));
            $kind = self::editable($node, $raw) ? 'rich' : 'protected';
            if ($kind === 'rich' && ($node instanceof \League\CommonMark\Extension\CommonMark\Node\Block\FencedCode || $node instanceof \League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode)) $kind = 'code';
            $out[] = ['source' => $raw, 'html' => $renderer->renderNodes([$node]), 'kind' => $kind];
            $cursor = $end;
        }
        if ($cursor < count($lines)) $out[] = ['source' => implode('', array_slice($lines, $cursor)), 'html' => '', 'kind' => 'gap'];
        return $out;
    }

    private static function editable(Node $node, string $source): bool
    {
        // A definition can belong to the source range of a paragraph, yet be absent from its AST.
        // Custom directives and definitions remain editable in Markdown mode without normalization.
        if (str_contains($source, ']:') || str_contains($source, '![') || preg_match('/^\h*:{3,}/m', $source)) return false;
        $walker = $node->walker();
        while ($event = $walker->next()) {
            if (!$event->isEntering()) continue;
            $child = $event->getNode();
            if (!in_array(get_class($child), self::EDITABLE, true) || $child->data->get('book_editor_unsafe', false)) return false;
            // Keep nested code and complex code fence attributes in source mode.
            if ($child instanceof \League\CommonMark\Extension\CommonMark\Node\Block\FencedCode) {
                if ($child !== $node || !preg_match('/\A[a-zA-Z0-9_+.-]{0,64}\z/D', trim($child->getInfo() ?? ''))) return false;
            }
            if ($child instanceof \League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode && $child !== $node) return false;
        }
        return true;
    }

    public static function markup(string $source): string
    {
        $buttons = [
            'bold' => 'Bold', 'italic' => 'Italic', 'strikeThrough' => 'Strike', 'inlineCode' => 'Inline code',
            'insertUnorderedList' => 'Bullets', 'insertOrderedList' => 'Numbered list', 'indent' => 'Indent', 'outdent' => 'Outdent',
            'link' => 'Link', 'unlink' => 'Unlink', 'undo' => 'Undo', 'redo' => 'Redo',
        ];
        $toolbar = '<label class="editor-format-label">Block style<select data-format aria-label="Block style"><option value="p">Paragraph</option>';
        for ($i = 1; $i <= 6; $i++) $toolbar .= '<option value="h'.$i.'">Heading '.$i.'</option>';
        $toolbar .= '<option value="blockquote">Quote</option></select></label>';
        foreach ($buttons as $command => $label) $toolbar .= '<button type="button" data-command="'.$command.'">'.$label.'</button>';
        return '<section class="book-editor" data-book-editor><div class="editor-mode" hidden data-enhanced>'
            .'<div role="group" aria-label="Editor mode"><button type="button" data-mode="visual" aria-pressed="false">Visual</button><button type="button" data-mode="markdown" aria-pressed="true">Markdown</button><button type="button" data-mode="preview" aria-pressed="false">Preview</button></div>'
            .'<button type="button" data-copy-source>Copy Markdown</button><span data-save-state role="status" aria-live="polite">Draft loaded</span></div>'
            .'<div data-markdown-panel><label>Markdown<textarea name="body" rows="16" spellcheck="false">'."\n".Markdown::e($source).'</textarea></label></div>'
            .'<div data-visual-panel hidden><div class="editor-toolbar" role="toolbar" aria-label="Formatting">'.$toolbar.'</div>'
            .'<p class="editor-hint">Edit a block directly. Use the toolbar to format text, or add a block below. Tables, images and other protected blocks remain intact in Markdown mode.</p>'
            .'<div data-blocks class="editor-blocks"></div><div class="editor-add"><label>Add block<select data-add-kind aria-label="New block type"><option value="p">Paragraph</option><option value="h2">Heading</option><option value="blockquote">Quote</option><option value="ul">Bullet list</option><option value="ol">Numbered list</option><option value="code">Code block</option><option value="hr">Divider</option></select></label><button type="button" data-add>Add block</button></div></div>'
            .'<div data-preview-panel hidden><article data-live-preview></article></div><p data-editor-error class="editor-error" role="alert" hidden></p></section>';
    }
}
