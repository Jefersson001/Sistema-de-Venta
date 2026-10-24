function restaurantPackPayments(payload) {
    const rows = {};
    for (const [key, value] of [...payload.entries()]) {
        const field = /^payments\[(\d+)\]\[(label|method_id|account_id|amount|received_amount)\]$/.exec(key);
        const item = /^payments\[(\d+)\]\[items\]\[(\d+)\]$/.exec(key);
        if (!field && !item) continue;
        const index = (field || item)[1]; rows[index] ||= {};
        if (field) rows[index][field[2]] = value;
        else { rows[index].items ||= {}; rows[index].items[item[2]] = value; }
        payload.delete(key);
    }
    payload.set('payments_json', JSON.stringify(Object.keys(rows).sort((a,b)=>Number(a)-Number(b)).map(index=>rows[index])));
    return payload;
}

(() => {
    'use strict';
    const root = document.querySelector('.restaurant-workspace');
    if (!root) return;
    const areaFilter = root.querySelector('[data-restaurant-area]');
    const filterAreas = () => {
        const counts = {free: 0, occupied: 0, reserved: 0, cleaning: 0};
        root.querySelectorAll('[data-restaurant-area-id]').forEach((area) => {
            area.hidden = Boolean(areaFilter.value && area.dataset.restaurantAreaId !== areaFilter.value);
            if (!area.hidden) area.querySelectorAll('[data-restaurant-table-state]').forEach(table => {
                const state = table.dataset.restaurantTableState;
                if (Object.hasOwn(counts, state)) counts[state]++;
            });
        });
        root.querySelectorAll('[data-restaurant-count]').forEach(counter => {
            counter.textContent = String(counts[counter.dataset.restaurantCount] || 0);
        });
    };
    areaFilter?.addEventListener('change', filterAreas);
    if (areaFilter) filterAreas();
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
    const tools = root.querySelector('[data-table-tools]');
    const tableDialog = root.querySelector('[data-table-dialog]');
    if (tools && tableDialog) {
        const toggle = tools.querySelector('[data-table-menu-toggle]');
        const menu = tools.querySelector('#restaurant-table-menu');
        const closeMenu = () => { menu.hidden = true; toggle.setAttribute('aria-expanded', 'false'); };
        toggle.addEventListener('click', () => {
            menu.hidden = !menu.hidden;
            toggle.setAttribute('aria-expanded', String(!menu.hidden));
        });
        document.addEventListener('click', event => { if (!tools.contains(event.target)) closeMenu(); });
        tools.addEventListener('keydown', event => {
            if (event.key === 'Escape') { closeMenu(); toggle.focus(); }
        });
        const showOption = option => {
            const panel = [...tableDialog.querySelectorAll('[data-table-panel]')].find(item => item.dataset.tablePanel === option);
            if (!panel) return;
            closeMenu();
            tableDialog.querySelectorAll('[data-table-panel]').forEach(item => { item.hidden = item !== panel; });
            tableDialog.querySelector('[data-table-dialog-title]').textContent = panel.dataset.tableTitle;
            panel.querySelectorAll('[data-table-step]').forEach(step => { step.hidden = step.dataset.tableStep !== 'edit'; });
            const progress = panel.querySelector('[data-table-progress]');
            if (progress) progress.textContent = 'Paso 1 de 2 · Datos del cambio';
            tableDialog.showModal();
        };
        tools.querySelectorAll('[data-table-option]').forEach(button => {
            button.addEventListener('click', () => showOption(button.dataset.tableOption));
        });
        tableDialog.querySelectorAll('[data-table-dialog-close]').forEach(button => button.addEventListener('click', () => tableDialog.close()));
        tableDialog.addEventListener('close', () => toggle.focus());
        tableDialog.querySelectorAll('[data-table-wizard]').forEach(form => {
            const edit = form.querySelector('[data-table-step=edit]');
            const confirm = form.querySelector('[data-table-step=confirm]');
            const progress = form.querySelector('[data-table-progress]');
            form.querySelector('[data-table-next]').addEventListener('click', () => {
                if (!form.reportValidity()) return;
                const table = form.querySelector('[name=table_id]');
                form.querySelector('[data-table-review-target]').textContent = table ? table.selectedOptions[0].textContent : 'Cerrar la atención sin consumo';
                form.querySelector('[data-table-review-reason]').textContent = 'Motivo: ' + form.querySelector('[name=reason]').value;
                edit.hidden = true; confirm.hidden = false;
                progress.textContent = 'Paso 2 de 2 · Confirmar cambio';
                form.querySelector('[data-table-back]').focus();
            });
            form.querySelector('[data-table-back]').addEventListener('click', () => {
                confirm.hidden = true; edit.hidden = false;
                progress.textContent = 'Paso 1 de 2 · Datos del cambio';
                form.querySelector('select,input[name=reason]').focus();
            });
            form.addEventListener('submit', event => {
                // Enter in the first step must never bypass the confirmation.
                if (confirm.hidden) { event.preventDefault(); form.querySelector('[data-table-next]').click(); }
            });
        });
        if (tools.dataset.tableRetry) showOption(tools.dataset.tableRetry);
    }
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
        if (payload.get('action') === 'pos_checkout') restaurantPackPayments(payload);
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
            pos.dispatchEvent(new Event('restaurant:cart-updated'));
            root.querySelectorAll('[name=version]').forEach(input => { input.value = result.version; });
            if (form.isConnected && window.crypto?.getRandomValues) {
                form.querySelector('[name=request_key]').value = [...crypto.getRandomValues(new Uint8Array(32))].map(value => value.toString(16).padStart(2, '0')).join('');
            }
            if (payload.get('action') === 'pos_checkout') {
                try {
                    const preferences = {};
                    pos.querySelectorAll('[data-pos-preference]').forEach(select => {
                        const key = select.dataset.posPreference;
                        if (key !== 'customer_id' && !(key in preferences)) preferences[key] = select.value;
                    });
                    sessionStorage.setItem(preferenceKey, JSON.stringify(preferences));
                } catch (_) { /* Optional convenience only. */ }
            }
            if (result.closed) location.assign(result.receipt_url || location.href);
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
