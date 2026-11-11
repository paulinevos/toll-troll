(() => {
    const form = document.querySelector('.review-panel');
    if (!form) return;

    const enabled = document.querySelector('#shortcuts-enabled');
    const draft = document.querySelector('#review-draft');
    const note = form.elements.note;
    const labels = {a: 'pass', s: 'fail', d: 'unknown', j: 'pass', k: 'fail', l: 'unknown'};
    const directions = {q: 'prev', e: 'next', u: 'prev', o: 'next'};
    let submitting = false;

    // Storage may be disabled by the host browser; shortcuts still work for this page.
    try { enabled.checked = localStorage.getItem('toll-troll-shortcuts') !== 'off'; } catch {}
    document.querySelectorAll('[data-shortcuts]').forEach(element => { element.hidden = false; });
    enabled.addEventListener('change', () => {
        try { localStorage.setItem('toll-troll-shortcuts', enabled.checked ? 'on' : 'off'); } catch {}
    });

    const dirty = () => (form.elements.label.value || '') !== form.dataset.savedLabel
        || note.value !== form.dataset.savedNote.replace(/\r\n?/g, '\n');
    const updateDraft = () => { draft.textContent = dirty() ? 'Unsaved changes' : ''; };
    form.addEventListener('input', updateDraft);
    form.addEventListener('change', updateDraft);
    updateDraft();
    window.addEventListener('beforeunload', event => {
        if (!submitting && dirty()) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
    form.addEventListener('submit', event => {
        if (submitting) event.preventDefault();
        else submitting = true;
    });
    window.addEventListener('pageshow', () => { submitting = false; updateDraft(); });

    document.addEventListener('keydown', event => {
        if (!enabled.checked || submitting || event.defaultPrevented || event.repeat || event.isComposing) return;
        if (event.key === 'Enter' && (event.ctrlKey || event.metaKey) && !event.altKey && !event.shiftKey) {
            event.preventDefault();
            form.requestSubmit();
            return;
        }
        if (event.ctrlKey || event.metaKey || event.altKey || event.shiftKey) return;
        const target = event.target;
        if (target.isContentEditable || target.closest('textarea, select, input:not([type="radio"])')) return;

        const key = event.key.toLowerCase();
        if (Object.hasOwn(labels, key)) {
            event.preventDefault();
            const radio = form.querySelector(`input[value="${labels[key]}"]`);
            radio.focus();
            radio.click();
        } else if (Object.hasOwn(directions, key)) {
            event.preventDefault();
            document.querySelector(`.case-navigation a[rel="${directions[key]}"]`)?.click();
        }
    });
})();
