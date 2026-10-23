(() => {
    'use strict';
    const scope = window.nexoCompanyScope;
    if (!scope || window.nexoCompanyLoaded) return;
    window.nexoCompanyLoaded = true;
    const key = 'nexo-company-scope';
    let outdated = false;
    const refresh = () => { outdated = true; window.location.reload(); };
    window.addEventListener('storage', event => {
        if (event.key === key && event.newValue && event.newValue !== scope) refresh();
    });
    try { localStorage.setItem(key, scope); } catch (_) { /* Session remains enforced by the server. */ }
    const fetchOriginal = window.fetch.bind(window);
    window.fetch = async (input, options = {}) => {
        if (outdated) throw new Error('La selección de empresas cambió.');
        const url = new URL(input instanceof Request ? input.url : input, location.href);
        if (url.origin !== location.origin || !url.pathname.startsWith('/project/')) return fetchOriginal(input, options);
        const headers = new Headers(options.headers || (input instanceof Request ? input.headers : undefined));
        headers.set('X-Nexo-Company-Scope', scope);
        const response = await fetchOriginal(input, { ...options, headers });
        if (response.status === 401 || response.status === 403 || (response.redirected && new URL(response.url).pathname.endsWith('/login.php'))) {
            refresh(); throw new Error('Tu acceso cambió.');
        }
        if (response.headers.get('X-Nexo-Company-Scope') && response.headers.get('X-Nexo-Company-Scope') !== scope) {
            refresh(); throw new Error('La selección de empresas cambió.');
        }
        return response;
    };
    document.addEventListener('submit', event => {
        if (outdated) { event.preventDefault(); refresh(); }
    });
    // pageshow also covers pages restored from the browser's back/forward cache.
    window.addEventListener('pageshow', () => {
        try { if (localStorage.getItem(key) && localStorage.getItem(key) !== scope) refresh(); } catch (_) {}
    });
    document.addEventListener('DOMContentLoaded', () => {
        const selector = document.querySelector('[data-company-selector]');
        if (selector) {
            const primary = selector.querySelector('[name="active_company"]');
            const boxes = Array.from(selector.querySelectorAll('[name="companies[]"]'));
            let pending;
            const apply = () => { clearTimeout(pending); selector.requestSubmit(); };
            selector.addEventListener('change', event => {
                if (!boxes.includes(event.target)) return;
                const selected = boxes.filter(box => box.checked);
                if (!selected.length) { event.target.checked = true; return; }
                if (!selected.some(box => box.value === primary.value)) primary.value = selected[0].value;
                pending = setTimeout(apply, 450);
            });
            selector.addEventListener('click', event => {
                const button = event.target.closest('button[name="primary_company"]');
                if (!button) return;
                event.preventDefault();
                primary.value = button.value;
                boxes.find(box => box.value === button.value).checked = true;
                apply();
            });
        }
        const live = document.querySelector('meta[name="nexo-company-live-list"]');
        if (!live) return;
        let busy = false;
        const saved = sessionStorage.getItem('nexo-company-scroll:' + location.pathname);
        if (saved) { sessionStorage.removeItem('nexo-company-scroll:' + location.pathname); window.scrollTo(0, Number(saved)); }
        const update = async () => {
            if (busy || outdated || document.hidden || document.querySelector('dialog[open]') || /^(INPUT|SELECT|TEXTAREA|BUTTON)$/.test(document.activeElement?.tagName || '')) return;
            busy = true;
            try {
                const response = await window.fetch(location.href, { cache: 'no-store' });
                if (!response.ok) return;
                const next = new DOMParser().parseFromString(await response.text(), 'text/html');
                const signature = page => Array.from(page.querySelectorAll('main .contact-table-wrap, main .contact-pagination')).map(node => node.textContent.trim()).join('|');
                if (next.querySelector('meta[name="nexo-company-live-list"]') && (live.dataset.report || signature(next) !== signature(document))) {
                    sessionStorage.setItem('nexo-company-scroll:' + location.pathname, String(scrollY)); window.location.reload();
                }
            } catch (_) { /* Retain the current values; the next poll retries. */ }
            finally { busy = false; }
        };
        setInterval(update, Number(live.content) * 1000);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) update(); });
    });
})();
