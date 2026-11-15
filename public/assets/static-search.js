/* No fetch, server, CDN or storage: search works from a ZIP extracted to file://. */
(() => {
    'use strict';
    const form = document.querySelector('form.search'), input = form?.querySelector('input[name=q]');
    const language = form?.querySelector('[name=locale]');
    const validLocale = value => ['en','ru'].includes(value) ? value : '';
    const status = document.querySelector('[data-search-status]'), results = document.querySelector('[data-search-results]');
    if (!form || !input) return;
    if (!status || !results) {
        form.addEventListener('submit', event => {
            event.preventDefault();
            const target = new URL(form.getAttribute('action'), location.href);
            target.searchParams.set('q', input.value.trim().slice(0,200));
            if (language) target.searchParams.set('locale', validLocale(language.value));
            location.href = target.href;
        });
        return;
    }
    const index = Array.isArray(window.BOOK_STATIC_INDEX) ? window.BOOK_STATIC_INDEX : [];
    const fold = value => value.normalize('NFKC').toLocaleLowerCase();
    const rows = index.map(row => ({row, title: fold(row.title), path: fold(row.path), text: fold(row.text)}));
    const MAX_RESULTS = 50;
    function excerpt(row, tokens) {
        const source = row.text, lower = fold(source);
        let at = -1;
        for (const token of tokens) { const found = lower.indexOf(token); if (found >= 0 && (at < 0 || found < at)) at = found; }
        const start = Math.max(0, at - 70), end = Math.min(source.length, start + 220);
        return (start > 0 ? '…' : '') + source.slice(start, end) + (end < source.length ? '…' : '');
    }
    function render(query, locale = '') {
        query = query.trim().slice(0, 200); input.value = query; locale = validLocale(locale); if (language) language.value = locale; results.replaceChildren();
        if (!query) { status.textContent = 'Enter a search term.'; return; }
        const phrase = fold(query), tokens = [...new Set(phrase.split(/\s+/u).filter(Boolean))];
        const hits = [];
        for (const row of rows) {
            if (locale && (row.row.locale || 'en') !== locale) continue;
            if (!tokens.every(token => row.title.includes(token) || row.path.includes(token) || row.text.includes(token))) continue;
            let score = row.title === phrase ? 1000 : row.title.includes(phrase) ? 100 : 0;
            for (const token of tokens) score += (row.title.includes(token) ? 20 : 0) + (row.path.includes(token) ? 8 : 0) + (row.text.includes(token) ? 1 : 0);
            hits.push({row: row.row, score});
        }
        hits.sort((a,b) => b.score - a.score || a.row.path.localeCompare(b.row.path));
        status.textContent = `${hits.length} result${hits.length === 1 ? '' : 's'}${hits.length > MAX_RESULTS ? ` (showing ${MAX_RESULTS})` : ''}`;
        const fragment = document.createDocumentFragment();
        for (const {row} of hits.slice(0, MAX_RESULTS)) {
            // The exporter emits this closed URL namespace. No HTML interpolation or arbitrary navigation.
            if (!/^docs\/(?:p-[a-z0-9][a-z0-9._-]{0,63}\/){3,}index\.html$/.test(row.url)) continue;
            const item = document.createElement('article'); item.className = 'result static-result'; item.lang = validLocale(row.locale) || 'en';
            const heading = document.createElement('h2'), link = document.createElement('a'); link.href = row.url; link.textContent = row.title; heading.append(link);
            const scope = document.createElement('p'); scope.className = 'eyebrow'; scope.textContent = row.path + ' · ' + ((row.locale || 'en') === 'ru' ? 'Русский' : 'English');
            const summary = document.createElement('p'); summary.textContent = excerpt(row, tokens);
            item.append(heading, scope, summary); fragment.append(item);
        }
        results.append(fragment);
    }
    const initial = new URLSearchParams(location.search).get('q') || '';
    render(initial, new URLSearchParams(location.search).get('locale') || '');
    language?.addEventListener('change', () => form.requestSubmit());
    form.addEventListener('submit', event => {
        event.preventDefault(); const query = input.value.trim().slice(0,200); const locale = validLocale(language?.value || '');
        // file:// history APIs may be restricted; search still works without history updates.
        try { const next = new URL(location.href); next.searchParams.set('q', query); next.searchParams.set('locale', locale); history.replaceState(null, '', next.href); } catch {}
        render(query, locale);
    });
})();
