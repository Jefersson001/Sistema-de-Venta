const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const assert = require('node:assert/strict');

class Element {
    constructor() { this.events = {}; this.children = []; this.attributes = {}; this.dataset = {}; this.hidden = false; this.value = ''; this.textContent = ''; }
    addEventListener(type, listener) { (this.events[type] ??= []).push(listener); }
    dispatchEvent(event) {
        for (const listener of this.events[event.type] ?? []) listener(event);
        if (event.bubbles && this.parent) this.parent.dispatchEvent(event);
    }
    setAttribute(key, value) { this.attributes[key] = value; }
    append(...nodes) { this.children.push(...nodes); }
    replaceChildren(...nodes) { this.children = nodes; }
    checkValidity() { return true; }
    focus() { this.focused = true; }
    querySelector(selector) { return this.selectors?.[selector] ?? null; }
}
const element = () => new Element();
const dashboard = element(), widget = element(), form = element();
widget.parent = dashboard;
const period = element(), from = element(), to = element(), dates = element(), error = element();
period.value = 'today'; from.value = to.value = '2026-10-13'; error.hidden = true;
form.selectors = {'[name="period"]':period,'[name="date_from"]':from,'[name="date_to"]':to,'[data-cash-flow-dates]':dates};
form.requestSubmit = () => form.dispatchEvent({type:'submit', preventDefault(){}});
widget.dataset.cashFlowEndpoint = 'resumen.php';
const keys = ['income','expense','net','balance_start','balance_end'];
const amounts = Object.fromEntries(keys.map(key => [key, element()]));
const cashUpdated = element(), globalUpdated = element(), globalError = element(), rangeLabel = element(), historyLink = element();
globalError.hidden = true;
widget.selectors = {'[data-cash-flow-filter]':form,'[data-cash-flow-error]':error,'[data-cash-flow-updated]':cashUpdated,'[data-cash-flow-history]':historyLink,'[data-cash-flow-range]':rangeLabel,'[data-cash-flow-cards]':element()};
for (const key of keys) widget.selectors[`[data-cash-flow-amount="${key}"]`] = amounts[key];
const metrics = ['sales','purchases','receivables','payables','overdue','unallocated'].map(key => {
    const node = element(); node.dataset.dashboardMetric = key; return node;
});
const alerts = element(), tasks = element(), count = element();
dashboard.selectors = {'[data-dashboard-updated]':globalUpdated,'[data-dashboard-error]':globalError,'[data-dashboard-alerts]':alerts,'[data-dashboard-alert-count]':count,'[data-dashboard-tasks]':tasks};
dashboard.querySelectorAll = () => metrics;
const document = element();
document.hidden = false;
document.querySelectorAll = selector => selector === '[data-dashboard]' ? [dashboard] : selector === '[data-cash-flow]' ? [widget] : [];
document.createElement = element;
document.createTextNode = text => ({textContent:text});
const window = element(); window.location = {href:'http://localhost/project/modules/dashboard/index.php'};
window.history = {pushState(_state, _title, url) { window.location.href = String(url); }};
let poll, reject = false, snapshot, requests = [];
const timers = new Map(); let nextTimer = 0;
const context = {
    document, window, URL, URLSearchParams, AbortController,
    CustomEvent: class { constructor(type, options) { this.type = type; Object.assign(this, options); } },
    setInterval(callback, milliseconds) { assert.equal(milliseconds, 30000); poll = callback; return 1; },
    clearInterval() {},
    setTimeout(callback, milliseconds) { const id = ++nextTimer; timers.set(id, {callback,milliseconds}); return id; },
    clearTimeout(id) { timers.delete(id); },
    async fetch(url) {
        requests.push(url);
        if (reject) throw new Error('Fallo simulado');
        return {ok:true, async json(){ return snapshot; }};
    }
};
vm.createContext(context);
for (const file of ['dashboard.js','cash-flow.js']) vm.runInContext(fs.readFileSync(path.join(__dirname, '../js', file), 'utf8'), context);
function data(sales = '5') {
    return {updated_time:'14:30:00',range:{period:'today',date_from:'2026-10-13',date_to:'2026-10-13',label:'Hoy'},currencies:[{code:'USD',income:'50.00',expense:'30.00',net:'20.00',balance_start:'10.00',balance_end:'30.00'}],dashboard:{available:true,metrics:{sales,purchases:'3',receivables:'45.00 USD',payables:'20.00 USD',overdue:'2',unallocated:'15.00 USD'},alert_count:1,alerts:[{id:7,name:'Producto <script>alert(1)</script>',available:'0.00'}],tasks:[{label:'RECEP/001',detail:'Listo · 2026-10-13',href:'modules/inventario/operaciones.php?type=receipt&id=8'}]}};
}
const settle = () => new Promise(resolve => setImmediate(resolve));
(async () => {
    snapshot = data(); poll(); await settle();
    assert.equal(requests[0], 'resumen.php?period=today');
    assert.deepEqual(metrics.map(node => node.textContent), ['5','3','45.00 USD','20.00 USD','2','15.00 USD']);
    assert.equal(amounts.income.children[0].children[0].textContent, '50.00');
    assert.equal(amounts.balance_end.children[0].children[0].textContent, '30.00');
    assert.equal(alerts.children[0].children[0].textContent, 'Producto <script>alert(1)</script>');
    assert.equal(alerts.children[0].children[0].children.length, 0, 'Los nombres deben mostrarse como texto.');
    assert.equal(tasks.children[0].children[0].href, '../../modules/inventario/operaciones.php?type=receipt&id=8');
    assert.match(globalUpdated.textContent, /14:30:00/);

    period.value = 'custom'; from.value = ''; period.dispatchEvent({type:'change'});
    snapshot = data('8'); poll(); await settle();
    assert.equal(metrics[0].textContent, '8', 'Los pendientes deben seguir actualizándose al escribir fechas.');
    assert.equal(requests.at(-1), 'resumen.php?period=today', 'Debe consultarse el último período aplicado.');
    assert.equal(period.value, 'custom'); assert.equal(from.value, ''); assert.equal(dates.hidden, false);
    from.value = '2026-10-14'; to.value = '2026-10-13'; from.dispatchEvent({type:'input'});
    poll(); await settle();
    assert.equal(error.hidden, false, 'La actualización no debe ocultar un error del rango en edición.');

    const retained = tasks.children;
    reject = true; poll(); await settle();
    assert.equal(metrics[0].textContent, '8'); assert.equal(tasks.children, retained);
    assert.equal(globalError.hidden, false); assert.match(globalError.textContent, /Fallo simulado/);
    reject = false; snapshot = data('9'); snapshot.dashboard.tasks = []; snapshot.dashboard.alerts = []; snapshot.dashboard.alert_count = 0;
    document.dispatchEvent({type:'visibilitychange'}); await settle();
    assert.equal(metrics[0].textContent, '9'); assert.equal(globalError.hidden, true);
    assert.equal(alerts.children.length, 0); assert.match(tasks.children[0].textContent, /No hay operaciones/);
    assert.match(count.textContent, /No hay productos/);

    const priorRequests = requests.length;
    document.hidden = true; poll(); await settle(); assert.equal(requests.length, priorRequests);
    document.hidden = false;
    from.value = '2026-10-01'; to.value = '2026-10-13'; from.dispatchEvent({type:'input'});
    const debounce = [...timers.values()].find(timer => timer.milliseconds === 400);
    assert.ok(debounce); debounce.callback(); await settle();
    assert.match(requests.at(-1), /period=custom&date_from=2026-10-01&date_to=2026-10-13/);
    assert.match(window.location.href, /period=custom/);
    console.log('PASS: todas las secciones se actualizan automáticamente; fechas en edición, errores, recuperación y texto seguro.');
})().catch(error => { console.error(error); process.exitCode = 1; });
