(() => {
    'use strict';
    const root=document.querySelector('[data-direct-root]');if(!root)return;
    root.querySelector('[data-direct-payment]')?.addEventListener('submit',event=>{event.target.querySelector('button[type="submit"]').disabled=true;});
    window.addEventListener('pageshow',()=>{const button=root.querySelector('[data-direct-payment] button[type="submit"]');if(button)button.disabled=false;});
    root.querySelector('[data-direct-print]')?.addEventListener('click',()=>window.print());
    const scope=root.dataset.storageScope;
    const completed=root.querySelector('[data-direct-completed]');
    if(completed){
        try{for(let i=localStorage.length-1;i>=0;i--){const key=localStorage.key(i);if(key?.startsWith('vent-direct-'+scope+'-')){const value=JSON.parse(localStorage.getItem(key));if(value.request_key===completed.dataset.directCompleted)localStorage.removeItem(key);}}}catch{}
    }
    const dataNode=root.querySelector('[data-direct-data]');if(!dataNode)return;
    const data=JSON.parse(dataNode.textContent),products=new Map(data.products.map(p=>[String(p.id),p]));
    const grid=root.querySelector('[data-direct-products]'),search=root.querySelector('[data-direct-search]'),form=root.querySelector('[data-direct-form]');
    const request=form?.dataset.requestMode==='1',cart=new Map();let gpsPending=false,gpsGeneration=0,cartTotal=0n,credit=null,creditGeneration=0;
    const cartPanel=form?.querySelector('[data-direct-cart]')||form;
    const pageSize=12;let catalogPage=0;
    const storageKey=form?'vent-direct-'+scope+'-'+form.elements.seller_id.value:null;
    const hundredths=value=>{const s=String(value??'').replace(',','.');if(!/^\d{1,12}(?:\.\d{1,2})?$/.test(s))return null;const [a,b='']=s.split('.');return BigInt(a)*100n+BigInt(b.padEnd(2,'0'));};
    const decimal=value=>(value/100n).toString()+'.'+(value%100n).toString().padStart(2,'0');
    function node(tag,cls,text){const el=document.createElement(tag);if(cls)el.className=cls;if(text!==undefined)el.textContent=text;return el;}
    function lineAmount(product,quantity){const base=(quantity*hundredths(product.unit_price)+50n)/100n;return base+(base*hundredths(product.tax_rate)+5000n)/10000n;}
    function rows(){return [...cart].map(([id,quantity])=>({product_id:Number(id),quantity:decimal(quantity)}));}
    function persist(){
        if(!form)return;const state={lines:rows()};
        for(const key of ['request_key','customer_id','payment_mode','payment_term_id','sale_latitude','sale_longitude','sale_location_source','notes','request_kind'])if(form.elements[key])state[key]=form.elements[key].value;
        try{localStorage.setItem(storageKey,JSON.stringify(state));}catch{}
    }
    let initial=data.initial||{};
    if(form){
        try{if(!initial.lines){const saved=JSON.parse(localStorage.getItem(storageKey)||'null');if(saved)initial=saved;}}catch{}
        for(const row of Array.isArray(initial.lines)?initial.lines:[]){if(!row||typeof row!=='object')continue;const id=String(row.product_id),qty=hundredths(row.quantity);if(products.has(id)&&qty>0n)cart.set(id,qty);}
        for(const key of ['request_key','customer_id','payment_mode','payment_term_id','sale_latitude','sale_longitude','notes','request_kind']){
            const input=form.elements[key];if(input&&typeof initial[key]==='string'&&(key!=='request_key'||/^[a-f0-9]{64}$/.test(initial[key])))input.value=initial[key];
        }
    }
    const message=root.querySelector('[data-direct-message]');
    function setQuantity(id,qty){
        const p=products.get(id);if(!p||qty===null)return;
        if(p.tracking_mode==='serial'&&qty%100n!==0n){if(message)message.textContent='Los productos por serie requieren cantidades enteras.';return;}
        if(!request&&qty>hundredths(p.available)){if(message)message.textContent='La cantidad supera lo disponible de '+p.name+'.';return;}
        if(qty<=0n)cart.delete(id);else cart.set(id,qty);
        if(message)message.textContent='';renderCart();renderProducts();persist();
    }
    const pager=node('nav','direct-pagination');pager.setAttribute('aria-label','Páginas de productos');
    const previous=node('button','','← Anterior'),next=node('button','','Siguiente →'),pageStatus=node('span');
    previous.type=next.type='button';previous.setAttribute('data-direct-previous','');next.setAttribute('data-direct-next','');pageStatus.setAttribute('aria-live','polite');
    pager.append(previous,pageStatus,next);grid.after(pager);
    function changePage(delta){catalogPage+=delta;renderProducts();grid.closest('.direct-catalog').scrollIntoView({behavior:'smooth',block:'start'});}
    previous.addEventListener('click',()=>changePage(-1));next.addEventListener('click',()=>changePage(1));
    function renderProducts(){
        const term=(search?.value||'').toLocaleLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'');
        const selected=[...products].filter(([,p])=>p.name.toLocaleLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').includes(term));
        const pages=Math.max(1,Math.ceil(selected.length/pageSize));catalogPage=Math.max(0,Math.min(catalogPage,pages-1));
        const first=catalogPage*pageSize,last=Math.min(first+pageSize,selected.length);
        const cards=selected.slice(first,last).map(([id,p])=>{
            const card=node('article','direct-product');card.dataset.productId=id;
            card.append(node('span','direct-product-mark',p.name.slice(0,2).toUpperCase()),node('h3','',p.name),node('small','',p.tracking_mode==='none'?'Artículo':p.tracking_mode==='serial'?'Número de serie':'Lotes'));
            if(!request)card.append(node('strong','',p.unit_total+' '+data.currency));
            const bottom=node('div','direct-product-bottom');bottom.append(node('small','',p.available+' disponibles'));
            if(form){const add=node('button','direct-add','+');add.type='button';add.setAttribute('aria-label','Agregar '+p.name);add.addEventListener('click',()=>setQuantity(id,(cart.get(id)||0n)+100n));bottom.append(add);}
            card.append(bottom);return card;
        });grid.replaceChildren(...cards);const empty=root.querySelector('[data-direct-empty]');if(empty)empty.hidden=cards.length>0;
        pager.hidden=pages<=1;previous.disabled=catalogPage===0;next.disabled=catalogPage===pages-1;
        const label=(selected.length?first+1:0)+'–'+last+' de '+selected.length;if(pageStatus.textContent!==label)pageStatus.textContent=label;
    }
    let jump,cartVisible=false;
    if(form){jump=node('button','direct-cart-jump','Ver carrito');jump.type='button';jump.addEventListener('click',()=>cartPanel.scrollIntoView({behavior:'smooth',block:'start'}));root.append(jump);}
    if(cartPanel&&'IntersectionObserver' in window)new IntersectionObserver(entries=>{cartVisible=entries[0].isIntersecting;if(jump)jump.hidden=!cart.size||cartVisible;}).observe(cartPanel);
    function renderCart(){
        if(!form)return;let total=0n,count=0n;
        const list=[...cart].map(([id,quantity])=>{
            const p=products.get(id);count+=quantity;const amount=request?quantity:lineAmount(p,quantity);total+=amount;
            const line=node('div','direct-cart-line'),heading=node('div');heading.append(node('strong','',p.name),node('span','',decimal(amount)+(request?' uds.':'')));line.append(heading);
            const controls=node('div','direct-quantity');
            const minus=node('button','','−');minus.type='button';minus.setAttribute('aria-label','Reducir '+p.name);minus.addEventListener('click',()=>setQuantity(id,quantity-100n));
            const serial=p.tracking_mode==='serial',formatQuantity=value=>serial?(value/100n).toString():decimal(value);
            const input=node('input');input.type='number';input.inputMode=serial?'numeric':'decimal';input.min=serial?'1':'0.01';input.step=serial?'1':'0.01';input.value=formatQuantity(quantity);input.setAttribute('aria-label','Cantidad de '+p.name);input.addEventListener('change',()=>{const value=hundredths(input.value);if(value===null){input.value=formatQuantity(quantity);return;}setQuantity(id,value);});
            const plus=node('button','','+');plus.type='button';plus.setAttribute('aria-label','Aumentar '+p.name);plus.addEventListener('click',()=>setQuantity(id,quantity+100n));
            const remove=node('button','direct-remove','×');remove.type='button';remove.setAttribute('aria-label','Quitar '+p.name);remove.addEventListener('click',()=>setQuantity(id,0n));
            controls.append(minus,input,plus,remove);line.append(controls);return line;
        });
        form.querySelector('[data-direct-cart-lines]').replaceChildren(...list);
        form.querySelector('[data-direct-lines]').value=JSON.stringify(rows());
        form.querySelector('[data-direct-total]').textContent=decimal(total)+(request?' uds.':' '+data.currency);
        form.querySelector('[data-direct-submit]').disabled=!cart.size||gpsPending||!navigator.onLine;
        form.querySelector('.direct-cart-hint').hidden=cart.size>0;
        jump.textContent=(request?'Solicitud':'Carrito')+' · '+cart.size+' artículos · '+decimal(total)+(request?' uds.':' '+data.currency);jump.hidden=!cart.size||cartVisible;
        cartTotal=total;showCredit();
    }
    function paymentMode(){
        if(!form||request)return;
        const summary=form.querySelector('[data-direct-cart-customer]'),edit=form.querySelector('[data-direct-edit-customer]');
        if(summary)summary.textContent=form.elements.customer_id.value?form.elements.customer_id.selectedOptions[0].textContent:'Sin cliente seleccionado';
        if(edit)edit.textContent=form.elements.customer_id.value?'Cambiar cliente':'Elegir cliente';
        const option=form.elements.payment_term_id.selectedOptions[0],due=form.querySelector('[data-direct-due]');due.textContent='';
        if(option?.dataset.days){const date=new Date(data.today+'T12:00:00Z');date.setUTCDate(date.getUTCDate()+Number(option.dataset.days));due.textContent='Vencimiento: '+date.toLocaleDateString('es-VE',{timeZone:'UTC'});}
        showCredit();
    }
    function showCredit(){
        const panel=form?.querySelector('[data-direct-credit]');if(!panel)return;
        panel.hidden=!credit||Number(form.elements.payment_term_id.selectedOptions[0]?.dataset.days||0)===0;if(panel.hidden)return;
        const warnings=[];if(hundredths(credit.overdue)>0n)warnings.push('Tiene '+credit.overdue+' '+credit.currency+' vencidos.');
        if(credit.limit!==null&&hundredths(credit.debt)+cartTotal>hundredths(credit.limit))warnings.push('La venta supera el límite de '+credit.limit+' '+credit.currency+'.');
        panel.textContent='Saldo del cliente: '+credit.debt+' '+credit.currency+'. '+warnings.join(' ')+(warnings.length?(credit.policy==='block'?' El control bloqueará esta venta a crédito.':' Puedes continuar con la venta.'):'');
    }
    async function loadCredit(){
        if(!form||request)return;const generation=++creditGeneration;credit=null;showCredit();const id=form.elements.customer_id.value;if(!id)return;
        const url=new URL(location.href);url.searchParams.set('credit_customer_id',id);
        try{const response=await fetch(url,{headers:{Accept:'application/json'}});if(!response.ok)throw Error();const result=await response.json();if(generation!==creditGeneration)return;credit=result;showCredit();}catch{if(generation===creditGeneration){const panel=form.querySelector('[data-direct-credit]');panel.hidden=false;panel.textContent='No se pudo consultar el crédito. El sistema lo comprobará al confirmar.';}}
    }
    search?.addEventListener('input',()=>{catalogPage=0;renderProducts();});
    search?.addEventListener('keydown',event=>{if(event.key==='Enter')event.preventDefault();});
    if(form){
        form.querySelector('[data-direct-edit-customer]')?.addEventListener('click',()=>{
            const customer=form.elements.customer_id;form.querySelector('.direct-sale-details').scrollIntoView({behavior:'smooth',block:'start'});
            (customer.closest('label').querySelector('.searchable-select-input')||customer).focus({preventScroll:true});
        });
        form.addEventListener('change',()=>{paymentMode();persist();});
        form.elements.customer_id?.addEventListener('change',loadCredit);
        form.addEventListener('input',persist);
        form.addEventListener('submit',event=>{
            if(!cart.size||gpsPending||!navigator.onLine){event.preventDefault();message.textContent=!navigator.onLine?'Necesitas conexión para confirmar. Tu carrito se conserva en este navegador.':gpsPending?'Espera a que termine la captura de ubicación.':'Agrega productos antes de continuar.';return;}
            form.querySelector('[data-direct-lines]').value=JSON.stringify(rows());persist();form.querySelector('[data-direct-submit]').disabled=true;message.textContent=request?'Enviando solicitud…':'Confirmando venta…';
        });
        const status=form.querySelector('[data-direct-gps-status]');
        form.querySelector('[data-direct-gps]')?.addEventListener('click',()=>{
            if(!navigator.geolocation){status.textContent='Este navegador no permite capturar la ubicación.';return;}
            const generation=++gpsGeneration;gpsPending=true;status.textContent='Capturando ubicación…';renderCart();
            navigator.geolocation.getCurrentPosition(position=>{
                if(generation!==gpsGeneration)return;gpsPending=false;form.elements.sale_latitude.value=position.coords.latitude.toFixed(6);form.elements.sale_longitude.value=position.coords.longitude.toFixed(6);status.textContent='Ubicación capturada: '+form.elements.sale_latitude.value+', '+form.elements.sale_longitude.value;persist();renderCart();
            },()=>{if(generation!==gpsGeneration)return;gpsPending=false;status.textContent='No se pudo capturar la ubicación. Puedes continuar sin ella.';renderCart();},{enableHighAccuracy:true,timeout:15000,maximumAge:0});
        });
        form.querySelector('[data-direct-clear-gps]')?.addEventListener('click',()=>{gpsGeneration++;gpsPending=false;form.elements.sale_latitude.value='';form.elements.sale_longitude.value='';status.textContent='Ubicación retirada.';persist();renderCart();});
        if(status&&form.elements.sale_latitude.value)status.textContent='Ubicación guardada en el carrito.';
    }
    window.addEventListener('pageshow',event=>{if(event.persisted){renderCart();if(message)message.textContent='';}});
    for(const name of ['online','offline'])window.addEventListener(name,()=>{renderCart();if(message)message.textContent=navigator.onLine?'Conexión recuperada. Revisa y confirma tu carrito.':'Sin conexión. El carrito se conserva, pero no reserva existencias.';});
    renderProducts();paymentMode();renderCart();loadCredit();
})();
