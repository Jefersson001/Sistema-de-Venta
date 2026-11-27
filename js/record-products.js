(() => {
    'use strict';
    const dialog=document.querySelector('#record-products-dialog');if(!dialog)return;
    const title=dialog.querySelector('h2'),status=dialog.querySelector('[data-record-products-status]'),content=dialog.querySelector('[data-record-products-content]');
    let pending,trigger,previousOverflow='';
    const close=()=>dialog.close();
    dialog.querySelector('[data-close-record-products]').addEventListener('click',close);
    dialog.addEventListener('close',()=>{pending?.abort();document.body.style.overflow=previousOverflow;trigger?.focus();});
    dialog.addEventListener('click',event=>{if(event.target!==dialog)return;const r=dialog.getBoundingClientRect();if(event.clientX<r.left||event.clientX>r.right||event.clientY<r.top||event.clientY>r.bottom)close();});
    document.addEventListener('click',async event=>{
        const button=event.target.closest('[data-record-products-url]');if(!button)return;
        pending?.abort();const request=new AbortController();pending=request;trigger=button;
        title.textContent='Productos';content.replaceChildren();status.textContent='Cargando productos…';
        if(!dialog.open){previousOverflow=document.body.style.overflow;dialog.showModal();document.body.style.overflow='hidden';}
        const url=new URL(button.dataset.recordProductsUrl,location.href),scope=dialog.dataset.companyScope;url.searchParams.set('scope',scope);
        try{
            const response=await fetch(url,{headers:{Accept:'application/json'},signal:request.signal,cache:'no-store'});const data=await response.json();
            if(pending!==request||!dialog.open)return;
            if(!response.ok)throw Error(data.error||'No se pudieron consultar los productos.');
            if(data.scope!==scope)throw Error('La selección de empresas cambió. Recarga la lista.');
            title.textContent='Productos · '+data.name;
            if(!Array.isArray(data.products))throw Error('No se pudieron consultar los productos.');
            if(!data.products.length){status.textContent='Este documento no tiene productos.';return;}
            const table=document.createElement('table'),head=table.createTHead().insertRow(),body=table.createTBody();
            for(const label of ['Producto','Cantidad']){const th=document.createElement('th');th.scope='col';th.textContent=label;head.append(th);}
            for(const product of data.products){const row=body.insertRow();row.insertCell().textContent=product.name;row.insertCell().textContent=String(product.quantity).replace(/(\.\d*?)0+$/,'$1').replace(/\.$/,'');}
            content.append(table);status.textContent='';
        }catch(error){if(error.name!=='AbortError'&&pending===request&&dialog.open){content.replaceChildren();status.textContent=error.message||'No se pudo cargar el detalle. Intenta nuevamente.';}}
    });
})();
