(() => {
    const parent = document.querySelector('[data-product-variant-parent]');
    if (!parent) return;
    parent.addEventListener('change', () => {
        if (!parent.value) return;
        const url = new URL('variantes.php', location.href);
        url.searchParams.set('new', '1');
        url.searchParams.set('parent_id', parent.value);
        location.assign(url.href);
    });
})();
