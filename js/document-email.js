(() => {
    const trigger=document.querySelector('[data-open-invoice-email]'),dialog=document.querySelector('[data-invoice-email-dialog]');if(!trigger||!dialog)return;
    const frame=dialog.querySelector('iframe');let originalOverflow='';
    trigger.addEventListener('click',event=>{if(!dialog.showModal)return;event.preventDefault();const url=new URL(trigger.href,location.href);url.searchParams.set('embedded','1');frame.src=url.href;originalOverflow=document.body.style.overflow;dialog.showModal();document.body.style.overflow='hidden';});
    dialog.querySelector('[data-close-invoice-email]').addEventListener('click',()=>dialog.close());
    dialog.addEventListener('close',()=>{document.body.style.overflow=originalOverflow;frame.src='about:blank';trigger.focus();});
})();
