(() => {
    'use strict';
    const widget=document.querySelector('[data-navigation-search]');
    if(!widget)return;
    const input=widget.querySelector('[data-module-search]'),results=widget.querySelector('[role=listbox]'),status=widget.querySelector('[data-navigation-search-status]');
    const catalog=JSON.parse(widget.querySelector('[data-navigation-search-data]').textContent);
    const normalize=text=>text.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLocaleLowerCase('es');
    const ignored=new Set(['de','del','la','el','los','las','y','en','a']);
    const entries=catalog.map(entry=>({...entry,search:normalize(entry.path+' '+entry.keywords),label:normalize(entry.title)}));
    let matches=[],active=-1;

    function close(){results.hidden=true;input.setAttribute('aria-expanded','false');input.removeAttribute('aria-activedescendant');active=-1;matches=[];}
    function position(){
        if(results.hidden)return;
        const viewport=window.visualViewport;
        const bottom=viewport?viewport.height+viewport.offsetTop:innerHeight;
        results.style.maxHeight=Math.max(100,Math.min(360,bottom-widget.getBoundingClientRect().bottom-14))+'px';
    }
    function highlight(index,scroll=false){
        if(!matches.length)return;
        active=(index+matches.length)%matches.length;
        const options=[...results.querySelectorAll('[role=option]')];
        options.forEach((option,i)=>option.setAttribute('aria-selected',String(i===active)));
        input.setAttribute('aria-activedescendant',options[active].id);
        if(scroll)options[active].scrollIntoView({block:'nearest'});
    }
    function render(){
        const query=normalize(input.value.trim());
        if(!query){status.textContent='';close();return;}
        const words=query.split(/[^a-z0-9]+/).filter(word=>word&&!ignored.has(word));
        if(!words.length){status.textContent='Escribe el nombre de una sección.';close();return;}
        const phrase=words.join(' ');
        const score=entry=>entry.label===phrase?100:entry.label.startsWith(phrase)?60:entry.label.includes(phrase)?30:0;
        matches=entries.filter(entry=>words.every(word=>entry.search.includes(word))).sort((a,b)=>score(b)-score(a)||a.path.localeCompare(b.path,'es'));
        results.replaceChildren();
        matches.forEach((entry,i)=>{
            const link=document.createElement('a');link.href=entry.url;link.className='navigation-search-result';link.id='navigation-search-option-'+i;link.setAttribute('role','option');link.tabIndex=-1;link.dataset.module=entry.module;
            const marker=document.createElement('span');marker.className='navigation-search-marker';marker.setAttribute('aria-hidden','true');
            const copy=document.createElement('span');copy.className='navigation-search-copy';
            const title=document.createElement('strong');title.textContent=entry.title;
            const path=document.createElement('small');path.textContent=entry.path;
            const arrow=document.createElement('span');arrow.className='navigation-search-arrow';arrow.textContent='→';arrow.setAttribute('aria-hidden','true');
            copy.append(title,path);link.append(marker,copy,arrow);
            link.addEventListener('pointermove',()=>highlight(i));results.append(link);
        });
        if(!matches.length){const empty=document.createElement('p');empty.className='navigation-search-empty';empty.textContent='No se encontraron secciones. Prueba con pagos, productos o monedas.';results.append(empty);}
        status.textContent=matches.length===1?'1 sección encontrada.':matches.length+' secciones encontradas.';
        results.hidden=false;input.setAttribute('aria-expanded','true');input.removeAttribute('aria-activedescendant');active=-1;position();
        if(matches.length)highlight(0);
    }
    input.addEventListener('input',render);
    input.addEventListener('focus',()=>{if(input.value.trim())render();});
    input.addEventListener('click',()=>{if(results.hidden&&input.value.trim())render();});
    input.addEventListener('keydown',event=>{
        if(event.key==='Escape'){event.preventDefault();close();return;}
        if(event.key==='Tab'){close();return;}
        if(event.key==='ArrowDown'||event.key==='ArrowUp'){
            event.preventDefault();
            if(results.hidden){render();if(event.key==='ArrowUp'&&matches.length)highlight(matches.length-1,true);}
            else highlight(active+(event.key==='ArrowDown'?1:-1),true);
        }
        if(event.key==='Enter'&&!results.hidden&&active>=0){event.preventDefault();location.assign(matches[active].url);}
    });
    widget.addEventListener('focusout',event=>{if(!widget.contains(event.relatedTarget))close();});
    document.addEventListener('pointerdown',event=>{if(!widget.contains(event.target))close();});
    window.addEventListener('resize',position);
    window.visualViewport?.addEventListener('resize',position);
})();
