(() => {
  'use strict';
  const form = document.querySelector('[data-markdown-import]');
  if (!form) return;
  const status = document.querySelector('[data-import-status]');
  const result = document.querySelector('[data-import-result]');
  const apply = form.querySelector('[data-import-apply]');
  const preview = form.querySelector('[value="preview"]');
  let token = '', busy = false;
  const active = () => form.querySelector(`[data-import-input="${form.elements.mode.value}"] input`);
  function invalidate() { token = ''; apply.disabled = true; result.hidden = true; status.textContent = ''; }
  function mode() {
    for (const label of form.querySelectorAll('[data-import-input]')) {
      const selected = label.dataset.importInput === form.elements.mode.value;
      label.hidden = !selected; const input = label.querySelector('input'); input.disabled = !selected; input.required = selected;
    }
  }
  form.addEventListener('change', () => { mode(); invalidate(); });
  form.addEventListener('input', invalidate);
  mode();
  function element(tag, text) { const node = document.createElement(tag); node.textContent = text; return node; }
  function show(data, committed) {
    result.replaceChildren(element('h2', committed ? 'Import completed' : 'Import plan'));
    result.append(element('p', `${data.rows.length} documents; ${data.media_new} new media files (${data.media_total} source assets).`));
    const table = document.createElement('table'); table.className = 'markdown-table';
    const head = document.createElement('thead'), row = document.createElement('tr');
    for (const name of ['Source', 'Title', 'Target', 'Action']) row.append(element('th', name));
    head.append(row); table.append(head); const body = document.createElement('tbody');
    for (const item of data.rows) {
      const r = document.createElement('tr'); r.append(element('td', item.source), element('td', item.title));
      const target = element('td', '');
      if (committed && item.action !== 'skip') {
        const a = element('a', item.target); a.href = form.action.replace(/\/import$/, '') + '?edit=' + encodeURIComponent(item.target); target.append(a);
      } else target.textContent = item.target;
      r.append(target, element('td', item.action)); body.append(r);
    }
    table.append(body); result.append(table);
    if (data.warnings.length) {
      result.append(element('h3', 'Unresolved local links (left unchanged)'));
      const list = document.createElement('ul');
      for (const w of data.warnings) list.append(element('li', `${w.source}: ${w.url}`));
      result.append(list);
    }
    result.hidden = false;
  }
  async function request(action) {
    if (busy || !form.reportValidity()) return;
    const input = active();
    if (!input.files.length) return;
    if (input.files.length > 200) { status.textContent = 'Select at most 200 files.'; return; }
    const data = new FormData(form); data.delete('files[]');
    for (const file of input.files) data.append('files[]', file, form.elements.mode.value === 'directory' ? file.webkitRelativePath : file.name);
    data.set('action', action); data.set('count', String(input.files.length));
    if (action === 'apply') data.set('token', token);
    busy = true; preview.disabled = true; apply.disabled = true;
    const controls = [...form.querySelectorAll('input,select')].filter(el => !el.disabled);
    controls.forEach(el => el.disabled = true);
    status.textContent = action === 'preview' ? 'Checking import files…' : 'Saving drafts…';
    const controller = new AbortController(); const timer = setTimeout(() => controller.abort(), 120000);
    try {
      const response = await fetch(form.action, {method:'POST',body:data,credentials:'same-origin',headers:{'X-Book-Import':'1'},signal:controller.signal});
      const payload = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(payload.error || `Import failed (HTTP ${response.status}). Sign in again if necessary.`);
      show(payload, action === 'apply');
      token = action === 'preview' ? payload.token : '';
      status.textContent = action === 'preview' ? 'Check the plan, then apply. No data has been changed.' : 'Drafts saved. Publish or submit for review from Studio.';
    } catch (error) {
      token = ''; status.textContent = error.name === 'AbortError' ? 'Request timed out. Check Studio before retrying; preview again.' : error.message;
    } finally {
      clearTimeout(timer); controls.forEach(el => el.disabled = false); mode(); busy = false; preview.disabled = false; apply.disabled = !token;
    }
  }
  form.addEventListener('submit', event => { event.preventDefault(); request('preview'); });
  apply.addEventListener('click', () => { if (token) request('apply'); });
})();
