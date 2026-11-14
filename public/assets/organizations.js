(() => {
  'use strict';
  // Fragments are not sent to the HTTP server or access logs. Never persist invitation tokens.
  if (location.pathname === '/organizations/join') {
    const token = location.hash.slice(1);
    const input = document.querySelector('form[action="/organizations/join"] input[name="token"]');
    if (input && /^[a-f0-9]{64}$/.test(token)) {
      input.value = token;
      history.replaceState(null, '', location.pathname);
    }
  }
  const link = document.querySelector('[data-org-invite-link]');
  const copy = document.querySelector('[data-org-copy]');
  if (link && copy) {
    link.value = new URL(link.value, location.origin).href;
    copy.addEventListener('click', async () => {
      const status = document.querySelector('[data-org-copy-status]');
      try { await navigator.clipboard.writeText(link.value); status.textContent = 'Invitation link copied.'; }
      catch { link.focus(); link.select(); status.textContent = 'Copy the selected link manually.'; }
    });
  }
})();
