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
        'League\\CommonMark\\Extension\\TaskList\\TaskListItemMarker',
        'League\\CommonMark\\Extension\\Table\\Table',
        'League\\CommonMark\\Extension\\Table\\TableSection',
        'League\\CommonMark\\Extension\\Table\\TableRow',
        'League\\CommonMark\\Extension\\Table\\TableCell',
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
            if ($kind === 'rich' && $node instanceof \League\CommonMark\Extension\Table\Table) $kind = 'table';
            if ($kind === 'rich' && ($node instanceof \League\CommonMark\Extension\CommonMark\Node\Block\FencedCode || $node instanceof \League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode)) $kind = 'code';
            $component = $node->data->get('book_rich_data', null);
            if ($component !== null) {
                $out[] = ['source'=>$raw,'html'=>$renderer->renderNodes([$node]),'kind'=>isset($component['cards'])?'cards':'callout',
                    'component'=>$component,'bodyEditors'=>$node->data->get('book_rich_editors',[])];
                $cursor=$end; continue;
            }
            if ($node instanceof \League\CommonMark\Extension\CommonMark\Node\Block\FencedCode && RichBlocks::reserved(trim($node->getInfo()??''))) $kind='protected';
            $media = $node->data->get('book_media_block', null);
            // A reference definition can share a paragraph's source range. Preserve it verbatim.
            if ($media !== null && ($media['kind'] !== 'attachment' || self::plainAttachment($node)) && !str_contains($raw, ']:') && !preg_match('/^\h*:{3,}/m', $raw)) {
                $out[] = ['source'=>$raw, 'html'=>$renderer->renderNodes([$node]), 'kind'=>$media['kind'], 'media'=>$media];
            } else $out[] = ['source' => $raw, 'html' => $renderer->renderNodes([$node]), 'kind' => $media !== null ? 'protected' : $kind];
            $cursor = $end;
        }
        if ($cursor < count($lines)) $out[] = ['source' => implode('', array_slice($lines, $cursor)), 'html' => '', 'kind' => 'gap'];
        return $out;
    }

    private static function plainAttachment(Node $node): bool
    {
        $link = $node->firstChild();
        if ($link === null) return false;
        foreach ($link->children() as $child) if (!$child instanceof \League\CommonMark\Node\Inline\Text) return false;
        return true;
    }

    private static function editable(Node $node, string $source): bool
    {
        // A definition can belong to the source range of a paragraph, yet be absent from its AST.
        // Custom directives and definitions remain editable in Markdown mode without normalization.
        if (str_contains($source, ']:') || str_contains($source, '![') || preg_match('/^\h*:{3,}/m', $source)) return false;
        $table = $node instanceof \League\CommonMark\Extension\Table\Table;
        $cells = 0;
        $walker = $node->walker();
        while ($event = $walker->next()) {
            if (!$event->isEntering()) continue;
            $child = $event->getNode();
            if (!in_array(get_class($child), self::EDITABLE, true) || $child->data->get('book_editor_unsafe', false)) return false;
            // Nested tables cannot be represented by a standalone GFM table editor.
            if ($child instanceof \League\CommonMark\Extension\Table\Table && !$table) return false;
            if ($child instanceof \League\CommonMark\Extension\Table\TableCell && ++$cells > 2500) return false;
            // Keep nested code and complex code fence attributes in source mode.
            if ($child instanceof \League\CommonMark\Extension\CommonMark\Node\Block\FencedCode) {
                if ($child !== $node || !preg_match('/\A[a-zA-Z0-9_+.-]{0,64}\z/D', trim($child->getInfo() ?? ''))) return false;
            }
            if ($child instanceof \League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode && $child !== $node) return false;
        }
        if ($table) {
            // GFM silently discards surplus cells. Do not normalize a table whose
            // raw rows contain data absent from the rendered model.
            $rows = 0; $columns = 0;
            foreach ($node->children() as $section) {
                foreach ($section->children() as $row) {
                    ++$rows;
                    if ($columns === 0) foreach ($row->children() as $cell) ++$columns;
                }
            }
            if ($columns < 1 || $columns > 50 || $rows > 200) return false;
            $lines = preg_split('/\r\n|\r|\n/', rtrim($source, "\r\n")) ?: [];
            if (count($lines) !== $rows + 1) return false;
            foreach ($lines as $line) {
                if (self::sourceCellCount($line) > $columns) return false;
            }
        }
        return true;
    }

    /** Count raw GFM cells, including cells the renderer would truncate. */
    private static function sourceCellCount(string $line): int
    {
        $line = trim($line);
        $count = 0; $hasContent = false;
        for ($i = str_starts_with($line, '|') ? 1 : 0, $length = strlen($line); $i < $length; ++$i) {
            if ($line[$i] === "\\" && ($line[$i + 1] ?? '') === '|') {
                $hasContent = true; ++$i;
            } elseif ($line[$i] === '|') {
                ++$count; $hasContent = false;
            } else $hasContent = true;
        }
        return $count + (int) $hasContent;
    }

    public static function markup(string $source): string
    {
        $buttons = [
            'bold' => 'Bold', 'italic' => 'Italic', 'strikeThrough' => 'Strike', 'inlineCode' => 'Inline code',
            'insertUnorderedList' => 'Bullets', 'insertOrderedList' => 'Numbered list', 'indent' => 'Indent', 'outdent' => 'Outdent',
            'taskList' => 'Task list', 'link' => 'Link', 'unlink' => 'Unlink', 'undo' => 'Undo', 'redo' => 'Redo',
        ];
        $toolbar = '<label class="editor-format-label">Block style<select data-format aria-label="Block style"><option value="p">Paragraph</option>';
        for ($i = 1; $i <= 6; $i++) $toolbar .= '<option value="h'.$i.'">Heading '.$i.'</option>';
        $toolbar .= '<option value="blockquote">Quote</option></select></label>';
        foreach ($buttons as $command => $label) $toolbar .= '<button type="button" data-command="'.$command.'">'.$label.'</button>';
        return '<section class="book-editor" data-book-editor><div class="editor-mode" hidden data-enhanced>'
            .'<div role="group" aria-label="Editor mode"><button type="button" data-mode="visual" aria-pressed="false">Visual</button><button type="button" data-mode="markdown" aria-pressed="true">Markdown</button><button type="button" data-mode="preview" aria-pressed="false">Preview</button></div>'
            .'<button type="button" data-insert-media="image">Insert image</button><button type="button" data-insert-media="attachment">Insert attachment</button><button type="button" data-copy-source>Copy Markdown</button><span data-save-state role="status" aria-live="polite">Draft loaded</span></div>'
            .'<div data-markdown-panel><label>Markdown<textarea name="body" rows="16" spellcheck="false">'."\n".Markdown::e($source).'</textarea></label></div>'
            .'<div data-visual-panel hidden><div class="editor-toolbar" role="toolbar" aria-label="Formatting">'.$toolbar.'</div>'
            .'<p class="editor-hint">Edit a block directly. Use the toolbar to format text, or add a block below. Edit tables directly, or paste spreadsheet cells. Insert images and attachments from your media library. Add callouts and cards with structured properties and Markdown or visual text. Unsupported inline media remains intact in Markdown mode.</p>'
            .'<div data-blocks class="editor-blocks"></div><div class="editor-add"><label>Add block<select data-add-kind aria-label="New block type"><option value="p">Paragraph</option><option value="h2">Heading</option><option value="blockquote">Quote</option><option value="ul">Bullet list</option><option value="ol">Numbered list</option><option value="code">Code block</option><option value="table">Table</option><option value="task">Task list</option><option value="image">Image</option><option value="attachment">Attachment</option><option value="callout">Callout</option><option value="cards">Cards</option><option value="hr">Divider</option></select></label><button type="button" data-add>Add block</button></div></div>'
            .'<div data-preview-panel hidden><article data-live-preview></article></div><p data-editor-error class="editor-error" role="alert" hidden></p></section>';
    }
}
