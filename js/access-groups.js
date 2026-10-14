for(const form of document.querySelectorAll('[data-access-group-form]')) {
    const rows=[...form.querySelectorAll('[data-access-row]')];
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
