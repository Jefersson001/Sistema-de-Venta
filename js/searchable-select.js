(() => {
    'use strict';
    const controls = new Map();
    let sequence = 0, active = null;
    const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();

    function enhance(select) {
        if (controls.has(select) || select.multiple || select.size > 1 || select.closest('[data-native-select]')) return;
        const wrapper = document.createElement('span'); wrapper.className = 'searchable-select';
        const input = document.createElement('input');
        input.type = 'text'; input.autocomplete = 'off'; input.spellcheck = false;
        input.className = 'searchable-select-input'; input.id = 'searchable-select-' + ++sequence;
        input.setAttribute('role', 'combobox'); input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false'); input.setAttribute('aria-haspopup', 'listbox');
        const label = select.labels?.[0];
        input.setAttribute('aria-label', select.getAttribute('aria-label') || (label ? [...label.childNodes].filter(node => node.nodeType === 3).map(node => node.textContent).join(' ').trim() : '') || 'Buscar una opción');
        const popup = document.createElement('div');
        popup.className = 'searchable-select-popup'; popup.hidden = true;
        const list = document.createElement('div'); list.id = input.id + '-list';
        list.setAttribute('role', 'listbox'); list.setAttribute('aria-label', input.getAttribute('aria-label'));
        input.setAttribute('aria-controls', list.id);
        const status = document.createElement('div'); status.className = 'searchable-select-status'; status.setAttribute('role', 'status');
        popup.append(list, status); (select.closest('dialog') || document.body).append(popup);
        select.before(wrapper); wrapper.append(select, input);
        select.classList.add('searchable-select-native'); select.tabIndex = -1; select.setAttribute('aria-hidden', 'true');
        const state = {select, input, wrapper, popup, list, status, matches: [], index: -1, editing: false};
        controls.set(select, state);
        function sync() {
            input.disabled = select.matches(':disabled');
            input.setAttribute('aria-required', String(select.required));
            for (const attribute of ['aria-invalid', 'aria-describedby']) {
                const value = select.getAttribute(attribute);
                if (value === null) { if (input.hasAttribute(attribute)) input.removeAttribute(attribute); }
                else if (input.getAttribute(attribute) !== value) input.setAttribute(attribute, value);
            }
            if (!state.editing) input.value = select.selectedOptions[0]?.textContent.trim() || '';
            if (input.disabled) close(state);
        }
        state.sync = sync;
        select.addEventListener('change', () => { state.editing = false; sync(); });
        select.addEventListener('focus', () => input.focus());
        select.addEventListener('invalid', () => queueMicrotask(() => { sync(); input.focus(); }));
        input.addEventListener('focus', () => { sync(); input.select(); });
        input.addEventListener('click', () => { if (active !== state) { state.editing = false; open(state, ''); input.select(); } });
        input.addEventListener('input', event => {
            // Query text is not a business field change; only choosing an option is.
            event.stopPropagation(); state.editing = true; open(state, input.value);
        });
        input.addEventListener('change', event => event.stopPropagation());
        input.addEventListener('keydown', event => {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                if (active !== state) open(state, '');
                else if (state.matches.length) highlight(state, (state.index + (event.key === 'ArrowDown' ? 1 : -1) + state.matches.length) % state.matches.length);
            } else if (event.key === 'Enter' && active === state) {
                event.preventDefault(); if (state.matches[state.index]) choose(state, state.matches[state.index]);
            } else if (event.key === 'Escape' && active === state) {
                event.preventDefault(); close(state);
            } else if (event.key === 'Tab') close(state);
        });
        input.addEventListener('blur', () => close(state));
        popup.addEventListener('pointerdown', event => event.preventDefault());
        popup.addEventListener('click', event => {
            const item = event.target.closest('[data-option-index]');
            if (item) choose(state, state.matches[Number(item.dataset.optionIndex)]);
        });
        sync();
    }
    function position(state) {
        const rect = state.input.getBoundingClientRect();
        const width = Math.min(Math.max(rect.width, 240), window.innerWidth - 16);
        state.popup.style.width = width + 'px';
        state.popup.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - width - 8)) + 'px';
        const below = window.innerHeight - rect.bottom - 8, above = rect.top - 8;
        const height = Math.min(300, Math.max(80, below >= above ? below : above));
        state.popup.style.maxHeight = height + 'px';
        state.popup.style.top = (below >= above ? rect.bottom + 4 : Math.max(8, rect.top - Math.min(state.popup.scrollHeight, height) - 4)) + 'px';
    }
    function open(state, query) {
        if (state.input.disabled) return;
        if (active && active !== state) close(active);
        active = state; state.popup.hidden = false; state.input.setAttribute('aria-expanded', 'true');
        const words = normalize(query).split(/\s+/).filter(Boolean);
        const matches = [...state.select.options].filter(option => !option.disabled && !option.hidden && !option.parentElement.disabled && words.every(word => normalize(option.textContent).includes(word)));
        state.matches = matches.slice(0, 80); state.list.replaceChildren();
        state.matches.forEach((option, index) => {
            const item = document.createElement('div'); item.id = state.input.id + '-option-' + index;
            item.className = 'searchable-select-option'; item.setAttribute('role', 'option');
            item.setAttribute('aria-selected', String(option.selected)); item.dataset.optionIndex = index; item.textContent = option.textContent;
            state.list.append(item);
        });
        state.status.textContent = matches.length > 80 ? matches.length + ' opciones. Escribe para filtrar.' : matches.length ? matches.length + ' opciones' : 'Sin coincidencias';
        highlight(state, Math.max(0, state.matches.findIndex(option => option.selected))); position(state);
    }
    function highlight(state, index) {
        state.index = index;
        [...state.list.children].forEach((item, i) => item.classList.toggle('is-active', i === index));
        const item = state.list.children[index];
        if (item) { state.input.setAttribute('aria-activedescendant', item.id); item.scrollIntoView({block: 'nearest'}); }
        else state.input.removeAttribute('aria-activedescendant');
    }
    function close(state) {
        state.popup.hidden = true; state.input.setAttribute('aria-expanded', 'false');
        state.input.removeAttribute('aria-activedescendant'); state.editing = false;
        state.input.value = state.select.selectedOptions[0]?.textContent.trim() || '';
        if (active === state) active = null;
    }
    function choose(state, option) {
        if (!option || state.select.matches(':disabled') || option.disabled) return;
        const changed = state.select.value !== option.value;
        state.select.value = option.value; close(state);
        if (changed) {
            state.select.dispatchEvent(new Event('input', {bubbles: true}));
            state.select.dispatchEvent(new Event('change', {bubbles: true}));
        }
        state.sync();
    }
    function scan(root) {
        if (root.matches?.('select')) enhance(root);
        root.querySelectorAll?.('select').forEach(enhance);
    }
    scan(document);
    document.addEventListener('DOMContentLoaded', () => { for (const state of controls.values()) state.sync(); }, {once: true});
    document.addEventListener('live-filters:updated', () => { scan(document); for (const state of controls.values()) state.sync(); });
    document.addEventListener('pointerdown', event => { if (active && !active.wrapper.contains(event.target) && !active.popup.contains(event.target)) close(active); });
    document.addEventListener('change', () => { for (const state of controls.values()) state.sync(); });
    document.addEventListener('reset', () => setTimeout(() => { for (const state of controls.values()) { close(state); state.sync(); } }, 0));
    window.addEventListener('resize', () => { if (active) position(active); });
    window.addEventListener('scroll', () => { if (active) position(active); }, true);
    new MutationObserver(records => {
        for (const record of records) {
            for (const node of record.addedNodes) if (node.nodeType === 1) scan(node);
            const select = record.target.closest?.('select');
            if (select && controls.has(select)) {
                const state = controls.get(select); state.sync();
                if (active === state) open(state, state.editing ? state.input.value : '');
            }
            if (record.target.matches?.('fieldset')) for (const state of controls.values()) state.sync();
        }
        for (const [select, state] of controls) if (!select.isConnected) {
            close(state); state.popup.remove(); controls.delete(select);
        }
    }).observe(document.body, {childList: true, subtree: true, attributes: true, attributeFilter: ['disabled', 'selected', 'aria-invalid', 'aria-describedby']});
})();
