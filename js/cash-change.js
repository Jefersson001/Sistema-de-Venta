for(const form of document.querySelectorAll('[data-cash-change-form]')){
    const method=form.querySelector('[name="method_id"]'),account=form.querySelector('[name="account_id"]'),direction=form.querySelector('[name="direction"]');
    const amount=form.querySelector('[name="amount"]'),received=form.querySelector('[name="received_amount"]'),change=form.querySelector('[name="change_amount"]');
    const currency=form.querySelector('[name="currency_code"]'),rate=form.querySelector('[name="exchange_rate"]'),error=form.querySelector('[data-cash-change-error]');
    const fields=form.querySelectorAll('[data-cash-change-field]'),label=form.querySelector('[data-cash-net-label]');
    if(!method||!account||!amount||!received||!change)continue;
    const locked=form.dataset.cashLocked==='1'||form.dataset.permissionReadonly==='true',originalReadOnly=amount.readOnly,originalLabel=label?.textContent;
    let active=false,initialized=false,manual=form.dataset.cashManual==='1'||form.dataset.cashSaved==='1',previousCurrency=currency.value;
    function cents(value){const text=String(value).trim().replace(',','.');if(!/^\d+(?:\.\d{1,2})?$/.test(text))throw new Error('Usa importes positivos con hasta dos decimales.');const [units,fraction='']=text.split('.');return BigInt(units)*100n+BigInt(fraction.padEnd(2,'0'));}
    function money(value){return (value/100n)+'.'+String(value%100n).padStart(2,'0');}
    function pair(){return {...JSON.parse(document.querySelector('[data-invoice-payment-settings]')?.textContent||'{}'),exchange_rate:rate?.value};}
    function update(){
        const enabled=locked?form.dataset.cashSaved==='1':method.selectedOptions[0]?.dataset.allowChange==='1'&&account.selectedOptions[0]?.dataset.accountType==='cash'&&(direction?.value||form.dataset.cashDirection)==='inbound';
        for(const field of fields)field.hidden=!enabled;
        received.disabled=change.disabled=!enabled||locked;received.required=enabled&&!locked;
        amount.readOnly=enabled||originalReadOnly;if(label)label.textContent=enabled?'Importe neto del pago *':originalLabel;
        error.hidden=true;received.setCustomValidity('');change.setCustomValidity('');
        if(enabled){
            if(!active&&initialized&&!locked){received.value=amount.value;change.value='0.00';manual=false;}
            try{
                const tendered=cents(received.value);
                if(!manual&&!locked&&form.dataset.cashBalance){
                    let balance=form.dataset.cashBalance;
                    if(currency.value!==form.dataset.cashBalanceCurrency)balance=convertOrderMoney(balance,form.dataset.cashBalanceCurrency,currency.value,pair());
                    change.value=money(tendered>cents(balance)?tendered-cents(balance):0n);
                }
                const returned=cents(change.value||'0');
                if(returned>=tendered||tendered===0n)throw new Error('El vuelto debe ser menor al recibido para dejar un pago neto positivo.');
                amount.value=money(tendered-returned);
            }catch(failure){error.textContent=failure.message;error.hidden=false;if(!locked)change.setCustomValidity(failure.message);}
        }
        active=enabled;initialized=true;
    }
    received.addEventListener('input',update);change.addEventListener('input',()=>{manual=true;update();});
    for(const control of [method,account,direction])control?.addEventListener('change',update);
    rate?.addEventListener('input',update);
    currency.addEventListener('change',()=>{
        if(active&&form.dataset.cashBalance&&currency.value!==previousCurrency){try{received.value=convertOrderMoney(received.value,previousCurrency,currency.value,pair());change.value=convertOrderMoney(change.value||'0',previousCurrency,currency.value,pair());}catch(failure){error.textContent=failure.message;error.hidden=false;}}
        previousCurrency=currency.value;update();
    });
    update();
}
