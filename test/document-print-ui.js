const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
(async () => {
    const root = path.join(__dirname, '..'), fixtures = process.argv[2];
    const server = http.createServer((req, res) => {
        let file;
        if (/^\/(commercial|stock)\.html$/.test(req.url)) file = path.join(fixtures, req.url.slice(1));
        else if (req.url.startsWith('/project/css/document-print.css')) {file = path.join(root, 'css/document-print.css'); res.setHeader('Content-Type', 'text/css');}
        else if (req.url === '/project/js/document-print.js') {file = path.join(root, 'js/document-print.js'); res.setHeader('Content-Type', 'application/javascript');}
        if (!file) {res.writeHead(404); res.end(); return;}
        res.end(fs.readFileSync(file));
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const target = await (await fetch('http://127.0.0.1:9343/json/new?about:blank', {method: 'PUT'})).json();
    const socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise(resolve => {socket.onopen = resolve;});
    let next = 0, load;
    const requests = new Map(), errors = [];
    socket.onmessage = ({data}) => {
        const message = JSON.parse(data);
        if (message.method === 'Page.loadEventFired') load?.();
        if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.text);
        const entry = requests.get(message.id);
        if (entry) {requests.delete(message.id); clearTimeout(entry.timer); message.error ? entry.reject(new Error(message.error.message)) : entry.resolve(message.result);}
    };
    const call = (method, params = {}) => new Promise((resolve, reject) => {
        const id = ++next, timer = setTimeout(() => reject(new Error('Timeout: ' + method)), 15000);
        requests.set(id, {resolve, reject, timer}); socket.send(JSON.stringify({id, method, params}));
    });
    const evaluate = async expression => {
        const result = await call('Runtime.evaluate', {expression, returnByValue: true, awaitPromise: true});
        if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description);
        return result.result.value;
    };
    try {
        await call('Page.enable'); await call('Runtime.enable');
        for (const width of [1440, 390]) {
            await call('Emulation.setDeviceMetricsOverride', {width, height: 1050, deviceScaleFactor: 1, mobile: width === 390});
            for (const kind of ['commercial', 'stock']) {
                const loaded = new Promise(resolve => {load = resolve;});
                await call('Page.navigate', {url: 'http://127.0.0.1:' + server.address().port + '/' + kind + '.html'}); await loaded;
                await evaluate('document.fonts.ready');
                assert.ok(await evaluate('document.documentElement.scrollWidth') <= width, 'La página desborda en móvil.');
                assert.equal(await evaluate('getComputedStyle(document.querySelector(".document-paper")).backgroundColor'), 'rgb(255, 255, 255)');
                assert.equal(await evaluate('(()=>{let printed=false;window.print=()=>{printed=true};document.querySelector("[data-print-document]").click();return printed})()'), true);
                const screenshot = await call('Page.captureScreenshot', {format: 'png', captureBeyondViewport: true});
                fs.writeFileSync(path.join(fixtures, kind + '-' + width + '.png'), Buffer.from(screenshot.data, 'base64'));
                if (width === 1440) {
                    const pdf = await call('Page.printToPDF', {printBackground: true, preferCSSPageSize: true});
                    fs.writeFileSync(path.join(fixtures, kind + '.pdf'), Buffer.from(pdf.data, 'base64'));
                    await evaluate('(()=>{const body=document.querySelector("tbody"),row=body.rows[0];for(let i=0;i<100;i++)body.append(row.cloneNode(true))})()');
                    const longPdf = await call('Page.printToPDF', {printBackground: true, preferCSSPageSize: true});
                    const bytes = Buffer.from(longPdf.data, 'base64');
                    assert.ok((bytes.toString('latin1').match(/\/Type\s*\/Page\b/g) || []).length >= 3, 'No genera PDF de varias páginas.');
                    fs.writeFileSync(path.join(fixtures, kind + '-multipage.pdf'), bytes);
                }
            }
        }
        assert.deepEqual(errors, []);
        console.log('PASS: formatos comercial e inventario, escritorio/móvil, impresión, PDF A4 y documentos de varias páginas.');
    } finally {await call('Page.close').catch(() => {}); socket.close(); server.closeAllConnections(); await new Promise(resolve => server.close(resolve));}
})().catch(error => {console.error(error); process.exitCode = 1;});
