(() => {
    const parent = document.querySelector('[data-product-variant-parent]');
    if (!parent) return;
    const original = parent.value;
    parent.addEventListener('change', () => {
        if (!parent.value) return;
        const form = parent.closest('form');
        if (window.nexoUnsavedChanges?.hasChanges(form, 'parent_product_id') && !window.confirm('Cambiar el producto principal descartará los cambios de esta ficha y cargará sus datos. ¿Deseas continuar?')) {
            parent.value = original;
            return;
        }
        window.nexoUnsavedChanges?.allowNavigation(form);
        const url = new URL('variantes.php', location.href);
        url.searchParams.set('new', '1');
        url.searchParams.set('parent_id', parent.value);
        location.assign(url.href);
    });
})();
