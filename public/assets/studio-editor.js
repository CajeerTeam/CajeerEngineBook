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
        if (tag === 'input' && node.type === 'checkbox') return node.checked ? '[x] ' : '[ ] ';
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
    // GFM tables are rectangular, with a single header and column-wide alignment.
    const tableLimits = {rows: 200, columns: 50, cells: 2500};
    function checkTableSize(rows, columns) {
        if (rows < 1 || columns < 1 || rows > tableLimits.rows || columns > tableLimits.columns || rows * columns > tableLimits.cells)
            throw new Error('Visual tables are limited to 200 rows, 50 columns and 2500 cells. Use Markdown for larger tables.');
    }
    function tableMarkdown(segment) {
        const rows = [...segment.table.rows], columns = rows[0]?.cells.length || 0;
        checkTableSize(rows.length, columns);
        const line = row => '| ' + [...row.cells].map(cell => {
            // GFM removes one backslash before every pipe before parsing inline Markdown.
            // Escape once more than normal inline serialization, including inside code.
            return [...cell.childNodes].map(inline).join('').trim().replace(/\s*\n\s*/g, ' ').replace(/\|/g, '\\|');
        }).join(' | ') + ' |';
        if (rows.some(row => row.cells.length !== columns)) throw new Error('Table rows must have equal width.');
        const separators = [...rows[0].cells].map(cell => ({left: ':---', center: ':---:', right: '---:'}[cell.getAttribute('align')] || '---'));
        return [line(rows[0]), '| ' + separators.join(' | ') + ' |', ...rows.slice(1).map(line)].join('\n');
    }
    function parseDelimited(text, delimiter) {
        // RFC 4180-style quoting, including doubled quotes and multiline fields.
        if (encoder.encode(text).length > 262144) throw new Error('Pasted data exceeds 256 KiB.');
        text = text.replace(/\r\n?/g, '\n');
        const rows = []; let row = [], field = '', quoted = false, closed = false;
        const push = () => { row.push(field.replace(/\n/g, ' ')); field = ''; closed = false; if (row.length > tableLimits.columns) throw new Error('Too many columns.'); };
        const finish = () => { push(); rows.push(row); row = []; if (rows.length > tableLimits.rows) throw new Error('Too many rows.'); };
        for (let i = 0; i < text.length; i++) {
            const ch = text[i];
            if (quoted) { if (ch === '"') { if (text[i+1] === '"') { field += '"'; i++; } else { quoted = false; closed = true; } } else field += ch; continue; }
            if (ch === delimiter) { push(); continue; }
            if (ch === '\n') { finish(); continue; }
            if (closed) throw new Error('Unexpected text after a quoted field.');
            if (ch === '"' && field === '') quoted = true;
            else field += ch;
        }
        if (quoted) throw new Error('Unclosed quoted field in pasted data.');
        if (field || row.length || closed || !rows.length || !text.endsWith('\n')) finish();
        return rows;
    }
    function tableCell(segment, cell) {
        cell.contentEditable = 'true'; cell.spellcheck = true;
        cell.tabIndex = 0;
        cell.addEventListener('focus', () => { segment.cell = cell; selection = null; setActive(segment); refreshTableControls(segment); });
        cell.addEventListener('input', () => changeSegment(segment));
        cell.addEventListener('drop', e => e.preventDefault());
        cell.addEventListener('paste', e => {
            e.preventDefault();
            const text = e.clipboardData.getData('text/plain');
            try {
                if (text.includes('\t') || /[\r\n]/.test(text)) pasteGrid(segment, parseDelimited(text, '\t'));
                else document.execCommand('insertText', false, text);
                changeSegment(segment);
            } catch (err) { fail(err.message); }
        });
        cell.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); return; }
            if ((e.ctrlKey || e.metaKey) && ['z','y'].includes(e.key.toLowerCase())) { e.preventDefault(); restoreTableHistory(segment, e.key.toLowerCase() === 'y' || e.shiftKey ? 1 : -1); return; }
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); captureSelection(); command('link'); return; }
            if (e.key === 'Tab') {
                const cells = [...segment.table.rows].flatMap(row => [...row.cells]);
                const next = cells[cells.indexOf(cell) + (e.shiftKey ? -1 : 1)];
                if (next) { e.preventDefault(); next.focus(); }
            }
        });
    }
    function newCell(segment, row, index, alignment = '') {
        const cell = document.createElement(row.parentElement.tagName === 'THEAD' ? 'th' : 'td');
        if (alignment) cell.setAttribute('align', alignment);
        row.insertBefore(cell, row.cells[index] || null); tableCell(segment, cell); return cell;
    }
    function addTableRow(segment, after) {
        const table = segment.table, header = table.rows[0], body = table.tBodies[0] || table.createTBody();
        const row = body.insertRow(after); [...header.cells].forEach((cell, i) => newCell(segment, row, i, cell.getAttribute('align') || '')); return row;
    }
    function addTableColumn(segment, index) {
        [...segment.table.rows].forEach(row => newCell(segment, row, index));
    }
    function pasteGrid(segment, matrix) {
        if (!matrix.length || !matrix.every(row => row.length > 0)) throw new Error('No table data to insert.');
        const table = segment.table, rowIndex = segment.cell?.parentElement.rowIndex ?? 0, colIndex = segment.cell?.cellIndex ?? 0;
        const rows = Math.max(table.rows.length, rowIndex + matrix.length), columns = Math.max(table.rows[0].cells.length, colIndex + Math.max(...matrix.map(row => row.length)));
        checkTableSize(rows, columns);
        while (table.rows[0].cells.length < columns) addTableColumn(segment, table.rows[0].cells.length);
        while (table.rows.length < rows) addTableRow(segment, -1);
        matrix.forEach((row, y) => row.forEach((value, x) => { table.rows[rowIndex + y].cells[colIndex + x].textContent = value; }));
        segment.cell = table.rows[rowIndex].cells[colIndex]; segment.cell.focus(); changeSegment(segment);
    }
    function refreshTableControls(segment) {
        const row = segment.cell?.parentElement, r = row?.rowIndex ?? 0, c = segment.cell?.cellIndex ?? 0;
        const table = segment.table, n = table.rows.length, m = table.rows[0].cells.length;
        const disabled = {'delete-row':r === 0, 'row-up':r <= 1, 'row-down':r === 0 || r === n-1, 'delete-column':m === 1, 'column-left':c === 0, 'column-right':c === m-1, 'add-row':n >= tableLimits.rows || (n+1)*m > tableLimits.cells, 'add-column':m >= tableLimits.columns || n*(m+1) > tableLimits.cells};
        segment.controls.querySelectorAll('[data-table-action]').forEach(button => { button.disabled = !!disabled[button.dataset.tableAction]; });
        segment.controls.querySelector('[data-table-align]').value = table.rows[0].cells[c]?.getAttribute('align') || '';
        segment.controls.querySelector('[data-table-position]').textContent = `Row ${r+1}, column ${c+1} · ${n} × ${m}`;
    }
    function tableAction(segment, action) {
        const table = segment.table, row = segment.cell?.parentElement || table.rows[0], r = row.rowIndex, c = segment.cell?.cellIndex ?? 0;
        const rows = table.rows.length, cols = table.rows[0].cells.length;
        let target = segment.cell || row.cells[c];
        try {
            switch (action) {
                case 'add-row': checkTableSize(rows+1, cols); target = addTableRow(segment, r).cells[c]; break;
                case 'delete-row': if (!r) return; row.remove(); target = table.rows[Math.min(r, rows-2)].cells[c]; break;
                case 'row-up': if (r <= 1) return; row.parentElement.insertBefore(row, table.rows[r-1]); break;
                case 'row-down': if (!r || r === rows-1) return; row.parentElement.insertBefore(table.rows[r+1], row); break;
                case 'add-column': checkTableSize(rows, cols+1); addTableColumn(segment, c+1); target = row.cells[c+1]; break;
                case 'delete-column': if (cols <= 1) return; [...table.rows].forEach(tr => tr.cells[c].remove()); target = row.cells[Math.min(c, cols-2)]; break;
                case 'column-left': if (!c) return; [...table.rows].forEach(tr => tr.insertBefore(tr.cells[c], tr.cells[c-1])); break;
                case 'column-right': if (c === cols-1) return; [...table.rows].forEach(tr => tr.insertBefore(tr.cells[c+1], tr.cells[c])); break;
                default: return;
            }
            segment.cell = target; selection = null; target.focus(); refreshTableControls(segment); changeSegment(segment);
        } catch (err) { fail(err.message); }
    }
    function mountTable(segment, content, wrapper) {
        const table = content.querySelector('table'); segment.table = table; segment.content = content;
        // Table editing is cell-local: the browser cannot merge/split GFM cells.
        const controls = document.createElement('div'); controls.className = 'editor-table-controls'; segment.controls = controls;
        const position = document.createElement('span'); position.dataset.tablePosition = ''; position.setAttribute('aria-live','polite'); controls.append(position);
        for (const [action, label] of Object.entries({'add-row':'Add row', 'delete-row':'Delete row', 'row-up':'Row up', 'row-down':'Row down', 'add-column':'Add column', 'delete-column':'Delete column', 'column-left':'Column left', 'column-right':'Column right'})) {
            const b = document.createElement('button'); b.type = 'button'; b.dataset.tableAction = action; b.textContent = label; b.addEventListener('click', () => tableAction(segment, action)); controls.append(b);
        }
        const alignment = document.createElement('select'); alignment.dataset.tableAlign = ''; alignment.setAttribute('aria-label','Column alignment');
        for (const [v, label] of [['','Default'],['left','Left'],['center','Center'],['right','Right']]) alignment.add(new Option(label, v));
        alignment.addEventListener('change', () => { const c = segment.cell?.cellIndex ?? 0; [...table.rows].forEach(row => { if (alignment.value) row.cells[c].setAttribute('align', alignment.value); else row.cells[c].removeAttribute('align'); }); changeSegment(segment); });
        controls.append(alignment);
        const details = document.createElement('details'); const summary = document.createElement('summary'); summary.textContent = 'Import CSV / TSV'; details.append(summary);
        const area = document.createElement('textarea'); area.rows = 3; area.setAttribute('aria-label','Delimited table data'); area.placeholder = 'Paste CSV or tab-separated spreadsheet data here';
        const format = document.createElement('select'); format.setAttribute('aria-label','Import format'); format.add(new Option('CSV', ',')); format.add(new Option('TSV', '\t'));
        const apply = document.createElement('button'); apply.type = 'button'; apply.textContent = 'Insert data at selected cell'; apply.addEventListener('click', () => { try { if (!area.value) return; pasteGrid(segment, parseDelimited(area.value, format.value)); area.value = ''; details.open = false; } catch (err) { fail(err.message); } });
        details.append(area, format, apply); controls.append(details);
        wrapper.append(controls, content);
        [...table.rows].forEach(row => [...row.cells].forEach(cell => tableCell(segment, cell)));
        segment.cell = table.rows[0].cells[0]; segment.initialTable = tableMarkdown(segment);
        segment.history = []; segment.historyIndex = -1; recordTableHistory(segment); refreshTableControls(segment);
    }
    function taskCheckbox(checked = false) {
        const input = document.createElement('input'); input.type = 'checkbox'; input.checked = checked; input.toggleAttribute('checked', checked); input.contentEditable = 'false'; input.setAttribute('aria-label','Task completed'); return input;
    }
    function enableTasks(content, segment) {
        content.querySelectorAll('input[type=checkbox]').forEach(input => { input.disabled = false; input.contentEditable = 'false'; input.setAttribute('aria-label','Task completed'); });
        content.addEventListener('change', e => { if (e.target.matches('input[type=checkbox]')) { e.target.toggleAttribute('checked', e.target.checked); changeSegment(segment); } });
    }
    function serializeSegment(segment) {
        if (segment.deleted) return '';
        if (!segment.changed) return segment.source;
        if (['callout','cards'].includes(segment.kind)) return richMarkdown(segment) + (segment.source.match(/(?:\r\n|\r|\n)+$/)?.[0] || '');
        if (['image','attachment'].includes(segment.kind)) return mediaMarkdown(segment.media) + (segment.source.match(/(?:\r\n|\r|\n)+$/)?.[0] || '');
        if (segment.kind === 'table') { const text = tableMarkdown(segment); return segment.source && text === segment.initialTable ? segment.source : text + (segment.source.match(/(?:\r\n|\r|\n)+$/)?.[0] || ''); }
        if (segment.source && (segment.kind === 'code' ? segment.code.value === segment.initialCode && segment.language.value === segment.initialLanguage : segment.content.innerHTML === segment.initialHtml)) return segment.source;
        const text = segment.kind === 'code' ? fenceCode(segment.code.value, segment.language.value.trim()) : serializeChildren(segment.content);
        // Keep the original terminators; the document join adds a boundary only where needed.
        return text ? text + (segment.source.match(/(?:\r\n|\r|\n)+$/)?.[0] || '') : '';
    }
    function syncVisual() {
        if (mode !== 'visual') return true;
        try {
            let result = '', previousChanged = false, richCost = 0;
            for (const segment of segments) {
                if (!segment.deleted && ['callout','cards'].includes(segment.kind)) { richCost += segment.kind==='callout'?1:segment.component.cards.length; if (richCost>96) throw new Error('A page supports at most 96 callout/card bodies. Remove a component.'); }
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
    function recordTableHistory(segment) {
        if (!segment.history || segment.restoring) return;
        const state = {html: segment.table.innerHTML, row: segment.cell?.parentElement.rowIndex ?? 0, column: segment.cell?.cellIndex ?? 0};
        if (segment.history[segment.historyIndex]?.html === state.html) return;
        segment.history.splice(segment.historyIndex + 1);
        segment.history.push(state);
        // Bound memory even when a document contains thousands of cells.
        while (segment.history.length > 25 || (segment.history.length > 2 && segment.history.reduce((n, entry) => n + entry.html.length, 0) > 2000000)) segment.history.shift();
        segment.historyIndex = segment.history.length - 1;
    }
    function restoreTableHistory(segment, direction) {
        const index = segment.historyIndex + direction;
        if (index < 0 || index >= segment.history.length) return;
        const state = segment.history[index]; segment.historyIndex = index;
        segment.restoring = true;
        try {
            segment.table.innerHTML = state.html;
            [...segment.table.rows].forEach(row => [...row.cells].forEach(cell => tableCell(segment, cell)));
            const row = segment.table.rows[Math.min(state.row, segment.table.rows.length - 1)];
            segment.cell = row.cells[Math.min(state.column, row.cells.length - 1)]; selection = null; segment.cell.focus();
            refreshTableControls(segment); changeSegment(segment);
        } finally { segment.restoring = false; }
    }
    function changeSegment(segment) {
        segment.changed = true;
        if (segment.richPreview) segment.richPreview.hidden=true;
        if (segment.kind === 'table') recordTableHistory(segment);
        if (syncVisual()) updateDirty();
        else { dirty = true; clearTimeout(timer); announce('Unsaved visual changes'); }
    }
    function setActive(segment) {
        active = segment;
        blocks.querySelectorAll('.editor-block.active').forEach(el => el.classList.remove('active'));
        if (segment?.element) segment.element.classList.add('active');
        const rich = segment?.kind === 'rich' && !segment.deleted;
        const table = segment?.kind === 'table' && !segment.deleted;
        const cellCommands = ['bold','italic','strikeThrough','inlineCode','link','unlink','undo','redo'];
        toolbar.querySelectorAll('button,select').forEach(el => { el.disabled = !rich && !(table && cellCommands.includes(el.dataset.command)); });
        if (rich || table) updateToolbar();
    }
    function captureSelection() {
        const sel = window.getSelection();
        if (!active?.content || !sel?.rangeCount) return;
        const range = sel.getRangeAt(0);
        const target = active.kind === 'table' ? active.cell : active.content;
        if (target?.contains(range.commonAncestorContainer)) selection = range.cloneRange();
    }
    function restoreSelection() {
        if (!active?.content) return false;
        const target = active.kind === 'table' ? active.cell : active.content;
        if (!target) return false;
        target.focus();
        const sel = window.getSelection();
        if (selection && target.contains(selection.commonAncestorContainer)) { sel.removeAllRanges(); sel.addRange(selection); }
        else { const range = document.createRange(); range.selectNodeContents(target); range.collapse(false); sel.removeAllRanges(); sel.addRange(range); }
        return true;
    }
    function updateToolbar() {
        if (mode !== 'visual' || !['rich','table'].includes(active?.kind)) return;
        for (const button of toolbar.querySelectorAll('[data-command]')) {
            if (['bold', 'italic', 'strikeThrough', 'insertUnorderedList', 'insertOrderedList'].includes(button.dataset.command)) {
                button.setAttribute('aria-pressed', String(document.queryCommandState(button.dataset.command)));
            }
        }
    }
    function command(name, argument) {
        if (active?.kind === 'table' && ['undo','redo'].includes(name)) { restoreTableHistory(active, name === 'undo' ? -1 : 1); return; }
        if (!restoreSelection()) return;
        try {
            if (name === 'taskList') {
                const currentItem = () => { const node = window.getSelection()?.anchorNode; return (node?.nodeType === Node.ELEMENT_NODE ? node : node?.parentElement)?.closest('li'); };
                let li = currentItem();
                if (!li || !active.content.contains(li)) { document.execCommand('insertUnorderedList'); li = currentItem(); }
                if (!li || !active.content.contains(li)) throw new Error('Select a list item to add a task.');
                const parent = li.firstElementChild?.tagName === 'P' ? li.firstElementChild : li;
                const existing = parent.querySelector(':scope > input[type=checkbox]');
                if (existing) existing.remove(); else parent.prepend(taskCheckbox());
            } else if (name === 'link') {
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
    const localMedia = url => /^\/media\/[a-f0-9]{32}$/.test(url);
    function mediaMarkdown(media) {
        const text = value => escapeText(String(value).replace(/[\r\n]/g, ' '));
        if (!media || !safeUrl(media.url) || !media.url || media.url.startsWith('/studio/media/') || /^(?:mailto:|#|\?)/i.test(media.url)) throw new Error('Choose a valid image or attachment URL.');
        try { new URL(media.url, new URL('/docs/'+[value('book'),value('version'),value('path')].join('/'), document.baseURI)); } catch { throw new Error('Invalid media URL.'); }
        const target = destination(media.url.replace(/&/g, '&amp;'));
        if (media.kind === 'attachment') {
            if (!localMedia(media.url) || !media.label.trim()) throw new Error('Choose a local attachment and enter a label.');
            return '[' + text(media.label) + '](' + target + ' "book-attachment:v1")';
        }
        const caption = media.caption.replace(/[\r\n]/g,' ');
        if (encoder.encode(caption).length > 2000 || /[\x00-\x1f\x7f]/.test(caption)) throw new Error('Caption must be plain text, at most 2000 UTF-8 bytes.');
        const options = {width: Number(media.width), align: media.align, caption};
        if (![25,50,75,100].includes(options.width) || !['left','center','right'].includes(options.align)) throw new Error('Invalid image layout.');
        return '![' + text(media.alt) + '](' + target + ' "book-image:v1:' + encodeURIComponent(JSON.stringify(options)) + '")';
    }
    function mediaFromItem(item, kind) {
        return kind === 'image' ? {kind,url:'/media/'+item.id,alt:item.name,caption:'',width:100,align:'center'} : {kind,url:'/media/'+item.id,label:item.name};
    }
    function mediaPreviewUrl(media) {
        if (localMedia(media.url)) return '/studio/media/file/' + media.url.slice(7);
        return new URL(media.url, new URL('/docs/'+[value('book'),value('version'),value('path')].join('/'), document.baseURI)).href;
    }
    function mountMedia(segment, wrapper) {
        const media = segment.media;
        const controls = document.createElement('div'); controls.className = 'editor-media-controls';
        const preview = document.createElement('div'); preview.className = 'editor-media-preview';
        const fields = {};
        let figure, image, caption, attachment, previewTimer;
        if (media.kind === 'image') {
            figure = document.createElement('figure'); image = document.createElement('img');
            image.referrerPolicy = 'no-referrer'; image.loading = 'lazy'; image.decoding = 'async';
            caption = document.createElement('figcaption'); figure.append(image,caption); preview.append(figure);
        } else { attachment = document.createElement('p'); attachment.className = 'book-attachment'; preview.append(attachment); }
        function refresh() {
            if (media.kind === 'image') {
                figure.className = `book-image book-image-${media.width} book-image-${media.align}`;
                image.alt = media.alt; caption.textContent = media.caption; caption.hidden = !media.caption;
                let src = '';
                if (safeUrl(media.url) && media.url && !/^(mailto:|#|\?)/i.test(media.url)) { try { src = mediaPreviewUrl(media); } catch {} }
                // Updating a caption or width must not request the original blob again.
                if (src && image.getAttribute('src') !== src) image.src = src;
                else if (!src) image.removeAttribute('src');
            } else attachment.textContent = 'Attachment: ' + media.label;
            for (const [key, field] of Object.entries(fields)) if (field.value !== String(media[key])) field.value = String(media[key]);
        }
        function field(key, name, choices = null) {
            const label = document.createElement('label'); label.textContent = name;
            const input = document.createElement(choices ? 'select' : 'input'); input.setAttribute('aria-label',name);
            if (choices) for (const [v, text] of choices) input.add(new Option(text,v));
            else { input.type = 'text'; input.maxLength = key === 'caption' ? 2000 : key === 'url' ? 2048 : 500; }
            input.value = String(media[key]); fields[key] = input; label.append(input); controls.append(label);
            input.addEventListener('focus', () => setActive(segment));
            input.addEventListener(choices ? 'change' : 'input', () => { media[key] = key === 'width' ? Number(input.value) : input.value; if (key === 'url') { delete media.previewUrl; clearTimeout(previewTimer); previewTimer = setTimeout(refresh, 350); } else refresh(); changeSegment(segment); });
        }
        if (media.kind === 'image') {
            field('url','Image URL'); field('alt','Alternative text'); field('caption','Caption');
            field('width','Image width',[[25,'25%'],[50,'50%'],[75,'75%'],[100,'100%']]);
            field('align','Image alignment',[['left','Left'],['center','Center'],['right','Right']]);
        } else field('label','Attachment label');
        const replace = document.createElement('button'); replace.type = 'button'; replace.textContent = 'Replace from media library';
        replace.addEventListener('click', () => window.BookMedia.pick(media.kind,value('csrf'),item => {
            if (segment.deleted || mode !== 'visual') return;
            const replacement = mediaFromItem(item, media.kind); media.url = replacement.url; delete media.previewUrl;
            if (media.kind === 'image' && !media.alt) media.alt = item.name;
            if (media.kind === 'attachment') media.label = item.name;
            refresh(); changeSegment(segment);
        }));
        controls.append(replace); wrapper.append(preview,controls); refresh();
    }
    function insertMedia(kind, initialFile = null) {
        if (transitioning || !syncVisual()) return;
        window.BookMedia.pick(kind,value('csrf'),item => {
            if (transitioning || !syncVisual()) return;
            const media = mediaFromItem(item,kind);
            if (mode === 'visual') {
                const segment = {kind,media,source:'',html:'',changed:true,deleted:false};
                // Insert before the active block (or at the start). This cannot append
                // media inside an unclosed code fence or raw HTML block.
                const at = active && !active.deleted ? segments.indexOf(active) : 0;
                const next = segments.slice(Math.max(0,at)).find(s => !s.deleted && s.element);
                segments.splice(Math.max(0,at),0,segment); renderSegment(segment);
                if (next?.element) blocks.insertBefore(segment.element,next.element);
                setActive(segment); changeSegment(segment); segment.element.scrollIntoView({block:'nearest'});
            } else {
                // Switch to Markdown so authors can position the inserted block explicitly.
                const markdown = mediaMarkdown(media);
                // An unclosed fence consumes following lines; prepend instead of appending.
                const result = markdown + (source.value ? '\n\n'+source.value : '');
                if (encoder.encode(result).length > 262144) { fail('Markdown exceeds 256 KiB.'); return; }
                source.value = result; showMode('markdown'); source.focus(); source.setSelectionRange(0,markdown.length); updateDirty();
            }
        },initialFile);
    }
    // Components keep their structured fields and nested Markdown separate from the
    // document's contenteditable DOM. Only the changed component is serialized.
    const richTypes = ['note','info','tip','warning','danger','success'];
    const richIcons = ['','book','code','link','download','rocket','check','info'];
    const newCard = () => ({title:'New card',body:'',href:'',icon:'',image:'',alt:''});
    function richMarkdown(segment) {
        for (const sync of segment.bodySync.values()) sync();
        const d=segment.component;
        const text=(s,max,multi=false)=>typeof s==='string' && encoder.encode(s).length<=max && !(multi?/\x00|[\x01-\x08\x0b\x0c\x0e-\x1f\x7f]/:/[\x00-\x1f\x7f]/).test(s);
        const url=(s,image)=>!s || (text(s,2048) && safeUrl(s) && !s.startsWith('/studio/') && !/^mailto:$/i.test(s) && (!image || !/^(mailto:|#|\?)/i.test(s)));
        if (segment.kind==='callout') {
            if (!richTypes.includes(d.type) || !text(d.title,512) || !text(d.body,65536,true)) throw new Error('Invalid callout type, title or body (64 KiB maximum).');
        } else {
            if (![1,2,3,4].includes(d.columns) || d.cards.length<1 || d.cards.length>12) throw new Error('Choose 1–12 cards and 1–4 columns.');
            for (const c of d.cards) if (!text(c.title,512) || !c.title.trim() || !text(c.body,65536,true) || !url(c.href,false) || !url(c.image,true) || !text(c.alt,512) || !richIcons.includes(c.icon)) throw new Error('Invalid card: enter a title, safe link/image URLs and valid text.');
        }
        // JSON escapes line breaks in Markdown bodies. A longer fence still guards
        // literal backtick runs in values and is accepted by CommonMark.
        return fenceCode(JSON.stringify(d,null,2),segment.kind==='callout'?'book-callout':'book-cards');
    }
    function richButton(label,fn) { const b=document.createElement('button');b.type='button';b.textContent=label;b.addEventListener('click',fn);return b; }
    function richField(parent,name,object,key,segment,choices=null,onChange=null) {
        const label=document.createElement('label');label.textContent=name;
        const input=document.createElement(choices?'select':'input');input.setAttribute('aria-label',name);
        if (choices) for (const item of choices) input.add(new Option(item===''?'None':String(item),String(item)));
        else {input.type='text';input.maxLength=['href','image'].includes(key)?2048:512;}
        input.value=String(object[key]);label.append(input);parent.append(label);
        input.addEventListener('focus',()=>setActive(segment));
        input.addEventListener(choices?'change':'input',()=>{object[key]=key==='columns'?Number(input.value):input.value;onChange?.();changeSegment(segment);});
        return input;
    }
    function mountRichBody(segment,object,parent,initial,name) {
        const box=document.createElement('div');box.className='editor-rich-body';
        const label=document.createElement('div');label.className='editor-rich-body-label';label.textContent=name;
        const controls=document.createElement('div');controls.className='editor-rich-actions';
        const area=document.createElement('textarea');area.rows=5;area.setAttribute('aria-label',name+' Markdown');area.value=object.body;
        const visual=document.createElement('article');visual.className='editor-block-content editor-rich-text';visual.contentEditable='true';visual.setAttribute('role','textbox');visual.setAttribute('aria-label',name+' visual');visual.setAttribute('aria-multiline','true');visual.hidden=true;
        const formatting=document.createElement('div');formatting.className='editor-rich-actions';formatting.hidden=true;
        let editing=false,modified=false,ticket=0,range=null;
        function sync() { if (editing && modified) object.body=serializeChildren(visual); }
        segment.bodySync.set(object,sync);
        function enter(parts) {
            // Rich mode is lossless when untouched. Complex bodies (tables, code,
            // definitions, images, directives) keep full Markdown editing instead.
            if (!Array.isArray(parts) || parts.map(p=>p.source).join('')!==object.body || parts.some(p=>p.kind!=='rich' && !(p.kind==='gap' && !p.source.trim()))) return false;
            visual.replaceChildren();
            for (const p of parts) if (p.kind==='rich') { const t=document.createElement('template');t.innerHTML=p.html;visual.append(t.content); }
            visual.querySelectorAll('.heading-anchor').forEach(el=>el.remove());
            visual.querySelectorAll('[id]').forEach(el=>el.removeAttribute('id'));
            visual.querySelectorAll('a[data-book-source-href]').forEach(el=>{el.setAttribute('href',el.dataset.bookSourceHref);el.removeAttribute('data-book-source-href');});
            visual.querySelectorAll('input[type=checkbox]').forEach(el=>{el.disabled=false;el.contentEditable='false';});
            if (!visual.hasChildNodes()) {const p=document.createElement('p');p.append(document.createElement('br'));visual.append(p);}
            modified=false;editing=true;range=null;area.hidden=true;visual.hidden=false;formatting.hidden=false;return true;
        }
        function leave() { sync();area.value=object.body;editing=false;modified=false;range=null;visual.hidden=true;formatting.hidden=true;area.hidden=false; }
        const sourceButton=richButton('Markdown',()=>{++ticket;try{leave();area.focus();}catch(e){fail(e.message);}});
        const visualButton=richButton('Visual text',async()=>{
            if (editing) {visual.focus();return;}
            const current=area.value, mine=++ticket;visualButton.disabled=true;
            try { const params=parameters(current);params.set('mode','visual');const result=await request('/studio/editor/render',params);
                if (!box.isConnected || segment.deleted || mine!==ticket || area.value!==current || mode!=='visual') return;
                if (!enter(result.blocks)) {fail('This body contains complex Markdown. Edit it in Markdown mode; the full Preview supports all blocks.');return;}
                visual.focus();
            } catch(e){fail(e.message);} finally{visualButton.disabled=false;}
        });
        controls.append(visualButton,sourceButton);
        function changed(){modified=true;changeSegment(segment);}
        function capture(){const s=getSelection();if(s?.rangeCount && visual.contains(s.getRangeAt(0).commonAncestorContainer))range=s.getRangeAt(0).cloneRange();}
        for (const [command,title] of [['bold','Bold'],['italic','Italic'],['strikeThrough','Strike'],['insertUnorderedList','Bullets'],['insertOrderedList','Numbered'],['inlineCode','Code'],['link','Link'],['undo','Undo'],['redo','Redo']]) {
            const b=richButton(title,()=>{
                if (!editing)return;visual.focus();if(range && visual.contains(range.commonAncestorContainer)){const s=getSelection();s.removeAllRanges();s.addRange(range);}
                try {
                    if(command==='link'){const u=prompt('Link URL','https://');if(u===null)return;if(!u||!safeUrl(u))throw new Error('Invalid link URL.');document.execCommand('createLink',false,u);}
                    else if(command==='inlineCode'){const t=getSelection().toString();if(!t)return;const c=document.createElement('code');c.textContent=t;document.execCommand('insertHTML',false,c.outerHTML);}
                    else {document.execCommand('styleWithCSS',false,false);document.execCommand(command);}
                    capture();changed();
                }catch(e){fail(e.message);}
            });b.addEventListener('pointerdown',e=>{capture();e.preventDefault();});formatting.append(b);
        }
        area.addEventListener('focus',()=>setActive(segment));visual.addEventListener('focus',()=>setActive(segment));
        area.addEventListener('input',()=>{object.body=area.value;++ticket;changeSegment(segment);});
        visual.addEventListener('input',changed);visual.addEventListener('keyup',capture);visual.addEventListener('mouseup',capture);
        visual.addEventListener('change',e=>{if(e.target.matches('input[type=checkbox]')){e.target.toggleAttribute('checked',e.target.checked);changed();}});
        visual.addEventListener('paste',e=>{e.preventDefault();document.execCommand('insertText',false,e.clipboardData.getData('text/plain'));});
        visual.addEventListener('drop',e=>e.preventDefault());visual.addEventListener('click',e=>{if(e.target.closest('a'))e.preventDefault();});
        box.append(label,controls,formatting,area,visual);parent.append(box);
        if (initial) enter(initial);else if (!object.body) enter([]);
    }
    function mountRichComponent(segment,wrapper) {
        const d=segment.component;segment.bodySync=new Map();
        const controls=document.createElement('div');controls.className='editor-rich-controls';
        const preview=document.createElement('article');preview.className='editor-rich-preview';preview.hidden=true;segment.richPreview=preview;
        let previewTicket=0;
        const previewButton=richButton('Refresh component preview',async()=>{
            const ticket=++previewTicket;previewButton.disabled=true;
            try { const md=richMarkdown(segment),params=parameters(md);params.set('mode','preview');const r=await request('/studio/editor/render',params);
                if(ticket!==previewTicket || segment.deleted || !wrapper.isConnected || mode!=='visual')return;
                if(md!==richMarkdown(segment)){fail('Component changed during rendering. Refresh the preview again.');return;}
                showPreview(r.html);
            }catch(e){fail(e.message);}finally{previewButton.disabled=false;}
        });
        function showPreview(html) {
            preview.innerHTML=html;preview.querySelectorAll('[id]').forEach(el=>el.removeAttribute('id'));
            preview.hidden=false;window.BookCode?.enhance(preview);window.BookImages?.enhance(preview);
        }
        preview.addEventListener('click',e=>{if(e.target.closest('a'))e.preventDefault();});
        if(segment.html) showPreview(segment.html);
        wrapper.append(controls);
        if(segment.kind==='callout') {
            richField(controls,'Callout type',d,'type',segment,richTypes);
            richField(controls,'Callout title',d,'title',segment);
            mountRichBody(segment,d,controls,segment.bodyEditors?.[0],'Callout body');
        } else {
            richField(controls,'Card columns',d,'columns',segment,[1,2,3,4]);
            const list=document.createElement('div');list.className='editor-card-list';controls.append(list);
            const nodes=new Map();
            const add=richButton('Add card',()=>{if(d.cards.length>=12)return;const c=newCard();d.cards.push(c);mountCard(c,null);refresh();changeSegment(segment);});
            function refresh(){for(const c of d.cards)list.append(nodes.get(c).node);d.cards.forEach((c,i)=>{const n=nodes.get(c);n.title.textContent='Card '+(i+1);n.up.disabled=i===0;n.down.disabled=i===d.cards.length-1;n.remove.disabled=d.cards.length===1;});add.disabled=d.cards.length>=12;}
            function mountCard(c,initial){
                const node=document.createElement('section');node.className='editor-card-fields';const header=document.createElement('div');header.className='editor-rich-actions';
                const title=document.createElement('strong');header.append(title);
                const move=delta=>{const i=d.cards.indexOf(c),j=i+delta;if(j<0||j>=d.cards.length)return;[d.cards[i],d.cards[j]]=[d.cards[j],d.cards[i]];refresh();changeSegment(segment);};
                const up=richButton('Card ↑',()=>move(-1)),down=richButton('Card ↓',()=>move(1));
                const remove=richButton('Remove card',()=>{if(d.cards.length===1 || !confirm('Remove this card?'))return;d.cards.splice(d.cards.indexOf(c),1);segment.bodySync.delete(c);nodes.delete(c);node.remove();refresh();changeSegment(segment);});
                header.append(up,down,remove);node.append(header);
                richField(node,'Card title',c,'title',segment);richField(node,'Card link',c,'href',segment);richField(node,'Card icon',c,'icon',segment,richIcons);
                const image=richField(node,'Card image URL',c,'image',segment),alt=richField(node,'Card image alt',c,'alt',segment);
                node.append(richButton('Choose card image',()=>window.BookMedia.pick('image',value('csrf'),item=>{if(segment.deleted || !node.isConnected || mode!=='visual')return;c.image='/media/'+item.id;if(!c.alt)c.alt=item.name;image.value=c.image;alt.value=c.alt;changeSegment(segment);}))); 
                mountRichBody(segment,c,node,initial,'Card body');nodes.set(c,{node,title,up,down,remove});list.append(node);
            }
            d.cards.forEach((c,i)=>mountCard(c,segment.bodyEditors?.[i]));controls.append(add);refresh();
        }
        controls.append(previewButton);wrapper.append(preview);
    }
    function renderSegment(segment) {
        if (segment.kind === 'gap' && !segment.source.trim()) return;
        const wrapper = document.createElement('div'); wrapper.className = 'editor-block'; segment.element = wrapper;
        const bar = document.createElement('div'); bar.className = 'editor-block-bar';
        const label = document.createElement('span'); label.textContent = segment.kind === 'protected' || segment.kind === 'gap' ? 'Protected Markdown — edit in Markdown mode' : segment.kind === 'code' ? 'Code block' : segment.kind === 'table' ? 'Table' : segment.kind === 'image' ? 'Image' : segment.kind === 'attachment' ? 'Attachment' : segment.kind === 'callout' ? 'Callout' : segment.kind === 'cards' ? 'Cards' : 'Text block'; bar.append(label);
        if (['rich', 'code', 'table', 'image', 'attachment', 'callout', 'cards'].includes(segment.kind)) {
            const remove = document.createElement('button'); remove.type = 'button'; remove.textContent = 'Remove block';
            remove.addEventListener('click', () => { if (!window.confirm('Remove this block from the draft?')) return; segment.deleted = true; wrapper.remove(); if (active === segment) setActive(null); if (syncVisual()) updateDirty(); }); bar.append(remove);
        }
        wrapper.append(bar);
        if (['callout','cards'].includes(segment.kind)) { mountRichComponent(segment, wrapper); }
        else if (['image','attachment'].includes(segment.kind)) { mountMedia(segment, wrapper); }
        else if (segment.kind === 'code') {
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
            content.querySelectorAll('a[data-book-source-href]').forEach(el => { if (['rich','table'].includes(segment.kind)) el.setAttribute('href', el.dataset.bookSourceHref); el.removeAttribute('data-book-source-href'); });
            content.addEventListener('click', e => { if (e.target.closest('a')) e.preventDefault(); });
            if (segment.kind === 'table') { mountTable(segment, content, wrapper); }
            else if (segment.kind === 'rich') {
                enableTasks(content, segment);
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
            if (segment.kind !== 'table') wrapper.append(content);
        }
        blocks.append(wrapper);
    }
    function installBlocks(data, original) {
        if (!Array.isArray(data) || data.some(s => !['rich','code','gap','protected','table','image','attachment','callout','cards'].includes(s.kind) || (['image','attachment'].includes(s.kind) && (!s.media || s.media.kind !== s.kind)) || (['callout','cards'].includes(s.kind) && (!s.component || !Array.isArray(s.bodyEditors))) || typeof s.source !== 'string' || typeof s.html !== 'string') || data.map(s => s.source).join('') !== original) throw new Error('Visual conversion did not preserve the Markdown source. Continue in Markdown mode.');
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
            else { $('[data-live-preview]').innerHTML = result.html; window.BookCode?.enhance($('[data-live-preview]')); window.BookImages?.enhance($('[data-live-preview]')); }
            showMode(next);
        } catch (e) { fail(e.message); }
        finally { transitioning = false; lockButtons(); updateDirty(); }
    }
    async function refreshPreview() {
        if (mode !== 'preview' || transitioning) return;
        const original = source.value, ticket = ++generation;
        try { const data = parameters(); data.set('mode', 'preview'); const result = await request('/studio/editor/render', data); if (mode === 'preview' && ticket === generation && source.value === original) { $('[data-live-preview]').innerHTML = result.html; window.BookCode?.enhance($('[data-live-preview]')); window.BookImages?.enhance($('[data-live-preview]')); } }
        catch (e) { if (mode === 'preview' && ticket === generation) fail(e.message); }
    }
    function addBlock() {
        const kind = $('[data-add-kind]').value;
        if (['image','attachment'].includes(kind)) { insertMedia(kind); return; }
        if (['callout','cards'].includes(kind)) {
            const component=kind==='callout'?{v:1,type:'note',title:'',body:''}:{v:1,columns:2,cards:[newCard()]};
            const segment={kind,component,source:'',html:'',changed:true,deleted:false};
            // Insert before active block, or at the beginning, so an unclosed fence cannot consume the component.
            const at=active && !active.deleted?Math.max(0,segments.indexOf(active)):0;
            const next=segments.slice(at).find(s=>!s.deleted && s.element);segments.splice(at,0,segment);renderSegment(segment);
            if(next?.element)blocks.insertBefore(segment.element,next.element);setActive(segment);changeSegment(segment);segment.element.scrollIntoView({block:'nearest'});return;
        }
        const segment = {source: '', html: '', kind: kind === 'code' ? 'code' : kind === 'table' ? 'table' : 'rich', changed: true, deleted: false};
        const templates = {table: '<div class="markdown-table"><table><thead><tr><th>Column 1</th><th>Column 2</th></tr></thead><tbody><tr><td></td><td></td></tr></tbody></table></div>', task: '<ul><li><input type="checkbox">Task</li></ul>', p: '<p><br></p>', h2: '<h2><br></h2>', blockquote: '<blockquote><p><br></p></blockquote>', ul: '<ul><li><br></li></ul>', ol: '<ol><li><br></li></ol>', hr: '<hr>', code: '<pre><code></code></pre>'};
        segment.html = templates[kind]; segments.push(segment); renderSegment(segment);
        (segment.cell || segment.content || segment.code)?.focus(); changeSegment(segment);
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
        let list = document.querySelector('.editor-list');
        // The server returns a fully escaped nested tree only when title/order/new page changes.
        if (list && typeof result.navigation === 'string') {
            const updated = new DOMParser().parseFromString(result.navigation, 'text/html').querySelector('.editor-list');
            if (updated) { list.replaceWith(updated); list = updated; }
        }
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
    root.querySelectorAll('[data-insert-media]').forEach(button => button.addEventListener('click', () => insertMedia(button.dataset.insertMedia)));
    panels.visual.addEventListener('paste', e => {
        const file = [...(e.clipboardData?.files || [])].find(f => f.type.startsWith('image/'));
        if (file) { e.preventDefault(); e.stopImmediatePropagation(); const ext = {'image/png':'png','image/jpeg':'jpg','image/gif':'gif','image/webp':'webp'}[file.type]; insertMedia('image',ext && !/\.(png|jpe?g|gif|webp)$/i.test(file.name) ? new File([file],'clipboard.'+ext,{type:file.type}) : file); }
    }, true);
    panels.visual.addEventListener('dragover', e => { if ([...(e.dataTransfer?.types || [])].includes('Files')) e.preventDefault(); }, true);
    panels.visual.addEventListener('drop', e => {
        const file = e.dataTransfer?.files[0]; if (!file) return;
        e.preventDefault(); e.stopImmediatePropagation(); insertMedia(file.type.startsWith('image/') ? 'image' : 'attachment',file);
    }, true);
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
