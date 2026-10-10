const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const assert = require('node:assert/strict');

function node(value = '') {
    return { value, hidden: true, disabled: false, textContent: '', events: {},
        addEventListener(event, listener) { this.events[event] = listener; }, focus() { this.focused = true; } };
}

function select(options, value = '') {
    const element = node(value);
    element.options = options;
    Object.defineProperty(element, 'selectedOptions', { get: () => options.filter(option => option.value === element.value) });
    return element;
}

let rows = [];
function row(index, productId = '', quantity = '1', price = '0.00') {
    const fields = {
        '[data-line-product]': select([{ value: '', dataset: {} }, { value: '1', dataset: { price: '19.99', tax: '7' } }, { value: '2', dataset: { price: '0.10', tax: '7' } }], productId),
        '[data-line-quantity]': node(quantity), '[data-line-price]': node(price),
        '[data-line-tax]': select([{ value: '', dataset: {} }, { value: '7', dataset: {} }]),
        '[data-line-base]': node(), '[data-line-total]': node(), '[data-remove-line]': node(),
    };
    const named = [fields['[data-line-product]'], fields['[data-line-quantity]'], fields['[data-line-price]'], fields['[data-line-tax]']];
    named.forEach((field, position) => { field.name = `lines[${index}][field${position}]`; });
    return { fields, querySelector: selector => fields[selector], querySelectorAll: () => named,
        remove() { rows = rows.filter(item => item !== this); } };
}

rows = [row(0, '1', '3.125', '19.99'), row(1, '2', '0.050', '0.10')];
const lines = { querySelectorAll: () => rows, append(fragment) { rows.push(fragment.row); } };
const add = node();
const template = { content: { cloneNode() { const newRow = row('__INDEX__'); return { row: newRow, querySelector: () => newRow }; } } };
const customer = select([{ value: '', dataset: {} }, { value: '8', dataset: { address: 'Calle, Ciudad, Estado, 12345, País', salesperson: '9' } }, { value: '10', dataset: { address: 'Otra dirección', salesperson: '' } }], '8');
const seller = select([{ value: '', dataset: {} }, { value: '9', dataset: { storageLabel: 'Estante / Almacén Principal' } }, { value: '11', dataset: { storageLabel: 'Almacén Secundario' } }]);
const storage = { ...node(), dataset: { preserveStorage: 'false' } };
const invoice = node();
const delivery = node();
const baseTotal = node();
const grandTotal = node();
const fields = { '[data-quotation-customer]': customer, '[data-quotation-salesperson]': seller,
    '[data-quotation-lines]': lines, '[data-add-line]': add, '[data-quotation-line-template]': template,
    '[data-invoice-address]': invoice, '[data-delivery-address]': delivery,
    '[data-quotation-base]': baseTotal, '[data-quotation-total]': grandTotal, '[data-quotation-storage]': storage };
const form = { ...node(), querySelector: selector => fields[selector] };
const actionButtons = [node(), node()];
const dirtyHelp = node();
const statusForm = { querySelectorAll: () => actionButtons, querySelector: () => dirtyHelp };
const document = { querySelector: selector => selector === '[data-quotation-form]' ? form : statusForm };

vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../js/sales.js'), 'utf8'), { document });
assert.equal(seller.value, '9');
assert.equal(storage.value, 'Estante / Almacén Principal');
assert.equal(baseTotal.textContent, '62.48');
assert.equal(grandTotal.textContent, '62.48');
assert.equal(rows[1].fields['[data-line-base]'].textContent, '0.01');
assert.equal(add.hidden, false);
customer.value = '8'; customer.events.change();
assert.equal(invoice.value, 'Calle, Ciudad, Estado, 12345, País');
assert.equal(delivery.value, invoice.value);
assert.equal(seller.value, '9');
customer.value = '10'; customer.events.change();
assert.equal(seller.value, '');
assert.equal(storage.value, '');
customer.value = '8'; customer.events.change();
assert.equal(seller.value, '9');
seller.value = '11'; seller.events.change();
assert.equal(storage.value, 'Almacén Secundario');
storage.dataset.preserveStorage = 'true';
seller.value = '9'; seller.events.change();
assert.equal(storage.value, 'Almacén Secundario');
storage.dataset.preserveStorage = 'false';
add.events.click();
assert.equal(rows.length, 3);
assert.ok(rows[2].fields['[data-line-product]'].name.includes('lines[2]'));
assert.equal(rows[2].fields['[data-line-product]'].focused, true);
assert.ok(actionButtons.every(button => button.disabled));
assert.equal(dirtyHelp.hidden, false);
const last = rows[2];
last.fields['[data-line-product]'].value = '1'; last.fields['[data-line-product]'].events.change();
assert.equal(last.fields['[data-line-price]'].value, '19.99');
assert.equal(last.fields['[data-line-tax]'].value, '7');
assert.equal(grandTotal.textContent, '82.47');
last.fields['[data-line-quantity]'].value = '2,5'; form.events.input();
assert.equal(grandTotal.textContent, '112.46');
last.fields['[data-line-quantity]'].value = '-1'; form.events.input();
assert.equal(grandTotal.textContent, '—');
last.fields['[data-remove-line]'].events.click();
assert.equal(rows.length, 2);
assert.equal(grandTotal.textContent, '62.48');
console.log('PASS: addresses, salesperson, product price/tax, add/remove lines, exact totals and unsaved-change protection.');
