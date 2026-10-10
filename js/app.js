const searchInput = document.querySelector('[data-module-search]');
for (const warehouse of document.querySelectorAll('[data-location-warehouse]')) {
    const parent = warehouse.closest('form').querySelector('[data-location-parent]');
    const options = [...parent.options].map(option => option.cloneNode(true));
    function updateLocationParents() {
        const selected = parent.value;
        const matching = options.filter(option => !option.value || option.dataset.warehouse === warehouse.value);
        parent.replaceChildren(...matching.map(option => option.cloneNode(true)));
        parent.value = matching.some(option => option.value === selected) ? selected : '';
        parent.disabled = !warehouse.value;
    }
    warehouse.addEventListener('change', updateLocationParents);
    updateLocationParents();
}
for (const filter of document.querySelectorAll('[data-auto-submit]')) {
    filter.addEventListener('change', () => filter.closest('form')?.requestSubmit());
}
const moduleCards = [...document.querySelectorAll('[data-module-card]')];
const emptySearch = document.querySelector('[data-empty-search]');

searchInput?.addEventListener('input', () => {
    const searchTerm = searchInput.value.trim().toLocaleLowerCase('es');
    let visibleCount = 0;

    for (const card of moduleCards) {
        const matches = card.dataset.moduleName.includes(searchTerm);
        card.hidden = !matches;
        visibleCount += Number(matches);
    }

    if (emptySearch) {
        emptySearch.hidden = visibleCount > 0;
    }
});

const menuToggle = document.querySelector('[data-menu-toggle]');
const backdrop = document.querySelector('[data-sidebar-backdrop]');

function setSidebarOpen(isOpen) {
    document.body.classList.toggle('sidebar-open', isOpen);
    menuToggle?.setAttribute('aria-expanded', String(isOpen));
}

menuToggle?.addEventListener('click', () => {
    setSidebarOpen(menuToggle.getAttribute('aria-expanded') !== 'true');
});

backdrop?.addEventListener('click', () => setSidebarOpen(false));

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        setSidebarOpen(false);
    }

    if (event.key === '/' && !['INPUT', 'TEXTAREA'].includes(document.activeElement?.tagName)) {
        event.preventDefault();
        searchInput?.focus();
    }
});

for (const locationFields of document.querySelectorAll('[data-contact-location]')) {
    const country = locationFields.querySelector('[data-country]');
    const state = locationFields.querySelector('[data-state]');
    const city = locationFields.querySelector('[data-city]');
    const states = [...state.options].map(option => option.cloneNode(true));
    const cities = [...city.options].map(option => option.cloneNode(true));

    function filterLocationOptions(select, options, parentId) {
        const selected = select.value;
        const matching = options.filter(option => !option.value || (parentId && option.dataset.parent === parentId));
        select.replaceChildren(...matching.map(option => option.cloneNode(true)));
        select.value = matching.some(option => option.value === selected) ? selected : '';
        select.disabled = !parentId;
    }

    function updateCities() {
        filterLocationOptions(city, cities, state.value);
    }

    function updateStates() {
        filterLocationOptions(state, states, country.value);
        updateCities();
    }

    country.addEventListener('change', updateStates);
    state.addEventListener('change', updateCities);
    updateStates();
}

for (const notebook of document.querySelectorAll('[data-notebook]')) {
    const tabList = notebook.querySelector('[data-notebook-tabs]');
    const tabs = [...tabList.querySelectorAll('a')];
    const pages = [...notebook.querySelectorAll('[data-notebook-page]')];
    tabList.setAttribute('role', 'tablist');

    function activateTab(index, focus = false) {
        tabs.forEach((tab, position) => {
            tab.setAttribute('aria-selected', String(position === index));
            tab.tabIndex = position === index ? 0 : -1;
            pages[position].hidden = position !== index;
        });
        if (focus) tabs[index].focus();
    }

    tabs.forEach((tab, index) => {
        tab.setAttribute('role', 'tab');
        tab.setAttribute('aria-controls', pages[index].id);
        pages[index].setAttribute('role', 'tabpanel');
        pages[index].tabIndex = 0;
        tab.addEventListener('click', event => {
            event.preventDefault();
            activateTab(index);
        });
        tab.addEventListener('keydown', event => {
            let next;
            if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
            if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
            if (event.key === 'Home') next = 0;
            if (event.key === 'End') next = tabs.length - 1;
            if (next !== undefined) {
                event.preventDefault();
                activateTab(next, true);
            }
        });
    });
    activateTab(0);
}

for (const contactType of document.querySelectorAll('[data-contact-type]')) {
    const form = contactType.closest('form');
    const field = form.querySelector('[data-salesperson-field]');
    const help = form.querySelector('[data-salesperson-help]');
    const salesperson = field.querySelector('select');
    const sellerStorage = form.querySelector('[data-storage-seller-field]');
    const warehouse = sellerStorage?.querySelector('[data-related-warehouse]');
    function updateSalespersonVisibility() {
        const kind = contactType.selectedOptions[0]?.dataset.contactKind;
        const isClient = kind === 'client';
        field.hidden = !isClient;
        salesperson.disabled = !isClient;
        help.hidden = isClient;
        if (sellerStorage) {
            sellerStorage.hidden = kind !== 'salesperson';
            if (warehouse) warehouse.disabled = kind !== 'salesperson';
        }
    }
    contactType.addEventListener('change', updateSalespersonVisibility);
    updateSalespersonVisibility();
}
