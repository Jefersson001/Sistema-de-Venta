const paymentForm=document.querySelector('[data-payment-form]');
if (paymentForm) {
    const direction = paymentForm.querySelector('[name="direction"]');
    const accountLabel = paymentForm.querySelector('[data-payment-account-label]');
    if (direction && accountLabel) {
        const updateAccountLabel = () => { accountLabel.textContent = direction.value === 'inbound' ? 'Cuenta de ingreso *' : 'Cuenta de egreso *'; };
        direction.addEventListener('change', updateAccountLabel);
        updateAccountLabel();
    }
    const concepts = paymentForm.querySelector('[data-payment-concept]');
    if(direction && concepts && !paymentForm.querySelector('fieldset[disabled]')) {
        const filterConcepts=()=>{for(const option of concepts.options){if(!option.value)continue;option.disabled=option.dataset.active==='0'||![direction.value,'both'].includes(option.dataset.direction);option.hidden=option.disabled;if(option.selected&&option.disabled)concepts.value='';}};
        direction.addEventListener('change',filterConcepts);filterConcepts();
    }
}
if(paymentForm){const transition=document.querySelector('[data-payment-transition]');for(const event of ['input','change'])paymentForm.addEventListener(event,()=>{if(transition){for(const button of transition.querySelectorAll('button'))button.disabled=true;transition.querySelector('[data-payment-dirty]').hidden=false;}});}
for(const allocation of document.querySelectorAll('[data-payment-allocation]')){const invoice=allocation.querySelector('[data-payment-invoice]');invoice.addEventListener('change',()=>{allocation.querySelector('[data-payment-allocation-amount]').value=invoice.selectedOptions[0]?.dataset.maximum||'0.00';});}
