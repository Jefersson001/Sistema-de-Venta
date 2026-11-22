(() => {
    const form=document.querySelector('#email-documents-form'),confirmation=document.querySelector('[data-email-resend-confirmation]');
    if(form){
        let submitting=false;
        form.addEventListener('submit',event=>{
            if(submitting){event.preventDefault();return;}
            if(confirmation&&event.submitter?.name!=='confirm_resend'){
                event.preventDefault();if(confirmation.showModal){if(!confirmation.open)confirmation.showModal();}else confirmation.setAttribute('open','');return;
            }
            submitting=true;
            const no=confirmation?.querySelector('[data-email-resend-no]');if(no)no.disabled=true;
            // Keep submitter values in the request; do not disable form controls.
            for(const button of document.querySelectorAll('[form="email-documents-form"][type=submit]'))button.setAttribute('aria-busy','true');
        });
        confirmation?.querySelector('[data-email-resend-no]')?.addEventListener('click',()=>confirmation.close());
        confirmation?.addEventListener('close',()=>document.querySelector('.email-wizard-footer [type=submit]')?.focus());
        if(confirmation?.hasAttribute('data-confirmation-required')){if(confirmation.showModal)confirmation.showModal();else confirmation.setAttribute('open','');}
    }
    document.querySelector('[data-dismiss-email-wizard]')?.addEventListener('click',()=>{
        window.frameElement?.closest('[data-invoice-email-dialog]')?.close();
    });
    const trigger=document.querySelector('[data-open-invoice-email]'),dialog=document.querySelector('[data-invoice-email-dialog]');if(!trigger||!dialog)return;
    const frame=dialog.querySelector('iframe');let originalOverflow='';
    trigger.addEventListener('click',event=>{if(!dialog.showModal)return;event.preventDefault();const url=new URL(trigger.href,location.href);url.searchParams.set('embedded','1');frame.src=url.href;originalOverflow=document.body.style.overflow;dialog.showModal();document.body.style.overflow='hidden';});
    dialog.querySelector('[data-close-invoice-email]').addEventListener('click',()=>dialog.close());
    dialog.addEventListener('close',()=>{document.body.style.overflow=originalOverflow;frame.src='about:blank';trigger.focus();});
})();
