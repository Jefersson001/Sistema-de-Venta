(() => {
    'use strict';
    const states = new Map();
    const snapshot = (form, ignored) => JSON.stringify([...new FormData(form).entries()].filter(([key])=>key!==ignored));
    for (const form of document.querySelectorAll('[data-unsaved-changes]')) {
        const baseline = [...new FormData(form).entries()];
        const state = {baseline, initial: form.dataset.unsavedInitial === 'true', leaving: false};
        states.set(form, state);
        form.addEventListener('submit', event => {
            state.leaving = true;
            queueMicrotask(() => { if (event.defaultPrevented) state.leaving = false; });
        });
    }
    // Other deferred form scripts may enable/disable fields during initialization.
    if (document.readyState === 'loading' || document.readyState === 'interactive') {
        document.addEventListener('DOMContentLoaded', () => {
            for (const [form, state] of states) state.baseline = [...new FormData(form).entries()];
        }, {once: true});
    }
    const hasChanges = (form, ignored) => {
        const state = states.get(form);
        return Boolean(state && !state.leaving && (state.initial || snapshot(form, ignored) !== JSON.stringify(state.baseline.filter(([key])=>key!==ignored))));
    };
    window.nexoUnsavedChanges = {
        hasChanges,
        allowNavigation(form) { const state = states.get(form); if (state) state.leaving = true; }
    };
    window.addEventListener('beforeunload', event => {
        if ([...states.keys()].some(form => hasChanges(form))) { event.preventDefault(); event.returnValue = ''; }
    });
})();
