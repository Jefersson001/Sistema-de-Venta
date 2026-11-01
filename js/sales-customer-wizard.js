(() => {
    'use strict';
    const form = document.querySelector('[data-quotation-form]');
    const check = form?.querySelector('[data-unregistered-customer]');
    const button = form?.querySelector('[data-create-sales-customer]');
    const dialog = document.querySelector('[data-sales-customer-dialog]');
    if (!check || !button || !dialog || form.dataset.permissionReadonly === 'true') return;
    const frame = dialog.querySelector('[data-sales-customer-frame]');
    const loading = dialog.querySelector('[data-sales-customer-loading]');
    const customer = form.querySelector('[data-quotation-customer]');
    const result = document.querySelector('[data-sales-customer-result]');
    let scope = '';
    check.addEventListener('input', event => event.stopPropagation());
    check.addEventListener('change', event => { event.stopPropagation(); button.hidden = !check.checked; });
    button.addEventListener('click', () => {
        if (!check.checked || check.matches(':disabled')) return;
        scope = form.querySelector('[name="company_scope"]')?.value || '';
        const url = new URL('../contactos/index.php', window.location.href);
        url.search = new URLSearchParams({new: '1', sales_wizard: '1', wizard_scope: scope});
        loading.hidden = false; result.hidden = true;
        frame.src = url.href; dialog.showModal();
    });
    frame.addEventListener('load', () => { loading.hidden = true; });
    dialog.querySelector('[data-close-sales-customer]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => button.focus());
    window.addEventListener('message', event => {
        if (event.origin !== window.location.origin || event.source !== frame.contentWindow || !dialog.open || event.data?.type !== 'sales-customer-created') return;
        const record = event.data.customer;
        if (!record || record.scope !== scope || scope !== (form.querySelector('[name="company_scope"]')?.value || '') || !Number.isSafeInteger(record.id) || record.id <= 0 || typeof record.name !== 'string' || typeof record.address !== 'string' || !Number.isSafeInteger(record.salesperson_id)) return;
        let option = [...customer.options].find(item => item.value === String(record.id));
        if (!option) { option = new Option(record.name, String(record.id)); customer.add(option); }
        option.textContent = record.name; option.dataset.address = record.address;
        option.dataset.salesperson = String(record.salesperson_id || '');
        option.dataset.addresses=JSON.stringify(Array.isArray(record.addresses)?record.addresses:[]);
        customer.value = String(record.id);
        customer.dispatchEvent(new Event('change', {bubbles: true}));
        check.checked = false; button.hidden = true; dialog.close();
        result.textContent = 'Cliente creado y seleccionado: ' + record.name; result.hidden = false;
        (customer.parentElement.querySelector('[role="combobox"]') || customer).focus();
    });
})();
