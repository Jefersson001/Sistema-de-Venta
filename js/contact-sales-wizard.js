(() => {
    const payload = document.querySelector('[data-created-sales-customer]');
    if (payload && window.parent !== window) {
        window.parent.postMessage({type: 'sales-customer-created', customer: JSON.parse(payload.textContent)}, window.location.origin);
    }
})();
