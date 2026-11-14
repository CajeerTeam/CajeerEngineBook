/* Local, text-only syntax highlighting. Unsupported languages stay plain. */
(() => {
    'use strict';
    const root = document.documentElement;
    try { const stored = localStorage.getItem('book-theme'); root.dataset.theme = stored || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'); } catch {}
    document.querySelector('#theme')?.addEventListener('click', () => { root.dataset.theme = root.dataset.theme === 'dark' ? 'light' : 'dark'; try { localStorage.setItem('book-theme', root.dataset.theme); } catch {} });
    document.querySelectorAll('[data-confirm]').forEach(b => b.addEventListener('click', e => { if (!confirm(b.dataset.confirm)) e.preventDefault(); }));
    const keywords = {
        javascript: 'const let var function return if else for while switch case break continue class extends new async await try catch finally throw import export from default typeof instanceof in of true false null undefined this super delete yield',
        typescript: 'const let var function return if else for while switch case break continue class extends new async await try catch finally throw import export from default typeof instanceof in of true false null undefined this super interface type enum implements readonly public private protected as unknown never void',
        php: 'function class interface trait enum extends implements public private protected static final abstract readonly return if else elseif foreach for while switch case break continue new try catch finally throw use namespace declare echo print require include require_once include_once true false null match fn yield as',
        python: 'def class return if elif else for while in is not and or break continue pass import from as try except finally raise with lambda yield async await True False None global nonlocal assert del',
        bash: 'if then else elif fi for while do done case esac function in export local readonly return exit set unset source echo printf',
        sql: 'SELECT FROM WHERE JOIN LEFT RIGHT INNER OUTER ON AS GROUP BY ORDER HAVING LIMIT OFFSET INSERT INTO VALUES UPDATE SET DELETE CREATE TABLE ALTER DROP DISTINCT UNION ALL AND OR NOT NULL IS IN EXISTS CASE WHEN THEN ELSE END ASC DESC TRUE FALSE',
        json: 'true false null',
    };
    const aliases = {js:'javascript', jsx:'javascript', ts:'typescript', tsx:'typescript', py:'python', sh:'bash', shell:'bash', zsh:'bash'};
    function highlight(code) {
        const rawLanguage = (code.dataset.language || [...code.classList].find(c => c.startsWith('language-'))?.slice(9) || '').toLowerCase();
        const lang = Object.hasOwn(aliases, rawLanguage) ? aliases[rawLanguage] : rawLanguage;
        const words = Object.hasOwn(keywords, lang) ? keywords[lang] : null, text = code.textContent;
        if (!words || text.length > 65536) return;
        const set = new Set(words.split(' '));
        const comments = lang === 'python' || lang === 'bash' ? '#[^\\n]*' : lang === 'sql' ? '--[^\\n]*|/\\*[\\s\\S]*?(?:\\*/|$)' : lang === 'json' ? '' : '//[^\\n]*|/\\*[\\s\\S]*?(?:\\*/|$)' + (lang === 'php' ? '|#[^\\n]*' : '');
        const pattern = new RegExp((comments ? `(${comments})|` : '((?!))|') + `("(?:\\\\[\\s\\S]|[^"\\\\])*"|'(?:\\\\[\\s\\S]|[^'\\\\])*'|\x60(?:\\\\[\\s\\S]|[^\x60\\\\])*\x60)|(\\b\\d+(?:\\.\\d+)?\\b)|(\\b[A-Za-z_$][\\w$]*\\b)`, 'g');
        // No HTML interpolation: token spans contain only text nodes.
        const fragment = document.createDocumentFragment(); let end = 0;
        for (const match of text.matchAll(pattern)) {
            if (!match[0]) continue;
            fragment.append(text.slice(end, match.index));
            const kind = match[1] ? 'comment' : match[2] ? 'string' : match[3] ? 'number' : set.has(lang === 'sql' ? match[4]?.toUpperCase() : match[4]) ? 'keyword' : '';
            if (kind) { const span = document.createElement('span'); span.className = 'syntax-' + kind; span.textContent = match[0]; fragment.append(span); } else fragment.append(match[0]);
            end = match.index + match[0].length;
        }
        fragment.append(text.slice(end)); code.replaceChildren(fragment);
    }
    function enhance(scope = document) {
        scope.querySelectorAll('pre > code').forEach(code => {
            if (code.dataset.bookEnhanced || (code.closest('[data-visual-panel]') && !code.closest('.editor-rich-preview'))) return;
            code.dataset.bookEnhanced = 'true'; highlight(code);
            const b = document.createElement('button'); b.type = 'button'; b.className = 'copy'; b.textContent = 'Copy'; b.setAttribute('aria-label', 'Copy code');
            b.addEventListener('click', async () => { try { await navigator.clipboard.writeText(code.textContent); b.textContent = 'Copied'; } catch { const range = document.createRange(); range.selectNodeContents(code); const selection = getSelection(); selection.removeAllRanges(); selection.addRange(range); b.textContent = 'Selected'; } });
            code.parentElement.append(b);
        });
    }
    window.BookCode = {enhance}; enhance();
})();

/* Accessible, local fullscreen image viewer for Reader and Preview. */
(() => {
    'use strict';
    let viewer = null;
    function openImage(figure, original, opener) {
        if (viewer) return;
        const dialog = document.createElement('dialog'); dialog.className = 'book-image-dialog';
        dialog.setAttribute('aria-label', original.alt || 'Image viewer');
        const bar = document.createElement('div'); bar.className = 'book-image-dialog-bar';
        const close = document.createElement('button'); close.type = 'button'; close.textContent = 'Close image';
        close.addEventListener('click', () => dialog.close());
        const href = original.getAttribute('src');
        if (!href) return;
        const link = document.createElement('a'); link.href = href; link.target = '_blank'; link.rel = 'noopener noreferrer'; link.textContent = 'Open original';
        const actual = document.createElement('button'); actual.type = 'button'; actual.textContent = 'Actual size'; actual.setAttribute('aria-pressed','false');
        actual.addEventListener('click', () => { const enabled = dialog.classList.toggle('book-image-actual'); actual.textContent = enabled ? 'Fit to screen' : 'Actual size'; actual.setAttribute('aria-pressed',String(enabled)); });
        bar.append(close,actual,link);
        if (/^\/(?:studio\/media\/file|media)\/[a-f0-9]{32}$/.test(href)) {
            const download = document.createElement('a'); download.href = href+'?download=1'; download.textContent = 'Download image'; bar.append(download);
        }
        const img = document.createElement('img'); img.src = href; img.alt = original.alt; img.referrerPolicy = 'no-referrer';
        dialog.append(bar,img);
        const caption = figure.querySelector('figcaption'); if (caption) { const p = document.createElement('p'); p.textContent = caption.textContent; dialog.append(p); }
        dialog.addEventListener('close', () => { dialog.remove(); viewer = null; if (opener.isConnected) opener.focus(); });
        dialog.addEventListener('click', e => { if (e.target === dialog) dialog.close(); });
        viewer = dialog; document.body.append(dialog); dialog.showModal(); close.focus();
    }
    function enhance(scope = document) {
        scope.querySelectorAll('figure[data-book-image]').forEach(figure => {
            if ((figure.closest('[data-visual-panel]') && !figure.closest('.editor-rich-preview')) || figure.querySelector('[data-image-zoom]')) return;
            const img = figure.querySelector('img'); if (!img || !img.getAttribute('src')) return;
            const zoom = document.createElement('button'); zoom.type = 'button'; zoom.dataset.imageZoom = ''; zoom.className = 'book-image-zoom'; zoom.textContent = 'View full size';
            zoom.setAttribute('aria-label', 'View full size: ' + (img.alt || 'image'));
            zoom.addEventListener('click', () => openImage(figure,img,zoom));
            figure.append(zoom);
        });
    }
    window.BookImages = {enhance}; enhance();
})();

/* Progressive enhancement: readable panels without JS; accessible tabs with JS. */
(() => {
    'use strict';
    const instances = new WeakMap();
    let nextId = 0;
    const direct = (el, selector) => [...el.children].filter(child => child.matches(selector));
    function enhance(scope = document) {
        for (const group of scope.querySelectorAll('[data-book-tabs]')) {
            if (instances.has(group)) continue;
            const panels = direct(group, '[data-tab-panel]');
            if (!panels.length) continue;
            const titles = panels.map(p => direct(p, '[data-tab-title]')[0]);
            if (titles.some(t => !t)) continue;
            const bar = document.createElement('div'); bar.className = 'book-tab-list'; bar.setAttribute('role','tablist'); bar.setAttribute('aria-label','Documentation tabs');
            const buttons = panels.map((panel,i) => {
                const button = document.createElement('button'); button.type='button'; button.textContent=titles[i].textContent;
                const id='book:tab:'+ ++nextId; button.id=id; panel.id=id+':panel';
                button.setAttribute('role','tab');button.setAttribute('aria-controls',panel.id);
                panel.setAttribute('role','tabpanel');panel.setAttribute('aria-labelledby',button.id);panel.tabIndex=0;
                titles[i].hidden=true;bar.append(button);return button;
            });
            const state={panels,buttons,activate}; instances.set(group,state);group.prepend(bar);
            function activate(index, focus=false, propagate=false) {
                buttons.forEach((b,i)=>{b.setAttribute('aria-selected',String(i===index));b.tabIndex=i===index?0:-1;panels[i].hidden=i!==index;});
                if(focus) buttons[index].focus();
                if(propagate && group.dataset.sync) {
                    // Match keys, never positional indexes. Preview state stays local to its own scope.
                    const boundary=group.closest('.editor-rich-preview,[data-live-preview]') || document;
                    for(const peer of boundary.querySelectorAll('[data-book-tabs]')) {
                        if(peer===group || peer.dataset.sync!==group.dataset.sync || (peer.closest('.editor-rich-preview,[data-live-preview]') || document)!==boundary) continue;
                        const other=instances.get(peer),j=other?.panels.findIndex(p=>p.dataset.tabKey===panels[index].dataset.tabKey);
                        if(j>=0) other.activate(j);
                    }
                }
            }
            buttons.forEach((b,i)=>{
                b.addEventListener('click',()=>activate(i,false,true));
                b.addEventListener('keydown',event=>{
                    const to=({ArrowLeft:(i+buttons.length-1)%buttons.length,ArrowRight:(i+1)%buttons.length,Home:0,End:buttons.length-1})[event.key];
                    if(typeof to==='number'){event.preventDefault();activate(to,true,true);}
                });
            });
            let initial=0;
            if(group.dataset.sync) {
                const boundary=group.closest('.editor-rich-preview,[data-live-preview]') || document;
                for(const peer of boundary.querySelectorAll('[data-book-tabs]')) {
                    if(peer===group || peer.dataset.sync!==group.dataset.sync || (peer.closest('.editor-rich-preview,[data-live-preview]') || document)!==boundary) continue;
                    const state=instances.get(peer);if(!state)continue;
                    const selected=state.buttons.findIndex(b=>b.getAttribute('aria-selected')==='true');
                    const j=panels.findIndex(p=>p.dataset.tabKey===state.panels[selected]?.dataset.tabKey);
                    if(j>=0){initial=j;break;}
                }
            }
            activate(initial);
        }
        revealHash();
    }
    function revealHash() {
        if(!location.hash) return;
        let id;try{id=decodeURIComponent(location.hash.slice(1));}catch{return;}
        const target=document.getElementById(id);if(!target)return;
        let opened=false;
        for(let p=target;p;p=p.parentElement){
            if(p.matches('details')&&!p.open){p.open=true;opened=true;}
            if(p.matches('[data-tab-panel]')){const state=instances.get(p.parentElement);if(state&&p.hidden){state.activate(state.panels.indexOf(p));opened=true;}}
        }
        if(opened)target.scrollIntoView({block:'start'});
    }
    window.BookInteractive={enhance};window.addEventListener('hashchange',revealHash);enhance();
})();
