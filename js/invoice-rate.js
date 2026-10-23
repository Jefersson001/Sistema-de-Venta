for(const form of document.querySelectorAll('[data-invoice-rate-form]')){
    const date=form.querySelector('[name="invoice_date"]'),rate=form.querySelector('[data-invoice-rate]'),note=form.querySelector('[data-invoice-rate-date]');
    const reference=document.querySelector('[data-invoice-currency-reference]');let request=0,controller;
    date.addEventListener('change',async()=>{
        if(!date.value||!date.checkValidity())return;
        controller?.abort();controller=new AbortController();const current=++request;note.textContent='Consultando tasa de esta fecha…';
        try{const response=await fetch(form.dataset.rateEndpoint+'&date='+encodeURIComponent(date.value),{cache:'no-store',signal:controller.signal,headers:{Accept:'application/json'}});const data=await response.json();
            if(current!==request)return;if(!response.ok)throw new Error(data.error||'No se pudo consultar la tasa.');
            if(window.nexoCompanyScope&&data.scope!==window.nexoCompanyScope){window.location.reload();return;}
            rate.value=data.exchange_rate||'';note.textContent=data.exchange_rate?((data.exchange_rate_date||'Tasa original')+' · '+(data.exchange_rate_source==='bcv'?'BCV':'Manual')+' · Guarda para aplicar'):'No hay una tasa vigente para esta fecha. Registra la tasa histórica antes de confirmar.';
            if(reference)reference.textContent=data.reference?data.reference.amount+' '+data.reference.code:'Sin tasa para convertir';
        }catch(error){if(current!==request)return;rate.value='';note.textContent=error.message;if(reference)reference.textContent='No se pudo consultar la referencia';}
    });
}
