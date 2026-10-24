for(const form of document.querySelectorAll('[data-access-group-form]')) {
    const rows=[...form.querySelectorAll('[data-access-row]')];
    const templateSelect=form.querySelector('[data-access-template]');
    const templateButton=form.querySelector('[data-access-apply-template]');
    if(templateSelect&&templateButton){
        const templates=JSON.parse(form.querySelector('[data-access-template-data]').textContent);
        const autoValues={};
        templateButton.addEventListener('click',()=>{
            if(form.dataset.permissionReadonly==='true')return;
            const template=templates[templateSelect.value];if(!template)return;
            for(const row of rows){
                const checks=[...row.querySelectorAll('[data-access-permission]')];
                for(const check of checks)if(!check.disabled)check.checked=Object.hasOwn(template.permissions,check.value);
                const selected=checks.find(check=>Object.hasOwn(template.permissions,check.value));
                const scope=row.querySelector('[data-access-scope]');
                if(scope)scope.value=selected?template.permissions[selected.value]:'all';
            }
            for(const field of ['name','description']){
                const input=form.querySelector(`[name=${field}]`);
                if(input&&(!input.value.trim()||input.value===autoValues[field])){
                    input.value=template[field];autoValues[field]=template[field];
                }
            }
            update();
            form.querySelector('#access-tab-restaurante')?.click();
        });
    }
    function update() {
        const counts={};
        for(const row of rows) {
            const checks=[...row.querySelectorAll('[data-access-permission]')];
            const read=checks.find(input=>input.dataset.accessPermission==='read');
            const scope=row.querySelector('[data-access-scope]');
            if(scope)scope.disabled=form.dataset.permissionReadonly==='true'||!read?.checked;
            counts[row.dataset.accessModule]=(counts[row.dataset.accessModule]??0)+checks.filter(input=>input.checked).length;
        }
        for(const count of form.querySelectorAll('[data-access-count]'))count.textContent=String(counts[count.dataset.accessCount]??0);
    }
    for(const row of rows) {
        const checks=[...row.querySelectorAll('[data-access-permission]')];
        const read=checks.find(input=>input.dataset.accessPermission==='read');
        for(const check of checks)check.addEventListener('change',()=>{
            if(check===read&&!check.checked) {
                for(const other of checks)if(!other.disabled)other.checked=false;
            }
            else if(check.checked&&read)read.checked=true;
            update();
        });
    }
    update();
}
