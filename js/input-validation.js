(() => {
    'use strict';
    const states=new WeakMap();
    let messageId=0;
    function policy(field) {
        if(!(field instanceof HTMLInputElement)||!['text','tel','number'].includes(field.type)||field.readOnly||field.disabled)return null;
        const key=(field.name||'').replace(/.*\[([^\]]+)\]$/,'$1');
        if(field.type==='tel'||['phone','mobile'].includes(key))return {kind:'phone',message:'Usa de 7 a 15 dígitos y, si corresponde, + al inicio, espacios, guiones o paréntesis.'};
        if(key==='tax_id'){
            const selector=field.form?.querySelector('[name="document_type_id"]');
            const kind=selector?(selector.selectedOptions[0]?.dataset.documentFormat||'digits'):'company-document';
            return {kind,message:kind==='digits'?'Este documento solo admite números.':kind==='tax-number'?'Usa números y un guion opcional antes del dígito final.':kind==='company-document'?'Usa los dígitos del documento, con su letra inicial si corresponde.':'Usa letras, números o guiones entre grupos.'};
        }
        if(field.type==='number'||['numeric','decimal'].includes(field.inputMode)){
            const coordinate=field.matches('[data-geo-latitude],[data-geo-longitude]')||/(?:^|_)(?:latitude|longitude)$/.test(key);
            const integer=!coordinate&&(field.inputMode==='numeric'||(field.type==='number'&&!field.step.includes('.')&&field.step!=='any'));
            const scale=coordinate?6:Number(field.dataset.decimalPlaces||2);
            return {kind:integer?'integer':'decimal',scale,signed:coordinate||(field.min!==''&&Number(field.min)<0),message:integer?'Este campo solo admite números enteros.':'Usa un número con hasta '+scale+' decimales, con coma o punto y sin separadores de miles.'};
        }
        return null;
    }
    function acceptable(value,p,complete=false) {
        if(value==='')return true;
        if(p.kind==='digits'||p.kind==='integer')return /^[0-9]+$/.test(value);
        if(p.kind==='tax-number')return (complete?/^[0-9]+(?:-[0-9])?$/:/^[0-9]*(?:-[0-9]?)?$/).test(value);
        if(p.kind==='alphanumeric')return (complete?/^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*$/:/^[A-Za-z0-9]*(?:-[A-Za-z0-9]*)*$/).test(value);
        if(p.kind==='company-document')return (complete?/^(?:[A-Za-z]-?)?[0-9]+(?:-[0-9])?$/:/^(?:[A-Za-z]-?)?[0-9]*(?:-[0-9]?)?$/).test(value);
        if(p.kind==='phone'){
            if(!/^\+?[0-9 ()-]*$/.test(value)||value.replace(/[^0-9]/g,'').length>15)return false;
            if(!complete)return true;
            const count=value.replace(/[^0-9]/g,'').length;if(count<7||count>15)return false;
            let balance=0;for(const c of value){if(c==='(')balance++;if(c===')')balance--;if(balance<0||balance>1)return false;}return balance===0;
        }
        const sign=p.signed?'-?':'';
        return new RegExp('^'+sign+(complete?'[0-9]+':'[0-9]*')+'(?:[.,][0-9]{'+(complete?'1':'0')+','+p.scale+'})?$').test(value);
    }
    function state(field) {
        if(!states.has(field))states.set(field,{previous:field.value,ownError:'',hint:null});
        return states.get(field);
    }
    function hint(field,message) {
        const current=state(field);
        if(!current.hint){
            current.hint=document.createElement('small');current.hint.id='input-format-'+(++messageId);current.hint.className='workspace-field-error';current.hint.setAttribute('role','status');
            (field.closest('.document-field .document-controls')||field).insertAdjacentElement('afterend',current.hint);
            field.setAttribute('aria-describedby',[...(field.getAttribute('aria-describedby')||'').split(/\s+/).filter(Boolean),current.hint.id].join(' '));
        }
        current.hint.textContent=message;current.hint.hidden=!message;
    }
    function validate(field,p) {
        const current=state(field),valid=acceptable(field.value,p,true);
        // Other validators retain ownership of their range/business-rule messages.
        if(field.validity.customError&&field.validationMessage!==current.ownError)return;
        current.ownError=valid?'':(p.kind==='phone'?'Indica un teléfono de 7 a 15 dígitos, con formato válido.':p.message);
        field.setCustomValidity(current.ownError);
        if(valid&&current.hint)hint(field,'');
    }
    function initialize(field) {
        const p=policy(field);if(!p)return;
        state(field);
        if(p.kind==='digits'||p.kind==='tax-number')field.inputMode='numeric';
        if(p.kind==='alphanumeric'||p.kind==='company-document')field.inputMode='text';
        if(p.kind==='phone')field.inputMode='tel';
    }
    function candidate(field,text) {
        if(field.selectionStart===null)return text;
        return field.value.slice(0,field.selectionStart)+text+field.value.slice(field.selectionEnd);
    }
    function prepareDocumentPaste(field,text) {
        if(field.name!=='tax_id')return text;
        const selector=field.form?.querySelector('[name="document_type_id"]');
        const prefix=selector?.selectedOptions[0]?.dataset.documentCode;
        if(prefix&&new RegExp('^'+prefix+'\\s*-','i').test(text))return text.replace(/^[A-Za-z]\s*-\s*/,'');
        return text;
    }
    document.addEventListener('focusin',event=>{const p=policy(event.target);if(!p)return;initialize(event.target);state(event.target).previous=event.target.value;});
    document.addEventListener('beforeinput',event=>{
        const field=event.target,p=policy(field);if(!p||event.isComposing||!event.inputType.startsWith('insert')||event.data===null)return;
        if(!acceptable(candidate(field,event.data),p)){event.preventDefault();hint(field,p.message);}
    });
    document.addEventListener('paste',event=>{
        const field=event.target,p=policy(field);if(!p||!event.clipboardData)return;
        const original=event.clipboardData.getData('text'),text=prepareDocumentPaste(field,original);
        const next=candidate(field,text);
        if(field.maxLength>=0&&next.length>field.maxLength){event.preventDefault();hint(field,'Este campo admite hasta '+field.maxLength+' caracteres.');return;}
        if(!acceptable(next,p)){event.preventDefault();hint(field,p.message);return;}
        if(text!==original&&field.selectionStart!==null){event.preventDefault();field.setRangeText(text,field.selectionStart,field.selectionEnd,'end');field.dispatchEvent(new Event('input',{bubbles:true}));}
    });
    document.addEventListener('keydown',event=>{
        if(event.key==='Enter'&&event.target.form)for(const field of event.target.form.querySelectorAll('input')){const p=policy(field);if(p)validate(field,p);}
        const p=policy(event.target);if(!p||event.ctrlKey||event.metaKey||event.altKey||event.key.length!==1||event.isComposing)return;
        if(!acceptable(candidate(event.target,event.key),p)){event.preventDefault();hint(event.target,p.message);}
    });
    document.addEventListener('input',event=>{
        const field=event.target,p=policy(field);if(!p)return;
        const current=state(field);
        if(!acceptable(field.value,p)){
            // Do not turn an invalid value such as 1e3 into the unrelated number 13.
            if(event.isTrusted&&acceptable(current.previous,p)){field.value=current.previous;hint(field,p.message);}
        }else{current.previous=field.value;if(current.hint)hint(field,'');}
        validate(field,p);
    },true);
    document.addEventListener('focusout',event=>{const p=policy(event.target);if(p){validate(event.target,p);if(event.target.validity.customError)hint(event.target,event.target.validationMessage);}});
    document.addEventListener('change',event=>{
        if(!(event.target instanceof Element))return;
        if(event.target.matches('[name="document_type_id"]')){
            const field=event.target.form?.querySelector('input[name="tax_id"]');if(field){initialize(field);const p=policy(field);if(p)validate(field,p);}
        }
        if(event.target.form)for(const field of event.target.form.querySelectorAll('input')){const p=policy(field);if(p)validate(field,p);}
    });
    document.addEventListener('click',event=>{
        const button=event.target.closest('button,input[type="submit"]');if(!button||button.type!=='submit'||!button.form)return;
        for(const field of button.form.querySelectorAll('input')){const p=policy(field);if(p)validate(field,p);}
    },true);
    document.addEventListener('submit',event=>{
        if(!(event.target instanceof HTMLFormElement))return;
        let invalid=null;
        for(const field of event.target.querySelectorAll('input')){const p=policy(field);if(!p)continue;validate(field,p);if(!field.validity.valid)invalid??=field;}
        if(invalid){event.preventDefault();invalid.reportValidity();}
    },true);
    for(const field of document.querySelectorAll('input'))initialize(field);
})();
