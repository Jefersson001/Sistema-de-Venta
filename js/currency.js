function currencyUnits(value,scale){const text=String(value).trim().replace(',','.');if(!/^[0-9]+(?:\.[0-9]+)?$/.test(text))throw new Error('Indica un importe válido.');const parts=text.split('.');return BigInt(parts[0])*10n**BigInt(scale)+BigInt((parts[1]||'').padEnd(scale,'0').slice(0,scale));}
function currencyMoney(cents){return `${cents/100n}.${String(cents%100n).padStart(2,'0')}`;}
function convertOrderMoney(value,from,to,settings){const cents=currencyUnits(value,2);if(from===to||cents===0n)return currencyMoney(cents);if(![settings.primary_code,settings.alternate_code].includes(from)||![settings.primary_code,settings.alternate_code].includes(to)||!settings.exchange_rate)throw new Error('Configura una tasa válida en Contabilidad → Configuración → Monedas y tasa de cambio.');const rate=currencyUnits(settings.exchange_rate,8);if(rate<=0n)throw new Error('La tasa de cambio debe ser positiva.');return currencyMoney(from===settings.primary_code?(cents*rate+50000000n)/100000000n:(cents*100000000n+rate/2n)/rate);}
const orderCurrencySettings=JSON.parse(document.querySelector('[data-currency-settings]')?.textContent||'null');
for(const form of document.querySelectorAll('[data-quotation-form],[data-order-form]')){
    const currency=form.querySelector('[data-order-currency]');if(!currency||!orderCurrencySettings)continue;let previous=currency.value;
    currency.addEventListener('change',()=>{
        const prices=[...form.querySelectorAll('[data-line-price],[data-order-price]')];
        try{const converted=prices.map(p=>convertOrderMoney(p.value,previous,currency.value,{...orderCurrencySettings,exchange_rate:form.querySelector('[data-order-exchange]')?.value||orderCurrencySettings.exchange_rate}));prices.forEach((p,i)=>{p.value=converted[i];});previous=currency.value;form.dispatchEvent(new Event('input',{bubbles:true}));}
        catch(error){currency.value=previous;window.alert(error.message);}
    });
}
