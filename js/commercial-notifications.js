(() => {
    'use strict';
    const root=document.querySelector('[data-message-notifications]');if(!root)return;
    const toggle=root.querySelector('summary'),count=root.querySelector('[data-notification-count]'),summary=root.querySelector('[data-notification-summary]'),list=root.querySelector('[data-notification-items]'),userMenu=document.querySelector('.workspace-user-menu');
    let busy=false,stopped=false,last='';
    const refresh=async()=>{
        if(busy||stopped||document.hidden)return;busy=true;
        try{
            const response=await fetch(root.dataset.url,{credentials:'same-origin',headers:{Accept:'application/json'}});
            if(response.status===401){stopped=true;count.hidden=true;list.replaceChildren();throw Error('Inicia sesión de nuevo para consultar los mensajes.');}
            if(!response.ok)throw Error('No pudimos actualizar los mensajes.');
            const data=await response.json();count.hidden=data.total===0;count.textContent=data.total>99?'99+':String(data.total);
            toggle.setAttribute('aria-label',data.total?data.total+' mensajes sin leer':'Notificaciones: no hay mensajes sin leer');
            summary.textContent=data.total===0?'Todo al día':data.total+' sin leer';
            const signature=JSON.stringify(data.items);if(signature!==last){
                last=signature;const fragment=document.createDocumentFragment();
                for(const item of data.items){
                    const link=document.createElement('a'),name=document.createElement('strong'),reference=document.createElement('span'),preview=document.createElement('p'),badge=document.createElement('span');
                    const url=new URL(root.dataset.destination,location.href);url.searchParams.set('request',item.request_id);url.searchParams.set('tab','seguimiento');link.href=url.href;link.className='notification-item';
                    name.textContent=item.name;reference.textContent='Solicitud #'+item.request_id;preview.textContent=item.preview;badge.textContent=item.count;badge.className='notification-item-count';
                    link.append(name,reference,preview,badge);fragment.append(link);
                }
                if(!data.items.length){const empty=document.createElement('p');empty.className='notification-empty';empty.textContent='No tienes mensajes pendientes de leer.';fragment.append(empty);}
                list.replaceChildren(fragment);
            }
        }catch(e){summary.textContent='Sin actualizar';if(!list.querySelector('a')){const error=document.createElement('p');error.className='notification-empty';error.textContent=e.message;list.replaceChildren(error);}}
        finally{busy=false;}
    };
    root.addEventListener('toggle',()=>{if(root.open){if(userMenu)userMenu.open=false;refresh();}});
    userMenu?.addEventListener('toggle',()=>{if(userMenu.open)root.open=false;});
    document.addEventListener('click',event=>{if(!root.contains(event.target))root.open=false;});
    document.addEventListener('keydown',event=>{if(event.key==='Escape'&&root.open){root.open=false;toggle.focus();}});
    document.addEventListener('commercial-chat-read',refresh);
    document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
    setInterval(refresh,10000);refresh();
})();
