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
        dashboard.querySelector('[data-dashboard-alerts]').replaceChildren(...alerts);
        dashboard.querySelector('[data-dashboard-alert-count]').textContent = data.alert_count
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
        dashboard.querySelector('[data-dashboard-tasks]').replaceChildren(...tasks);
        const commissions=dashboard.querySelector('[data-dashboard-commissions]');
        if(commissions){
            const params=new URLSearchParams({period:event.detail.range.period});
            if(event.detail.range.period==='custom') { params.set('date_from',event.detail.range.date_from);params.set('date_to',event.detail.range.date_to); }
            const rows=event.detail.commissions.map(group=>{
                const row=element('tr');const cell=element('td');const link=element('a','contact-name',group.seller_name);
                link.href='../ventas/comisiones.php?'+params.toString()+'&seller_id='+encodeURIComponent(group.seller_id);cell.append(link);row.append(cell,element('td','',group.currency_code));
                for(const key of ['sales_count','sales_amount','commission_generated','commission_earned','commission_paid','commission_pending','commission_overpaid'])row.append(element('td','',group[key]));
                return row;
            });
            if(!rows.length){const row=element('tr');const cell=element('td','contact-empty','No hay comisiones para las facturas de este período.');cell.colSpan=9;row.append(cell);rows.push(row);}
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
}
