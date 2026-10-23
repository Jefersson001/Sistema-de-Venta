for(const badge of document.querySelectorAll('[data-daily-rate]')){
    const scope=window.nexoCompanyScope;let pending=false;
    const refresh=async()=>{
        if(pending||document.hidden)return;pending=true;
        try{const response=await fetch(badge.dataset.rateEndpoint,{cache:'no-store',signal:AbortSignal.timeout(10000),headers:{Accept:'application/json'}});
            if(!response.ok)throw new Error('No se pudo actualizar la tasa.');const data=await response.json();
            if(scope&&data.scope!==scope){window.location.reload();return;}
            badge.querySelector('[data-daily-rate-label]').textContent=data.label;
            badge.querySelector('[data-daily-rate-date]').textContent=(data.rate_date||'')+' · '+data.source+(data.sync_failed?' · Consulta pendiente':'');badge.title=data.title;
            badge.classList.toggle('connection-online',data.available);badge.classList.toggle('connection-offline',!data.available);
        }catch{badge.title='No se pudo actualizar la consulta. Se muestra la última tasa consultada.';}finally{pending=false;}
    };
    const timer=setInterval(refresh,60000);document.addEventListener('visibilitychange',refresh);window.addEventListener('pagehide',()=>clearInterval(timer));
}
