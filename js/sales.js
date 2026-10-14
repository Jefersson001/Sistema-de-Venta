const quotationForm = document.querySelector('[data-quotation-form]');

if (quotationForm && quotationForm.dataset.permissionReadonly!=='true') {
    const customer = quotationForm.querySelector('[data-quotation-customer]');
    const salesperson = quotationForm.querySelector('[data-quotation-salesperson]');
    const lines = quotationForm.querySelector('[data-quotation-lines]');
    const addLine = quotationForm.querySelector('[data-add-line]');
    const template = quotationForm.querySelector('[data-quotation-line-template]');
    let nextIndex = lines.querySelectorAll('[data-quotation-line]').length;
    let hadSalesperson=Boolean(salesperson.value);
    const pricingStates=new Map();

    function decimalUnits(value, scale) {
        const normalized = value.trim().replace(',', '.');
        if (!new RegExp(`^[0-9]+(\\.[0-9]{1,${scale}})?$`).test(normalized)) return null;
        const [whole, fractional = ''] = normalized.split('.');
        return BigInt(whole + fractional.padEnd(scale, '0'));
    }

    function money(cents) {
        return `${cents / 100n}.${String(cents % 100n).padStart(2, '0')}`;
    }

    function updateTotals() {
        let total = 0n; let baseTotal = 0n; let commissionTotal = 0n;
        let valid = true;const taxGroups=new Map();
        const rows = [...lines.querySelectorAll('[data-quotation-line]')];
        for (const row of rows) {
            const productId = row.querySelector('[data-line-product]').value;
            const quantity = decimalUnits(row.querySelector('[data-line-quantity]').value, 3);
            const price = decimalUnits(row.querySelector('[data-line-price]').value, 2);
            let amount = 0n;
            let display = '0.00';
            let commission = 0n;
            if (productId) {
                if (quantity === null || quantity <= 0n || price === null) {
                    valid = false;
                    display = '—';
                } else {
                    amount = (quantity * price + 500n) / 1000n;
                    display = money(amount);
                    baseTotal += amount;
                    const reference = decimalUnits(row.querySelector('[data-line-reference]')?.value ?? '', 2);
                    const configured=decimalUnits(row.querySelector('[data-line-commission-unit]')?.value??'',2);
                    if (salesperson.value && (configured!==null || (reference!==null&&price>reference))) {
                        commission = (quantity * (configured??(price-reference)) + 500n) / 1000n;
                        commissionTotal += commission;
                    }
                    const taxSelect=row.querySelector('[data-line-tax]');const taxOption=taxSelect.selectedOptions[0];
                    const rawRate=taxOption?.dataset?.rate || '0';
                    const rate=decimalUnits(rawRate,4) || 0n;
                    const taxAmount=(amount * rate + 500000n) / 1000000n;
                    if(taxSelect.value){
                        const name=taxOption?.dataset?.taxName||taxOption?.textContent||'Impuesto';
                        const fraction=String(rate%10000n).padStart(4,'0').replace(/0+$/,'');
                        const percentage=String(rate/10000n)+(fraction?'.'+fraction:'');
                        const key=JSON.stringify([taxSelect.value,name,String(rate)]);
                        if(!taxGroups.has(key))taxGroups.set(key,{label:name+' ('+percentage+' %)',amount:0n});
                        taxGroups.get(key).amount+=taxAmount;
                    }
                    amount += taxAmount;
                    total += amount;
                }
            }
            row.querySelector('[data-line-base]').textContent = display;
            row.querySelector('[data-line-total]').textContent = display==='—' ? display : money(amount);
            const commissionCell=row.querySelector('[data-line-commission]');
            if (commissionCell) commissionCell.textContent=display==='—' ? display : money(commission);
        }
        quotationForm.querySelector('[data-quotation-base]').textContent = valid ? money(baseTotal) : '—';
        quotationForm.querySelector('[data-quotation-total]').textContent = valid ? money(total) : '—';
        renderTaxGroups(taxGroups,valid);
        const commissionValue=quotationForm.querySelector('[data-quotation-commission]');
        if (commissionValue) commissionValue.textContent=valid ? money(commissionTotal) : '—';
        if (addLine) addLine.disabled = rows.length >= 100;
        const help=quotationForm.querySelector('[data-quotation-commission-help]');
        if(help)help.hidden=Boolean(salesperson.value)||!rows.some(row=>row.querySelector('[data-line-product]').value&&(decimalUnits(row.querySelector('[data-line-commission-unit]')?.value??'',2)??0n)>0n);
    }

    function renderTaxGroups(groups,valid) {
        const totalRow=quotationForm.querySelector('[data-quotation-total-row]');
        if(!totalRow)return;
        for(const row of quotationForm.querySelectorAll('[data-quotation-tax-group]'))row.remove();
        for(const group of groups.values()){
            const row=document.createElement('div');row.setAttribute('data-quotation-tax-group','');
            const label=document.createElement('dt');label.textContent=group.label;
            const amount=document.createElement('dd');amount.textContent=valid?money(group.amount):'—';
            row.append(label,amount);totalRow.parentElement.insertBefore(row,totalRow);
        }
    }

    function updatePricingStatus() {
        const states=[...pricingStates.values()];const loading=states.some(s=>s==='loading');const failed=states.some(s=>s==='error');
        const status=quotationForm.querySelector('[data-quotation-pricing-status]');
        if(status){status.hidden=!loading&&!failed;status.textContent=failed?'No se pudo actualizar la configuración de un producto. Vuelve a seleccionarlo antes de guardar.':'Actualizando precio, comisión e impuesto del producto…';}
        for(const button of quotationForm.querySelectorAll('button[type="submit"]'))button.disabled=loading||failed;
    }

    function applyProductPricing(row,selected) {
        const currency=quotationForm.querySelector('[data-order-currency]');
        const pricing=typeof productCommissionPricing==='function'?productCommissionPricing(selected?.dataset.price??'0.00',selected?.dataset.commissionType??'none',selected?.dataset.commissionValue??'0'):{price:selected?.dataset.price??'0.00',commission:null};
        const convert=value=>currency&&typeof convertOrderMoney==='function'?convertOrderMoney(value,selected?.dataset.currency||currency.value,currency.value,{...orderCurrencySettings,exchange_rate:quotationForm.querySelector('[data-order-exchange]')?.value||orderCurrencySettings.exchange_rate}):value;
        const base=convert(pricing.price);const commission=pricing.commission===null?'':convert(pricing.commission);
        const reference=row.querySelector('[data-line-reference]');if(reference)reference.value=row.querySelector('[data-line-product]').value?base:'';
        const configured=row.querySelector('[data-line-commission-unit]');if(configured)configured.value=commission;
        const id=row.querySelector('[data-line-id]');if(id)id.value='0';
        row.querySelector('[data-line-price]').value=money(decimalUnits(base,2)+(salesperson.value?(decimalUnits(commission,2)??0n):0n));
    }

    function applyProductTax(row,id,liveTax=null) {
        const tax=row.querySelector('[data-line-tax]');
        let option=[...tax.options].find(option=>option.value===String(id));
        if(liveTax){
            if(!option){option=document.createElement('option');option.value=String(liveTax.id);tax.append(option);}
            option.dataset.catalogRate=String(liveTax.rate);option.dataset.catalogName=liveTax.name;
        }
        tax.value=option?String(id):'';
        if(option){
            option.dataset.rate=option.dataset.catalogRate??option.dataset.rate??'0';
            option.dataset.taxName=option.dataset.catalogName??option.dataset.taxName??option.textContent??'Impuesto';
            if(liveTax)option.textContent=liveTax.name;
        }
    }

    function markDirty() {
        const actions = document.querySelector('[data-quotation-status-form]');
        if (actions) {
            actions.querySelectorAll('button').forEach(button => { button.disabled = true; });
            actions.querySelector('[data-quotation-dirty-help]').hidden = false;
        }
    }

    function wireLine(row) {
        const product = row.querySelector('[data-line-product]');
        let request=0;let controller=null;
        product.addEventListener('change', async () => {
            const current=++request;controller?.abort();
            const selected = product.selectedOptions[0];
            try{
                applyProductPricing(row,selected);applyProductTax(row,selected?.dataset.tax??'');
            }catch(error){window.alert(error.message);}
            updateTotals();
            const endpoint=quotationForm.dataset?.productEndpoint;
            if(!endpoint||!product.value){pricingStates.delete(row);row.querySelector('[data-line-price]').setCustomValidity?.('');updatePricingStatus();return;}
            const productId=product.value;controller=new AbortController();const activeController=controller;
            pricingStates.set(row,'loading');row.querySelector('[data-line-price]').setCustomValidity?.('Espera a que se actualice el producto.');updatePricingStatus();
            const timeout=setTimeout(()=>activeController.abort(),12000);
            try{
                const response=await fetch(endpoint+'?id='+encodeURIComponent(productId),{cache:'no-store',signal:activeController.signal,headers:{Accept:'application/json'}});
                const data=await response.json();
                if(current!==request||product.value!==productId||!row.isConnected)return;
                if(!response.ok)throw new Error(data.error||'No se pudo actualizar el producto.');
                if(String(data.product?.id)!==productId)throw new Error('La configuración recibida no corresponde al producto.');
                Object.assign(selected.dataset,{price:data.product.sale_price,currency:data.product.currency_code,commissionType:data.product.commission_type,commissionValue:data.product.commission_value,tax:data.tax?String(data.tax.id):''});
                applyProductPricing(row,selected);applyProductTax(row,selected.dataset.tax,data.tax);
                pricingStates.delete(row);row.querySelector('[data-line-price]').setCustomValidity?.('');updateTotals();
            }catch(error){
                if(current!==request||!row.isConnected)return;
                pricingStates.set(row,'error');row.querySelector('[data-line-price]').setCustomValidity?.('Vuelve a seleccionar el producto para actualizar su configuración.');
            }finally{clearTimeout(timeout);if(current===request)updatePricingStatus();}
        });
        const remove = row.querySelector('[data-remove-line]');
        if (remove) {
            remove.hidden = false;
            remove.addEventListener('click', () => {
                controller?.abort();pricingStates.delete(row);
                row.remove();
                updateTotals();
                updatePricingStatus();
                markDirty();
            });
        }
    }

    function updateRelatedSalesperson() {
        const sellerId = customer.selectedOptions[0]?.dataset.salesperson ?? '';
        salesperson.value = [...salesperson.options].some(option => option.value === sellerId) ? sellerId : '';
        updateRelatedStorage();
        updateCommissionPricingForSeller();
    }

    function updateCommissionPricingForSeller() {
        const active=Boolean(salesperson.value);
        if(active!==hadSalesperson)for(const row of lines.querySelectorAll('[data-quotation-line]')){
            const commission=decimalUnits(row.querySelector('[data-line-commission-unit]')?.value??'',2);
            const reference=decimalUnits(row.querySelector('[data-line-reference]')?.value??'',2);
            if(commission!==null&&reference!==null)row.querySelector('[data-line-price]').value=money(reference+(active?commission:0n));
        }
        hadSalesperson=active;updateTotals();
    }

    function updateRelatedStorage() {
        const storage = quotationForm.querySelector('[data-quotation-storage]');
        if (storage && storage.dataset.preserveStorage !== 'true') {
            storage.value = salesperson.selectedOptions[0]?.dataset?.storageLabel ?? '';
        }
    }

    customer.addEventListener('change', () => {
        const selected = customer.selectedOptions[0];
        const address = selected?.dataset.address ?? '';
        quotationForm.querySelector('[data-invoice-address]').value = address;
        quotationForm.querySelector('[data-delivery-address]').value = address;
        updateRelatedSalesperson();
    });
    if (!salesperson.value && customer.value && quotationForm.dataset?.salespersonInitialized!=='true') updateRelatedSalesperson();
    salesperson.addEventListener('change',()=>{updateRelatedStorage();updateCommissionPricingForSeller();});
    updateRelatedStorage();
    lines.querySelectorAll('[data-quotation-line]').forEach(wireLine);
    if (addLine && template) {
        addLine.hidden = false;
        addLine.addEventListener('click', () => {
            if (lines.querySelectorAll('[data-quotation-line]').length >= 100) return;
            const fragment = template.content.cloneNode(true);
            const row = fragment.querySelector('[data-quotation-line]');
            row.querySelectorAll('[name]').forEach(field => {
                field.name = field.name.replace('__INDEX__', String(nextIndex));
            });
            nextIndex++;
            lines.append(fragment);
            wireLine(row);
            updateTotals();
            markDirty();
            row.querySelector('[data-line-product]').focus();
        });
    }
    quotationForm.addEventListener('input', () => { updateTotals(); markDirty(); });
    quotationForm.addEventListener('change', () => { updateTotals(); markDirty(); });
    quotationForm.addEventListener('submit',event=>{if(pricingStates.size){event.preventDefault();updatePricingStatus();}});
    quotationForm.querySelector('[data-pick-quotation-salesperson]')?.addEventListener('click',()=>{document.querySelector('#quotation-tab-other')?.click();salesperson.focus();});
    updateTotals();
}
