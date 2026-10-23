for (const report of document.querySelectorAll('[data-live-report], [data-live-module]')) {
    const moduleMode = report.hasAttribute?.('data-live-module') || false;
    const form = report.querySelector('[data-live-filters]');
    const results = moduleMode ? report : report.querySelector('[data-live-results]');
    const status = report.querySelector('[data-live-filter-status]');
    let timer, controller, generation = 0;

    function cancel() { clearTimeout(timer); controller?.abort(); generation++; }
    function filterUrl() {
        const url = new URL(form.action, window.location.href);
        url.search = '';
        for (const [key, value] of new FormData(form)) {
            if (!['csrf', 'company_scope'].includes(key) && value !== '') url.searchParams.append(key, value);
        }
        return url;
    }
    async function refresh(url = filterUrl(), remember = true) {
        cancel(); const current = generation; controller = new AbortController();
        const activeController = controller, timeout = setTimeout(() => activeController.abort(), 15000);
        results.setAttribute('aria-busy', 'true'); status.textContent = 'Actualizando…';
        try {
            const response = await fetch(url, {cache: 'no-store', signal: activeController.signal, headers: {Accept: 'text/html'}});
            const html = await response.text();
            if (current !== generation) return;
            const page = new DOMParser().parseFromString(html, 'text/html');
            const next = page.querySelector(moduleMode ? '[data-live-module]' : '[data-live-results]');
            if (!next || response.status >= 500) throw new Error('No se pudo actualizar. Se conservan los últimos resultados.');
            const nextForm = page.querySelector('[data-live-filters]');
            if (!remember && nextForm) {
                for (const field of form.querySelectorAll('input[name], select[name]')) {
                    const counterpart = [...nextForm.querySelectorAll('input[name], select[name]')].find(other => other.name === field.name && (field.type !== 'checkbox' && field.type !== 'radio' || other.value === field.value));
                    if (!counterpart) continue;
                    if (field.type === 'checkbox' || field.type === 'radio') field.checked = counterpart.checked;
                    else field.value = counterpart.value;
                }
            }
            if (moduleMode) {
                // Keep the actual filter form to preserve focus, typing and event listeners.
                if (!nextForm) throw new Error('La vista cambió. Recarga la página para continuar.');
                const active = document.activeElement;
                const restoreFocus = active && form.contains(active);
                const selection = restoreFocus ? [active.selectionStart, active.selectionEnd] : null;
                nextForm.replaceWith(form);
                results.replaceChildren(...next.childNodes);
                if (restoreFocus) {
                    active.focus({preventScroll:true});
                    if (selection[0] !== null && selection[0] !== undefined && active.setSelectionRange) active.setSelectionRange(...selection);
                }
            } else {
                results.replaceChildren(...next.childNodes);
            }
            // Export must retain the filters of the newly displayed results.
            if (!moduleMode) {
                const menu = report.querySelector('[data-model-options]'), nextMenu = page.querySelector('[data-live-report] [data-model-options]');
                if (menu && nextMenu) menu.replaceWith(nextMenu);
            }
            status.textContent = response.ok ? '' : 'Revisa los filtros indicados.';
            if (remember && String(url) !== window.location.href) window.history.pushState(null, '', url);
            if (typeof CustomEvent !== 'undefined') results.dispatchEvent(new CustomEvent('live-filters:updated', {bubbles:true}));
        } catch (error) {
            if (current === generation) status.textContent = error.name === 'AbortError' ? 'La consulta tardó demasiado. Modifica un filtro para reintentar.' : error.message;
        } finally {
            clearTimeout(timeout);
            if (current === generation) results.setAttribute('aria-busy', 'false');
        }
    }
    form.addEventListener('submit', event => { event.preventDefault(); refresh(); });
    form.addEventListener('input', event => {
        if (!event.target.matches('input[type="search"], input[type="text"], input:not([type]), textarea')) return;
        cancel(); timer = setTimeout(() => refresh(), 300);
    });
    form.addEventListener('change', event => { if (event.target.matches('select, input[type="checkbox"], input[type="radio"], input[type="date"], input[type="number"]')) refresh(); });
    window.addEventListener('popstate', () => {
        const url = new URL(window.location.href);
        refresh(url, false);
    });
    window.addEventListener('pagehide', cancel);
}
