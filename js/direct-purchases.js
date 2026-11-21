(() => {
    const root=document.querySelector('[data-purchase-scope]');
    if(!root)return;
    const storageKey='konta-direct-purchase-'+root.dataset.purchaseScope;
    if(root.dataset.purchaseCompleted){try{const draft=JSON.parse(sessionStorage.getItem(storageKey));if(draft?.request_key===root.dataset.purchaseCompleted)sessionStorage.removeItem(storageKey);}catch{}return;}
    const form=document.querySelector('[data-purchase-checkout]');
    if(!form)return;
    const data=JSON.parse(document.querySelector('[data-purchase-data]').textContent);
    const products=new Map(data.products.map(p=>[String(p.id),p]));
    const catalog=form.querySelector('[data-purchase-catalog]'),cart=form.querySelector('[data-purchase-cart]'),field=form.querySelector('[data-purchase-lines]');
    const supplier=form.querySelector('[name=supplier_id]'),currency=form.querySelector('[name=currency_code]');
    const draftFields=['request_key','supplier_id','destination_location_id','payment_term_id','currency_code','order_date','lines_json'];
    if(!document.querySelector('.notice-error'))try{const draft=JSON.parse(sessionStorage.getItem(storageKey));if(draft)for(const name of draftFields)if(typeof draft[name]==='string')form.elements[name].value=draft[name];}catch{}
    supplier.dispatchEvent(new Event('change',{bubbles:true}));
    let lines=[];try{lines=JSON.parse(field.value).filter(l=>products.has(String(l.product_id)));}catch{}
    let limit=20;
    const node=(tag,text,className)=>{const el=document.createElement(tag);if(text!==undefined)el.textContent=text;if(className)el.className=className;return el;};
    const money=n=>Number(n).toLocaleString('es-VE',{minimumFractionDigits:2,maximumFractionDigits:2});
    function price(product){
        const match=data.supplierPrices.find(p=>String(p.product_id)===String(product.id)&&String(p.supplier_id)===supplier.value);
        let value=Number(match?.price??product.price),from=product.currency_code,to=currency.value;
        const settings=data.settings,rate=Number(settings.exchange_rate);
        if(from!==to&&rate>0){if(from===settings.primary_code&&to===settings.alternate_code)value*=rate;else if(from===settings.alternate_code&&to===settings.primary_code)value/=rate;}
        return value.toFixed(2);
    }
    function persist(){
        field.value=JSON.stringify(lines);
        try{sessionStorage.setItem(storageKey,JSON.stringify(Object.fromEntries(draftFields.map(name=>[name,form.elements[name].value]))));}catch{}
        form.querySelector('[data-purchase-count]').textContent=String(lines.length);
        form.querySelector('[data-purchase-total]').textContent='Subtotal estimado: '+money(lines.reduce((sum,l)=>sum+Number(l.quantity||0)*Number(l.unit_price||0),0))+' '+currency.value;
    }
    function renderCatalog(){
        catalog.replaceChildren();const query=form.querySelector('[data-purchase-search]').value.trim().toLocaleLowerCase('es');
        const filtered=data.products.filter(p=>p.name.toLocaleLowerCase('es').includes(query));
        for(const p of filtered.slice(0,limit)){
            const tile=node('article',undefined,'purchase-product');tile.append(node('strong',p.name),node('small',money(price(p))+' '+currency.value));
            const add=node('button','Agregar','contact-button');add.type='button';add.addEventListener('click',()=>{
                const existing=lines.find(l=>String(l.product_id)===String(p.id));
                if(existing)existing.quantity=(Number(existing.quantity)+1).toFixed(2);
                else if(lines.length<100)lines.push({product_id:String(p.id),quantity:'1.00',unit_price:price(p),tax_id:p.tax_id?String(p.tax_id):'',tracking:[]});
                renderCart();
            });tile.append(add);catalog.append(tile);
        }
        form.querySelector('[data-purchase-more]').hidden=filtered.length<=limit;
        form.querySelector('[data-purchase-search-status]').textContent=filtered.length?Math.min(limit,filtered.length)+' de '+filtered.length+' productos':'No hay productos que coincidan.';
    }
    function input(labelText,value,attributes,onChange){
        const label=node('label',labelText),control=node('input');Object.assign(control,attributes);control.value=value;control.addEventListener('input',()=>{onChange(control.value);persist();});label.append(control);return label;
    }
    function renderCart(){
        cart.replaceChildren();
        for(const line of lines){
            const p=products.get(String(line.product_id)),row=node('article',undefined,'purchase-cart-line');row.append(node('h3',p.name));
            const grid=node('div',undefined,'contact-form-grid');
            grid.append(input('Cantidad',line.quantity,{type:'number',min:'0.01',step:'0.01',required:true,inputMode:'decimal'},v=>line.quantity=v));
            grid.append(input('Costo unitario ('+currency.value+')',line.unit_price,{type:'number',min:'0',step:'0.01',required:true,inputMode:'decimal'},v=>line.unit_price=v));
            row.append(grid);
            const taxLabel=node('label','Impuesto'),tax=node('select');tax.append(new Option('Sin impuesto',''));
            for(const t of data.taxes)tax.append(new Option(t.name+' · '+t.rate+'%',String(t.id)));
            tax.value=String(line.tax_id||'');tax.addEventListener('change',()=>{line.tax_id=tax.value;persist();});taxLabel.append(tax);row.append(taxLabel);
            if(p.tracking_mode==='lot'){
                const track=line.tracking[0]||{name:'',quantity:line.quantity,expiration_date:''};line.tracking=[track];
                row.append(input('Lote recibido *',track.name,{type:'text',required:true,maxLength:100},v=>track.name=v));
            }
            if(p.tracking_mode==='serial'){
                const label=node('label','Series recibidas · una por línea *'),area=node('textarea');area.rows=3;area.required=true;area.value=line.tracking.map(t=>t.name).join('\n');
                area.addEventListener('input',()=>{const expiry=line.tracking[0]?.expiration_date||'';line.tracking=area.value.split(/\r?\n/).map(s=>s.trim()).filter(Boolean).map(name=>({name,quantity:'1.00',expiration_date:expiry}));persist();});label.append(area);row.append(label);
            }
            if(p.tracking_mode!=='none'&&Number(p.expiration_enabled))row.append(input('Fecha de vencimiento',line.tracking[0]?.expiration_date||'',{type:'date'},v=>{if(!line.tracking.length)line.tracking=[{name:'',quantity:p.tracking_mode==='lot'?line.quantity:'1.00'}];for(const t of line.tracking)t.expiration_date=v;}));
            const remove=node('button','Quitar producto','contact-button');remove.type='button';remove.addEventListener('click',()=>{lines=lines.filter(l=>l!==line);renderCart();});row.append(remove);cart.append(row);
        }
        if(!lines.length)cart.append(node('p','Agrega productos para comenzar.'));
        persist();
    }
    form.querySelector('[data-purchase-search]').addEventListener('input',()=>{limit=20;renderCatalog();});
    form.querySelector('[data-purchase-more]').addEventListener('click',()=>{limit+=20;renderCatalog();});
    form.addEventListener('change',persist);
    supplier.addEventListener('change',renderCatalog);
    let previousCurrency=currency.value;
    currency.addEventListener('change',()=>{
        const rate=Number(data.settings.exchange_rate);
        if(rate>0&&previousCurrency!==currency.value)for(const line of lines){const value=Number(line.unit_price);line.unit_price=(previousCurrency===data.settings.primary_code?value*rate:value/rate).toFixed(2);}
        previousCurrency=currency.value;renderCatalog();renderCart();
    });
    form.addEventListener('submit',event=>{
        const error=form.querySelector('[data-purchase-error]');error.textContent='';
        if(!lines.length){event.preventDefault();error.textContent='Agrega al menos un producto.';return;}
        for(const line of lines)if(products.get(String(line.product_id)).tracking_mode==='lot'&&line.tracking[0])line.tracking[0].quantity=line.quantity;
        persist();
        form.querySelector('button[type=submit]').disabled=true;
    });
    renderCatalog();renderCart();
})();
