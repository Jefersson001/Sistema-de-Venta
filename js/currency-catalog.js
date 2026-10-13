const currencyForm = document.querySelector('[data-currency-form]');
if (currencyForm) {
    const body = currencyForm.querySelector('[data-currency-rates]');
    const template = currencyForm.querySelector('[data-currency-rate-template]');
    const add = currencyForm.querySelector('[data-add-currency-rate]');
    let nextIndex = body.querySelectorAll('[data-currency-rate]').length;
    function wireRate(row) {
        const value = row.querySelector('[data-rate-value]');
        const inverse = row.querySelector('[data-rate-inverse]');
        function updateInverse() {
            const text = value.value.trim().replace(',', '.');
            if (!/^[0-9]{1,12}(\.[0-9]{1,8})?$/.test(text)) { inverse.value = ''; return; }
            const [whole, fraction = ''] = text.split('.');
            const units = BigInt(whole + fraction.padEnd(8, '0'));
            if (units === 0n) { inverse.value = ''; return; }
            const result = 10000000000000000n / units;
            inverse.value = `${result / 100000000n}.${String(result % 100000000n).padStart(8, '0')}`;
        }
        value.addEventListener('input', updateInverse); updateInverse();
        const remove = row.querySelector('[data-remove-currency-rate]');
        if (remove) { remove.hidden = false; remove.addEventListener('click', () => row.remove()); }
    }
    body.querySelectorAll('[data-currency-rate]').forEach(wireRate);
    add.hidden = false;
    add.addEventListener('click', () => {
        if (body.querySelectorAll('[data-currency-rate]').length >= 100) return;
        const fragment = template.content.cloneNode(true);
        const row = fragment.querySelector('[data-currency-rate]');
        row.querySelectorAll('[name]').forEach(field => field.name = field.name.replace('__INDEX__', nextIndex));
        nextIndex++; body.append(row); wireRate(row); row.querySelector('[data-rate-date]').focus();
    });
    const symbol = currencyForm.querySelector('[data-currency-symbol]');
    const position = currencyForm.querySelector('[data-currency-position]');
    const example = currencyForm.querySelector('[data-currency-example]');
    const preview = () => { example.textContent = position.value === 'before' ? `${symbol.value} 100.00` : `100.00 ${symbol.value}`; };
    symbol.addEventListener('input', preview); position.addEventListener('change', preview); preview();
}
