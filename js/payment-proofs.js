(() => {
    function validate(form) {
        const fields=[...form.querySelectorAll('[data-payment-proof]')];
        let total=0,count=0;
        for(const field of fields){
            field.setCustomValidity('');
            for(const file of field.files){
                total+=file.size;count++;
                if(!file.size||file.size>10*1024*1024)field.setCustomValidity('Cada comprobante debe tener contenido y pesar como máximo 10 MB.');
                else if(!/\.(jpe?g|png|webp|gif|pdf)$/i.test(file.name))field.setCustomValidity('Selecciona una foto o un PDF.');
            }
        }
        if(fields.length&&(total>20*1024*1024||count>20))fields[fields.length-1].setCustomValidity('Selecciona hasta 20 comprobantes y 20 MB en total.');
    }
    document.addEventListener('change',event=>{if(event.target.matches('[data-payment-proof]'))validate(event.target.form);});
})();
