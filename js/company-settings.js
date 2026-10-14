for (const form of document.querySelectorAll('[data-company-form]')) {
    const input=form.querySelector('[data-company-logo-input]');
    const preview=form.querySelector('[data-company-logo-preview]');
    const error=form.querySelector('[data-company-logo-error]');
    const remove=form.querySelector('[data-company-logo-remove]');
    const original=preview.getAttribute('src');
    let objectUrl=null;
    function restorePreview() {
        if(original)preview.src=original;else preview.removeAttribute('src');
        preview.hidden=!original || !!remove?.checked;
    }
    input.addEventListener('change',()=>{
        if(objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl=null; }
        input.setCustomValidity(''); error.hidden=true;
        const file=input.files[0];
        if(!file) { restorePreview(); return; }
        if(file.size>2*1024*1024 || !['image/png','image/jpeg','image/webp'].includes(file.type)) {
            error.textContent='Selecciona una imagen PNG, JPEG o WebP de hasta 2 MB.';error.hidden=false;
            input.setCustomValidity(error.textContent);restorePreview();return;
        }
        if(remove)remove.checked=false;
        objectUrl=URL.createObjectURL(file);preview.src=objectUrl;preview.hidden=false;
    });
    remove?.addEventListener('change',()=>{preview.hidden=remove.checked || (!original&&!objectUrl);});
}
