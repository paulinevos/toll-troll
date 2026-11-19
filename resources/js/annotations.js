(() => {
    const form = document.querySelector('.review-panel');
    const desktop = matchMedia('(min-width: 761px) and (hover: hover) and (pointer: fine)');
    const carousel = document.querySelector('[data-advice-carousel]');
    const cards = carousel ? [...carousel.querySelectorAll('[data-advice-card]')] : [];
    const updateShortcutHints = () => {
        document.querySelectorAll('[data-shortcuts], [data-advice-key]').forEach(element => { element.hidden = !desktop.matches; });
        carousel?.querySelectorAll('[data-advice-prev], [data-advice-next]').forEach(button => {
            if (desktop.matches) button.setAttribute('aria-keyshortcuts', button.hasAttribute('data-advice-prev') ? 'j' : 'k');
            else button.removeAttribute('aria-keyshortcuts');
        });
        const help = carousel?.querySelector('[data-advice-help]');
        if (help) help.hidden = !desktop.matches || !form || cards.length < 2;
    };
    desktop.addEventListener('change', updateShortcutHints);
    updateShortcutHints();
    if (cards.length) {
        let position = 0;
        const previous = carousel.querySelector('[data-advice-prev]');
        const next = carousel.querySelector('[data-advice-next]');
        const render = () => {
            cards.forEach((card, index) => {
                card.hidden = index !== position;
                card.setAttribute('aria-current', String(index === position));
            });
            previous.disabled = position === 0;
            next.disabled = position === cards.length - 1;
            carousel.querySelector('[data-advice-position]').textContent = `${position + 1} / ${cards.length}`;
        };
        const move = offset => {
            const focusedCard = document.activeElement.closest('[data-advice-card]');
            position = Math.max(0, Math.min(cards.length - 1, position + offset));
            render();
            // Do not leave keyboard focus in a slide that just became hidden.
            if (focusedCard?.hidden) cards[position].querySelector('button').focus({preventScroll: true});
        };
        previous.addEventListener('click', () => move(-1));
        next.addEventListener('click', () => move(1));
        carousel.classList.add('advice-enhanced');
        carousel.querySelector('[data-advice-controls]').hidden = cards.length < 2;
        render();

        document.addEventListener('keydown', event => {
            if (!desktop.matches || cards.length < 2 || event.defaultPrevented || event.repeat || event.isComposing
                || event.ctrlKey || event.metaKey || event.altKey || event.shiftKey) return;
            const target = event.target;
            if (target.isContentEditable || target.closest('.review-panel, textarea, select, input')) return;
            const key = event.key.toLowerCase();
            if (key !== 'j' && key !== 'k') return;
            event.preventDefault();
            move(key === 'j' ? -1 : 1);
        });
    }

    if (!form) return;
    const draft = document.querySelector('#review-draft');
    const note = form.elements.note;
    const labels = {a: 'pass', s: 'fail', d: 'unknown', j: 'pass', k: 'fail', l: 'unknown'};
    const directions = {q: 'prev', e: 'next', u: 'prev', o: 'next'};
    let submitting = false;

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
        if (!desktop.matches || submitting || event.defaultPrevented || event.repeat || event.isComposing) return;
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
