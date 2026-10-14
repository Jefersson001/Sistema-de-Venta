const purchaseForm = document.querySelector('[data-order-form]');
if (purchaseForm && purchaseForm.dataset.permissionReadonly!=='true') {
    const body = purchaseForm.querySelector('[data-order-lines]');
    const template = purchaseForm.querySelector('[data-order-template]');
    const add = purchaseForm.querySelector('[data-order-add]');
    const transition = document.querySelector('[data-order-transition]');
    let next = body.children.length;
    function markDirty() {
        if (!transition) return;
        for (const button of transition.querySelectorAll('button')) button.disabled = true;
        transition.querySelector('[data-order-dirty]').hidden = false;
    }
    function scaled(value, scale) {
        const normalized = value.trim().replace(',', '.');
        if (!new RegExp('^[0-9]+(\\.[0-9]{1,' + scale + '})?$').test(normalized)) return null;
        const parts = normalized.split('.');
        return BigInt(parts[0]) * 10n ** BigInt(scale) + BigInt((parts[1] || '').padEnd(scale, '0'));
    }
    function money(cents) { return String(cents / 100n) + '.' + String(cents % 100n).padStart(2, '0'); }
    function updateTotals() {
        let total = 0n; let baseTotal = 0n;
        for (const row of body.querySelectorAll('[data-order-line]')) {
            const selected = row.querySelector('[data-order-product]').value;
            const quantity = scaled(row.querySelector('[data-order-quantity]').value, 3);
            const price = scaled(row.querySelector('[data-order-price]').value, 2);
            const base = selected && quantity !== null && price !== null ? (quantity * price + 500n) / 1000n : 0n;
            row.querySelector('[data-order-line-base]').textContent = money(base);
            const rate = scaled(row.querySelector('[data-order-tax]').selectedOptions[0]?.dataset.rate || '0',4) || 0n;
            const tax = (base * rate + 500000n) / 1000000n;
            row.querySelector('[data-order-line-total]').textContent = money(base+tax);
            baseTotal += base; total += base+tax;
        }
        purchaseForm.querySelector('[data-order-base]').textContent = money(baseTotal);
        purchaseForm.querySelector('[data-order-total]').textContent = money(total);
    }
    function setupRow(row) {
        row.querySelector('[data-order-product]').addEventListener('change', event => {
            const option = event.target.selectedOptions[0];
            const supplier = purchaseForm.querySelector('[data-purchase-supplier]').value;
            const prices = JSON.parse(document.querySelector('[data-supplier-prices]')?.textContent || '[]');
            const vendorPrice=prices.find(p=>String(p.product_id)===event.target.value && String(p.supplier_id)===supplier);
            const price=vendorPrice?.price || option?.dataset.price || '0.00';const currency=purchaseForm.querySelector('[data-order-currency]');
            try{row.querySelector('[data-order-price]').value=currency&&typeof convertOrderMoney==='function'?convertOrderMoney(price,option?.dataset.currency||currency.value,currency.value,{...orderCurrencySettings,exchange_rate:purchaseForm.querySelector('[data-order-exchange]')?.value||orderCurrencySettings.exchange_rate}):price;}catch(error){window.alert(error.message);}
            row.querySelector('[data-order-tax]').value = option?.dataset.tax === '0' ? '' : (option?.dataset.tax || '');
            updateTotals();
        });
        const remove = row.querySelector('[data-order-remove]');
        if (remove) { remove.hidden = false; remove.addEventListener('click', () => { row.remove(); updateTotals(); markDirty(); }); }
    }
    for (const row of body.querySelectorAll('[data-order-line]')) setupRow(row);
    if (add) {
        add.hidden = false;
        add.addEventListener('click', () => {
            if (body.children.length >= 100) return;
            const fragment = template.content.cloneNode(true);
            for (const input of fragment.querySelectorAll('[name]')) input.name = input.name.replace('__INDEX__', String(next));
            next++; setupRow(fragment.querySelector('[data-order-line]')); body.append(fragment); updateTotals(); markDirty();
        });
    }
    const mode = purchaseForm.querySelector('[data-purchase-mode]');
    const destination = purchaseForm.querySelector('[data-purchase-destination]');
    function updateDestination() {
        destination.disabled = Boolean(purchaseForm.querySelector('fieldset[disabled]'));
        destination.required = !destination.disabled && mode.value !== 'invoice';
    }
    mode.addEventListener('change', updateDestination); updateDestination();
    purchaseForm.querySelector('[data-purchase-supplier]').addEventListener('change',()=>{
        for(const row of body.querySelectorAll('[data-order-line]')) row.querySelector('[data-order-product]').dispatchEvent(new Event('change'));
        markDirty();
    });
    purchaseForm.addEventListener('input', () => { updateTotals(); markDirty(); });
    purchaseForm.addEventListener('change', () => { updateTotals(); markDirty(); });
    updateTotals();
}
