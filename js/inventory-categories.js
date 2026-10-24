(() => {
    'use strict';
    const form = document.querySelector('[data-inventory-category]');
    if (!form) return;
    const parent = form.querySelector('[name=parent_id]');
    const checkbox = form.querySelector('[name=restaurant_enabled]');
    const readOnly = checkbox.matches(':disabled');
    const update = () => {
        const inherited = Boolean(parent.value);
        form.querySelector('[data-restaurant-category-option]').hidden = inherited;
        form.querySelector('[data-restaurant-category-inherited]').hidden = !inherited;
        checkbox.disabled = readOnly || inherited;
    };
    parent.addEventListener('change', update);
    update();
})();
