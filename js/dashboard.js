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
