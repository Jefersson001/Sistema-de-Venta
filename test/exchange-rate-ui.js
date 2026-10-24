const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function fixture(response) {
    const nodes = new Map(['label','date','source'].map(name => [name, { textContent: '', hidden: false }]));
    const classes = new Set();
    const badge = {
        dataset: { rateEndpoint: '/project/tasa.php' }, title: '',
        querySelector(selector) { return nodes.get(selector.match(/data-daily-rate-(\w+)/)[1]); },
        classList: { toggle(name, enabled) { if (enabled) classes.add(name); else classes.delete(name); } },
    };
    let refresh, reloaded = false;
    const context = {
        document: { hidden: false, querySelectorAll: () => [badge], addEventListener() {} },
        window: { nexoCompanyScope: 'current', location: { reload() { reloaded = true; } }, addEventListener() {} },
        AbortSignal, setInterval(fn) { refresh = fn; return 1; }, clearInterval() {},
        fetch: async () => { if (response instanceof Error) throw response; return { ok: true, json: async () => response }; },
    };
    vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname,'../js/exchange-rate.js'),'utf8'), context);
    return { refresh: () => refresh(), nodes, classes, badge, reloaded: () => reloaded };
}
(async () => {
    const rate = fixture({ scope:'current', label:'Tasa del día · 1 USD = 123.45 VES', rate_date:'2026-10-24', source:'BCV', available:true, sync_failed:false, title:'Fecha valor' });
    await rate.refresh();
    assert.equal(rate.nodes.get('label').textContent, '1 USD = 123.45 VES');
    assert.equal(rate.nodes.get('date').textContent, 'Tasa del día · 24/10/2026');
    assert.equal(rate.nodes.get('source').textContent, 'BCV');
    assert.equal(rate.nodes.get('source').hidden, false);
    assert.ok(rate.classes.has('connection-online'));
    const missing = fixture({ scope:'current', label:'Tasa del día: no disponible', source:'Sin tasa', available:false, title:'Sin historial' });
    await missing.refresh();
    assert.equal(missing.nodes.get('label').textContent, 'Sin tasa disponible');
    assert.equal(missing.nodes.get('source').hidden, true);
    assert.ok(missing.classes.has('connection-offline'));
    const stale = fixture({ scope:'other', label:'Otra empresa' });
    await stale.refresh();
    assert.equal(stale.reloaded(), true);
    const failed = fixture(new Error('Sin conexión'));
    failed.nodes.get('label').textContent = '1 USD = 123.45 VES';
    await failed.refresh();
    assert.equal(failed.nodes.get('label').textContent, '1 USD = 123.45 VES');
    assert.match(failed.badge.title, /última tasa/);
    console.log('PASS: rate value, date, source, unavailable state, company changes and retained rate on network failure.');
})().catch(error => { console.error(error); process.exitCode = 1; });
