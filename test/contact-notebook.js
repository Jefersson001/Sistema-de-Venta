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
const salesperson = { value: '42', disabled: false };
const field = { ...element(), querySelector: () => salesperson };
const help = element();
const form = { querySelector: selector => selector === '[data-salesperson-field]' ? field : help };
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
contactType.selectedOptions[0].dataset.contactKind = 'employee';
contactType.events.change();
assert.equal(field.hidden, true);
assert.equal(salesperson.disabled, true);
assert.equal(help.hidden, false);
contactType.selectedOptions[0].dataset.contactKind = 'client';
contactType.events.change();
assert.equal(field.hidden, false);
assert.equal(salesperson.disabled, false);
assert.equal(salesperson.value, '42');
console.log('PASS: notebook tabs, keyboard navigation and salesperson visibility.');
