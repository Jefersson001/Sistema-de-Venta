(() => {
    'use strict';
    const root = document.querySelector('.restaurant-workspace');
    if (!root) return;
    root.querySelector('[data-restaurant-area]')?.addEventListener('change', (event) => {
        root.querySelectorAll('[data-restaurant-area-id]').forEach((area) => {
            area.hidden = Boolean(event.target.value && area.dataset.restaurantAreaId !== event.target.value);
        });
    });
    const updateTimes = () => root.querySelectorAll('[data-restaurant-elapsed]').forEach((time) => {
        const minutes = Math.max(0, Math.floor((Date.now() - Date.parse(time.dateTime)) / 60000));
        if (Number.isFinite(minutes)) time.textContent = minutes < 60 ? `${minutes} min` : `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
    });
    updateTimes();
    if (root.querySelector('[data-restaurant-elapsed]')) setInterval(updateTimes, 30000);
    const filterAccounts = (state) => {
        root.querySelectorAll('[data-restaurant-account-filter]').forEach((button) => {
            const selected = button.dataset.restaurantAccountFilter === state;
            button.classList.toggle('is-active', selected);
            button.setAttribute('aria-pressed', String(selected));
        });
        let visible = 0;
        root.querySelectorAll('[data-restaurant-account-state]').forEach((row) => {
            row.hidden = state === 'open' && !['open', 'billing'].includes(row.dataset.restaurantAccountState);
            if (!row.hidden) visible++;
        });
        const empty = root.querySelector('[data-restaurant-no-accounts]');
        if (empty) empty.hidden = visible > 0;
    };
    root.querySelectorAll('[data-restaurant-account-filter]').forEach((button) => {
        button.addEventListener('click', () => filterAccounts(button.dataset.restaurantAccountFilter));
    });
    if (root.querySelector('[data-restaurant-account-filter]')) filterAccounts('open');
    const pos = root.querySelector('[data-pos-root]');
    if (!pos) return;
    const preferenceKey = 'nexo-pos:' + (pos.querySelector('[name=company_scope]')?.value || '');
    let categoryIds = null;
    const filterProducts = () => {
        const query = (pos.querySelector('[data-pos-search]')?.value || '').toLocaleLowerCase().trim();
        let count = 0;
        pos.querySelectorAll('[data-pos-product]').forEach(product => {
            product.hidden = Boolean((categoryIds && !categoryIds.has(product.dataset.posCategoryId)) || !product.dataset.posName.includes(query));
            if (!product.hidden) count++;
        });
        pos.querySelector('[data-pos-no-results]').hidden = count > 0;
    };
    pos.querySelector('[data-pos-search]')?.addEventListener('input', filterProducts);
    const categoryButtons = [...pos.querySelectorAll('[data-pos-category]')];
    const selectCategory = button => {
        const category = button.dataset.posCategory;
        categoryIds = category ? new Set(button.dataset.posCategoryIds.split(',')) : null;
        const path = (button.dataset.posCategoryPath || '').split(',');
        categoryButtons.forEach(item => {
            const active = item.dataset.posCategory === category || (
                category && !item.hasAttribute('data-pos-category-all') && path.includes(item.dataset.posCategory)
            );
            item.classList.toggle('is-active', Boolean(active));
            item.setAttribute('aria-pressed', String(Boolean(active)));
        });
        pos.querySelectorAll('[data-pos-subcategories]').forEach(row => { row.hidden = !path.includes(row.dataset.posSubcategories); });
        try { sessionStorage.setItem(preferenceKey + ':category', category); } catch (_) { /* Optional preference. */ }
        filterProducts();
    };
    categoryButtons.forEach(button => button.addEventListener('click', () => selectCategory(button)));
    try {
        const savedCategory = sessionStorage.getItem(preferenceKey + ':category');
        const savedButton = categoryButtons.find(button => button.dataset.posCategory === savedCategory);
        if (savedButton) selectCategory(savedButton);
    } catch (_) { /* Keep all products available if storage is unavailable. */ }
    const dialog = pos.querySelector('[data-pos-checkout-dialog]');
    pos.addEventListener('click', event => {
        if (event.target.closest('[data-pos-open-checkout]')) dialog?.showModal();
        if (event.target.closest('[data-pos-close-checkout]')) dialog?.close();
    });
    try {
        const preferences = JSON.parse(sessionStorage.getItem(preferenceKey) || '{}');
        pos.querySelectorAll('[data-pos-preference]').forEach(select => {
            if (select.name !== 'customer_id' && [...select.options].some(option => option.value === preferences[select.name])) select.value = preferences[select.name];
        });
    } catch (_) { /* Storage is optional; server validates every choice. */ }
    let busy = false;
    pos.addEventListener('submit', async event => {
        const form = event.target.closest('[data-pos-command]');
        if (!form || !window.fetch) return;
        event.preventDefault();
        if (busy || !form.reportValidity()) return;
        busy = true;
        const payload = new FormData(form);
        const controls = [...pos.querySelectorAll('button,input,select,textarea')];
        const enabled = controls.filter(control => !control.disabled);
        enabled.forEach(control => { control.disabled = true; });
        const errorBox = pos.querySelector('[data-pos-error]');
        const dialogError = pos.querySelector('[data-pos-dialog-error]');
        errorBox.hidden = true;
        if (dialogError) dialogError.hidden = true;
        pos.querySelector('[data-pos-saving]').hidden = false;
        try {
            // The hidden input named "action" shadows HTMLFormElement.action.
            const response = await fetch(form.getAttribute('action') || location.href, {
                method: 'POST', body: payload, credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Nexo-Company-Scope': payload.get('company_scope') }
            });
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.error || result.message || 'No se pudo guardar. Recarga para comprobar el estado.');
            pos.querySelector('[data-pos-cart]').innerHTML = result.html;
            pos.querySelectorAll('[name=version]').forEach(input => { input.value = result.version; });
            if (form.isConnected && window.crypto?.getRandomValues) {
                form.querySelector('[name=request_key]').value = [...crypto.getRandomValues(new Uint8Array(32))].map(value => value.toString(16).padStart(2, '0')).join('');
            }
            if (payload.get('action') === 'pos_checkout') {
                try {
                    const preferences = {};
                    pos.querySelectorAll('[data-pos-preference]').forEach(select => { if (select.name !== 'customer_id') preferences[select.name] = select.value; });
                    sessionStorage.setItem(preferenceKey, JSON.stringify(preferences));
                } catch (_) { /* Optional convenience only. */ }
            }
            if (result.closed) location.reload();
        } catch (error) {
            const message = error instanceof TypeError || error instanceof SyntaxError
                ? 'No se pudo confirmar la respuesta. Reintenta la misma acción; no se duplicará. Si cambiaste de empresa, recarga la página.' : error.message;
            errorBox.textContent = message; errorBox.hidden = false;
            if (dialogError && dialog?.open) { dialogError.textContent = message; dialogError.hidden = false; }
        } finally {
            enabled.forEach(control => { if (control.isConnected) control.disabled = false; });
            pos.querySelector('[data-pos-saving]').hidden = true;
            busy = false;
        }
    });
})();
