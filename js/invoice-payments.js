for (const button of document.querySelectorAll('[data-invoice-wizard-open]')) {
    button.addEventListener('click', () => {
        const dialog = document.getElementById(button.dataset.invoiceWizardOpen);
        if (dialog && !dialog.open) dialog.showModal();
    });
}

for (const dialog of document.querySelectorAll('[data-invoice-wizard]')) {
    for (const button of dialog.querySelectorAll('[data-invoice-wizard-close]')) {
        button.addEventListener('click', () => dialog.close());
    }
    dialog.addEventListener('click', (event) => {
        if (event.target !== dialog) return;
        const bounds = dialog.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) dialog.close();
    });
    // Native dialogs handle Escape, focus containment and return focus to the opener.
    if (dialog.hasAttribute('data-reopen')) dialog.showModal();
}

const invoicePaymentAction = document.querySelector('input[name="action"][value="register_payment"]');
if (invoicePaymentAction) {
    const form = invoicePaymentAction.closest('form');
    const currency = form.querySelector('[name="currency_code"]');
    const amount = form.querySelector('[name="amount"]');
    const rate = form.querySelector('[name="exchange_rate"]');
    const settings = JSON.parse(document.querySelector('[data-invoice-payment-settings]').textContent);
    let previous = currency.value;
    currency.addEventListener('change', () => {
        try {
            amount.value = convertOrderMoney(amount.value, previous, currency.value, { ...settings, exchange_rate: rate.value });
            previous = currency.value;
        } catch (error) {
            currency.value = previous;
            window.alert(error.message);
        }
    });
}
