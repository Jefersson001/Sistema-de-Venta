const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const assert = require('node:assert/strict');

function element(value = '') {
    return { value, events: {}, addEventListener(type, handler) { this.events[type] = handler; } };
}
function dialog(reopen = false) {
    return Object.assign(element(), {
        open: false, openings: 0, closes: 0, cancel: element(),
        showModal() { this.open = true; this.openings++; },
        close() { this.open = false; this.closes++; },
        querySelectorAll() { return [this.cancel]; },
        hasAttribute() { return reopen; },
        getBoundingClientRect() { return { left: 10, right: 100, top: 10, bottom: 100 }; }
    });
}
const paymentDialog = dialog(), creditDialog = dialog(true);
const opener = Object.assign(element(), { dataset: { invoiceWizardOpen: 'payment' } });
const fields = { currency_code: element('USD'), amount: element('20.00'), exchange_rate: element('100') };
const form = { querySelector(selector) { return fields[selector.match(/name="([^"]+)"/)[1]]; } };
const context = {
    document: {
        getElementById() { return paymentDialog; },
        querySelectorAll(selector) {
            if (selector === '[data-invoice-wizard-open]') return [opener];
            return selector === '[data-invoice-wizard]' ? [paymentDialog, creditDialog] : [];
        },
        querySelector(selector) {
            if (selector.includes('register_payment')) return { closest() { return form; } };
            return selector === '[data-invoice-payment-settings]' ? { textContent: JSON.stringify({ primary_code: 'USD', alternate_code: 'VES' }) } : null;
        }
    },
    window: { alert(message) { this.message = message; } }
};
vm.createContext(context);
vm.runInContext(fs.readFileSync(path.join(__dirname, '../js/currency.js'), 'utf8'), context);
vm.runInContext(fs.readFileSync(path.join(__dirname, '../js/invoice-payments.js'), 'utf8'), context);
assert.equal(paymentDialog.open, false);
assert.equal(creditDialog.open, true, 'Server errors should reopen the relevant wizard.');
opener.events.click(); opener.events.click();
assert.equal(paymentDialog.openings, 1, 'Clicking twice must not open an already open dialog.');
paymentDialog.events.click({ target: paymentDialog, clientX: 30, clientY: 30 });
assert.equal(paymentDialog.open, true, 'Clicking dialog padding must not close it.');
paymentDialog.events.click({ target: paymentDialog, clientX: 0, clientY: 30 });
assert.equal(paymentDialog.open, false);
opener.events.click(); paymentDialog.cancel.events.click();
assert.equal(paymentDialog.open, false, 'Cancel must close without submitting a payment.');
fields.currency_code.value = 'VES'; fields.currency_code.events.change();
assert.equal(fields.amount.value, '2000.00');
fields.currency_code.value = 'USD'; fields.currency_code.events.change();
assert.equal(fields.amount.value, '20.00');
fields.exchange_rate.value = ''; fields.currency_code.value = 'VES'; fields.currency_code.events.change();
assert.equal(fields.currency_code.value, 'USD');
assert.equal(fields.amount.value, '20.00');
assert.match(context.window.message, /Configura/);
console.log('PASS: apertura y cierre del asistente, errores del servidor, cancelación y conversión del importe sin perder datos.');
