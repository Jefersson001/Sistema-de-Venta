(() => {
    const lines = document.querySelector('[data-contact-upload-lines]');
    const form = lines?.closest('form');
    const add = document.querySelector('[data-contact-attachment-add]');
    const template = document.querySelector('[data-contact-attachment-template]');
    if (!lines || !add || !template || form.dataset.permissionReadonly === 'true') return;
    const status = document.querySelector('[data-contact-attachment-status]');
    let next = 0;
    function validate() {
        const fields = [...lines.querySelectorAll('[data-contact-upload]')];
        let total = 0;
        fields.forEach(field => { const file = field.files[0]; total += file?.size || 0; field.setCustomValidity(file && (!file.size || file.size>10*1024*1024) ? 'El archivo debe tener contenido y pesar como máximo 10 MB.' : ''); });
        if (total>20*1024*1024 && fields.length) fields[fields.length-1].setCustomValidity('Adjunta como máximo 20 MB por guardado.');
        add.disabled = fields.length>=20;
        status.textContent = fields.length ? fields.filter(field=>field.files.length).length + ' archivos nuevos · ' + (total/1024/1024).toFixed(2) + ' MB. Guarda el contacto para conservarlos.' : '';
    }
    add.addEventListener('click', () => {
        if (lines.children.length>=20) return;
        const fragment = template.content.cloneNode(true);
        fragment.querySelectorAll('[name]').forEach(field => { field.name=field.name.replace('__INDEX__',String(next)); });
        next++;
        lines.append(fragment); validate();
        lines.lastElementChild.querySelector('input').focus();
    });
    lines.addEventListener('change', validate);
    lines.addEventListener('click', event => {
        if (!event.target.closest('[data-contact-attachment-remove]')) return;
        event.target.closest('[data-contact-upload-row]').remove(); validate();
        form.dispatchEvent(new Event('change',{bubbles:true}));
    });
})();
