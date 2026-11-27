(() => {
    const form=document.querySelector('#whatsapp-documents-form'),confirmation=document.querySelector('[data-share-repeat-confirmation]');
    const prepare=document.querySelector('[data-share-prepare]'),chat=document.querySelector('[data-whatsapp-chat]'),copyMessage=document.querySelector('[data-copy-message]'),cancel=document.querySelector('[data-dismiss-share-wizard]');
    const preview=document.querySelector('[data-share-message]'),error=document.querySelector('[data-share-error]'),status=document.querySelector('[data-copy-status]');
    let prepared=null,busy=false,originalPreview=preview?.textContent||'';const canPrepare=prepare&&!prepare.disabled;
    const editableInputs=[...(form?.querySelectorAll('input:not([type=hidden]),select,textarea')||[])].map(input=>[input,input.disabled]);
    const lockEditor=locked=>{for(const [input,disabled] of editableInputs)input.disabled=locked||disabled;};
    const sentLabel=data=>(data.marked_sent_at?'Enviado · '+(data.marked_sent_action==='copy'?'Copiado':'Abierto en WhatsApp')+' · '+data.marked_sent_at:'Mensaje preparado')+(data.message_only?'':' · Enlace PDF vigente · Vence '+data.expires_at);
    const updateSentState=data=>{
        const label=document.querySelector('[data-share-history-id="'+Number(data.share_id)+'"] [data-share-state]');if(label)label.textContent=sentLabel(data);
        const note=document.querySelector('[data-share-dispatch-note]');if(note&&prepared?.share_id===data.share_id)note.textContent=data.marked_sent_at?'Enviado · '+(data.marked_sent_action==='copy'?'mensaje copiado':'WhatsApp abierto'):'Mensaje preparado. Puedes abrir WhatsApp o copiar el texto.';
    };
    const markSent=async(snapshot,interaction)=>{
        if(!snapshot||snapshot.marked_sent_at)return;
        const body=new FormData(form);body.set('action','mark_whatsapp_sent');body.set('share_id',snapshot.share_id);body.set('interaction',interaction);
        try{const response=await fetch(form.getAttribute('action')||location.href,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json'},body,keepalive:true});const result=await response.json();if(!response.ok||!result.ok)throw Error('Mark failed');Object.assign(snapshot,result);updateSentState(snapshot);}
        catch{if(status&&prepared?.share_id===snapshot.share_id)status.textContent='No se pudo guardar el estado Enviado. Puedes volver a intentarlo.';}
    };
    chat?.addEventListener('click',()=>{void markSent(prepared,'open');});
    const setInitial=(focus=true)=>{
        prepared=null;lockEditor(false);if(prepare)prepare.hidden=false;if(chat){chat.hidden=true;chat.removeAttribute('href');}if(copyMessage)copyMessage.hidden=true;if(status)status.textContent='';const note=document.querySelector('[data-share-dispatch-note]');if(note)note.hidden=true;
        if(preview)preview.textContent=originalPreview;if(form){delete form.dataset.ready;history.replaceState(null,'',form.dataset.prepareUrl);}if(focus)prepare?.focus();
    };
    const setReady=data=>{
        if(!data.urls?.whatsapp?.startsWith('https://wa.me/')||typeof data.urls.message!=='string')throw Error('Invalid preparation result');
        prepared=data;lockEditor(true);prepare.hidden=true;chat.href=data.urls.whatsapp;chat.hidden=false;copyMessage.hidden=false;preview.textContent=data.urls.message;form.dataset.ready='1';const note=document.querySelector('[data-share-dispatch-note]');if(note)note.hidden=false;
        form.elements.previous_share_id.value=data.previous_share_id;form.elements.request_key.value=data.next_request_key;
        const count=document.querySelector('[data-share-history-count]');if(count)count.textContent=data.history_count;
        const historyPanel=document.querySelector('.email-history');
        if(historyPanel&&!historyPanel.querySelector('[data-share-history-id="'+Number(data.share_id)+'"]')){
            const article=document.createElement('article');article.className='email-log';article.dataset.shareHistoryId=data.share_id;
            const phone=document.createElement('strong');phone.textContent='+'+data.phone;const label=document.createElement('p');label.dataset.shareState='';label.textContent=sentLabel(data);
            const link=document.createElement('a');link.className='contact-button';link.href=data.view_url;link.textContent='Ver mensaje';article.append(phone,label,link);historyPanel.querySelector('summary').after(article);
        }
        updateSentState(data);history.replaceState(null,'',data.view_url);chat.focus();
    };
    cancel?.addEventListener('click',event=>{
        if(prepared){event.preventDefault();setInitial();return;}
        if(cancel.tagName==='BUTTON')window.frameElement?.closest('[data-document-share-dialog]')?.close();
    });
    if(form){
        form.addEventListener('submit',async event=>{
            event.preventDefault();if(busy||prepared)return;
            if(Number(form.elements.previous_share_id.value)>0&&event.submitter?.name!=='confirm_share'){if(!confirmation.open)confirmation.showModal();return;}
            const body=new FormData(form);if(event.submitter?.name)body.append(event.submitter.name,event.submitter.value);
            originalPreview=preview?.textContent||'';
            confirmation.close();busy=true;error.hidden=true;error.textContent='';prepare.disabled=true;prepare.setAttribute('aria-busy','true');cancel.setAttribute('aria-disabled','true');if(cancel.tagName==='BUTTON')cancel.disabled=true;
            lockEditor(true);
            try{
                const response=await fetch(form.getAttribute('action')||location.href,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json'},body});
                if(!response.headers.get('content-type')?.includes('application/json'))throw Error('Unexpected response');const data=await response.json();
                if(response.ok&&data.ok)setReady(data);
                else if(data.confirmation_required){form.elements.previous_share_id.value=data.previous_share_id;confirmation.showModal();}
                else{error.textContent=data.message||'No se pudo preparar el mensaje. Revisa los datos e inténtalo nuevamente.';error.hidden=false;}
            }catch{error.textContent='No se pudo confirmar la preparación del mensaje. Puedes reintentar; la misma solicitud no creará un duplicado.';error.hidden=false;}
            finally{busy=false;lockEditor(Boolean(prepared));prepare.disabled=!canPrepare;prepare.removeAttribute('aria-busy');cancel.removeAttribute('aria-disabled');if(cancel.tagName==='BUTTON')cancel.disabled=false;}
        });
        confirmation.querySelector('[data-share-repeat-no]').addEventListener('click',()=>confirmation.close());
        confirmation.addEventListener('close',()=>{if(!busy)prepare.focus();});
        if(confirmation.hasAttribute('data-confirmation-required'))confirmation.showModal();
        const initial=document.querySelector('[data-share-initial]');if(initial)try{setReady(JSON.parse(initial.textContent));}catch{setInitial();}
    }
    const trigger=document.querySelector('[data-open-document-share]'),dialog=document.querySelector('[data-document-share-dialog]');
    if(trigger&&dialog){
        const frame=dialog.querySelector('iframe');let overflow='';
        trigger.addEventListener('click',event=>{if(!dialog.showModal)return;event.preventDefault();const url=new URL(trigger.href,location.href);url.searchParams.set('embedded','1');frame.src=url.href;overflow=document.body.style.overflow;dialog.showModal();document.body.style.overflow='hidden';});
        dialog.querySelector('[data-close-document-share]').addEventListener('click',()=>dialog.close());
        dialog.addEventListener('close',()=>{document.body.style.overflow=overflow;frame.src='about:blank';trigger.focus();});
    }
    const copy=async(value,label)=>{const status=document.querySelector('[data-copy-status]');let temporary;const focused=document.activeElement;
        try{if(navigator.clipboard&&window.isSecureContext)await navigator.clipboard.writeText(value);else{temporary=document.createElement('textarea');temporary.value=value;temporary.style.cssText='position:fixed;top:0;left:0;opacity:0';document.body.append(temporary);temporary.select();if(!document.execCommand('copy'))throw new Error('copy');}status.textContent=label;return true;}catch{status.textContent='No se pudo copiar automáticamente. Selecciona y copia el texto.';return false;}finally{temporary?.remove();focused?.focus();}
    };
    document.querySelector('[data-copy-share]')?.addEventListener('click',()=>copy(document.querySelector('[data-share-url]').value,'Enlace copiado.'));
    document.querySelector('[data-copy-message]')?.addEventListener('click',async()=>{const snapshot=prepared;if(await copy(snapshot?.urls.message||document.querySelector('[data-share-message]').textContent,'Mensaje copiado.'))await markSent(snapshot,'copy');});
})();
