(() => {
    const trigger=document.querySelector('[data-open-document-share]'),dialog=document.querySelector('[data-document-share-dialog]');
    if(trigger&&dialog){
        const frame=dialog.querySelector('iframe');let overflow='';
        trigger.addEventListener('click',event=>{if(!dialog.showModal)return;event.preventDefault();const url=new URL(trigger.href,location.href);url.searchParams.set('embedded','1');frame.src=url.href;overflow=document.body.style.overflow;dialog.showModal();document.body.style.overflow='hidden';});
        dialog.querySelector('[data-close-document-share]').addEventListener('click',()=>dialog.close());
        dialog.addEventListener('close',()=>{document.body.style.overflow=overflow;frame.src='about:blank';trigger.focus();});
    }
    document.querySelector('[data-copy-share]')?.addEventListener('click',async()=>{
        const input=document.querySelector('[data-share-url]'),status=document.querySelector('[data-copy-status]');
        try{if(navigator.clipboard&&window.isSecureContext)await navigator.clipboard.writeText(input.value);else{input.select();if(!document.execCommand('copy'))throw new Error('copy');}status.textContent='Enlace copiado.';}catch{input.select();status.textContent='Selecciona y copia el enlace.';}
    });
})();
