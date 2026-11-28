(() => {
    'use strict';
    const context=document.querySelector('[data-chat-context]');
    const draftPrefix=context?'konta:chat-draft:'+context.dataset.base+':'+context.dataset.side+':':'';
    const draftNamespace=draftPrefix+(context?.dataset.scope||'')+':';
    let draftEnabled=true;
    const storage={
        get:key=>{try{return localStorage.getItem(key);}catch{return null;}},
        set:(key,value)=>{try{localStorage.setItem(key,value);}catch{}},
        remove:key=>{try{localStorage.removeItem(key);}catch{}},
        clearRole:()=>{if(!draftPrefix)return;try{Object.keys(localStorage).filter(key=>key.startsWith(draftPrefix)).forEach(key=>localStorage.removeItem(key));}catch{}}
    };
    if(context){
        // Remove expired session namespaces, including drafts from prior accounts.
        try{Object.keys(localStorage).filter(key=>key.startsWith(draftPrefix)&&(!context.dataset.scope||!key.startsWith(draftNamespace))).forEach(key=>localStorage.removeItem(key));}catch{}
        document.addEventListener('submit',event=>{
            if(event.target.querySelector('input[name="action"][value="logout"]')){draftEnabled=false;storage.clearRole();document.dispatchEvent(new Event('commercial-chat-logout'));}
        },true);
        window.addEventListener('pageshow',event=>{if(event.persisted)location.reload();});
    }
    const root=document.querySelector('[data-demo-chat]');if(!root)return;
    const feed=root.querySelector('[data-chat-feed]'),text=root.querySelector('[data-chat-text]'),send=root.querySelector('[data-chat-send]'),older=root.querySelector('[data-chat-older]'),error=root.querySelector('[data-chat-error]'),connection=root.querySelector('[data-chat-connection]');
    const sendLabel=send.querySelector('[data-chat-send-label]'),counter=root.querySelector('[data-chat-count]');
    const resizeComposer=()=>{text.style.height='44px';text.style.height=Math.min(112,Math.max(44,text.scrollHeight))+'px';counter.textContent=text.value.length+' / 2000';};
    const newButton=root.querySelector('[data-chat-new]');
    const draftKey=draftNamespace+root.dataset.actor+':'+new URL(root.dataset.url,location.href).searchParams.get('request');
    const rememberDraft=()=>{
        if(!draftEnabled||!context?.dataset.scope)return;
        if(text.value)storage.set(draftKey,JSON.stringify({body:text.value,pending}));else storage.remove(draftKey);
    };
    let newest=0,oldest=0,busy=false,sending=false,stopped=false,loaded=false,lastRead=0,pending=null,newCount=0;
    try{const saved=JSON.parse(storage.get(draftKey)||'null');if(saved&&typeof saved.body==='string'&&saved.body.length<=2000){text.value=saved.body;if(saved.pending&&saved.pending.body===text.value.trim()&&/^[a-f0-9]{32}$/.test(saved.pending.key))pending=saved.pending;}}catch{}
    text.addEventListener('input',()=>{resizeComposer();rememberDraft();});
    window.addEventListener('pagehide',rememberDraft);
    window.addEventListener('storage',event=>{if(event.key===draftKey&&event.newValue===null){text.value='';pending=null;resizeComposer();}});
    document.addEventListener('commercial-chat-logout',()=>{text.value='';pending=null;resizeComposer();});
    const ids=new Set();
    const visible=()=>!document.hidden&&!root.closest('[data-request-panel]').hidden;
    const fail=message=>{error.textContent=message;error.hidden=false;};
    const api=async(params={},post=false)=>{
        const url=new URL(root.dataset.url,location.href),options={credentials:'same-origin',headers:{Accept:'application/json'}};
        if(post){options.method='POST';options.body=new URLSearchParams({...params,csrf:root.dataset.csrf});}
        else Object.entries(params).forEach(([k,v])=>url.searchParams.set(k,String(v)));
        const response=await fetch(url,options);let result;try{result=await response.json();}catch{throw Error('No se pudo conectar. Inténtalo nuevamente.');}
        if(!response.ok){if(response.status===401||response.status===403){stopped=true;send.disabled=true;if(response.status===401){draftEnabled=false;storage.clearRole();text.value='';pending=null;resizeComposer();}}throw Error(result.error||'No se pudo completar la operación.');}return result;
    };
    const nearEnd=()=>feed.scrollHeight-feed.scrollTop-feed.clientHeight<90;
    const clearNew=()=>{newCount=0;newButton.hidden=true;};
    const anchor=()=>{
        const top=feed.getBoundingClientRect().top;
        const item=[...feed.querySelectorAll('[data-message-id]')].find(item=>item.getBoundingClientRect().bottom>top);
        return item?{item,top:item.getBoundingClientRect().top}:null;
    };
    const restoreAnchor=saved=>{if(saved)feed.scrollTop+=saved.item.getBoundingClientRect().top-saved.top;};
    const venezuelaDay=date=>new Intl.DateTimeFormat('en-CA',{timeZone:'America/Caracas',year:'numeric',month:'2-digit',day:'2-digit'}).format(date);
    const dates=()=>{
        const today=venezuelaDay(new Date()),yesterdayDate=new Date(today+'T12:00:00-04:00');yesterdayDate.setUTCDate(yesterdayDate.getUTCDate()-1);
        const yesterday=venezuelaDay(yesterdayDate),used=new Set();let previous='';
        feed.querySelectorAll('[data-message-id]').forEach(item=>{
            const day=item.dataset.day;if(day===previous)return;previous=day;
            let separator=item.previousElementSibling;
            if(!separator?.classList.contains('chat-day')||separator.dataset.date!==day){separator=document.createElement('div');separator.className='chat-day';separator.dataset.date=day;feed.insertBefore(separator,item);}
            used.add(separator);
            const label=day===today?'Hoy':day===yesterday?'Ayer':new Date(day+'T12:00:00-04:00').toLocaleDateString('es-VE',{timeZone:'America/Caracas',day:'numeric',month:'long',year:'numeric'});
            if(separator.textContent!==label)separator.textContent=label;
        });
        feed.querySelectorAll('.chat-day').forEach(item=>{if(!used.has(item))item.remove();});
    };
    const add=rows=>{
        const fragment=document.createDocumentFragment();let count=0;
        for(const m of rows){
            if(ids.has(m.id))continue;ids.add(m.id);count++;newest=Math.max(newest,m.id);oldest=oldest?Math.min(oldest,m.id):m.id;
            const item=document.createElement('article');item.dataset.messageId=m.id;item.dataset.day=m.created_at.slice(0,10);
            const mine=m.sender_type===root.dataset.side&&m.sender_id===Number(root.dataset.actor);
            item.className='chat-message '+(m.sender_type==='system'?'chat-system':mine?'chat-mine':'chat-other');
            const header=document.createElement('header'),name=document.createElement('strong'),time=document.createElement('time'),body=document.createElement('p');
            name.textContent=m.sender_type==='system'?'Actividad de la solicitud':mine?'Tú':m.sender_name;
            name.dataset.initial=m.sender_name.trim().slice(0,1).toLocaleUpperCase('es');
            time.dateTime=m.created_at.replace(' ','T')+'-04:00';time.textContent=new Date(time.dateTime).toLocaleTimeString('es-VE',{timeZone:'America/Caracas',hour:'2-digit',minute:'2-digit'});
            time.title=new Date(time.dateTime).toLocaleString('es-VE',{timeZone:'America/Caracas'});
            const states={new:'Solicitud enviada',approved:'Demo aprobada',rejected:'Solicitud rechazada',contacted:'Contactado',scheduled:'Demo coordinada',closed:'Cerrada'};
            body.textContent=m.sender_type==='system'?m.body.replace(/^(new|approved|rejected|contacted|scheduled|closed)(?=:|$)/,key=>states[key]):m.body;
            header.append(name,time);item.append(header,body);fragment.append(item);
        }return {fragment,count};
    };
    const markRead=async()=>{
        if(!visible()||!newest||newest<=lastRead||feed.scrollHeight-feed.scrollTop-feed.clientHeight>90)return;
        const seen=newest;await api({action:'read',last_id:seen},true);lastRead=seen;
        document.querySelectorAll('[data-conversation-unread]').forEach(b=>b.hidden=true);
        document.dispatchEvent(new Event('commercial-chat-read'));
    };
    const refresh=async()=>{
        if(busy||stopped||!visible())return;busy=true;
        try{
            const data=await api(newest?{after:newest}:{}),nearBottom=nearEnd(),saved=anchor(),batch=add(data.messages);
            if(!loaded){feed.replaceChildren();older.hidden=!data.more;}
            if(batch.count)feed.querySelector('.chat-empty')?.remove();
            feed.append(batch.fragment);dates();
            if(!ids.size){const empty=document.createElement('p');empty.className='chat-empty';empty.textContent='Todavía no hay mensajes. Puedes iniciar la conversación.';feed.append(empty);}
            if(!loaded||nearBottom){feed.scrollTop=feed.scrollHeight;clearNew();}
            else{restoreAnchor(saved);if(batch.count){newCount+=batch.count;newButton.textContent=newCount===1?'1 mensaje nuevo ↓':newCount+' mensajes nuevos ↓';newButton.hidden=false;}}
            loaded=true;connection.textContent='Actualización automática';error.hidden=true;
            // If the catch-up contains more pages, do not mark unseen pages as read.
            await markRead();
        }catch(e){connection.textContent='Sin conexión';fail(e.message);}finally{busy=false;}
    };
    send.addEventListener('click',async()=>{
        if(sending||stopped)return;
        const body=text.value.trim();if(!body){fail('Escribe un mensaje antes de enviar.');text.focus();return;}
        if(!pending||pending.body!==body)pending={body,key:crypto.randomUUID().replaceAll('-','')};rememberDraft();
        sending=true;send.disabled=true;sendLabel.textContent='Enviando…';send.setAttribute('aria-label','Enviando mensaje');text.readOnly=true;
        try{await api({action:'send',message:pending.body,client_key:pending.key},true);text.value='';resizeComposer();pending=null;storage.remove(draftKey);error.hidden=true;await refresh();feed.scrollTop=feed.scrollHeight;clearNew();}
        catch(e){fail(e.message);}finally{sending=false;send.disabled=stopped;sendLabel.textContent='Enviar';send.setAttribute('aria-label','Enviar mensaje');text.readOnly=false;}
    });
    older.addEventListener('click',async()=>{
        if(busy||!oldest)return;busy=true;older.disabled=true;
        try{const data=await api({before:oldest}),saved=anchor(),batch=add(data.messages);feed.prepend(batch.fragment);dates();restoreAnchor(saved);older.hidden=!data.more;}
        catch(e){fail(e.message);}finally{busy=false;older.disabled=false;}
    });
    newButton.addEventListener('click',()=>{feed.scrollTop=feed.scrollHeight;clearNew();feed.focus({preventScroll:true});markRead().catch(e=>fail(e.message));});
    feed.addEventListener('scroll',()=>{if(nearEnd())clearNew();if(loaded&&!busy)markRead().catch(e=>fail(e.message));});
    text.addEventListener('keydown',event=>{if((event.ctrlKey||event.metaKey)&&event.key==='Enter'){event.preventDefault();send.click();}});
    document.addEventListener('click',event=>{if(event.target.closest('[data-request-tab]'))setTimeout(refresh,0);});
    document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
    setInterval(refresh,6000);refresh();
    if(visible())resizeComposer();
    document.addEventListener('click',event=>{if(event.target.closest('[data-request-tab="seguimiento"]'))setTimeout(resizeComposer,0);});
})();
