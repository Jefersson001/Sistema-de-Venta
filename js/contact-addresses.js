(() => {
    const list=document.querySelector('[data-contact-address-lines]'),add=document.querySelector('[data-add-contact-address]'),template=document.querySelector('[data-contact-address-template]');
    if(!list||!add||!template||list.closest('form').dataset.permissionReadonly==='true')return;
    let index=Math.max(-1,...[...list.querySelectorAll('input[name$="[id]"]')].map(field=>Number(/addresses\[(\d+)\]/.exec(field.name)?.[1]??-1)))+1;
    const form=list.closest('form');
    function refresh(row){
        const active=row.querySelector('[data-address-active]').checked,usage=row.querySelector('[data-address-usage]').value;
        for(const radio of row.querySelectorAll('[data-address-default]')){
            radio.disabled=!active||(usage!=='both'&&usage!==radio.dataset.addressDefault);
            if(radio.disabled&&radio.checked)form.querySelector('[name="'+radio.name+'"][value="primary"]').checked=true;
        }
    }
    list.querySelectorAll('[data-contact-address-row]').forEach(refresh);
    add.addEventListener('click',()=>{
        const fragment=template.content.cloneNode(true);
        for(const element of fragment.querySelectorAll('[name], [value]'))for(const attribute of ['name','value'])if(element.hasAttribute(attribute))element.setAttribute(attribute,element.getAttribute(attribute).replaceAll('__INDEX__',String(index)));
        index++;list.append(fragment);const row=list.lastElementChild;
        document.dispatchEvent(new CustomEvent('contact-addresses:added',{detail:row}));refresh(row);row.querySelector('input:not([type=hidden])').focus();
    });
    list.addEventListener('change',event=>{const row=event.target.closest('[data-contact-address-row]');if(row)refresh(row);});
    list.addEventListener('click',event=>{
        if(!event.target.closest('[data-remove-contact-address]'))return;
        const row=event.target.closest('[data-contact-address-row]');
        for(const radio of row.querySelectorAll('[data-address-default]'))if(radio.checked)form.querySelector('[name="'+radio.name+'"][value="primary"]').checked=true;
        row.remove();form.dispatchEvent(new Event('change',{bubbles:true}));
    });
})();
