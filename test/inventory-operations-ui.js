const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const events = {};
const submitButton = { disabled: false };
const help = { hidden: true };
const validation = {
    querySelector: selector => selector === 'button' ? submitButton : help,
    addEventListener: (event, handler) => { events['validate:' + event] = handler; }
};
// Delivery rows have no availability cell. Updating the form must still work.
const row = { querySelector: () => null };
const body = { children: [row], querySelectorAll: () => [row] };
const form = {
    dataset: {},
    querySelector(selector) {
        if (selector === '[data-operation-lines]') return body;
        if (selector === '[name="source_location_id"]') return { value: '1' };
        return null;
    },
    addEventListener: (event, handler) => { events[event] = handler; }
};
const document = { querySelector(selector) {
    if (selector === '[data-operation-form]') return form;
    if (selector === '[data-operation-validate]') return validation;
    if (selector === '[data-operation-stock]') return { textContent: '[]' };
    return null;
} };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../js/inventory-operations.js'), 'utf8'), { document });
events.change();
assert.equal(submitButton.disabled, true);
assert.equal(help.hidden, false);
submitButton.disabled = false;
events['validate:submit']();
assert.equal(submitButton.disabled, true);
const readonlyEvents = {};
form.dataset.permissionReadonly = 'true';
form.addEventListener = (event, handler) => { readonlyEvents[event] = handler; };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../js/inventory-operations.js'), 'utf8'), { document });
assert.deepEqual(readonlyEvents, {}, 'La ficha de consulta no debe habilitar edición de operaciones.');
console.log('PASS: delivery without availability cell, dirty protection and submit handler.');
