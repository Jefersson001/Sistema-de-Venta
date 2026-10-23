const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../js/multi-company.js'), 'utf8');

function browser(responseScope = 'current', status = 200) {
    const events = {}, requests = [];
    let reloads = 0;
    const context = {
        nexoCompanyScope: 'current', URL, Headers, Request,
        location: { href: 'http://localhost/project/modules/contactos/index.php', origin: 'http://localhost', pathname: '/project/modules/contactos/index.php', reload() { reloads++; } },
        document: { addEventListener(name, handler) { events['document:' + name] = handler; } },
        setTimeout(handler) { context.pendingApply = handler; return 1; }, clearTimeout() { context.pendingApply = null; },
        addEventListener(name, handler) { events[name] = handler; },
        localStorage: { setItem() {}, getItem() { return 'current'; } },
        fetch: async (input, options) => {
            requests.push({ input, options });
            return { status, redirected: false, headers: new Headers({ 'X-Nexo-Company-Scope': responseScope }) };
        },
    };
    context.window = context; vm.runInNewContext(source, context);
    return { context, requests, events, reloads: () => reloads };
}

(async () => {
    const valid = browser();
    await valid.context.fetch('/project/modules/dashboard/resumen.php');
    assert.equal(valid.requests[0].options.headers.get('X-Nexo-Company-Scope'), 'current');
    assert.equal(valid.reloads(), 0);
    await valid.context.fetch('https://example.com/', { headers: { Example: 'value' } });
    assert.equal(valid.requests[1].options.headers.Example, 'value');
    assert.equal(valid.requests[1].options.headers['X-Nexo-Company-Scope'], undefined);
    const stale = browser('another-company');
    await assert.rejects(stale.context.fetch('/project/modules/dashboard/resumen.php'));
    assert.equal(stale.reloads(), 1, 'A stale company response must not reach the dashboard.');
    const denied = browser('current', 403);
    await assert.rejects(denied.context.fetch('/project/modules/dashboard/resumen.php'));
    assert.equal(denied.reloads(), 1);
    const tabs = browser();
    tabs.events.storage({ key: 'nexo-company-scope', newValue: 'another-company' });
    assert.equal(tabs.reloads(), 1, 'Changing companies must invalidate other tabs.');
    const menu = browser(), handlers = {}, primary = { value: '1' };
    const boxes = [{ value: '1', checked: true }, { value: '2', checked: false }];
    let submissions = 0;
    const selector = {
        querySelector() { return primary; }, querySelectorAll() { return boxes; },
        addEventListener(name, handler) { handlers[name] = handler; }, requestSubmit() { submissions++; },
    };
    menu.context.document.querySelector = query => query === '[data-company-selector]' ? selector : null;
    menu.events['document:DOMContentLoaded']();
    boxes[0].checked = false; handlers.change({ target: boxes[0] });
    assert.equal(boxes[0].checked, true, 'The last selected company must remain selected.');
    boxes[1].checked = true; handlers.change({ target: boxes[1] });
    assert.equal(primary.value, '1', 'Checking a second company only adds consultation.');
    menu.context.pendingApply(); assert.equal(submissions, 1);
    handlers.click({ target: { closest() { return { value: '2' }; } }, preventDefault() {} });
    assert.equal(primary.value, '2', 'Clicking a company name changes the principal.');
    assert.equal(boxes[0].checked, true, 'Other selected companies remain available for consultation.');
    boxes[1].checked = false; handlers.change({ target: boxes[1] });
    assert.equal(primary.value, '1', 'Removing the principal automatically promotes a selected company.');
    console.log('PASS: contexto fetch, respuestas obsoletas, acceso revocado, sincronización de pestañas y ausencia de datos de empresa en solicitudes externas.');
})().catch(error => { console.error(error); process.exitCode = 1; });
