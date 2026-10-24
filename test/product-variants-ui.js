const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
(async () => {
    const server = http.createServer((request, response) => {
        const match = /^\/(list|new|parent|variant)\.html$/.exec(request.url);
        if (!match) { response.writeHead(404); response.end(); return; }
        response.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'});
        response.end(fs.readFileSync(path.join(process.argv[2], match[1] + '.html')));
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const origin = 'http://127.0.0.1:' + server.address().port;
    const target = await (await fetch('http://127.0.0.1:' + (process.env.NEXO_CDP_PORT || '9340') + '/json/new?about:blank', {method: 'PUT'})).json();
    const socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
    let id = 0, loaded;
    const pending = new Map(), errors = [];
    socket.onmessage = event => {
        const message = JSON.parse(event.data);
        if (message.method === 'Page.loadEventFired' && loaded) { const resolve = loaded; loaded = null; resolve(); }
        if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.text);
        const request = pending.get(message.id);
        if (!request) return;
        pending.delete(message.id); clearTimeout(request.timeout);
        if (message.error) request.reject(new Error(message.error.message)); else request.resolve(message.result);
    };
    const call = (method, params = {}) => new Promise((resolve, reject) => {
        const key = ++id, timeout = setTimeout(() => { pending.delete(key); reject(new Error('Timeout ' + method)); }, 15000);
        pending.set(key, {resolve, reject, timeout}); socket.send(JSON.stringify({id: key, method, params}));
    });
    const evaluate = async expression => {
        const result = await call('Runtime.evaluate', {expression, returnByValue: true, awaitPromise: true});
        if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
        return result.result.value;
    };
    try {
        await call('Page.enable'); await call('Runtime.enable');
        fs.mkdirSync(process.argv[3], {recursive: true});
        for (const width of [1440, 390]) {
            await call('Emulation.setDeviceMetricsOverride', {width, height: 900, deviceScaleFactor: 1, mobile: width === 390});
            for (const view of ['list', 'new', 'parent', 'variant']) {
                console.log('CHECK variants ' + view + ' ' + width);
                const navigation = new Promise((resolve, reject) => {
                    const timeout = setTimeout(() => reject(new Error('Navigation timeout')), 5000);
                    loaded = () => { clearTimeout(timeout); resolve(); };
                });
                await call('Page.navigate', {url: origin + '/' + view + '.html'}); await navigation;
                await evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))');
                assert.ok(await evaluate('document.documentElement.scrollWidth') <= width, 'Page overflows in ' + view);
                assert.ok(await evaluate('!!document.querySelector(".module-menu-options a[href=\\"variantes.php\\"]")'));
                if (view === 'new') {
                    const form = await evaluate(`(() => {
                        const form=document.querySelector('form.contact-sheet');form.querySelector('[name=name]').value='Torta de vainilla';
                        return {valid:form.checkValidity(), parent:form.querySelector('[name=parent_product_id]').value,
                            price:form.querySelector('[name=sale_price]').value, inputs:[...form.querySelectorAll('[required]')].map(input=>input.name),
                            tabs:[...form.querySelectorAll('[data-notebook-tabs] a')].map(tab=>tab.textContent.trim()),cost:!!form.querySelector('[name=cost_price]')};
                    })()`);
                    assert.equal(form.valid, true); assert.ok(form.parent); assert.equal(form.price, '10.00');assert.equal(form.cost,true);
                    assert.deepEqual(form.tabs,['Información general','Variantes','Ventas','Compras','Inventario','Contabilidad']);
                    assert.deepEqual(form.inputs, ['parent_product_id', 'name']);
                }
                if (view === 'parent') {
                    await evaluate('document.querySelector("#product-tab-variants").click()');
                    assert.equal(await evaluate('getComputedStyle(document.querySelector("#product-page-variants")).display') === 'none', false);
                    assert.ok(await evaluate('document.querySelector("#product-page-variants").textContent.includes("Torta de chocolate")'));
                    assert.equal(await evaluate('getComputedStyle(document.querySelector(".contact-form-actions")).position'),'static','Keep product variants clear of the save actions.');
                }
                if (view === 'variant') {
                    assert.ok(await evaluate('document.querySelector("#product-title").textContent.includes("Editar variante")'));
                    assert.equal(await evaluate('document.querySelectorAll("[data-notebook-tabs] a").length'),6);
                }
                await evaluate('window.scrollTo(0,0)');
                const png = await call('Page.captureScreenshot', {format: 'png', captureBeyondViewport: true});
                fs.writeFileSync(path.join(process.argv[3], view + '-' + width + '.png'), Buffer.from(png.data, 'base64'));
                await evaluate('document.querySelectorAll("[data-unsaved-changes]").forEach(form=>window.nexoUnsavedChanges.allowNavigation(form))');
            }
        }
        assert.deepEqual(errors, []);
        console.log('PASS variants browser: menus, parent/variant views, short creation, responsive layout and no runtime errors.');
    } finally {
        server.closeAllConnections(); await call('Page.close').catch(() => {}); socket.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
