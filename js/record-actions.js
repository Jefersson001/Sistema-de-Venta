(() => {
    document.addEventListener('change', event => {
        if (!event.target.matches('[data-record-select-all]')) return;
        for (const input of event.target.closest('table').querySelectorAll('[data-record-select]')) input.checked = event.target.checked;
    });
    document.addEventListener('click', event => {
        const selection = event.target.closest('[data-record-select], [data-record-select-all]');
        if (selection) {event.stopPropagation(); return;}
        const link = event.target.closest('[data-record-delete-selection]');
        if (!link) return;
        event.preventDefault();
        const ids = [...document.querySelectorAll('[data-record-select]:checked')].map(input => input.value);
        if (!ids.length) {window.alert('Selecciona los registros que deseas eliminar.'); return;}
        const url = new URL(link.href);
        ids.forEach(id => url.searchParams.append('ids[]', id));
        location.assign(url.href);
    });
})();
