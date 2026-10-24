const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
(async () => {
    const server = http.createServer((request, response) => {
        const match = /^\/(general|restaurant|group)\.html$/.exec(request.url);
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
            for (const view of ['general', 'restaurant', 'group']) {
                console.log('CHECK settings ' + view + ' ' + width);
                const navigation = new Promise((resolve, reject) => {
                    const timeout = setTimeout(() => reject(new Error('Navigation timeout')), 5000);
                    loaded = () => { clearTimeout(timeout); resolve(); };
                });
                await call('Page.navigate', {url: origin + '/' + view + '.html'}); await navigation;
                await evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))');
                assert.ok(await evaluate('document.documentElement.scrollWidth') <= width, 'Page overflows in ' + view);
                if(view==='restaurant')assert.ok(await evaluate('document.body.textContent.includes("Permitir vuelto")'));
                if(view==='group'){
                    const result=await evaluate(`(() => {
                        const form=document.querySelector('[data-access-group-form]');
                        const templates=JSON.parse(form.querySelector('[data-access-template-data]').textContent);
                        const select=form.querySelector('[data-access-template]');
                        const results=[];
                        for(const key of ['waiter','cashier','manager']){
                            select.value=key;form.querySelector('[data-access-apply-template]').click();
                            const payload=new FormData(form);const expected=Object.keys(templates[key].permissions).sort();
                            const selected=payload.getAll('permissions[]').sort();
                            const ownScope=payload.get('scopes[restaurante.accounts]');
                            results.push({key,expected,selected,ownScope});
                        }
                        const name=form.querySelector('[name=name]');const suggested=name.value;
                        name.value='Equipo de servicio';select.value='cashier';form.querySelector('[data-access-apply-template]').click();
                        return {results,suggested,name:name.value};
                    })()`);
                    for(const template of result.results){
                        assert.deepEqual(template.selected,template.expected,'Template selection '+template.key);
                        assert.equal(template.ownScope,template.key==='waiter'?'own':'all');
                    }
                    assert.equal(result.suggested,'Encargado de restaurante','Keep the suggested name consistent with the template.');
                    assert.equal(result.name,'Equipo de servicio','Do not replace a name entered by the user.');
                }
                await evaluate('window.scrollTo(0,0)');
                const png = await call('Page.captureScreenshot', {format: 'png', captureBeyondViewport: true});
                fs.writeFileSync(path.join(process.argv[3], view + '-' + width + '.png'), Buffer.from(png.data, 'base64'));
            }
        }
        assert.deepEqual(errors, []);
        console.log('PASS settings browser: sections, exact template permissions/scopes, responsive layout and no runtime errors.');
    } finally {
        server.closeAllConnections(); await call('Page.close').catch(() => {}); socket.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
