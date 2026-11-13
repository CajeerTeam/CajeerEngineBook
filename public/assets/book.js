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
            if (code.dataset.bookEnhanced || code.closest('[data-visual-panel]')) return;
            code.dataset.bookEnhanced = 'true'; highlight(code);
            const b = document.createElement('button'); b.type = 'button'; b.className = 'copy'; b.textContent = 'Copy'; b.setAttribute('aria-label', 'Copy code');
            b.addEventListener('click', async () => { try { await navigator.clipboard.writeText(code.textContent); b.textContent = 'Copied'; } catch { const range = document.createRange(); range.selectNodeContents(code); const selection = getSelection(); selection.removeAllRanges(); selection.addRange(range); b.textContent = 'Selected'; } });
            code.parentElement.append(b);
        });
    }
    window.BookCode = {enhance}; enhance();
})();
