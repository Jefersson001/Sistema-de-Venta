(() => {
    'use strict';
    const userMenu=document.querySelector('.workspace-user-menu');
    const passwordDialog=document.querySelector('[data-password-dialog]'),passwordTrigger=document.querySelector('[data-password-open]');
    if(passwordDialog&&passwordTrigger){
        let previousOverflow='';
        const openPassword=()=>{if(passwordDialog.open)return;previousOverflow=document.body.style.overflow;passwordDialog.showModal();document.body.style.overflow='hidden';};
        passwordTrigger.addEventListener('click',openPassword);
        passwordDialog.querySelectorAll('[data-password-close]').forEach(button=>button.addEventListener('click',()=>passwordDialog.close()));
        passwordDialog.addEventListener('close',()=>{document.body.style.overflow=previousOverflow;passwordDialog.querySelector('form').reset();passwordTrigger.focus({preventScroll:true});});
        if(passwordDialog.dataset.reopen==='true')openPassword();
    }
    if(userMenu){
        document.addEventListener('click',event=>{if(!userMenu.contains(event.target))userMenu.open=false;});
        document.addEventListener('keydown',event=>{if(event.key==='Escape'&&userMenu.open){userMenu.open=false;userMenu.querySelector('summary').focus();}});
    }
    document.querySelector('[data-status-filter]')?.addEventListener('change',event=>event.target.form.requestSubmit());
    document.querySelectorAll('[data-request-row]').forEach(row=>row.addEventListener('click',event=>{
        if(!event.target.closest('a,button,input')&&!window.getSelection()?.toString())location.href=row.dataset.requestRow;
    }));
    const requestTabs=[...document.querySelectorAll('[data-request-tab]')],requestPanels=[...document.querySelectorAll('[data-request-panel]')];
    if(requestTabs.length){
        requestTabs[0].parentElement.setAttribute('role','tablist');
        const selectTab=tab=>{
            requestTabs.forEach(link=>{const selected=link===tab;link.setAttribute('aria-selected',String(selected));link.tabIndex=selected?0:-1;if(selected)link.setAttribute('aria-current','page');else link.removeAttribute('aria-current');});
            requestPanels.forEach(panel=>panel.hidden=panel.dataset.requestPanel!==tab.dataset.requestTab);
            tab.closest('.request-detail')?.classList.toggle('chat-focused',tab.dataset.requestTab==='seguimiento');
            const activeTab=document.querySelector('input[name="active_tab"]');if(activeTab)activeTab.value=tab.dataset.requestTab;
        };
        requestTabs.forEach((tab,index)=>{
            tab.id='request-tab-link-'+tab.dataset.requestTab;tab.setAttribute('role','tab');tab.setAttribute('aria-controls','request-tab-'+tab.dataset.requestTab);
            tab.addEventListener('click',event=>{event.preventDefault();selectTab(tab);history.replaceState(null,'',tab.href);});
            tab.addEventListener('keydown',event=>{let next;if(event.key==='ArrowRight')next=(index+1)%requestTabs.length;else if(event.key==='ArrowLeft')next=(index+requestTabs.length-1)%requestTabs.length;else if(event.key==='Home')next=0;else if(event.key==='End')next=requestTabs.length-1;else return;event.preventDefault();requestTabs[next].click();requestTabs[next].focus();});
        });
        requestPanels.forEach(panel=>{panel.setAttribute('role','tabpanel');panel.setAttribute('aria-labelledby','request-tab-link-'+panel.dataset.requestPanel);panel.tabIndex=0;});
        selectTab(requestTabs.find(tab=>tab.hasAttribute('aria-current'))||requestTabs[0]);
    }
    document.querySelectorAll('a[href="#seguimiento"]').forEach(link=>link.addEventListener('click',()=>{
        const followUp=document.querySelector('#seguimiento');if(followUp)followUp.open=true;
    }));
    document.querySelectorAll('.community-navigation nav a,.community-mobile-nav a').forEach(link=>link.addEventListener('click',()=>{
        link.closest('nav').querySelectorAll('[aria-current]').forEach(item=>item.removeAttribute('aria-current'));
        link.setAttribute('aria-current','page');
    }));
})();
