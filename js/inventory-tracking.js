(() => {
    'use strict';
    const parse = text => { try { const value=JSON.parse(text||'[]');return Array.isArray(value)?value:[]; } catch { return []; } };
    const catalog=parse(document.querySelector('[data-tracking-catalog]')?.textContent);
    let sequence=0;
    function editor(root, rows=[], product=null, readonly=false, onChange=()=>{}) {
        root.replaceChildren();
        const list=document.createElement('div');list.className='tracking-rows';
        const datalist=document.createElement('datalist');datalist.id='tracking-options-'+(++sequence);
        const choices=product?catalog.filter(lot=>Number(lot.product_id)===Number(product)):[];
        for(const lot of choices){const option=document.createElement('option');option.value=lot.name;option.label=lot.expiration_date?'Vence '+lot.expiration_date:'';datalist.append(option);}
        const read=()=>[...list.children].map(row=>({lot_id:Number(row.dataset.lotId)||null,name:row.querySelector('[data-lot-name]').value.trim(),quantity:row.querySelector('[data-lot-quantity]').value,expiration_date:row.querySelector('[data-lot-expiration]').value||null})).filter(row=>row.name);
        const changed=()=>onChange(read());
        function add(value={}) {
            const row=document.createElement('div');row.className='tracking-row';row.dataset.lotId=value.lot_id||'';
            for(const [key,label,type,initial] of [['name','Lote / serie','text',value.name||''],['quantity','Cantidad','text',value.quantity||'1'],['expiration','Vencimiento','date',value.expiration_date||'']]){
                const field=document.createElement('label');field.textContent=label;
                const input=document.createElement('input');input.type=type;input.value=initial;input.disabled=readonly;input.setAttribute('data-lot-'+key,'');
                if(key==='name'){input.maxLength=100;input.setAttribute('list',datalist.id);input.autocomplete='off';}
                if(key==='quantity')input.inputMode='decimal';
                input.addEventListener('input',()=>{
                    if(key==='name'){
                        const lot=choices.find(item=>item.name===input.value.trim());row.dataset.lotId=lot?.lot_id||'';
                        if(lot)row.querySelector('[data-lot-expiration]').value=lot.expiration_date||'';
                    }
                    changed();
                });field.append(input);row.append(field);
            }
            if(!readonly){const remove=document.createElement('button');remove.type='button';remove.className='contact-button contact-icon-button';remove.textContent='×';remove.setAttribute('aria-label','Quitar lote o serie');remove.addEventListener('click',()=>{row.remove();changed();});row.append(remove);}
            list.append(row);
        }
        for(const row of rows)add(row);
        root.append(list,datalist);
        if(!readonly){
            const actions=document.createElement('div');actions.className='document-controls tracking-editor-actions';
            const append=document.createElement('button');append.type='button';append.className='contact-button';append.textContent='+ Agregar línea';append.addEventListener('click',()=>{add();list.lastElementChild.querySelector('input').focus();});
            const generate=document.createElement('button');generate.type='button';generate.className='contact-button';generate.textContent='Generar identificador';generate.addEventListener('click',()=>{add({name:'ID-'+Date.now().toString(36).toUpperCase()+'-'+crypto.getRandomValues(new Uint32Array(1))[0].toString(36).toUpperCase(),quantity:'1'});changed();});
            actions.append(append,generate);root.append(actions);
            const details=document.createElement('details');details.className='workspace-details';const summary=document.createElement('summary');summary.textContent='Pegar varias series';
            const paste=document.createElement('textarea');paste.setAttribute('aria-label','Una serie por línea');paste.placeholder='Una serie por línea';
            const importButton=document.createElement('button');importButton.type='button';importButton.className='contact-button';importButton.textContent='Agregar series';
            importButton.addEventListener('click',()=>{const values=paste.value.split(/\r?\n/).map(value=>value.trim()).filter(Boolean);if(values.length+list.children.length>1000){paste.setCustomValidity('Máximo 1000 identificadores por línea.');paste.reportValidity();return;}paste.setCustomValidity('');for(const name of values)add({name,quantity:'1'});paste.value='';changed();});
            details.append(summary,paste,importButton);root.append(details);
        }
        return {read};
    }
    for(const form of document.querySelectorAll('[data-tracking-initial]')){
        const output=form.querySelector('[data-tracking-output]'),root=form.querySelector('[data-tracking-editor]');
        const instance=editor(root,parse(output.value),null,false,rows=>{output.value=JSON.stringify(rows);});
        form.addEventListener('submit',()=>{output.value=JSON.stringify(instance.read());});
    }
    const dialog=document.querySelector('[data-tracking-dialog]');if(!dialog)return;
    let active=null,instance=null;
    function summary(row){const field=row.querySelector('[data-line-tracking]'),label=row.querySelector('[data-tracking-summary]');if(!field||!label)return;const rows=parse(field.value);label.textContent=rows.length?rows.map(item=>item.name+' × '+item.quantity).join(', '):'Sin asignar';const mode=row.querySelector('[data-operation-product]')?.selectedOptions[0]?.dataset.trackingMode;if(mode==='none'){row.querySelector('[data-open-tracking]').hidden=true;label.textContent='Sin seguimiento';}else row.querySelector('[data-open-tracking]').hidden=false;}
    for(const row of document.querySelectorAll('[data-operation-line]'))summary(row);
    document.addEventListener('click',event=>{
        const button=event.target.closest('[data-open-tracking]');if(!button)return;event.preventDefault();
        active=button.closest('[data-operation-line]');const product=active.querySelector('[data-operation-product]');
        if(!product.value){product.focus();return;}
        const form=active.closest('form'),readonly=!!form?.querySelector('fieldset:disabled')||form?.dataset.permissionReadonly==='true';
        dialog.querySelector('[data-tracking-product]').textContent=product.selectedOptions[0]?.textContent||'';
        instance=editor(dialog.querySelector('[data-tracking-editor]'),parse(active.querySelector('[data-line-tracking]').value),product.value,readonly);
        dialog.querySelector('[data-apply-tracking]').hidden=readonly;dialog.showModal();
    });
    for(const button of dialog.querySelectorAll('[data-close-tracking]'))button.addEventListener('click',()=>dialog.close());
    dialog.querySelector('[data-apply-tracking]').addEventListener('click',()=>{
        if(!active||!instance)return;const input=active.querySelector('[data-line-tracking]');input.value=JSON.stringify(instance.read());summary(active);input.dispatchEvent(new Event('input',{bubbles:true}));dialog.close();
    });
    document.addEventListener('change',event=>{if(!event.target.matches('[data-operation-product]'))return;const row=event.target.closest('[data-operation-line]');row.querySelector('[data-line-tracking]').value='[]';summary(row);});
})();
