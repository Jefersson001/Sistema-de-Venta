(() => {
    const source=document.querySelector('[data-order-address-catalog]');
    if(!source)return;
    const form=document.querySelector('[data-quotation-form], [data-order-form], [data-operation-form]'),partner=form?.querySelector('[name=customer_id], [name=supplier_id], [name=contact_id]');
    if(!form||!partner)return;
    const catalog=JSON.parse(source.textContent),selectors=[...form.querySelectorAll('[data-order-address]')];
    const existing=(form.getAttribute('action')||'').includes('id=');
    function rows(){
        let extra=[];try{extra=JSON.parse(partner.selectedOptions[0]?.dataset.addresses||'[]');}catch{}
        return [...new Map([...catalog,...extra].filter(row=>String(row.contact_id)===partner.value).map(row=>[String(row.id),row])).values()];
    }
    function refresh(select,initial=false){
        const kind=select.dataset.orderAddress,old=select.value,items=rows(),current=items.find(row=>String(row.id)===old);
        const options=items.filter(row=>(row.active&&['both',kind].includes(row.usage_type))||(initial&&String(row.id)===old));
        select.replaceChildren(new Option(initial&&existing&&!old?'Dirección guardada en el documento':'Dirección principal / predeterminada',''));
        for(const row of options)select.add(new Option(row.label+(!row.active?' (archivada)':''),String(row.id)));
        let selected=initial&&existing?old:String((options.find(row=>row['default_'+kind])||options[0])?.id||'');
        if(!options.some(row=>String(row.id)===selected))selected='';select.value=selected;
        if(!initial||!existing)preview(select);
    }
    function preview(select){
        const kind=select.dataset.orderAddress,row=rows().find(row=>String(row.id)===select.value);
        const text=form.querySelector(kind==='invoice'?'[data-invoice-address]':'[data-delivery-address]');
        if(text)text.value=row?.formatted||partner.selectedOptions[0]?.dataset.address||'';
        if(kind==='delivery')form.dispatchEvent(new CustomEvent('order:delivery-address',{bubbles:true,detail:row??null}));
    }
    for(const select of selectors){
        refresh(select,true);
        select.addEventListener('change',()=>{form.querySelector('[data-refresh-order-address="'+select.dataset.orderAddress+'"]').value='1';preview(select);});
    }
    partner.addEventListener('change',()=>{for(const select of selectors){refresh(select);form.querySelector('[data-refresh-order-address="'+select.dataset.orderAddress+'"]').value='1';}});
    let loading=false;
    form.addEventListener('submit',event=>{if(loading)event.preventDefault();});
    form.querySelector('[data-update-order-addresses]')?.addEventListener('click',async event=>{
        if(!partner.value||loading)return;
        const button=event.currentTarget,scope=form.querySelector('[name=company_scope]')?.value||'';
        const status=form.querySelector('[data-order-address-status]')||document.createElement('p');status.dataset.orderAddressStatus='';status.className='section-subtitle';status.setAttribute('role','status');if(!status.isConnected)button.after(status);
        loading=true;button.disabled=true;status.textContent='Consultando direcciones actuales…';
        try{
            const currentPartner=partner.value,url=new URL('../contactos/direcciones.php',location.href);url.search=new URLSearchParams({id:currentPartner,scope});
            const response=await fetch(url,{cache:'no-store',credentials:'same-origin',signal:AbortSignal.timeout(15000)}),payload=await response.json();
            if(!response.ok||!Array.isArray(payload.addresses)||payload.scope!==scope)throw Error(payload.error||'No se pudieron consultar las direcciones.');
            if(currentPartner!==partner.value)return;
            catalog.splice(0,catalog.length,...catalog.filter(row=>String(row.contact_id)!==currentPartner),...payload.addresses);
            if(partner.selectedOptions[0])partner.selectedOptions[0].dataset.addresses=JSON.stringify(payload.addresses);
            for(const select of selectors){
                const previous=select.value,kind=select.dataset.orderAddress,current=rows().find(row=>String(row.id)===previous&&row.active&&['both',kind].includes(row.usage_type));
                refresh(select);if(current)select.value=previous;preview(select);form.querySelector('[data-refresh-order-address="'+kind+'"]').value='1';
            }
            status.textContent='Direcciones actualizadas. Guarda el documento para conservarlas.';form.dispatchEvent(new Event('change',{bubbles:true}));
        }catch(error){status.textContent=error.message||'No se pudieron consultar las direcciones. Se conservan los datos anteriores.';}
        finally{loading=false;button.disabled=false;}
    });
})();
