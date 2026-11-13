/* CajeerEngine Book Studio — local, source-preserving visual editing. */
(() => {
    'use strict';
    const form = document.querySelector('[data-editor-form]');
    if (!form) return;
    const root = form.querySelector('[data-book-editor]');
    const $ = s => root.querySelector(s);
    const source = form.elements.body;
    const blocks = $('[data-blocks]');
    const status = $('[data-save-state]');
    const error = $('[data-editor-error]');
    const toolbar = $('.editor-toolbar');
    const modes = [...root.querySelectorAll('[data-mode]')];
    const panels = {markdown: $('[data-markdown-panel]'), visual: $('[data-visual-panel]'), preview: $('[data-preview-panel]')};
    const fieldNames = ['book', 'version', 'path', 'title', 'section', 'order', 'body'];
    const submitButtons = [...form.querySelectorAll('button[name="action"]')];
    const encoder = new TextEncoder();
    let mode = 'markdown', segments = [], active = null, selection = null;
    let pending = false, transitioning = false, conflicted = false, timer = null, previewTimer = null;
    let lastSaved = snapshot(), dirty = false, visualInvalid = false;
    let generation = 0;
    const value = name => form.elements[name].value;
    function snapshot() { return JSON.stringify(fieldNames.map(name => form.elements[name].value)); }
    function announce(message) { status.textContent = message; }
    function fail(message) { error.textContent = message; error.hidden = false; }
    function clearError() { if (conflicted) return; error.textContent = ''; error.hidden = true; }
    function updateDirty() {
        dirty = visualInvalid || snapshot() !== lastSaved;
        if (!pending && !conflicted) announce(dirty ? 'Unsaved changes' : 'Draft saved');
        if (dirty && !conflicted && Number(value('revision')) > 0) scheduleSave();
    }
    function scheduleSave() {
        clearTimeout(timer);
        timer = setTimeout(() => { if (dirty && !conflicted && !pending && !transitioning) save('save', true); }, 1800);
    }
    function lockButtons() {
        submitButtons.forEach(button => { button.disabled = pending || transitioning || conflicted; });
        modes.forEach(button => { button.disabled = transitioning; });
    }
    function showMode(next) {
        mode = next;
        for (const [name, panel] of Object.entries(panels)) panel.hidden = name !== mode;
        modes.forEach(button => button.setAttribute('aria-pressed', String(button.dataset.mode === mode)));
    }
    function parameters(body = source.value) {
        const data = new URLSearchParams();
        for (const name of [...fieldNames, 'csrf', 'revision']) data.set(name, name === 'body' ? body : value(name));
        return data;
    }
    async function request(path, data) {
        const response = await fetch(path, {method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: {'Accept': 'application/json', 'X-Book-Studio': 'editor'}, body: data});
        let result;
        try { result = await response.json(); } catch { throw Object.assign(new Error(`Request failed (${response.status}). Your changes are still in the editor.`), {status: response.status}); }
        if (!response.ok) throw Object.assign(new Error(result.error || `Request failed (${response.status})`), {status: response.status});
        return result;
    }
    // CommonMark-safe literals. Escaping ASCII punctuation avoids accidental directives,
    // lists, entities, links, HTML and marks when typing ordinary visual text.
    function escapeText(text) { return text.replace(/[!"#$%&'()*+,\-./:;<=>?@[\\\]^_`{|}~]/g, '\\$&'); }
    function codeSpan(text) {
        const run = (text.match(/`+/g) || []).reduce((n, x) => Math.max(n, x.length), 0);
        const fence = '`'.repeat(run + 1);
        const padded = /^`|`$|^ .* $/.test(text) && /[^ ]/.test(text);
        return fence + (padded ? ` ${text} ` : text) + fence;
    }
    function safeUrl(url) {
        if (typeof url !== 'string' || /[\u0000-\u0020\u007f\\]/.test(url) || url.startsWith('//')) return false;
        const scheme = /^([a-z][a-z0-9+.-]*):/i.exec(url);
        if (!scheme) return true;
        return /^mailto:/i.test(url) || /^https?:\/\/[^/?#]+/i.test(url);
    }
    function destination(url) {
        if (!safeUrl(url)) throw new Error('Unsafe link. Use HTTP(S), mailto, or a relative path.');
        return '<' + url.replace(/</g, '%3C').replace(/>/g, '%3E') + '>';
    }
    function marked(text, mark) {
        const m = /^(\s*)([\s\S]*?)(\s*)$/.exec(text);
        if (!m[2]) return text;
        return m[1] + mark + m[2] + mark + m[3];
    }
    function inline(node) {
        if (node.nodeType === Node.TEXT_NODE) return escapeText(node.nodeValue.replace(/\u00a0/g, ' '));
        if (node.nodeType !== Node.ELEMENT_NODE) return '';
        const tag = node.tagName.toLowerCase();
        if (tag === 'br') return '  \n';
        if (tag === 'code') return codeSpan(node.textContent.replace(/\r?\n/g, ' '));
        const content = () => [...node.childNodes].map(inline).join('');
        if (['b', 'strong'].includes(tag)) return marked(content(), '**');
        if (['i', 'em'].includes(tag)) return marked(content(), '*');
        if (['s', 'strike', 'del'].includes(tag)) return marked(content(), '~~');
        if (tag === 'a') {
            const href = node.getAttribute('href') || '';
            const title = node.getAttribute('title');
            return '[' + content() + '](' + destination(href) + (title ? ' "' + title.replace(/[\\"]/g, '\\$&') + '"' : '') + ')';
        }
        if (tag === 'span') {
            if (node.classList.contains('heading-anchor')) return '';
            if (node.getAttribute('style')) throw new Error('Unsupported inline formatting. Use Markdown mode to preserve your text.');
            return content();
        }
        throw new Error(`Unsupported visual element <${tag}>. Undo the change or use Markdown mode.`);
    }
    const blockTags = new Set(['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'blockquote', 'hr', 'pre']);
    function serializeChildren(parent) {
        const result = [];
        let text = '';
        const flush = () => { if (text.trim()) result.push(text.trim()); text = ''; };
        for (const node of parent.childNodes) {
            if (node.nodeType === Node.ELEMENT_NODE && blockTags.has(node.tagName.toLowerCase())) {
                flush(); const md = block(node); if (md !== '') result.push(md);
            } else text += inline(node);
        }
        flush();
        return result.join('\n\n');
    }
    function list(node) {
        const ordered = node.tagName === 'OL';
        let number = Number(node.getAttribute('start') || '1');
        if (!Number.isInteger(number) || number < 0 || number > 999999999) throw new Error('Invalid list numbering');
        const items = [];
        for (const child of node.children) {
            if (child.tagName !== 'LI') throw new Error('Unsupported list structure');
            const marker = ordered ? `${number++}. ` : '- ';
            let content = '', inlineText = '', parts = [];
            const flush = () => { if (inlineText.trim()) parts.push({text: inlineText.trim(), nested: false}); inlineText = ''; };
            for (const item of child.childNodes) {
                if (item.nodeType === Node.ELEMENT_NODE && blockTags.has(item.tagName.toLowerCase())) {
                    flush(); const text = block(item); if (text) parts.push({text, nested: ['UL', 'OL'].includes(item.tagName)});
                } else inlineText += inline(item);
            }
            flush();
            parts.forEach((part, i) => { content += (i ? (part.nested ? '\n' : '\n\n') : '') + part.text; });
            const lines = content.split('\n');
            items.push(marker + lines[0] + lines.slice(1).map(line => '\n' + (line ? ' '.repeat(marker.length) + line : '')).join(''));
        }
        return items.join('\n');
    }
    function block(node) {
        const tag = node.tagName.toLowerCase();
        if (tag === 'ul' || tag === 'ol') return list(node);
        if (tag === 'blockquote') return serializeChildren(node).split('\n').map(line => line ? '> ' + line : '>').join('\n');
        if (tag === 'hr') return '---';
        if (tag === 'pre') throw new Error('Use a dedicated code block for code.');
        if (/^h[1-6]$/.test(tag)) {
            const text = [...node.childNodes].map(inline).join('').trim().replace(/\s*\n\s*/g, ' ');
            return text ? '#'.repeat(Number(tag[1])) + ' ' + text : '';
        }
        return serializeChildren(node);
    }
    function fenceCode(text, language) {
        if (!/^[a-zA-Z0-9_+.-]{0,64}$/.test(language)) throw new Error('Invalid code language (letters, digits, _, +, . and - only).');
        const max = (text.match(/`+/g) || []).reduce((n, x) => Math.max(n, x.length), 2);
        const fence = '`'.repeat(max + 1);
        return fence + language + '\n' + text + (text ? '\n' : '') + fence;
    }
    function serializeSegment(segment) {
        if (segment.deleted) return '';
        if (!segment.changed) return segment.source;
        if (segment.source && (segment.kind === 'code' ? segment.code.value === segment.initialCode && segment.language.value === segment.initialLanguage : segment.content.innerHTML === segment.initialHtml)) return segment.source;
        const text = segment.kind === 'code' ? fenceCode(segment.code.value, segment.language.value.trim()) : serializeChildren(segment.content);
        // Keep the original terminators; the document join adds a boundary only where needed.
        return text ? text + (segment.source.match(/(?:\r\n|\r|\n)+$/)?.[0] || '') : '';
    }
    function syncVisual() {
        if (mode !== 'visual') return true;
        try {
            let result = '', previousChanged = false;
            for (const segment of segments) {
                const text = serializeSegment(segment), changed = text !== segment.source;
                if (!text.trim()) { result += text; previousChanged ||= changed; continue; }
                if (result.trim() && (changed || previousChanged) && !/\n[ \t]*\n[ \t]*$/.test(result)) result += /\n[ \t]*$/.test(result) ? '\n' : '\n\n';
                result += text; previousChanged = changed;
            }
            if (encoder.encode(result).length > 262144) throw new Error('Markdown exceeds 256 KiB. Reduce document size.');
            source.value = result;
            visualInvalid = false;
            clearError();
            return true;
        } catch (e) { visualInvalid = true; fail(e.message); return false; }
    }
    function changeSegment(segment) {
        segment.changed = true;
        if (syncVisual()) updateDirty();
        else { dirty = true; clearTimeout(timer); announce('Unsaved visual changes'); }
    }
    function setActive(segment) {
        active = segment;
        blocks.querySelectorAll('.editor-block.active').forEach(el => el.classList.remove('active'));
        if (segment?.element) segment.element.classList.add('active');
        const rich = segment?.kind === 'rich' && !segment.deleted;
        toolbar.querySelectorAll('button,select').forEach(el => { el.disabled = !rich; });
        if (rich) updateToolbar();
    }
    function captureSelection() {
        const sel = window.getSelection();
        if (!active?.content || !sel?.rangeCount) return;
        const range = sel.getRangeAt(0);
        if (active.content.contains(range.commonAncestorContainer)) selection = range.cloneRange();
    }
    function restoreSelection() {
        if (!active?.content) return false;
        active.content.focus();
        const sel = window.getSelection();
        if (selection && active.content.contains(selection.commonAncestorContainer)) { sel.removeAllRanges(); sel.addRange(selection); }
        else { const range = document.createRange(); range.selectNodeContents(active.content); range.collapse(false); sel.removeAllRanges(); sel.addRange(range); }
        return true;
    }
    function updateToolbar() {
        if (mode !== 'visual' || active?.kind !== 'rich') return;
        for (const button of toolbar.querySelectorAll('[data-command]')) {
            if (['bold', 'italic', 'strikeThrough', 'insertUnorderedList', 'insertOrderedList'].includes(button.dataset.command)) {
                button.setAttribute('aria-pressed', String(document.queryCommandState(button.dataset.command)));
            }
        }
    }
    function command(name, argument) {
        if (!restoreSelection()) return;
        try {
            if (name === 'link') {
                const sel = window.getSelection();
                if (sel.isCollapsed) { fail('Select text before inserting a link.'); return; }
                const url = window.prompt('Link URL (HTTP(S), mailto or relative path)', 'https://');
                if (url === null) return;
                if (!url || !safeUrl(url)) { fail('Invalid or unsafe link URL.'); return; }
                restoreSelection(); document.execCommand('createLink', false, url);
            } else if (name === 'inlineCode') {
                const selected = window.getSelection().toString();
                if (!selected) { fail('Select text to format as inline code.'); return; }
                const code = document.createElement('code'); code.textContent = selected;
                document.execCommand('insertHTML', false, code.outerHTML);
            } else {
                if (['indent','outdent'].includes(name) && !active.content.contains(window.getSelection()?.anchorNode?.parentElement?.closest('li'))) { fail('Indent and outdent are available inside list items.'); return; }
                document.execCommand('styleWithCSS', false, false);
                document.execCommand(name, false, argument ?? null);
            }
            captureSelection(); changeSegment(active); updateToolbar();
        } catch (e) { fail(e.message); }
    }
    function renderSegment(segment) {
        if (segment.kind === 'gap' && !segment.source.trim()) return;
        const wrapper = document.createElement('div'); wrapper.className = 'editor-block'; segment.element = wrapper;
        const bar = document.createElement('div'); bar.className = 'editor-block-bar';
        const label = document.createElement('span'); label.textContent = segment.kind === 'protected' || segment.kind === 'gap' ? 'Protected Markdown — edit in Markdown mode' : segment.kind === 'code' ? 'Code block' : 'Text block'; bar.append(label);
        if (['rich', 'code'].includes(segment.kind)) {
            const remove = document.createElement('button'); remove.type = 'button'; remove.textContent = 'Remove block';
            remove.addEventListener('click', () => { if (!window.confirm('Remove this block from the draft?')) return; segment.deleted = true; wrapper.remove(); if (active === segment) setActive(null); if (syncVisual()) updateDirty(); }); bar.append(remove);
        }
        wrapper.append(bar);
        if (segment.kind === 'code') {
            const temp = document.createElement('template'); temp.innerHTML = segment.html;
            const code = temp.content.querySelector('pre>code');
            const lang = document.createElement('input'); lang.type = 'text'; lang.maxLength = 64; lang.placeholder = 'Language (optional)'; lang.setAttribute('aria-label', 'Code language'); lang.value = code?.dataset.language || '';
            const area = document.createElement('textarea'); area.rows = 5; area.spellcheck = false; area.setAttribute('aria-label', 'Code content'); area.value = (code?.textContent || '').replace(/\n$/, '');
            segment.code = area; segment.language = lang; segment.initialCode = area.value; segment.initialLanguage = lang.value; wrapper.append(lang, area);
            for (const el of [lang, area]) { el.addEventListener('focus', () => setActive(segment)); el.addEventListener('input', () => changeSegment(segment)); }
        } else {
            const content = document.createElement('article'); content.className = 'editor-block-content';
            if (segment.kind === 'gap') { const pre = document.createElement('pre'); pre.textContent = segment.source; content.append(pre); }
            else content.innerHTML = segment.html;
            // Server output is the shared safe renderer. Remove reader-only anchors/IDs;
            // visual editing keeps the original href rather than the preview's resolved URL.
            content.querySelectorAll('.heading-anchor').forEach(el => el.remove());
            content.querySelectorAll('[id]').forEach(el => el.removeAttribute('id'));
            content.querySelectorAll('a[data-book-source-href]').forEach(el => { if (segment.kind === 'rich') el.setAttribute('href', el.dataset.bookSourceHref); el.removeAttribute('data-book-source-href'); });
            content.addEventListener('click', e => { if (e.target.closest('a')) e.preventDefault(); });
            if (segment.kind === 'rich') {
                content.contentEditable = 'true'; content.setAttribute('role', 'textbox'); content.setAttribute('aria-label', 'Visual text block'); content.setAttribute('aria-multiline', 'true');
                segment.content = content; segment.initialHtml = content.innerHTML;
                content.addEventListener('focus', () => { if (active !== segment) selection = null; setActive(segment); });
                content.addEventListener('input', () => changeSegment(segment));
                content.addEventListener('paste', e => { e.preventDefault(); document.execCommand('insertText', false, e.clipboardData.getData('text/plain')); });
                content.addEventListener('drop', e => e.preventDefault());
                content.addEventListener('keydown', e => {
                    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); captureSelection(); command('link'); }
                });
            } else content.classList.add('editor-protected');
            wrapper.append(content);
        }
        blocks.append(wrapper);
    }
    function installBlocks(data, original) {
        if (!Array.isArray(data) || data.some(s => !['rich','code','gap','protected'].includes(s.kind) || typeof s.source !== 'string' || typeof s.html !== 'string') || data.map(s => s.source).join('') !== original) throw new Error('Visual conversion did not preserve the Markdown source. Continue in Markdown mode.');
        segments = data.map(s => ({...s, changed: false, deleted: false})); blocks.replaceChildren(); active = null; selection = null;
        segments.forEach(renderSegment);
        if (!original.trim()) { const initial = {source: '', html: '<p><br></p>', kind: 'rich', changed: false, deleted: false}; segments.push(initial); renderSegment(initial); }
        setActive(null);
    }
    async function switchMode(next) {
        if (transitioning || next === mode) return;
        if (!syncVisual()) return;
        if (next === 'markdown') { showMode(next); clearError(); return; }
        const original = source.value, ticket = ++generation;
        transitioning = true; lockButtons(); clearError(); announce('Rendering…');
        try {
            const data = parameters(original); data.set('mode', next);
            const result = await request('/studio/editor/render', data);
            if (ticket !== generation || source.value !== original) throw new Error('Source changed during rendering. Switch modes again.');
            if (next === 'visual') { if (typeof document.execCommand !== 'function') throw new Error('This browser does not support visual editing. Markdown mode is available.'); installBlocks(result.blocks, original); }
            else $('[data-live-preview]').innerHTML = result.html;
            showMode(next);
        } catch (e) { fail(e.message); }
        finally { transitioning = false; lockButtons(); updateDirty(); }
    }
    async function refreshPreview() {
        if (mode !== 'preview' || transitioning) return;
        const original = source.value, ticket = ++generation;
        try { const data = parameters(); data.set('mode', 'preview'); const result = await request('/studio/editor/render', data); if (mode === 'preview' && ticket === generation && source.value === original) $('[data-live-preview]').innerHTML = result.html; }
        catch (e) { if (mode === 'preview' && ticket === generation) fail(e.message); }
    }
    function addBlock() {
        const kind = $('[data-add-kind]').value;
        const segment = {source: '', html: '', kind: kind === 'code' ? 'code' : 'rich', changed: true, deleted: false};
        const templates = {p: '<p><br></p>', h2: '<h2><br></h2>', blockquote: '<blockquote><p><br></p></blockquote>', ul: '<ul><li><br></li></ul>', ol: '<ol><li><br></li></ol>', hr: '<hr>', code: '<pre><code></code></pre>'};
        segment.html = templates[kind]; segments.push(segment); renderSegment(segment);
        (segment.content || segment.code)?.focus(); changeSegment(segment);
    }
    async function save(action, automatic = false) {
        if (pending || transitioning || conflicted || !syncVisual()) return;
        if (!form.reportValidity()) return;
        if (!value('title').trim() || !value('book') || !value('version') || !value('path')) { if (!automatic) fail('Book, version, path and title are required.'); return; }
        if (encoder.encode(source.value).length > 262144) { fail('Markdown exceeds 256 KiB.'); return; }
        clearTimeout(timer); clearError();
        const data = parameters(); data.set('action', action);
        const sent = snapshot(); const oldRevision = value('revision');
        pending = true; for (const name of ['book','version','path']) form.elements[name].readOnly = true; lockButtons(); announce(action === 'publish' ? 'Publishing…' : 'Saving draft…');
        try {
            const result = await request('/studio', data);
            if (typeof result.key !== 'string' || !Number.isSafeInteger(result.revision) || result.revision !== Number(oldRevision) + 1) throw new Error('Unexpected save response. Reload before saving again.');
            form.elements.revision.value = String(result.revision); lastSaved = sent;
            for (const name of ['book','version','path']) form.elements[name].readOnly = true;
            updatePageActions(result);
            const savedPreview = document.querySelector('[data-saved-preview]');
            if (savedPreview) savedPreview.hidden = true;
            history.replaceState(null, '', '/studio?edit=' + encodeURIComponent(result.key));
            dirty = visualInvalid || snapshot() !== lastSaved;
            announce(dirty ? 'Saved snapshot; newer changes pending' : action === 'publish' ? 'Published' : 'Draft saved');
            if (dirty) scheduleSave();
        } catch (e) {
            if (e.status === 409) { conflicted = true; announce('Revision conflict'); fail('Another session changed this page. Your edits are retained. Copy your Markdown before reloading; autosave is stopped.'); }
            else { announce('Save failed'); fail(e.message); }
        } finally { pending = false; if (Number(value('revision')) === 0 && !conflicted) for (const name of ['book','version','path']) form.elements[name].readOnly = false; lockButtons(); }
    }
    function updatePageActions(result) {
        const list = document.querySelector('.editor-list');
        if (list) {
            let row = [...list.querySelectorAll('[data-page-key]')].find(el => el.dataset.pageKey === result.key);
            if (!row) { row = document.createElement('div'); row.dataset.pageKey = result.key; const link = document.createElement('a'); link.href = '/studio?edit=' + encodeURIComponent(result.key); link.textContent = result.key; row.append(link, ' ', document.createElement('small')); list.append(row); }
            row.querySelector('small').textContent = result.published ? 'Published' : 'Draft';
        }
        let actions = document.querySelector('[data-page-actions]');
        if (!actions) {
            actions = document.createElement('form'); actions.method = 'post'; actions.action = '/studio'; actions.className = 'actions'; actions.dataset.pageActions = '';
            for (const [name, field] of Object.entries({csrf: value('csrf'), key: result.key, revision: String(result.revision)})) { const input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = field; actions.append(input); }
            for (const action of ['unpublish','delete']) { const button = document.createElement('button'); button.name = 'action'; button.value = action; button.textContent = action === 'delete' ? 'Delete' : 'Unpublish'; actions.append(button); }
            form.after(actions);
            actions.addEventListener('submit', e => { if (e.submitter?.value === 'delete' && !confirm('Delete this page permanently?')) e.preventDefault(); });
            bindPageActions(actions);
        }
        actions.elements.revision.value = String(result.revision);
        actions.querySelector('[value="unpublish"]').disabled = !result.published;
    }
    function bindPageActions(actions) { actions?.addEventListener('submit', e => { if (pending || dirty || conflicted) { e.preventDefault(); fail('Save or discard your unsaved edits before deleting or unpublishing.'); } }); }
    form.addEventListener('submit', e => { e.preventDefault(); save(e.submitter?.value === 'publish' ? 'publish' : 'save'); });
    form.addEventListener('input', e => { if (fieldNames.includes(e.target.name)) { updateDirty(); if (mode === 'preview') { clearTimeout(previewTimer); previewTimer = setTimeout(refreshPreview, 300); } } });
    modes.forEach(button => button.addEventListener('click', () => switchMode(button.dataset.mode)));
    $('[data-add]').addEventListener('click', addBlock);
    toolbar.addEventListener('pointerdown', e => { captureSelection(); if (e.target.closest('button')) e.preventDefault(); });
    toolbar.addEventListener('click', e => { const button = e.target.closest('[data-command]'); if (button && !button.disabled) command(button.dataset.command); });
    $('[data-format]').addEventListener('change', e => { command('formatBlock', e.target.value); e.target.value = 'p'; });
    document.addEventListener('selectionchange', () => { captureSelection(); updateToolbar(); });
    document.addEventListener('keydown', e => { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save('save'); } });
    window.addEventListener('beforeunload', e => { if (dirty || pending) { e.preventDefault(); e.returnValue = ''; } });
    bindPageActions(document.querySelector('[data-page-actions]'));
    $('[data-copy-source]').addEventListener('click', async () => {
        if (!syncVisual()) return;
        try { await navigator.clipboard.writeText(source.value); announce('Markdown copied'); }
        catch { showMode('markdown'); source.focus(); source.select(); announce('Markdown selected — use your copy shortcut'); }
    });
    const savedPreview = document.querySelector('[data-saved-preview]'); if (savedPreview) savedPreview.hidden = true;
    $('[data-enhanced]').hidden = false;
    lockButtons();
    switchMode('visual');
})();
