function productCommissionUnits(value,scale,digits=10) {
    const text=String(value).trim().replace(',','.');
    if(!new RegExp(`^[0-9]{1,${digits}}(?:\\.[0-9]{1,${scale}})?$`).test(text))throw new Error('Indica un precio o valor de comisión válido.');
    const [whole,fraction='']=text.split('.');return BigInt(whole+fraction.padEnd(scale,'0'));
}
function productCommissionMoney(cents) { return `${cents/100n}.${String(cents%100n).padStart(2,'0')}`; }
function productCommissionPricing(price,type,value) {
    const base=productCommissionUnits(price||'0',2);let unit=0n;
    if(type==='fixed')unit=productCommissionUnits(value||'0',2);
    else if(type==='percent'){
        const rate=productCommissionUnits(value||'0',2,4);
        if(rate>100000n)throw new Error('El porcentaje máximo es 1000 %.');
        unit=(base*rate+5000n)/10000n;
    }else if(type!=='none')throw new Error('Selecciona un tipo de comisión válido.');
    if(base+unit>999999999999n)throw new Error('El precio para vendedor supera el máximo permitido.');
    return {price:productCommissionMoney(base),commission:type==='none'?null:productCommissionMoney(unit),sellerPrice:productCommissionMoney(base+unit)};
}
if(typeof module!=='undefined'&&module.exports)module.exports={productCommissionPricing};
if(typeof document!=='undefined'&&document.querySelectorAll)for(const form of document.querySelectorAll('[data-product-commission-form]')){
    const type=form.querySelector('[data-product-commission-type]');
    const value=form.querySelector('[data-product-commission-value]');
    const currency=form.querySelector('[name="currency_code"]');
    const unit=form.querySelector('[data-product-commission-unit]');
    const price=form.querySelector('[data-product-seller-price]');
    const locked=type.disabled||form.dataset.permissionReadonly==='true';
    function update() {
        const none=type.value==='none';
        form.querySelector('[data-product-commission-value-field]').hidden=none;
        value.disabled=none||locked;value.required=!none&&!locked;
        form.querySelector('[data-product-commission-label]').textContent=type.value==='percent'?'Comisión (%)':'Comisión fija por unidad ('+currency.value+')';
        try {
            const result=productCommissionPricing(form.querySelector('[name="sale_price"]').value,type.value,value.value);
            unit.value=(result.commission??'0.00')+' '+currency.value;price.value=result.sellerPrice+' '+currency.value;
            value.setCustomValidity('');
        }catch(error){unit.value=price.value='—';value.setCustomValidity(none?'':error.message);}
    }
    type.addEventListener('change',()=>{if(type.value==='fixed')value.value=value.value.replace(/(\.\d{2})0+$/,'$1');update();});
    form.addEventListener('input',update);form.addEventListener('change',update);update();
}
