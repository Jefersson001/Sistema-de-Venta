(() => {
    'use strict';
    const states=new WeakMap();
    const observer='IntersectionObserver' in window?new IntersectionObserver(entries=>{
        for(const entry of entries){const current=states.get(entry.target);if(current){current.visible=entry.isIntersecting;if(current.visible)render(entry.target);}}
    },{rootMargin:'160px'}):null;
    function point(preview){
        const a=(preview.dataset.latitude||'').trim().replace(',','.'),b=(preview.dataset.longitude||'').trim().replace(',','.');
        if(!/^-?\d{1,3}(?:\.\d{1,6})?$/.test(a)||!/^-?\d{1,3}(?:\.\d{1,6})?$/.test(b))return null;
        const latitude=Number(a),longitude=Number(b);
        return Math.abs(latitude)<=90&&Math.abs(longitude)<=180?{latitude,longitude}:null;
    }
    function embedUrl(coordinates){
        const {latitude,longitude}=coordinates;
        // The embedded street map uses Web Mercator; retain the actual marker.
        const center=Math.max(-85.051128,Math.min(85.051128,latitude));
        const bounds=[Math.max(-180,longitude-.005),Math.max(-85.051128,center-.003),Math.min(180,longitude+.005),Math.min(85.051128,center+.003)];
        const url=new URL('https://www.openstreetmap.org/export/embed.html');
        url.search=new URLSearchParams({bbox:bounds.map(value=>value.toFixed(6)).join(','),layer:'mapnik',marker:latitude.toFixed(6)+','+longitude.toFixed(6)});
        return url.href;
    }
    function unload(preview){
        preview.querySelector('[data-geo-map-frame]')?.remove();
    }
    function render(preview){
        const current=states.get(preview);if(!current)return;
        clearTimeout(current.timer);
        const coordinates=point(preview);preview.hidden=!coordinates;
        const summary=preview.querySelector('summary'),caption=preview.open?'Mapa de la ubicación · ocultar':'Mostrar mapa de la ubicación';
        if(summary&&summary.textContent!==caption)summary.textContent=caption;
        if(!coordinates||!preview.open){unload(preview);return;}
        if(!current.visible||!preview.getClientRects().length)return;
        const canvas=preview.querySelector('[data-geo-map-canvas]');if(!canvas)return;
        const url=embedUrl(coordinates);let frame=canvas.querySelector('[data-geo-map-frame]');
        if(!frame){
            frame=document.createElement('iframe');frame.dataset.geoMapFrame='';frame.title=preview.dataset.mapTitle||'Mapa de la ubicación';
            frame.loading='lazy';frame.referrerPolicy='no-referrer';frame.setAttribute('allowfullscreen','');
            canvas.replaceChildren(frame);
        }
        if(frame.getAttribute('src')!==url)frame.src=url;
    }
    function init(root){
        const previews=[...(root.querySelectorAll?.('[data-geo-map-preview]')||[])];if(root.matches?.('[data-geo-map-preview]'))previews.push(root);
        for(const preview of previews){
            if(states.has(preview))continue;
            states.set(preview,{visible:!observer,timer:null});observer?.observe(preview);render(preview);
        }
    }
    document.addEventListener('geolocation:map-update',event=>{
        const preview=event.target;if(!preview.matches?.('[data-geo-map-preview]'))return;
        init(preview);const current=states.get(preview);clearTimeout(current.timer);
        if(!point(preview)){render(preview);return;}
        current.timer=setTimeout(()=>render(preview),300);
    });
    document.addEventListener('toggle',event=>{if(event.target.matches?.('[data-geo-map-preview]'))render(event.target);},true);
    init(document);
    new MutationObserver(records=>{
        for(const record of records)for(const node of record.addedNodes)if(node.nodeType===1)init(node);
        for(const record of records)for(const node of record.removedNodes)if(node.nodeType===1){
            const previews=[...(node.querySelectorAll?.('[data-geo-map-preview]')||[])];if(node.matches?.('[data-geo-map-preview]'))previews.push(node);
            for(const preview of previews)if(!preview.isConnected){observer?.unobserve(preview);clearTimeout(states.get(preview)?.timer);states.delete(preview);}
        }
    }).observe(document.body,{childList:true,subtree:true});
})();
