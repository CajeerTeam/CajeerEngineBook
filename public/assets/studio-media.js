/* Studio media picker: no external services, no HTML interpolation. */
(() => {
    'use strict';
    const idPattern = /^[a-f0-9]{32}$/;
    const make = (tag, text) => { const el = document.createElement(tag); if (text !== undefined) el.textContent = text; return el; };
    const button = (text, fn) => { const el = make('button', text); el.type = 'button'; el.addEventListener('click', fn); return el; };
    function validItem(item, kind) {
        return item && idPattern.test(item.id) && typeof item.name === 'string' && typeof item.mime === 'string'
            && (kind !== 'image' || item.mime.startsWith('image/'));
    }
    function pick(kind, csrf, onPick, initialFile = null) {
        if (document.querySelector('[data-book-media-picker]')) return;
        const dialog = make('dialog'); dialog.className = 'book-media-dialog'; dialog.dataset.bookMediaPicker = '';
        const title = make('h2', kind === 'image' ? 'Choose image' : 'Choose attachment'); title.id = 'book-media-picker-title';
        dialog.setAttribute('aria-labelledby', title.id);
        const close = button('Close', () => dialog.close());
        const head = make('div'); head.className = 'media-picker-head'; head.append(title, close);
        const search = make('input'); search.type = 'search'; search.placeholder = 'Search name or folder'; search.maxLength = 240; search.setAttribute('aria-label', 'Search media');
        const searchButton = button('Search', () => load(1));
        const searchBar = make('div'); searchBar.className = 'media-picker-search'; searchBar.append(search, searchButton);
        const status = make('p'); status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
        const items = make('div'); items.className = 'media-picker-grid';
        let page = 1, total = 0, controller = null, uploadRequest = null, closed = false, timer;
        const previous = button('Previous', () => load(page-1)), next = button('Next', () => load(page+1));
        const pager = make('div'); pager.className = 'actions'; pager.append(previous, next);
        const uploadPanel = make('div'); uploadPanel.className = 'media-picker-upload';
        const label = make('label', 'Upload new file'); const file = make('input'); file.type = 'file';
        file.accept = kind === 'image' ? '.png,.jpg,.jpeg,.gif,.webp' : '.png,.jpg,.jpeg,.gif,.webp,.pdf,.zip,.txt,.md,.csv'; label.append(file);
        const folderLabel = make('label', 'Folder (optional)'); const folder = make('input'); folder.maxLength = 240; folder.placeholder = 'images/guides'; folderLabel.append(folder);
        const progress = make('progress'); progress.max = 100; progress.value = 0; progress.hidden = true;
        const upload = button('Upload and insert', send);
        const selected = make('p', initialFile ? `Selected: ${initialFile.name}` : '');
        let chosen = initialFile;
        file.addEventListener('change', () => { chosen = file.files[0] || null; selected.textContent = chosen ? `Selected: ${chosen.name}` : ''; });
        uploadPanel.append(label, folderLabel, selected, upload, progress);
        dialog.append(head, uploadPanel, searchBar, status, items, pager);
        function choose(item) { if (closed || uploadRequest) return; if (!validItem(item, kind)) { status.textContent = 'Invalid media selection.'; return; } dialog.close(); onPick(item); }
        function showItems(results) {
            items.replaceChildren();
            for (const item of results) {
                if (!validItem(item, kind)) continue;
                const select = button('', () => choose(item)); select.className = 'media-picker-item';
                if (item.mime.startsWith('image/')) { const img = make('img'); img.src = '/studio/media/file/' + item.id + '?thumb=1'; img.alt = ''; img.loading = 'lazy'; select.append(img); }
                else select.append(make('span', item.mime));
                select.append(make('strong', item.name), make('small', `${item.folder || 'Root'} · ${Math.ceil(item.size/1024)} KiB`));
                items.append(select);
            }
            previous.disabled = page <= 1; next.disabled = page*24 >= total;
            status.textContent = total ? `${total} matching files · Page ${page}` : 'No matching files. Upload a file or change the search.';
        }
        async function load(wanted) {
            if (closed || uploadRequest) return;
            controller?.abort(); const current = new AbortController(); controller = current;
            previous.disabled = next.disabled = true; status.textContent = 'Loading media…';
            try {
                const query = new URLSearchParams({kind, q: search.value, page: String(Math.max(1,wanted))});
                const response = await fetch('/studio/media/picker?' + query, {credentials:'same-origin',cache:'no-store',signal:current.signal,headers:{Accept:'application/json'}});
                if (!response.ok) throw new Error(`Media search failed (${response.status})`);
                const result = await response.json();
                if (closed || controller !== current) return;
                if (!Array.isArray(result.items) || !Number.isInteger(result.total) || !Number.isInteger(result.page)) throw new Error('Invalid media response');
                page = result.page; total = result.total; showItems(result.items);
            } catch (err) { if (!closed && err.name !== 'AbortError') { items.replaceChildren(); status.textContent = err.message; } }
        }
        function send() {
            if (uploadRequest) return;
            if (!chosen || chosen.size < 1 || chosen.size > 20*1024*1024) { status.textContent = 'Choose a file between 1 byte and 20 MiB.'; return; }
            if (kind === 'image' && !/\.(png|jpe?g|gif|webp)$/i.test(chosen.name)) { status.textContent = 'Choose a PNG, JPEG, GIF or WebP image.'; return; }
            clearTimeout(timer); controller?.abort(); const xhr = new XMLHttpRequest(); uploadRequest = xhr;
            upload.disabled = file.disabled = folder.disabled = search.disabled = searchButton.disabled = true; previous.disabled = next.disabled = true;
            items.querySelectorAll('button').forEach(b => b.disabled = true); progress.hidden = false; progress.value = 0; status.textContent = 'Uploading…';
            const data = new FormData(); data.append('csrf',csrf);data.append('action','upload');data.append('folder',folder.value);data.append('file',chosen);
            function finish(message) { uploadRequest = null; if (closed) return; upload.disabled = file.disabled = folder.disabled = search.disabled = searchButton.disabled = false; previous.disabled = page<=1;next.disabled=page*24>=total; items.querySelectorAll('button').forEach(b=>b.disabled=false);progress.hidden = true; status.textContent = message; }
            xhr.open('POST','/studio/media'); xhr.setRequestHeader('X-Book-Media','1'); xhr.timeout = 120000;
            xhr.upload.onprogress = event => { if (event.lengthComputable) progress.value = event.loaded/event.total*100; };
            xhr.onload = () => { if (closed) return; let result; try { result = JSON.parse(xhr.responseText); } catch {} if (xhr.status >= 200 && xhr.status < 300 && validItem(result?.item,kind)) { uploadRequest = null; choose(result.item); } else finish(result?.error || `Upload failed (${xhr.status})`); };
            xhr.onerror = () => finish('Network error'); xhr.ontimeout = () => finish('Upload timed out'); xhr.onabort = () => finish('Upload cancelled'); xhr.send(data);
        }
        search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => load(1), 250); });
        search.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); clearTimeout(timer); load(1); } });
        const opener = document.activeElement;
        dialog.addEventListener('close', () => { closed = true; clearTimeout(timer); controller?.abort(); uploadRequest?.abort(); dialog.remove(); if (opener?.isConnected) opener.focus(); });
        document.body.append(dialog); dialog.showModal(); load(1);
    }
    window.BookMedia = {pick};
})();
