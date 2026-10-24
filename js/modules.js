(() => {
    'use strict';
    if (!document.body.classList.contains('module-workspace')) return;

    const normalize = text => text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/\s+/g, ' ').trim().toLowerCase();
    const statusTone = text => {
        const state = normalize(text).replace(/\s*\(activo\)$/, '');
        if (/^(pagado|confirmado|hecho|registrado|orden de venta|orden de compra|listo)$/.test(state)) return 'success';
        if (/^(cancelado|revertido|retirado)$/.test(state)) return 'danger';
        if (/^(en espera|en proceso de pago|pago parcial|parcialmente pagado|sin pagar|no pagado|pendiente)$/.test(state)) return 'warning';
        if (/^(enviado|cotizacion enviada|solicitud enviada)$/.test(state)) return 'info';
        return 'neutral';
    };
    const numericColumn = text => /^(total|importe|saldo|pagado|pendiente|precio|base imponible|comision|cantidad|existencias|sin aplicar|movimiento confirmado)(\b|$)/.test(normalize(text));

    function enhanceTable(table) {
        const headings = [...(table.tHead?.rows[0]?.cells || [])];
        if (!headings.length || table.tHead.rows.length !== 1 || headings.some(cell => cell.colSpan !== 1)) return;
        const labels = headings.map(cell => cell.textContent.trim());
        headings.forEach((cell, index) => {
            if (!cell.hasAttribute('scope')) cell.scope = 'col';
            cell.classList.toggle('workspace-number', numericColumn(labels[index]));
        });
        for (const body of table.tBodies) {
            for (const row of body.rows) {
                if (row.cells.length !== headings.length || [...row.cells].some(cell => cell.colSpan !== 1)) continue;
                [...row.cells].forEach((cell, index) => {
                    cell.dataset.columnLabel = labels[index];
                    cell.classList.toggle('workspace-number', numericColumn(labels[index]));
                    if (!/^estado(\b|$)/.test(normalize(labels[index])) || cell.querySelector('input,select,button,a')) return;
                    const existing = cell.querySelector('.workspace-status, .contact-tag');
                    if (existing) {
                        existing.classList.add('workspace-status');
                        existing.dataset.tone = statusTone(existing.textContent);
                    } else if (cell.textContent.trim() && cell.textContent.trim() !== '—') {
                        const badge = document.createElement('span');
                        badge.className = 'workspace-status';
                        badge.textContent = cell.textContent.trim();
                        badge.dataset.tone = statusTone(badge.textContent);
                        cell.replaceChildren(badge);
                    }
                });
            }
        }
        const wrap = table.closest('.contact-table-wrap');
        if (!wrap) return;
        wrap.setAttribute('role', 'region');
        if (!wrap.hasAttribute('aria-label')) wrap.setAttribute('aria-label', 'Listado de registros');
        if (!wrap.hasAttribute('tabindex')) wrap.tabIndex = 0;
        const cards = !table.closest('.contact-sheet, [data-dashboard]')
            && !!table.querySelector('a.contact-name')
            && !table.querySelector('form, input, select, textarea, [data-report-group], [data-report-group-toggle]')
            && [...table.tBodies].every(body => [...body.rows].every(row => row.cells.length === headings.length && [...row.cells].every(cell => cell.colSpan === 1)));
        table.classList.toggle('workspace-record-list', cards);
        wrap.classList.toggle('workspace-card-list', cards);
        wrap.classList.toggle('workspace-scroll-table', !cards);
        // Preserve table semantics when mobile CSS lays out records as cards.
        if (cards) {
            table.setAttribute('role', 'table');
            table.tHead.setAttribute('role', 'rowgroup');
            for (const row of table.tHead.rows) {
                row.setAttribute('role', 'row');
                for (const cell of row.cells) cell.setAttribute('role', 'columnheader');
            }
            for (const body of table.tBodies) {
                body.setAttribute('role', 'rowgroup');
                for (const row of body.rows) {
                    row.setAttribute('role', 'row');
                    for (const cell of row.cells) cell.setAttribute('role', 'cell');
                }
            }
        }
    }

    function enhanceActions(root) {
        for (const button of root.querySelectorAll('.contact-button')) {
            const ownAction = button.name === 'action' ? button.value : '';
            const action = ownAction || button.form?.querySelector('input[type="hidden"][name="action"]')?.value || '';
            const danger = /^(cancel_|reverse_|delete_)/.test(action) || button.hasAttribute('data-treasury-dialog-open');
            button.classList.toggle('workspace-danger', danger);
            if (danger && button.form && !button.form.querySelector('button:not(.workspace-danger), a')) {
                button.form.classList.add('workspace-danger-form');
            }
        }
        for (const actions of root.querySelectorAll('.document-actions')) {
            if (!actions.hasAttribute('role')) actions.setAttribute('role', 'group');
            if (!actions.hasAttribute('aria-label')) actions.setAttribute('aria-label', 'Acciones del documento');
        }
    }

    function enhance(root) {
        const tables = new Set(root.querySelectorAll('.contact-table'));
        if (root.matches?.('.contact-table')) tables.add(root);
        for (const table of tables) enhanceTable(table);
        for (const step of root.querySelectorAll('.quotation-status li')) step.dataset.tone = statusTone(step.textContent);
        enhanceActions(root);
    }
    enhance(document);
    document.addEventListener('live-filters:updated', event => enhance(event.target));

    // Product lines and live reports can add rows after the initial render.
    const pendingTables = new Set();
    let scheduled = false;
    const observer = new MutationObserver(records => {
        for (const record of records) {
            for (const node of record.addedNodes) {
                if (node.nodeType !== 1) continue;
                if (node.matches('tr, tbody, table')) {
                    const table = node.matches('table') ? node : node.closest('table');
                    if (table?.matches('.contact-table')) pendingTables.add(table);
                }
                for (const table of node.querySelectorAll('.contact-table')) pendingTables.add(table);
            }
        }
        if (!pendingTables.size || scheduled) return;
        scheduled = true;
        requestAnimationFrame(() => {
            for (const table of pendingTables) if (table.isConnected) enhanceTable(table);
            pendingTables.clear(); scheduled = false;
        });
    });
    const workspace = document.querySelector('.module-page');
    if (workspace) observer.observe(workspace, { childList: true, subtree: true });

    let errorIndex = 0;
    const errors = new WeakMap();
    function revealField(field) {
        for (let parent = field.parentElement; parent; parent = parent.parentElement) {
            if (parent.matches('details')) parent.open = true;
            if (parent.matches('[data-notebook-page]') && parent.hidden) {
                const notebook = parent.closest('[data-notebook]');
                const tab = [...(notebook?.querySelectorAll('[data-notebook-tabs] a') || [])]
                    .find(link => link.getAttribute('href') === '#' + parent.id);
                tab?.click();
            }
        }
    }
    function validationText(field) {
        const validity = field.validity;
        if (validity.customError) return field.validationMessage;
        if (validity.valueMissing) return field.tagName === 'SELECT' ? 'Selecciona una opción para continuar.' : 'Completa este campo para continuar.';
        if (validity.typeMismatch && field.type === 'email') return 'Escribe un correo válido, por ejemplo nombre@correo.com.';
        if (validity.typeMismatch && field.type === 'url') return 'Escribe una dirección completa, por ejemplo https://ejemplo.com.';
        if (validity.tooShort) return `Escribe al menos ${field.minLength} caracteres.`;
        if (validity.tooLong) return `Usa un máximo de ${field.maxLength} caracteres.`;
        return field.validationMessage;
    }
    function showError(field) {
        if (!field.willValidate || field.validity.valid) return clearError(field);
        let error = errors.get(field);
        if (!error) {
            const message = document.createElement('span');
            do { message.id = 'workspace-field-error-' + (++errorIndex); } while (document.getElementById(message.id));
            message.className = 'workspace-field-error';
            message.setAttribute('aria-live', 'polite');
            error = { message, previousInvalid: field.getAttribute('aria-invalid') };
            errors.set(field, error);
            field.insertAdjacentElement('afterend', message);
            const describedBy = new Set((field.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean));
            describedBy.add(message.id);
            field.setAttribute('aria-describedby', [...describedBy].join(' '));
        }
        error.message.textContent = validationText(field);
        field.setAttribute('aria-invalid', 'true');
    }
    function clearError(field) {
        const error = errors.get(field);
        if (!error) return;
        const describedBy = (field.getAttribute('aria-describedby') || '').split(/\s+/).filter(id => id && id !== error.message.id);
        if (describedBy.length) field.setAttribute('aria-describedby', describedBy.join(' '));
        else field.removeAttribute('aria-describedby');
        if (error.previousInvalid === null) field.removeAttribute('aria-invalid');
        else field.setAttribute('aria-invalid', error.previousInvalid);
        error.message.remove(); errors.delete(field);
    }
    const pendingForms = new Set();
    document.addEventListener('invalid', event => {
        const field = event.target;
        if (!field.closest('.module-page') || !field.matches('input, select, textarea')) return;
        event.preventDefault(); // Inline messages replace the browser's tooltip.
        showError(field);
        const form = field.form;
        if (!form || pendingForms.has(form)) return;
        pendingForms.add(form);
        queueMicrotask(() => {
            pendingForms.delete(form);
            const first = [...form.elements].find(control => control.willValidate && !control.validity.valid);
            if (!first) return;
            // Reveal the first error after the complete validation pass, so
            // invalid fields in another tab cannot hide the focused control.
            revealField(first);
            if (first.getClientRects().length) first.focus();
        });
    }, true);
    for (const eventName of ['input', 'change']) document.addEventListener(eventName, event => {
        const field = event.target;
        if (!errors.has(field)) return;
        // Run after the existing business validators update custom validity.
        queueMicrotask(() => field.validity.valid || !field.willValidate ? clearError(field) : showError(field));
    });
    document.addEventListener('reset', event => {
        if (!event.target.matches('form')) return;
        for (const field of event.target.elements) clearError(field);
    });
})();
