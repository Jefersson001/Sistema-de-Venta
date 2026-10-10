const quotationForm = document.querySelector('[data-quotation-form]');

if (quotationForm) {
    const customer = quotationForm.querySelector('[data-quotation-customer]');
    const salesperson = quotationForm.querySelector('[data-quotation-salesperson]');
    const lines = quotationForm.querySelector('[data-quotation-lines]');
    const addLine = quotationForm.querySelector('[data-add-line]');
    const template = quotationForm.querySelector('[data-quotation-line-template]');
    let nextIndex = lines.querySelectorAll('[data-quotation-line]').length;

    function decimalUnits(value, scale) {
        const normalized = value.trim().replace(',', '.');
        if (!new RegExp(`^[0-9]+(\\.[0-9]{1,${scale}})?$`).test(normalized)) return null;
        const [whole, fractional = ''] = normalized.split('.');
        return BigInt(whole + fractional.padEnd(scale, '0'));
    }

    function money(cents) {
        return `${cents / 100n}.${String(cents % 100n).padStart(2, '0')}`;
    }

    function updateTotals() {
        let total = 0n;
        let valid = true;
        const rows = [...lines.querySelectorAll('[data-quotation-line]')];
        for (const row of rows) {
            const productId = row.querySelector('[data-line-product]').value;
            const quantity = decimalUnits(row.querySelector('[data-line-quantity]').value, 3);
            const price = decimalUnits(row.querySelector('[data-line-price]').value, 2);
            let amount = 0n;
            let display = '0.00';
            if (productId) {
                if (quantity === null || quantity <= 0n || price === null) {
                    valid = false;
                    display = '—';
                } else {
                    amount = (quantity * price + 500n) / 1000n;
                    display = money(amount);
                    total += amount;
                }
            }
            row.querySelector('[data-line-base]').textContent = display;
            row.querySelector('[data-line-total]').textContent = display;
        }
        quotationForm.querySelector('[data-quotation-base]').textContent = valid ? money(total) : '—';
        quotationForm.querySelector('[data-quotation-total]').textContent = valid ? money(total) : '—';
        if (addLine) addLine.disabled = rows.length >= 100;
    }

    function markDirty() {
        const actions = document.querySelector('[data-quotation-status-form]');
        if (actions) {
            actions.querySelectorAll('button').forEach(button => { button.disabled = true; });
            actions.querySelector('[data-quotation-dirty-help]').hidden = false;
        }
    }

    function wireLine(row) {
        const product = row.querySelector('[data-line-product]');
        product.addEventListener('change', () => {
            const selected = product.selectedOptions[0];
            row.querySelector('[data-line-price]').value = selected?.dataset.price ?? '0.00';
            const tax = row.querySelector('[data-line-tax]');
            const taxId = selected?.dataset.tax ?? '';
            tax.value = [...tax.options].some(option => option.value === taxId) ? taxId : '';
            updateTotals();
        });
        const remove = row.querySelector('[data-remove-line]');
        if (remove) {
            remove.hidden = false;
            remove.addEventListener('click', () => {
                row.remove();
                updateTotals();
                markDirty();
            });
        }
    }

    function updateRelatedSalesperson() {
        const sellerId = customer.selectedOptions[0]?.dataset.salesperson ?? '';
        salesperson.value = [...salesperson.options].some(option => option.value === sellerId) ? sellerId : '';
    }

    customer.addEventListener('change', () => {
        const selected = customer.selectedOptions[0];
        const address = selected?.dataset.address ?? '';
        quotationForm.querySelector('[data-invoice-address]').value = address;
        quotationForm.querySelector('[data-delivery-address]').value = address;
        updateRelatedSalesperson();
    });
    if (!salesperson.value && customer.value) updateRelatedSalesperson();
    lines.querySelectorAll('[data-quotation-line]').forEach(wireLine);
    if (addLine && template) {
        addLine.hidden = false;
        addLine.addEventListener('click', () => {
            if (lines.querySelectorAll('[data-quotation-line]').length >= 100) return;
            const fragment = template.content.cloneNode(true);
            const row = fragment.querySelector('[data-quotation-line]');
            row.querySelectorAll('[name]').forEach(field => {
                field.name = field.name.replace('__INDEX__', String(nextIndex));
            });
            nextIndex++;
            lines.append(fragment);
            wireLine(row);
            updateTotals();
            markDirty();
            row.querySelector('[data-line-product]').focus();
        });
    }
    quotationForm.addEventListener('input', () => { updateTotals(); markDirty(); });
    quotationForm.addEventListener('change', () => { updateTotals(); markDirty(); });
    updateTotals();
}
