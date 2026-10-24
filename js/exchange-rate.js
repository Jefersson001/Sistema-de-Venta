for(const badge of document.querySelectorAll('[data-daily-rate]')){
    const scope=window.nexoCompanyScope;let pending=false;
    const refresh=async()=>{
        if(pending||document.hidden)return;pending=true;
        try{const response=await fetch(badge.dataset.rateEndpoint,{cache:'no-store',signal:AbortSignal.timeout(10000),headers:{Accept:'application/json'}});
            if(!response.ok)throw new Error('No se pudo actualizar la tasa.');const data=await response.json();
            if(scope&&data.scope!==scope){window.location.reload();return;}
            const parts=data.label.split(' · ');
            badge.querySelector('[data-daily-rate-label]').textContent=data.available?(parts.slice(1).join(' · ')||data.label):'Sin tasa disponible';
            const date=(data.rate_date||'').replace(/^(\d{4})-(\d{2})-(\d{2})$/,'$3/$2/$1');
            badge.querySelector('[data-daily-rate-date]').textContent=(data.available?(parts[0]+(date?' · '+date:'')):'Registra una tasa en Monedas')+(data.sync_failed?' · Consulta pendiente':'');badge.title=data.title;
            const source=badge.querySelector('[data-daily-rate-source]');
            if(source){source.textContent=data.source||'';source.hidden=!data.available;}
            badge.classList.toggle('connection-online',data.available);badge.classList.toggle('connection-offline',!data.available);
        }catch{badge.title='No se pudo actualizar la consulta. Se muestra la última tasa consultada.';}finally{pending=false;}
    };
    const timer=setInterval(refresh,60000);document.addEventListener('visibilitychange',refresh);window.addEventListener('pagehide',()=>clearInterval(timer));
}
