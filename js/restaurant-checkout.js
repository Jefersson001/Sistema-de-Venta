(() => {
    'use strict';
    const pos = document.querySelector('[data-pos-root]');
    const dialog = pos?.querySelector('[data-pos-checkout-dialog]');
    if (!dialog) return;
    const form = dialog.querySelector('[data-pos-checkout-form]');
    const list = form.querySelector('[data-checkout-payments]');
    const template = form.querySelector('[data-checkout-payment-template]');
    const mode = form.querySelector('[data-checkout-mode]');
    const customer = form.querySelector('[name=customer_id]');
    const remember = form.querySelector('[data-checkout-default-customer]');
    const preferenceKey = 'nexo-pos:' + (pos.querySelector('[name=company_scope]')?.value || '');
    let data, signature = '', preferences = {};
    try { preferences = JSON.parse(sessionStorage.getItem(preferenceKey) || '{}'); } catch (_) { /* Optional. */ }
    try {
        const saved = localStorage.getItem(form.dataset.posCustomerKey);
        if (saved && [...customer.options].some(option => option.value === saved)) {
            customer.value = saved; remember.checked = true;
        }
    } catch (_) { /* Optional. */ }
    const saveCustomer = () => {
        try {
            if (remember.checked && customer.value) localStorage.setItem(form.dataset.posCustomerKey, customer.value);
            else localStorage.removeItem(form.dataset.posCustomerKey);
        } catch (_) { /* The selection remains usable without storage. */ }
    };
    remember.addEventListener('change', saveCustomer);
    customer.addEventListener('change', saveCustomer);
    const scaled = (value, places = 2) => {
        const match = /^(\d+)(?:\.(\d*))?$/.exec(String(value).trim());
        if (!match || (match[2] || '').length > places) return null;
        return BigInt(match[1]) * (10n ** BigInt(places)) + BigInt((match[2] || '').padEnd(places, '0'));
    };
    const decimal = value => `${value / 100n}.${String(value % 100n).padStart(2, '0')}`;
    const money = value => decimal(value) + ' ' + data.currency;
    const lineTotal = (item, quantity) => {
        const base = (quantity * scaled(item.unit_price) + 500n) / 1000n;
        const tax = (base * scaled(item.tax_rate, 4) + 500000n) / 1000000n;
        return base + tax;
    };
    const rows = () => [...list.querySelectorAll('.restaurant-checkout-payment')];
    const field = (row, name) => row.querySelector(`[data-payment-field=${name}]`);
    const create = (tag, text, className) => {
        const element = document.createElement(tag); element.textContent = text;
        if (className) element.className = className;
        return element;
    };
    const buildProducts = row => {
        const lines = row.querySelector('[data-payment-product-lines]'); lines.replaceChildren();
        data.items.forEach(item => {
            const label = create('label', '');
            label.append(create('span', item.product_name + ' · ' + item.quantity + ' disponibles'));
            const input = document.createElement('input');
            Object.assign(input, {type: 'number', min: '0', max: item.quantity, step: '0.001', value: '0', inputMode: 'decimal'});
            input.dataset.paymentItem = item.id; input.setAttribute('aria-label', 'Cantidad de ' + item.product_name);
            label.append(input); lines.append(label);
        });
    };
    const addRow = (amount = '0.00') => {
        const row = template.content.firstElementChild.cloneNode(true);
        field(row, 'amount').value = amount;
        ['method_id', 'account_id'].forEach(name => {
            const select = field(row, name);
            if ([...select.options].some(option => option.value === preferences[name])) select.value = preferences[name];
        });
        buildProducts(row); list.append(row); return row;
    };
    const sync = () => {
        const payments = rows(), total = scaled(data.total), assigned = new Map(data.items.map(item => [String(item.id), 0n]));
        let paid = 0n, changeTotal = 0n, problem = '';
        payments.forEach((row, index) => {
            const productMode = mode.value === 'products';
            const title = mode.value === 'full' ? 'Pago' : 'Persona';
            row.querySelector('[data-payment-title]').textContent = `${title} ${index + 1}`;
            row.querySelector('[data-payment-label-field]').hidden = mode.value === 'full';
            row.querySelector('[data-payment-products]').hidden = !productMode;
            row.querySelector('[data-payment-remove]').hidden = payments.length === 1;
            row.querySelectorAll('[data-payment-field]').forEach(input => {
                input.name = `payments[${index}][${input.dataset.paymentField}]`;
            });
            let productAmount = 0n;
            data.items.forEach(item => {
                const input = row.querySelector(`[data-payment-item="${item.id}"]`);
                input.disabled = !productMode; input.name = `payments[${index}][items][${item.id}]`;
                if (!productMode) return;
                const quantity = scaled(input.value, 3), previous = assigned.get(String(item.id));
                if (quantity === null) { problem = 'Revisa las cantidades de cada producto.'; return; }
                const next = previous + quantity;
                if (next > scaled(item.quantity, 3)) problem = 'Se repartieron más productos que los cargados.';
                productAmount += lineTotal(item, next) - lineTotal(item, previous);
                assigned.set(String(item.id), next);
            });
            const amountInput = field(row, 'amount'); amountInput.readOnly = productMode;
            if (productMode) amountInput.value = decimal(productAmount);
            const amount = scaled(amountInput.value), receivedInput = field(row, 'received_amount');
            const allowChange = field(row, 'method_id').selectedOptions[0]?.dataset.allowChange === '1'
                && field(row, 'account_id').selectedOptions[0]?.dataset.accountType === 'cash';
            row.querySelector('[data-payment-received-field]').hidden = !allowChange; receivedInput.disabled = !allowChange;
            receivedInput.setCustomValidity('');
            const received = allowChange && receivedInput.value !== '' ? scaled(receivedInput.value) : amount;
            let change = 0n;
            if (amount === null || (amount <= 0n && total > 0n)) problem = 'Cada pago debe tener un importe mayor que cero.';
            else paid += amount;
            if (received === null || (amount !== null && received < amount)) {
                receivedInput.setCustomValidity('El efectivo recibido no alcanza para este pago.');
                problem = 'El efectivo recibido no alcanza para este pago.';
            } else if (amount !== null) { change = received - amount; changeTotal += change; }
            row.querySelector('[data-payment-change]').textContent = allowChange ? 'Cambio: ' + money(change) : '';
        });
        if (mode.value === 'products' && data.items.some(item => assigned.get(String(item.id)) !== scaled(item.quantity, 3))) {
            problem ||= 'Falta repartir todos los productos de la mesa.';
        }
        if (paid !== total) problem ||= paid < total ? 'Falta repartir ' + money(total - paid) : 'El reparto supera el total en ' + money(paid - total);
        mode.setCustomValidity(problem);
        const balance = form.querySelector('[data-checkout-balance]');
        balance.textContent = problem || 'Total cubierto: ' + money(paid);
        balance.classList.toggle('is-error', Boolean(problem));
        form.querySelector('[data-checkout-change]').textContent = 'Cambio total: ' + money(changeTotal);
        form.querySelector('[data-checkout-add]').disabled = payments.length >= 20;
        form.querySelector('[data-checkout-add]').textContent = mode.value === 'full' ? '＋ Agregar otro pago' : '＋ Agregar persona';
        form.querySelector('[data-checkout-even]').hidden = mode.value !== 'amount';
    };
    const refresh = () => {
        const source = pos.querySelector('[data-pos-checkout-data]');
        if (!source) return;
        const next = source.textContent;
        if (next === signature) return;
        signature = next; data = JSON.parse(next);
        form.querySelector('[data-checkout-total]').textContent = data.total + ' ' + data.currency;
        const items = form.querySelector('[data-checkout-items]'); items.replaceChildren();
        data.items.forEach(item => {
            const line = create('div', '', 'restaurant-checkout-order-line');
            line.append(create('span', item.quantity + ' × ' + item.product_name), create('strong', item.total_amount + ' ' + data.currency));
            items.append(line);
        });
        mode.value = 'full'; list.replaceChildren(); addRow(data.total); sync();
    };
    form.addEventListener('input', sync);
    form.addEventListener('change', sync);
    mode.addEventListener('change', () => {
        rows().forEach(row => { field(row, 'received_amount').value = ''; });
        sync();
    });
    form.addEventListener('submit', sync, true);
    form.addEventListener('click', event => {
        const remove = event.target.closest('[data-payment-remove]');
        if (remove && rows().length > 1) { remove.closest('.restaurant-checkout-payment').remove(); sync(); }
        if (event.target.closest('[data-checkout-add]')) {
            if (rows().length >= 20) return;
            const totalPaid = rows().reduce((sum, row) => sum + (scaled(field(row, 'amount').value) || 0n), 0n);
            const remaining = scaled(data.total) - totalPaid;
            const row = addRow(decimal(remaining > 0n ? remaining : 0n));
            if (mode.value === 'products') row.querySelector('[data-payment-products]').open = true;
            sync(); row.querySelector('input').focus();
        }
        if (event.target.closest('[data-checkout-even]')) {
            const payments = rows(), total = scaled(data.total), part = total / BigInt(payments.length);
            payments.forEach((row, index) => { field(row, 'amount').value = decimal(index === payments.length - 1 ? total - part * BigInt(index) : part); });
            sync();
        }
    });
    pos.addEventListener('restaurant:cart-updated', refresh);
    refresh();
})();
