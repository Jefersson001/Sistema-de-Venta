(() => {
    'use strict';
    const headerPanel = document.querySelector('[data-module-header]');
    if (headerPanel) {
        const preferenceKey = 'vent-as-header-open-' + headerPanel.dataset.headerUser;
        try { headerPanel.open = localStorage.getItem(preferenceKey) === '1'; } catch {}
        headerPanel.addEventListener('toggle', () => {
            if (!headerPanel.open) {
                for (const menu of headerPanel.querySelectorAll('details[open]')) menu.open = false;
            }
            try { localStorage.setItem(preferenceKey, headerPanel.open ? '1' : '0'); } catch {}
        });
    }
    const menuSelector = 'details.module-menu, details.company-selector, details.transfer-menu';
    function positionMenu(menu) {
        const options=menu.querySelector(':scope > .module-menu-options, :scope > .transfer-menu-options');
        if(!options)return;
        options.style.transform='';
        const bounds=options.getBoundingClientRect();
        const viewport=window.visualViewport;
        const left=(viewport?.offsetLeft||0)+12;
        const right=(viewport?.offsetLeft||0)+(viewport?.width||innerWidth)-12;
        const shift=bounds.right>right?right-bounds.right:(bounds.left<left?left-bounds.left:0);
        if(shift)options.style.transform='translateX('+shift+'px)';
    }
    function closeMenus(except = null) {
        for (const menu of document.querySelectorAll(menuSelector)) {
            if (menu !== except && !menu.contains(except)) menu.open = false;
        }
    }
    document.addEventListener('click', event => closeMenus(event.target.closest(menuSelector)));
    document.addEventListener('toggle', event => {
        if (event.target.matches(menuSelector) && event.target.open) {
            closeMenus(event.target);
            positionMenu(event.target);
        }
    }, true);
    const reposition=()=>{for(const menu of document.querySelectorAll(menuSelector))if(menu.open)positionMenu(menu);};
    window.addEventListener('resize',reposition);
    window.visualViewport?.addEventListener('resize',reposition);
    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        const menus = [...document.querySelectorAll(menuSelector)].filter(menu => menu.open);
        if (!menus.length) return;
        const focused = menus.find(menu => menu.contains(document.activeElement)) || menus[menus.length - 1];
        closeMenus();
        focused.querySelector('summary')?.focus();
        event.preventDefault();
    });
})();
