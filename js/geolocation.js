(() => {
    'use strict';
    const states=new WeakMap();
    const pair=panel=>[panel.querySelector('[data-geo-latitude]'),panel.querySelector('[data-geo-longitude]')];
    function state(panel){if(!states.has(panel))states.set(panel,{generation:0,pending:false});return states.get(panel);}
    function unavailable(panel){return pair(panel).some(field=>field.readOnly||field.matches(':disabled'));}
    function update(panel){
        const [lat,lon]=pair(panel),a=lat.value.trim().replace(',','.'),b=lon.value.trim().replace(',','.');
        const valid=value=>/^-?\d{1,3}(?:\.\d{1,6})?$/.test(value);
        lat.setCustomValidity('');lon.setCustomValidity('');
        if((a==='')!==(b===''))(a===''?lat:lon).setCustomValidity('Completa ambas coordenadas o déjalas vacías.');
        if(a!==''&&(!valid(a)||Number(a)<-90||Number(a)>90))lat.setCustomValidity('Indica una latitud entre -90 y 90, con hasta seis decimales.');
        if(b!==''&&(!valid(b)||Number(b)<-180||Number(b)>180))lon.setCustomValidity('Indica una longitud entre -180 y 180, con hasta seis decimales.');
        const map=panel.querySelector('[data-geo-map]'),ready=a!==''&&b!==''&&lat.validity.valid&&lon.validity.valid;
        map.hidden=!ready;
        if(ready){const url=new URL('https://www.openstreetmap.org/');url.search=new URLSearchParams({mlat:a,mlon:b});url.hash='map=17/'+a+'/'+b;map.href=url.href;}
        else map.removeAttribute('href');
    }
    function mark(panel,source){const field=panel.querySelector('[data-geo-source]');if(field)field.value=source;}
    function notify(panel){const [lat]=pair(panel);lat.dispatchEvent(new Event('change',{bubbles:true}));}
    function init(root){
        const panels=[...(root.querySelectorAll?.('[data-geolocation]')||[])];if(root.matches?.('[data-geolocation]'))panels.push(root);
        for(const panel of panels){state(panel);update(panel);}
    }
    document.addEventListener('input',event=>{
        if(!event.target.matches('[data-geo-latitude],[data-geo-longitude]'))return;
        const panel=event.target.closest('[data-geolocation]');state(panel).generation++;state(panel).pending=false;mark(panel,'manual');update(panel);
    });
    document.addEventListener('click',event=>{
        const button=event.target.closest('[data-geo-capture],[data-geo-clear]');if(!button)return;
        const panel=button.closest('[data-geolocation]');if(unavailable(panel))return;
        const current=state(panel),status=panel.querySelector('[data-geo-status]');current.generation++;current.pending=false;
        if(button.hasAttribute('data-geo-clear')){for(const field of pair(panel))field.value='';mark(panel,'manual');update(panel);status.textContent='Ubicación borrada. Guarda para conservar el cambio.';notify(panel);return;}
        if(!window.isSecureContext){status.textContent='La captura requiere HTTPS o localhost. También puedes escribir las coordenadas.';return;}
        if(!navigator.geolocation){status.textContent='El navegador no permite capturar la ubicación. Puedes escribir las coordenadas.';return;}
        const generation=current.generation,form=panel.closest('form'),context=()=>panel.dataset.geolocation==='customer'?((form?.querySelector('[name=customer_id]')?.value||'')+':'+(form?.querySelector('[data-order-address=delivery]')?.value||'')):'';
        const requestedContext=context();current.pending=true;status.textContent='Obteniendo ubicación…';
        navigator.geolocation.getCurrentPosition(position=>{
            if(!panel.isConnected||generation!==current.generation)return;current.pending=false;
            if(unavailable(panel)||context()!==requestedContext){status.textContent='Cambió la dirección seleccionada. Captura de nuevo la ubicación.';return;}
            const {latitude,longitude,accuracy}=position.coords;
            if(!Number.isFinite(latitude)||!Number.isFinite(longitude)||Math.abs(latitude)>90||Math.abs(longitude)>180){status.textContent='El dispositivo devolvió una ubicación inválida.';return;}
            const [lat,lon]=pair(panel);lat.value=latitude.toFixed(6);lon.value=longitude.toFixed(6);mark(panel,'device');update(panel);
            status.textContent='Ubicación obtenida'+(Number.isFinite(accuracy)?' (precisión estimada: '+Math.round(accuracy)+' m)':'')+'. Guarda para conservarla.';notify(panel);
        },error=>{
            if(generation!==current.generation)return;current.pending=false;
            status.textContent=error.code===1?'No se autorizó la ubicación. Puedes continuar sin ella o escribir las coordenadas.':error.code===3?'Se agotó el tiempo de captura. Intenta de nuevo o escribe las coordenadas.':'No se pudo obtener la ubicación. Puedes continuar sin ella o escribir las coordenadas.';
        },{enableHighAccuracy:true,timeout:15000,maximumAge:0});
    });
    document.addEventListener('order:delivery-address',event=>{
        const panel=event.target.querySelector('[data-geolocation=customer]');if(!panel)return;
        const current=state(panel);current.generation++;current.pending=false;
        const [lat,lon]=pair(panel),row=event.detail;lat.value=row?.latitude??'';lon.value=row?.longitude??'';mark(panel,'address');update(panel);
        panel.querySelector('[data-geo-status]').textContent='';
    });
    document.addEventListener('submit',event=>{
        for(const panel of event.target.querySelectorAll('[data-geolocation]'))if(state(panel).pending){event.preventDefault();panel.querySelector('[data-geo-status]').textContent='Espera a que termine la captura antes de guardar.';}
    },true);
    init(document);
    new MutationObserver(records=>{for(const record of records)for(const node of record.addedNodes)if(node.nodeType===1)init(node);}).observe(document.body,{childList:true,subtree:true});
})();
