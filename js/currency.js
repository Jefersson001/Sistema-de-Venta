function currencyUnits(value,scale){
    const text=String(value).trim().replace(',','.');
    const pattern=new RegExp('^[0-9]+(?:\\.[0-9]{1,'+scale+'})?$');
    if(!pattern.test(text))throw new Error('Indica un importe válido con hasta dos decimales.');
    const [whole,fraction='']=text.split('.');
    return BigInt(whole)*10n**BigInt(scale)+BigInt(fraction.padEnd(scale,'0'));
}
function currencyMoney(cents){return `${cents/100n}.${String(cents%100n).padStart(2,'0')}`;}
function convertOrderMoney(value,from,to,settings){
    const cents=currencyUnits(value,2);
    if(from===to||cents===0n)return currencyMoney(cents);
    if(![settings.primary_code,settings.alternate_code].includes(from)||![settings.primary_code,settings.alternate_code].includes(to)||!settings.exchange_rate)throw new Error('Configura una tasa válida en Contabilidad → Configuración → Monedas y tasa de cambio.');
    const rate=currencyUnits(settings.exchange_rate,2);
    if(rate<=0n)throw new Error('La tasa de cambio debe ser positiva.');
    return currencyMoney(from===settings.primary_code?(cents*rate+50n)/100n:(cents*100n+rate/2n)/rate);
}
const orderCurrencySettings=JSON.parse(document.querySelector('[data-currency-settings]')?.textContent||'null');
for(const form of document.querySelectorAll('[data-quotation-form],[data-order-form]')){
    const currency=form.querySelector('[data-order-currency]');if(!currency||!orderCurrencySettings)continue;let previous=currency.value;
    currency.addEventListener('change',()=>{
        const prices=[...form.querySelectorAll('[data-line-price],[data-order-price],[data-line-reference],[data-line-commission-unit]')].filter(p=>p.value!=='');
        try{const converted=prices.map(p=>convertOrderMoney(p.value,previous,currency.value,{...orderCurrencySettings,exchange_rate:form.querySelector('[data-order-exchange]')?.value||orderCurrencySettings.exchange_rate}));prices.forEach((p,i)=>{p.value=converted[i];});previous=currency.value;form.dispatchEvent(new Event('input',{bubbles:true}));}
        catch(error){currency.value=previous;window.alert(error.message);}
    });
}
