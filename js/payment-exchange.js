// Compare each applied portion, using the same cent rounding as the PHP repository.
for (const form of document.querySelectorAll('[data-payment-rate-endpoint]')) {
    const date=form.elements.namedItem(form.dataset.paymentDateField||'payment_date'), rate=form.querySelector('[name="exchange_rate"]');
    const currency=form.querySelector('[name="currency_code"]'), amount=form.querySelector('[name="amount"]');
    const status=form.querySelector('[data-payment-rate-status]'), preview=form.querySelector('[data-payment-fx-preview]');
    const pair=JSON.parse(form.dataset.paymentPair), invoice=JSON.parse(form.dataset.paymentFxInvoice||'null');
    if (!date || !rate || !currency || !amount || rate.matches(':disabled')) continue;
    let request=0, pending=false, restoreButtons=[], manualRevision=0, previousRate=rate.value;
    const money=value=>(value<0n?'-':'')+currencyMoney(value<0n?-value:value);
    const currentPair=()=>({...pair,exchange_rate:rate.value});
    if(form.hasAttribute('data-payment-convert-currency')){
        let previousCurrency=currency.value;
        currency.addEventListener('change',()=>{
            try{amount.value=convertOrderMoney(amount.value,previousCurrency,currency.value,currentPair());previousCurrency=currency.value;}
            catch(error){currency.value=previousCurrency;if(status)status.textContent=error.message;}
        });
    }
    function updatePreview() {
        if (!preview || !invoice) return;
        preview.hidden=true;
        const indexed=!!invoice.indexed_usd_total;
        if ((!indexed&&currency.value===invoice.currency_code) || !amount.value) return;
        try {
            const tendered=currencyUnits(amount.value,2), balance=currencyUnits(invoice.balance,2);
            const maximum=currencyUnits(convertOrderMoney(invoice.balance,invoice.currency_code,currency.value,currentPair()),2);
            let applied=tendered<maximum?tendered:maximum;
            let covered=currencyUnits(convertOrderMoney(currencyMoney(applied),currency.value,invoice.currency_code,currentPair()),2);
            if (covered>balance) {applied-=1n;covered=currencyUnits(convertOrderMoney(currencyMoney(applied),currency.value,invoice.currency_code,currentPair()),2);}
            if (applied<=0n || covered<=0n) return;
            let historic,actual,fxCurrency=currency.value;
            if(indexed){
                const total=currencyUnits(invoice.indexed_native_total,2),reference=currencyUnits(invoice.indexed_usd_total,2);
                historic=covered===balance?currencyUnits(invoice.native_balance,2):((currencyUnits(invoice.usd_paid||'0',2)+covered)*total+reference/2n)/reference-currencyUnits(invoice.native_paid||'0',2);
                actual=currencyUnits(convertOrderMoney(currencyMoney(applied),currency.value,'VES',currentPair()),2);fxCurrency='VES';
            }else{historic=currencyUnits(convertOrderMoney(currencyMoney(covered),invoice.currency_code,currency.value,invoice),2);actual=applied;}
            const remainder=balance-covered;
            preview.textContent=`Este abono cubre ${money(covered)} ${invoice.currency_code}. Saldo por cubrir: ${money(remainder)} ${invoice.currency_code}. Valor histórico del abono: ${money(historic)} ${fxCurrency}. Aplicado a la tasa del pago: ${money(actual)} ${fxCurrency}. Diferencial: ${money(actual-historic)} ${fxCurrency}.`;
            if (tendered>applied) preview.textContent+=` Excedente disponible: ${money(tendered-applied)} ${currency.value}.`;
            preview.hidden=false;
        } catch {
            preview.textContent='Completa el importe y las tasas para comparar el abono con su valor histórico.';
            preview.hidden=false;
        }
    }
    rate.addEventListener('input',()=>{
        if(invoice&&!amount.readOnly){try{if(currencyUnits(amount.value,2)===currencyUnits(convertOrderMoney(invoice.balance,invoice.currency_code,currency.value,{...pair,exchange_rate:previousRate}),2))amount.value=convertOrderMoney(invoice.balance,invoice.currency_code,currency.value,currentPair());}catch{}}
        previousRate=rate.value;manualRevision++;if(status)status.textContent='Tasa indicada manualmente para este pago.';
    });
    form.addEventListener('input',updatePreview);
    form.addEventListener('change',updatePreview);
    form.addEventListener('submit',event=>{if(pending){event.preventDefault();status?.focus();}});
    date.addEventListener('change',async()=>{
        const id=++request, revision=manualRevision, selected=date.value, oldRate=rate.value, oldAmount=amount.value, oldCurrency=currency.value;
        if(!selected){pending=false;for(const [button,disabled] of restoreButtons)button.disabled=disabled;restoreButtons=[];return;}
        if(!pending){restoreButtons=[...form.querySelectorAll('button[type="submit"],button:not([type]),input[type="submit"]')].map(button=>[button,button.disabled]);for(const [button] of restoreButtons)button.disabled=true;}
        pending=true;if(status)status.textContent='Consultando tasa para la fecha del pago…';
        try {
            const url=new URL(form.dataset.paymentRateEndpoint,location.href);url.searchParams.set('payment_rate_date',selected);
            const response=await fetch(url,{headers:{Accept:'application/json'},cache:'no-store',signal:AbortSignal.timeout(15000)});
            const data=await response.json();
            if(id!==request || date.value!==selected)return;
            if(!response.ok)throw Error(data.error||'No se pudo consultar la tasa.');
            const scope=document.querySelector('[name="company_scope"]')?.value;
            if(scope && data.scope!==scope)throw Error('La empresa cambió. Recarga antes de registrar el pago.');
            if(manualRevision!==revision){if(status)status.textContent='Se conserva la tasa que indicaste manualmente.';return;}
            if(data.primary_code!==pair.primary_code || data.alternate_code!==pair.alternate_code)throw Error('La configuración de monedas cambió. Recarga la página.');
            rate.value=data.exchange_rate||'';
            // A quoted full balance follows the new rate; a manually entered partial amount stays intact.
            if(invoice && oldCurrency===currency.value && oldAmount===amount.value && !amount.readOnly && (invoice.indexed_usd_total||currency.value!==invoice.currency_code)){
                try{if(currencyUnits(oldAmount,2)===currencyUnits(convertOrderMoney(invoice.balance,invoice.currency_code,currency.value,{...pair,exchange_rate:oldRate}),2))amount.value=convertOrderMoney(invoice.balance,invoice.currency_code,currency.value,currentPair());}catch{}
            }
            rate.dispatchEvent(new Event('input',{bubbles:true}));
            if(status)status.textContent=data.exchange_rate?`Tasa vigente: ${data.rate_date} (${data.source}). Puedes indicar otra tasa si corresponde al pago.`:'No hay tasa para esta fecha. Indícala manualmente antes de pagar en otra moneda.';
            updatePreview();
        } catch(error) {
            if(id===request){if(revision===manualRevision){rate.value='';rate.dispatchEvent(new Event('input',{bubbles:true}));}if(status)status.textContent=error.message+' Verifica la tasa antes de guardar.';updatePreview();}
        } finally {
            if(id===request){pending=false;for(const [button,disabled] of restoreButtons)button.disabled=disabled;restoreButtons=[];}
        }
    });
    updatePreview();
}
