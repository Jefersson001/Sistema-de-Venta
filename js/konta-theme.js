(() => {
    'use strict';
    let mode='light';try{mode=localStorage.getItem('konta-theme')==='dark'?'dark':'light';}catch{}
    document.documentElement.dataset.kontaTheme=mode;
    document.addEventListener('DOMContentLoaded',()=>{
        const buttons=[...document.querySelectorAll('[data-konta-theme-toggle]')];
        function sync(){for(const button of buttons){button.setAttribute('aria-pressed',String(mode==='dark'));button.setAttribute('aria-label',mode==='dark'?'Usar modo claro':'Usar modo oscuro');button.title=mode==='dark'?'Usar modo claro':'Usar modo oscuro';}}
        for(const button of buttons)button.addEventListener('click',()=>{mode=mode==='light'?'dark':'light';document.documentElement.dataset.kontaTheme=mode;try{localStorage.setItem('konta-theme',mode);}catch{}sync();});sync();
    });
})();
