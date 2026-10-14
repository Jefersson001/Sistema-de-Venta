const operationForm = document.querySelector('[data-operation-form]');
if (operationForm && operationForm.dataset.permissionReadonly!=='true') {
    const body = operationForm.querySelector('[data-operation-lines]');
    const template = operationForm.querySelector('[data-operation-template]');
    const add = operationForm.querySelector('[data-operation-add]');
    const validation = document.querySelector('[data-operation-validate]');
    const stock = JSON.parse(document.querySelector('[data-operation-stock]').textContent);
    const location = operationForm.querySelector('[name="source_location_id"]') || operationForm.querySelector('[name="destination_location_id"]');
    const contact = operationForm.querySelector('[name="contact_id"]');
    const external = operationForm.querySelector('[data-operation-external]');
    contact?.addEventListener('change', () => {
        if (external) external.value = contact.selectedOptions[0]?.dataset.externalLabel || '';
    });
    let next = body.children.length;
    function markDirty() {
        if (!validation) return;
        validation.querySelector('button').disabled = true;
        validation.querySelector('[data-operation-dirty]').hidden = false;
    }
    function updateStock() {
        for (const row of body.querySelectorAll('[data-operation-line]')) {
            const available = row.querySelector('[data-operation-available]');
            if (!available) continue;
            const product = row.querySelector('[data-operation-product]').value;
            const balance = stock.find(item => String(item.product_id) === product && String(item.location_id) === location?.value);
            available.textContent = balance?.quantity || '0.000';
        }
    }
    function setupRow(row) {
        const remove = row.querySelector('[data-operation-remove]');
        if (remove) {
            remove.hidden = false;
            remove.addEventListener('click', () => { row.remove(); markDirty(); });
        }
    }
    for (const row of body.querySelectorAll('[data-operation-line]')) setupRow(row);
    if (add) {
        add.hidden = false;
        add.addEventListener('click', () => {
            if (body.children.length >= 100) return;
            const fragment = template.content.cloneNode(true);
            for (const input of fragment.querySelectorAll('[name]')) input.name = input.name.replace('__INDEX__', String(next));
            next++;
            setupRow(fragment.querySelector('[data-operation-line]'));
            body.append(fragment); updateStock(); markDirty();
        });
    }
    operationForm.addEventListener('input', markDirty);
    operationForm.addEventListener('change', () => { updateStock(); markDirty(); });
    validation?.addEventListener('submit', () => { validation.querySelector('button').disabled = true; });
    updateStock();
}
