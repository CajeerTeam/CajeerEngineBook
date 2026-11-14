/* Native drag-and-drop with the same validated form as keyboard/touch actions. */
(() => {
    'use strict';
    const ui = document.querySelector('[data-tree-ui]');
    if (!ui) return;
    const form = ui.querySelector('[data-tree-form]');
    const status = ui.querySelector('[data-tree-status]');
    let dragging = null, active = null, submitted = false;
    const fields = form.elements;
    const moveButton = form.querySelector('[value="move"]');
    function clear() { active?.classList.remove('tree-drop-active'); active = null; }
    function destination(zone) {
        fields.position.value = zone.dataset.dropPosition;
        fields.target.value = zone.dataset.dropKey;
    }
    function allowed(zone) {
        const key = zone.dataset.dropKey;
        return dragging && (zone.dataset.dropPosition === 'root' || (key !== dragging && !key.startsWith(dragging + '/')));
    }
    ui.addEventListener('click', event => {
        const select = event.target.closest('[data-select-source]');
        if (select) { fields.source.value = select.dataset.selectSource; status.textContent = 'Selected source: ' + fields.source.value; return; }
        const zone = event.target.closest('[data-drop-position]');
        if (zone) { destination(zone); status.textContent = 'Destination selected. Choose Move branch or Duplicate branch above.'; }
    });
    ui.addEventListener('dragstart', event => {
        const handle = event.target.closest('[data-drag-key]');
        if (!handle || !event.dataTransfer) return;
        dragging = handle.dataset.dragKey;
        event.dataTransfer.setData('text/plain', dragging);
        event.dataTransfer.effectAllowed = 'move';
        status.textContent = 'Moving ' + dragging + '. Drop on Before, Inside, After or the version root.';
    });
    ui.addEventListener('dragover', event => {
        const zone = event.target.closest('[data-drop-position]');
        if (!zone || !allowed(zone) || submitted) { clear(); return; }
        event.preventDefault(); event.dataTransfer.dropEffect = 'move';
        if (active !== zone) { clear(); active = zone; active.classList.add('tree-drop-active'); }
    });
    ui.addEventListener('dragleave', event => { if (active && !active.contains(event.relatedTarget)) clear(); });
    ui.addEventListener('drop', event => {
        const zone = event.target.closest('[data-drop-position]');
        if (!zone || !allowed(zone) || submitted) return;
        event.preventDefault();
        fields.source.value = dragging;
        fields.slug.value = ''; // drag never renames accidentally
        destination(zone); clear(); dragging = null;
        form.requestSubmit(moveButton);
    });
    ui.addEventListener('dragend', () => { dragging = null; clear(); });
    form.addEventListener('submit', event => {
        if (submitted) { event.preventDefault(); return; }
        if (fields.position.value !== 'root' && (!fields.target.value || fields.target.value === fields.source.value || fields.target.value.startsWith(fields.source.value + '/'))) {
            event.preventDefault(); status.textContent = 'Select a destination outside the source branch.'; return;
        }
        submitted = true; status.textContent = 'Saving page tree…';
    });
    window.addEventListener('pageshow', () => { submitted = false; });
})();
