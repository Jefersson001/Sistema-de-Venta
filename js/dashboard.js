for (const dashboard of document.querySelectorAll('[data-dashboard]')) {
    const updated = dashboard.querySelector('[data-dashboard-updated]');
    const error = dashboard.querySelector('[data-dashboard-error]');
    function element(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }
    dashboard.addEventListener('cash-flow:snapshot', event => {
        if(event.detail.permission_signature && event.detail.permission_signature!==dashboard.dataset.permissionSignature){window.location.reload();return;}
        const data = event.detail.dashboard;
        if (!data?.available) return;
        for (const metric of dashboard.querySelectorAll('[data-dashboard-metric]')) {
            metric.textContent = data.metrics[metric.dataset.dashboardMetric];
        }
        const alerts = data.alerts.map(alert => {
            const row = element('li');
            const link = element('a', 'overview-link', alert.name);
            link.href = '../inventario/index.php?id=' + encodeURIComponent(alert.id);
            row.append(link, document.createTextNode(' · Disponible ' + alert.available));
            return row;
        });
        dashboard.querySelector('[data-dashboard-alerts]')?.replaceChildren(...alerts);
        const alertCount=dashboard.querySelector('[data-dashboard-alert-count]');if(alertCount)alertCount.textContent = data.alert_count
            ? data.alert_count + ' productos sin cantidad disponible después de reservas.'
            : 'No hay productos sin disponibilidad.';
        const tasks = data.tasks.map(task => {
            const card = element('article', 'overview-card');
            const link = element('a', 'overview-link', task.label);
            link.href = '../../' + task.href;
            card.append(link, element('p', '', task.detail));
            return card;
        });
        if (!tasks.length) tasks.push(element('p', '', 'No hay operaciones pendientes ni facturas vencidas con saldo.'));
        dashboard.querySelector('[data-dashboard-tasks]')?.replaceChildren(...tasks);
        const commissions=dashboard.querySelector('[data-dashboard-commissions]');
        for(const type of ['customer','supplier']){
            const section=data.balances?.[type],totals=dashboard.querySelector(`[data-outstanding-totals="${type}"]`);
            if(!totals)continue;
            if(!section){totals.replaceChildren();continue;}
            const cards=section.currencies.map(amount=>{
                const card=element('article','overview-card');card.append(element('span','overview-label',amount.code));const list=element('dl','cash-flow-account-totals');
                for(const [key,label] of Object.entries({balance:'Saldo pendiente',overdue:'Vencido',pending:'Pagos en proceso'})){const row=element('div');row.append(element('dt','',label),element('dd','',amount[key]));list.append(row);}card.append(list,element('small','',amount.count+' facturas con saldo'));return card;
            });
            if(!cards.length){const card=element('article','overview-card');card.append(element('span','overview-label','Saldo pendiente'),element('strong','','0.00'));cards.push(card);}
            totals.replaceChildren(...cards);
        }
        if(commissions){
            const params=new URLSearchParams({period:event.detail.range.period});
            if(event.detail.range.period==='custom') { params.set('date_from',event.detail.range.date_from);params.set('date_to',event.detail.range.date_to); }
            const rows=event.detail.commissions.map(group=>{
                const row=element('tr');const cell=element('td');const link=element('a','contact-name',group.seller_name);
                link.href='../ventas/comisiones.php?'+params.toString()+'&seller_id='+encodeURIComponent(group.seller_id);cell.append(link);row.append(cell,element('td','',group.currency_code));
                for(const key of ['sales_count','sales_amount','commission_generated','commission_earned','commission_paid','commission_pending','commission_overpaid'])row.append(element('td','',group[key]));
                const action=element('td');const pay=element('a','contact-button','Pagar comisión');
                pay.href='../ventas/pagos-comisiones.php?'+params.toString()+'&new=1&seller_id='+encodeURIComponent(group.seller_id)+'&commission_currency='+encodeURIComponent(group.currency_code);
                if(group.can_pay)action.append(pay);row.append(action);
                return row;
            });
            if(!rows.length){const row=element('tr');const cell=element('td','contact-empty','No hay comisiones para las facturas de este período.');cell.colSpan=10;row.append(cell);rows.push(row);}
            commissions.replaceChildren(...rows);
            dashboard.querySelector('[data-dashboard-commission-range]').textContent=event.detail.range.label;
            dashboard.querySelector('[data-dashboard-commission-history]').href='../ventas/comisiones.php?'+params.toString();
        }
        updated.textContent = 'Actualizado ' + event.detail.updated_time + ' · Se actualiza cada 30 s';
        error.hidden = true;
        error.textContent = '';
    });
    dashboard.addEventListener('cash-flow:refresh-state', event => {
        if (event.detail.error) {
            error.textContent = event.detail.error;
            error.hidden = false;
            updated.textContent = 'No se pudo actualizar. Se conservan los últimos datos disponibles.';
        } else if (event.detail.loading) updated.textContent = 'Actualizando dashboard…';
    });
    const filter=dashboard.querySelector('[data-dashboard-filter]');
    if(filter){
        const period=filter.elements.period,from=filter.elements.date_from,to=filter.elements.date_to,dates=filter.querySelector('[data-dashboard-dates]');
        let pending=false,debounce=null;let applied=new URLSearchParams(window.location.search);applied.delete('page');
        const showDates=()=>{dates.hidden=period.value!=='custom';from.required=to.required=!dates.hidden;};showDates();
        const refresh=async(change=false)=>{
            if(pending)return;pending=true;
            dashboard.dispatchEvent(new CustomEvent('cash-flow:refresh-state',{detail:{loading:true}}));
            try{
                const response=await fetch('resumen.php?'+applied,{cache:'no-store',signal:AbortSignal.timeout(15000)});const data=await response.json();
                if(response.status===401&&data.redirect){const login=new URL(data.redirect,window.location.href);login.searchParams.set('next',window.location.pathname+window.location.search);window.location.assign(login);return;}
                if(response.status===403){window.location.reload();return;}
                if(!response.ok)throw new Error(data.error||'No se pudo actualizar.');
                dashboard.dispatchEvent(new CustomEvent('cash-flow:snapshot',{detail:data}));
                if(change)history.replaceState(null,'','?'+applied);
            }catch(failure){dashboard.dispatchEvent(new CustomEvent('cash-flow:refresh-state',{detail:{error:failure.message}}));}
            finally{pending=false;}
        };
        const apply=()=>{showDates();if(period.value==='custom'&&(!from.value||!to.value||from.value>to.value||!filter.reportValidity()))return;applied=new URLSearchParams({period:period.value});if(period.value==='custom'){applied.set('date_from',from.value);applied.set('date_to',to.value);}refresh(true);};
        period.addEventListener('change',apply);for(const input of [from,to])input.addEventListener('input',()=>{clearTimeout(debounce);debounce=setTimeout(apply,400);});
        filter.addEventListener('submit',event=>{event.preventDefault();apply();});
        const timer=setInterval(()=>{if(!document.hidden)refresh();},30000);document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
        window.addEventListener('pagehide',()=>{clearInterval(timer);clearTimeout(debounce);});
    }
}
