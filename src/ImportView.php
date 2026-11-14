<?php
declare(strict_types=1);
namespace CajeerBook;
final class ImportView
{
    public static function render(array $db,string $csrf):string{
        $e=Markdown::e(...);$options='';foreach(BookStore::books($db) as $book)$options.='<option value="'.$e($book['slug']).'">'.$e($book['title']).'</option>';
        return '<h1>Import Markdown</h1><p>Import files, a folder or a ZIP as drafts. Existing publications remain unchanged. Review the plan before applying.</p><form class="editor" data-markdown-import method="post" enctype="multipart/form-data" action="'.$e(WorkspaceUrls::url('/studio/import')).'">'
            .'<input type="hidden" name="csrf" value="'.$e($csrf).'"><label>Book slug<input name="book" required maxlength="64" list="import-books" placeholder="guide"></label><datalist id="import-books">'.$options.'</datalist><label>Version<input name="version" required maxlength="64" value="1.0"></label>'
            .'<label>On existing page<select name="strategy"><option value="skip">Skip existing pages</option><option value="update">Update drafts (keep publications)</option><option value="rename">Rename imported pages</option></select></label>'
            .'<label>Source<select name="mode"><option value="files">Files</option><option value="directory">Directory</option><option value="zip">ZIP archive</option></select></label>'
            .'<label data-import-input="files">Markdown and attachments<input type="file" name="files[]" multiple required></label><label data-import-input="directory" hidden>Documentation directory<input type="file" name="files[]" webkitdirectory multiple disabled></label><label data-import-input="zip" hidden>ZIP archive<input type="file" name="files[]" accept=".zip,application/zip" disabled></label>'
            .'<p class="muted">Up to 200 files, 20 MiB per file, 64 MiB expanded; HTTP upload limit applies. Markdown must be UTF-8. Supported assets: PNG, JPEG, GIF, WebP, PDF, ZIP, TXT, CSV. Use ZIP for large folder imports.</p>'
            .'<div class="actions"><button type="submit" name="action" value="preview" class="primary">Preview import</button><button type="button" data-import-apply disabled>Apply as drafts</button></div></form><noscript><p>JavaScript is required for preview and confirmation.</p></noscript><p role="status" data-import-status aria-live="polite"></p><section data-import-result hidden></section>';
    }
}
