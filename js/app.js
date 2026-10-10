const searchInput = document.querySelector('[data-module-search]');
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
