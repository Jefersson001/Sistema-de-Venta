const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');

function element() {
    return { attributes: {}, events: {}, hidden: false,
        setAttribute(key, value) { this.attributes[key] = value; },
        addEventListener(key, listener) { this.events[key] = listener; },
        focus() { this.focused = true; } };
}

const tabs = Array.from({ length: 4 }, element);
const pages = Array.from({ length: 4 }, (_, index) => ({ ...element(), id: 'page-' + index }));
const tabList = { ...element(), querySelectorAll: () => tabs };
const notebook = { querySelector: () => tabList, querySelectorAll: () => pages };
const salesperson = { ...element(), value: '42', disabled: false, selectedOptions: [{ dataset: { storageLabel: 'Estante / Principal' } }] };
const field = { ...element(), querySelector: () => salesperson };
const help = element();
const storageSelect = { ...element(), value: '1', disabled: true };
const sellerStorage = { ...element(), querySelector: selector => selector === '[data-related-warehouse]' ? storageSelect : null };
const formFields = { '[data-salesperson-field]': field, '[data-salesperson-help]': help, '[data-storage-seller-field]': sellerStorage,
};
const form = { querySelector: selector => formFields[selector] ?? null };
const contactType = { ...element(), selectedOptions: [{ dataset: { contactKind: 'client' } }], closest: () => form };
const document = { querySelector: () => null, addEventListener() {},
    querySelectorAll(selector) {
        if (selector === '[data-notebook]') return [notebook];
        if (selector === '[data-contact-type]') return [contactType];
        return [];
    } };

vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../js/app.js'), 'utf8'), { document });
assert.deepEqual(pages.map(page => page.hidden), [false, true, true, true]);
assert.equal(tabs[0].attributes['aria-selected'], 'true');
assert.equal(tabList.attributes.role, 'tablist');
tabs[3].events.click({ preventDefault() {} });
assert.deepEqual(pages.map(page => page.hidden), [true, true, true, false]);
tabs[3].events.keydown({ key: 'ArrowRight', preventDefault() {} });
assert.equal(pages[0].hidden, false);
assert.equal(tabs[0].focused, true);
tabs[0].events.keydown({ key: 'End', preventDefault() {} });
assert.equal(pages[3].hidden, false);
tabs[3].events.keydown({ key: 'Home', preventDefault() {} });
assert.equal(pages[0].hidden, false);
assert.equal(field.hidden, false);
assert.equal(sellerStorage.hidden, true);
assert.equal(storageSelect.disabled, true);
contactType.selectedOptions[0].dataset.contactKind = 'employee';
contactType.events.change();
assert.equal(field.hidden, true);
assert.equal(salesperson.disabled, true);
assert.equal(help.hidden, false);
assert.equal(sellerStorage.hidden, true);
contactType.selectedOptions[0].dataset.contactKind = 'client';
contactType.events.change();
assert.equal(field.hidden, false);
assert.equal(salesperson.disabled, false);
assert.equal(salesperson.value, '42');
contactType.selectedOptions[0].dataset.contactKind = 'salesperson';
contactType.events.change();
assert.equal(sellerStorage.hidden, false);
assert.equal(storageSelect.disabled, false);
assert.equal(storageSelect.value, '1');
contactType.selectedOptions[0].dataset.contactKind = 'client';
contactType.events.change();
assert.equal(sellerStorage.hidden, true);
assert.equal(storageSelect.disabled, true);
console.log('PASS: notebook tabs, keyboard navigation and salesperson visibility.');
