for(const button of document.querySelectorAll('[data-treasury-dialog-open]'))button.addEventListener('click',()=>document.getElementById(button.dataset.treasuryDialogOpen)?.showModal());
for(const button of document.querySelectorAll('[data-treasury-dialog-close]'))button.addEventListener('click',()=>button.closest('dialog').close());
for(const dialog of document.querySelectorAll('[data-treasury-dialog][data-reopen]'))dialog.showModal();
const treasuryForm=document.querySelector('[data-treasury-form]');
if(treasuryForm){
    const kind=treasuryForm.querySelector('[data-treasury-kind]'),locked=Boolean(treasuryForm.querySelector('fieldset[disabled]'));
    const transition=document.querySelector('[data-treasury-transition]');
    const source=treasuryForm.querySelector('[data-treasury-currency]'),destination=treasuryForm.querySelector('[data-treasury-destination-currency]');
    const amount=treasuryForm.querySelector('[data-treasury-amount]'),rate=treasuryForm.querySelector('[data-treasury-rate]'),received=treasuryForm.querySelector('[data-treasury-received]');
    function update(){
        const transfer=kind.value==='transfer',general=['income','expense'].includes(kind.value),reversal=['reversal','payment_reversal'].includes(kind.value);
        const showTransfer=transfer||(reversal&&Boolean(treasuryForm.querySelector('[name="destination_account_id"]').value));
        for(const [selector,visible] of [['[data-treasury-transfer]',showTransfer],['[data-treasury-concept]',general||(reversal&&Boolean(treasuryForm.querySelector('[name="concept_id"]').value))],['[data-treasury-method]',kind.value!=='opening'&&(!reversal||Boolean(treasuryForm.querySelector('[name="method_id"]').value))],['[data-treasury-contact]',general||(reversal&&Boolean(treasuryForm.querySelector('[name="contact_id"]').value))]]){
            for(const label of treasuryForm.querySelectorAll(selector)){label.hidden=!visible;for(const input of label.querySelectorAll('select,input')){input.disabled=locked||!visible;input.required=!locked&&visible&&input.name!=='contact_id'&&input.name!=='exchange_rate'&&!input.readOnly;}}
        }
        treasuryForm.querySelector('[data-treasury-account-label]').textContent=(kind.value==='income'?'Cuenta de ingreso':kind.value==='expense'?'Cuenta de egreso':transfer?'Cuenta de origen':'Cuenta')+' *';
        const concepts=treasuryForm.querySelector('[name="concept_id"]');const direction=kind.value==='income'?'inbound':'outbound';
        if(general&&!locked)for(const option of concepts.options){if(!option.value)continue;option.disabled=option.dataset.active==='0'||![direction,'both'].includes(option.dataset.direction);option.hidden=option.disabled;if(option.selected&&option.disabled)concepts.value='';}
        if(transfer&&!locked)try{received.value=convertOrderMoney(amount.value,source.value,destination.value,{...orderCurrencySettings,exchange_rate:rate.value});}catch{received.value='—';}
    }
    kind.addEventListener('change',update);treasuryForm.addEventListener('input',update);treasuryForm.addEventListener('change',update);update();
    if(transition)for(const event of ['input','change'])treasuryForm.addEventListener(event,()=>{for(const button of transition.querySelectorAll('button'))button.disabled=true;transition.querySelector('[data-treasury-dirty]').hidden=false;});
}
