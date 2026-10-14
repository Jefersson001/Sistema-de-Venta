const commissionDialog = document.querySelector('[data-commission-payment]');
const commissionPaymentForm = document.querySelector('[data-commission-payment-form]');
if (commissionPaymentForm) {
    const settings=JSON.parse(document.querySelector('[data-commission-settings]').textContent);
    const form=commissionPaymentForm;
    const account=form.querySelector('[data-commission-account]');
    const currency=form.querySelector('[data-commission-currency]');
    const preview=form.querySelector('[data-commission-preview]');
    function updatePreview() {
        try {
            const amount=convertOrderMoney(form.querySelector('[data-commission-amount]').value,settings.invoice_currency,currency.value,
                {...orderCurrencySettings,exchange_rate:form.querySelector('[data-commission-rate]').value});
            preview.textContent='Se registrará un egreso de '+amount+' '+currency.value+' y se aplicará a esta comisión.';
        } catch(error) { preview.textContent=error.message; }
    }
    account.addEventListener('change',()=>{
        const code=account.selectedOptions[0]?.dataset.currency;
        if(code&&[...currency.options].some(option=>option.value===code))currency.value=code;
        updatePreview();
    });
    form.addEventListener('input',updatePreview);form.addEventListener('change',updatePreview);
    if(commissionDialog) {
        document.querySelector('[data-open-commission-payment]')?.addEventListener('click',()=>commissionDialog.showModal());
        for(const button of commissionDialog.querySelectorAll('[data-close-commission-payment]'))button.addEventListener('click',()=>commissionDialog.close());
        if(commissionDialog.hasAttribute('data-reopen'))commissionDialog.showModal();
    }
    updatePreview();
}
