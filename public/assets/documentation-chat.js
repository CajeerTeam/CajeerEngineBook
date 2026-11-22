/* Reader chat. No API credentials or conversation history are stored in the browser. */
(() => {
    'use strict';
    for (const panel of document.querySelectorAll('[data-doc-chat]')) {
        const form = panel.querySelector('form'), input = form.querySelector('textarea');
        const messages = panel.querySelector('.doc-chat-messages');
        const sources = panel.querySelector('.doc-chat-sources');
        const submit = form.querySelector('[type="submit"]');
        let history = [], controller = null;
        const message = (role, text = '') => {
            const p = document.createElement('p'); p.className = 'doc-chat-message doc-chat-' + role;
            p.textContent = text; messages.append(p); return p;
        };
        const validSource = url => {
            try {
                const u = new URL(url, location.origin);
                const prefix = panel.dataset.endpoint.slice(0, -'/api/v1/chat'.length);
                return u.origin === location.origin && u.search === '' && u.hash === '' &&
                    u.pathname.startsWith(prefix + '/docs/') &&
                    /^\/(?:w\/[a-f0-9]{32}\/[a-f0-9]{32}\/)?docs\/[a-zA-Z0-9._\/-]+$/.test(u.pathname);
            } catch { return false; }
        };
        function displaySources(items) {
            sources.replaceChildren();
            if (!Array.isArray(items) || !items.length) return;
            const title = document.createElement('strong'); title.textContent = 'Documentation sources'; sources.append(title);
            const list = document.createElement('ol');
            for (const item of items.slice(0, 6)) {
                if (!item || typeof item.url !== 'string' || !validSource(item.url) || typeof item.title !== 'string') continue;
                const li = document.createElement('li'), a = document.createElement('a');
                a.href = item.url; a.textContent = item.title.slice(0, 200); a.rel = 'noopener noreferrer';
                li.append(a); list.append(li);
            }
            sources.append(list);
        }
        function processFrame(frame, answer, flags) {
            let event = '', raw = '';
            for (const line of frame.split('\n')) {
                if (line.startsWith('event:')) event = line.slice(6).trim();
                if (line.startsWith('data:')) raw += line.slice(5).trimStart();
            }
            if (!raw) return;
            const data = JSON.parse(raw);
            if (event === 'sources') displaySources(data.items);
            else if (event === 'delta' && typeof data.text === 'string') {
                flags.text += data.text; answer.textContent = flags.text;
            } else if (event === 'done') flags.done = true;
            else if (event === 'error') throw new Error(data.message || 'Stream interrupted.');
        }
        form.addEventListener('submit', async e => {
            e.preventDefault(); if (controller) return;
            const question = input.value.trim(); if (!question || question.length > 1500) return;
            controller = new AbortController(); submit.disabled = true; input.disabled = true;
            message('user', question); const answer = message('assistant', 'Connecting…');
            const flags = {text:'',done:false};
            try {
                const response = await fetch(panel.dataset.endpoint, {
                    method: 'POST', credentials: 'same-origin', cache: 'no-store',
                    headers: {'Content-Type':'application/json','Accept':'text/event-stream'},
                    body: JSON.stringify({question, page: panel.dataset.page || '', history}),
                    signal: controller.signal
                });
                if (!response.ok) {
                    const failure = await response.json().catch(() => ({}));
                    throw new Error(typeof failure.error === 'string' ? failure.error : `Chat unavailable (${response.status}).`);
                }
                if (!response.body) throw new Error('Streaming is unavailable in this browser.');
                answer.textContent = '';
                const reader = response.body.getReader(), decoder = new TextDecoder('utf-8', {fatal:true});
                let buffer = '';
                while (true) {
                    const {value, done} = await reader.read();
                    buffer = (buffer + decoder.decode(value || new Uint8Array(), {stream:!done})).replace(/\r\n/g, '\n');
                    if (buffer.length > 262144) throw new Error('Chat response too large.');
                    let separator;
                    while ((separator = buffer.indexOf('\n\n')) >= 0) {
                        processFrame(buffer.slice(0, separator), answer, flags);
                        buffer = buffer.slice(separator + 2);
                    }
                    if (done) break;
                }
                if (!flags.done) throw new Error('Chat response stopped before completion.');
                if (!flags.text.trim()) answer.textContent = 'No response was generated.';
                else { history.push({role:'user',content:question}, {role:'assistant',content:flags.text.slice(0,1800)}); history = history.slice(-8); }
                input.value = '';
            } catch (err) {
                answer.textContent = (flags.text ? flags.text + '\n\n' : '') + (err.name === 'AbortError' ? 'Request cancelled.' : err.message || 'Chat unavailable.');
            } finally {
                controller = null; input.disabled = false; submit.disabled = false; input.focus();
            }
        });
        panel.querySelector('[data-chat-reset]')?.addEventListener('click', () => {
            controller?.abort(); history = []; messages.replaceChildren(); sources.replaceChildren();
        });
    }
})();
