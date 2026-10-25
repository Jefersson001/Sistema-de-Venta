const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const {spawnSync} = require('node:child_process');
const path = require('node:path');
const root = path.join(__dirname, '..');

// Exercise the actual checkout serializer with a division larger than max_input_vars.
const checkout = {document: {querySelector: () => null}};
vm.createContext(checkout);
vm.runInContext(fs.readFileSync(path.join(root, 'js/restaurant.js'), 'utf8'), checkout);
const payload = new FormData();
payload.set('action', 'pos_checkout');
for (let person = 0; person < 20; person++) {
    payload.set(`payments[${person}][label]`, `Persona ${person + 1}`);
    for (let item = 1; item <= 100; item++) payload.set(`payments[${person}][items][${item}]`, '0.05');
}
checkout.restaurantPackPayments(payload);
assert.equal([...payload.keys()].length, 2);
const rows = JSON.parse(payload.get('payments_json'));
assert.equal(rows.length, 20);
assert.ok(rows.every(row => Object.keys(row.items).length === 100));
const php = process.env.PHP_BINARY || 'C:/xampp/php/php.exe';
const result = spawnSync(php, ['-r', `require 'php/restaurant-checkout.php'; parse_str(stream_get_contents(STDIN),$input); $data=restaurantCheckoutInput($input); if(count($data['payments'])!==20||count($data['payments'][19]['items'])!==100)exit(1); foreach(['{','null','[]'] as $bad){try{restaurantCheckoutInput(['payments_json'=>$bad]);exit(2);}catch(InvalidArgumentException){}} try{restaurantCheckoutInput(['payments_json'=>'[{}]','payments'=>[]]);exit(3);}catch(InvalidArgumentException){} echo 'PASS PHP payment decoding';`], {cwd: root, input: new URLSearchParams([...payload.entries()]).toString(), encoding: 'utf8'});
assert.equal(result.status, 0, result.stderr);

// Model form events and navigation; execute the production unsaved-change scripts.
const events = {}, handlers = {};
const form = {dataset: {}, values: [['parent_product_id', '1'], ['name', '']], addEventListener: (type, fn) => {handlers[type] = fn;}};
const parent = {value: '1', closest: () => form, addEventListener: (type, fn) => {handlers.parentChange = fn;}};
let confirmCount = 0, navigated = false;
const context = {
    document: {querySelectorAll: () => [form], querySelector: () => parent},
    FormData: class {constructor(value) {this.values = value.values.map(row => [...row]);} entries() {return this.values[Symbol.iterator]();}},
    window: {addEventListener: (type, fn) => {events[type] = fn;}, confirm: () => {confirmCount++; return false;}},
    queueMicrotask, URL, location: {href: 'http://localhost/project/modules/inventario/variantes.php', assign: () => {navigated = true;}}
};
vm.createContext(context);
vm.runInContext(fs.readFileSync(path.join(root, 'js/unsaved-changes.js'), 'utf8'), context);
vm.runInContext(fs.readFileSync(path.join(root, 'js/product-variant-form.js'), 'utf8'), context);
assert.equal(context.window.nexoUnsavedChanges.hasChanges(form), false);
form.values[1][1] = 'Mi variante';
parent.value = '2'; form.values[0][1] = '2';
handlers.parentChange();
assert.equal(confirmCount, 1); assert.equal(navigated, false); assert.equal(parent.value, '1');
let prevented = false;
events.beforeunload({preventDefault: () => {prevented = true;}});
assert.equal(prevented, true);
context.window.confirm = () => true;
parent.value = '2'; handlers.parentChange();
assert.equal(navigated, true);
assert.equal(context.window.nexoUnsavedChanges.hasChanges(form), false);
console.log('PASS: 20 pagos × 100 artículos, decodificación PHP, aviso al salir y cancelación/confirmación de cambio de principal.');
