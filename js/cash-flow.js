for (const widget of document.querySelectorAll('[data-cash-flow]')) {
    const form = widget.querySelector('[data-cash-flow-filter]');
    const period = form.querySelector('[name="period"]');
    const from = form.querySelector('[name="date_from"]');
    const to = form.querySelector('[name="date_to"]');
    const dates = form.querySelector('[data-cash-flow-dates]');
    const error = widget.querySelector('[data-cash-flow-error]');
    const endpoint = widget.dataset.cashFlowEndpoint;
    let dirty = false;
    let dateTimer = null;

    function showDates() {
        const custom = period.value === 'custom';
        dates.hidden = !custom;
        from.required = to.required = custom;
        from.disabled = to.disabled = !custom;
    }
    showDates();
    function scheduleRange() {
        dirty = true;
        clearTimeout(dateTimer);
        if (period.value !== 'custom' || !from.value || !to.value || !from.checkValidity() || !to.checkValidity()) return;
        if (from.value > to.value) {
            error.textContent = 'La fecha inicial no puede ser posterior a la fecha final.'; error.hidden = false; return;
        }
        dateTimer = setTimeout(() => form.requestSubmit(), 400);
    }
    period.addEventListener('change', () => {
        clearTimeout(dateTimer);
        dirty = true;
        showDates();
        if (period.value !== 'custom') form.requestSubmit();
        else { from.focus(); scheduleRange(); }
    });
    for (const input of [from, to]) {
        input.addEventListener('input', scheduleRange);
        input.addEventListener('change', scheduleRange);
    }
    if (!endpoint) continue;

    const updated = widget.querySelector('[data-cash-flow-updated]');
    const link = widget.querySelector('[data-cash-flow-history]');
    const rangeLabel = widget.querySelector('[data-cash-flow-range]');
    const cards = widget.querySelector('[data-cash-flow-cards]');
    let controller = null;
    let requestNumber = 0;

    function parameters() {
        const result = new URLSearchParams({ period: period.value });
        if (period.value === 'custom') { result.set('date_from', from.value); result.set('date_to', to.value); }
        return result;
    }
    // Poll the applied period; leave an unfinished custom range untouched.
    let applied = parameters();

    function render(data) {
        for (const key of ['income', 'expense', 'net','balance_start','balance_end']) {
            const amounts = widget.querySelector(`[data-cash-flow-amount="${key}"]`);
            const rows = data.currencies.map(currency => {
                const row = document.createElement('p'); row.className = 'cash-flow-money';
                const amount = document.createElement('strong'); amount.textContent = currency[key];
                const code = document.createElement('span'); code.textContent = currency.code;
                row.append(amount, code); return row;
            });
            amounts.replaceChildren(...rows);
        }
        rangeLabel.textContent = data.range.label;
        if(link)link.href = '../contabilidad/ingresos-egresos.php?' + applied.toString();
        const accountGrid=widget.querySelector('[data-cash-flow-account-grid]');
        if(accountGrid){
            const element=(tag,className,value)=>{const node=document.createElement(tag);node.className=className;if(value!==undefined)node.textContent=value;return node;};
            const labels={balance_start:'Saldo al inicio',income:'Ingresos',expense:'Egresos',net:'Diferencia',transfer_in:'Transferencias recibidas',transfer_out:'Transferencias enviadas',opening_changes:'Saldos iniciales del período',balance_end:'Saldo al cierre'};
            const accountCards=data.accounts.map(account=>{
                const card=element('a','overview-card cash-flow-account-card');card.dataset.cashFlowAccount=account.id;
                card.href=accountGrid.dataset.historyPrefix+'ingresos-egresos.php?'+applied.toString()+'&account_id='+encodeURIComponent(account.id);
                const heading=element('div','cash-flow-account-heading');heading.append(element('h3','',account.name),element('span','module-status',account.account_type==='cash'?'Caja':'Banco'));card.append(heading);
                for(const currency of account.currencies){const block=element('div','cash-flow-account-currency');block.append(element('strong','',currency.code));const totals=element('dl','cash-flow-account-totals');
                    for(const [key,label] of Object.entries(labels)){if(['transfer_in','transfer_out','opening_changes'].includes(key)&&currency[key]==='0.00')continue;const row=element('div','cash-flow-'+key);row.append(element('dt','',label),element('dd','',currency[key]));totals.append(row);}block.append(totals);
                    const methods=(currency.methods||[]).filter(method=>Number(method.income)!==0||Number(method.expense)!==0);
                    if(methods.length){
                        const detail=element('div','cash-flow-methods');detail.dataset.cashFlowMethods='';detail.append(element('p','cash-flow-methods-title','Por medio de pago'));
                        const table=element('table',''),head=element('thead',''),heading=element('tr','');
                        for(const label of ['Medio','Ingresos','Egresos']){const cell=element('th','',label);cell.setAttribute('scope','col');heading.append(cell);}head.append(heading);table.append(head);
                        const body=element('tbody','');
                        for(const method of methods){const row=element('tr','');row.dataset.cashFlowMethod=method.method_id||0;const name=element('th','',method.name);name.setAttribute('scope','row');row.append(name);
                            for(const key of ['income','expense'])row.append(element('td','cash-flow-'+key,Number(method[key])===0?'—':method[key]));body.append(row);}
                        table.append(body);detail.append(table);block.append(detail);
                    }
                    card.append(block);}
                const footer=element('div','cash-flow-account-footer');footer.append(element('span','',account.count+' movimientos confirmados'),element('span','overview-link','Ver historial →'));card.append(footer);return card;
            });
            accountGrid.replaceChildren(...accountCards);
        }
        if (!dirty) { period.value = data.range.period; from.value = data.range.date_from; to.value = data.range.date_to; showDates(); }
        updated.textContent = 'Actualizado ' + data.updated_time + ' · Cada 30 s · Hora de Venezuela';
        if (!dirty) { error.hidden = true; error.textContent = ''; }
        widget.dispatchEvent(new CustomEvent('cash-flow:snapshot', { bubbles: true, detail: data }));
    }

    async function update(changeUrl = false) {
        if (controller) controller.abort();
        const current = new AbortController(); controller = current;
        const number = ++requestNumber;
        const timeout = setTimeout(() => current.abort(), 15000);
        cards.setAttribute('aria-busy', 'true');
        updated.textContent = 'Actualizando…';
        widget.dispatchEvent(new CustomEvent('cash-flow:refresh-state', { bubbles: true, detail: { loading: true } }));
        try {
            const response = await fetch(endpoint + '?' + applied.toString(), { cache: 'no-store', signal: current.signal, headers: { Accept: 'application/json' } });
            const data = await response.json();
            if (number !== requestNumber) return;
            if(response.status===401&&data.redirect){const login=new URL(data.redirect,window.location.href);login.searchParams.set('next',window.location.pathname+window.location.search);window.location.assign(login);return;}
            if(response.status===403){window.location.reload();return;}
            if (!response.ok) throw new Error(data.error || 'No se pudo actualizar el resumen.');
            render(data);
            if (changeUrl) {
                const url = new URL(window.location.href);
                for (const key of ['period', 'date_from', 'date_to']) url.searchParams.delete(key);
                for (const [key, value] of applied) url.searchParams.set(key, value);
                window.history.pushState(null, '', url);
            }
        } catch (failure) {
            if (number !== requestNumber) return;
            error.textContent = failure.name === 'AbortError' ? 'La consulta tardó demasiado. Intenta actualizar de nuevo.' : failure.message;
            error.hidden = false;
            updated.textContent = 'No se pudo actualizar. Los valores conservan el último período consultado.';
            widget.dispatchEvent(new CustomEvent('cash-flow:refresh-state', { bubbles: true, detail: { error: error.textContent } }));
        } finally {
            clearTimeout(timeout);
            if (number === requestNumber) { controller = null; cards.setAttribute('aria-busy', 'false'); }
        }
    }

    form.addEventListener('submit', event => {
        event.preventDefault();
        clearTimeout(dateTimer);
        if (period.value === 'custom' && from.value > to.value) {
            error.textContent = 'La fecha inicial no puede ser posterior a la fecha final.'; error.hidden = false; return;
        }
        applied = parameters(); dirty = false; update(true);
    });
    if (error.hidden) updated.textContent += ' · Se actualiza cada 30 s';
    const timer = setInterval(() => { if (!document.hidden && !controller) update(); }, 30000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden && !controller) update(); });
    window.addEventListener('popstate', () => {
        const query = new URL(window.location.href).searchParams;
        clearTimeout(dateTimer);
        period.value = query.get('period') || 'today';
        from.value = query.get('date_from') || from.value; to.value = query.get('date_to') || to.value;
        dirty = false; showDates(); applied = parameters(); update();
    });
    window.addEventListener('pagehide', () => { clearTimeout(dateTimer); clearInterval(timer); if (controller) controller.abort(); });
}
