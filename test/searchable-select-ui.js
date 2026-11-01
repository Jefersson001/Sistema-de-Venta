// Real Chromium checks, isolated from business data and the user's browser profile.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {spawn} = require('node:child_process');

(async () => {
    const chrome = process.env.NEXO_CHROME || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'nexo-select-test-'));
    const child = spawn(chrome, ['--headless=new', '--disable-gpu', '--no-sandbox', '--remote-debugging-port=0', '--no-first-run', '--no-default-browser-check', '--user-data-dir=' + profile, 'about:blank'], {windowsHide: true, stdio: 'ignore'});
    let ws;
    try {
        const portFile = path.join(profile, 'DevToolsActivePort');
        for (let i = 0; !fs.existsSync(portFile) && i < 100; i++) await new Promise(resolve => setTimeout(resolve, 100));
        const port = fs.readFileSync(portFile, 'utf8').split('\n')[0];
        const target = await (await fetch('http://127.0.0.1:' + port + '/json/new?about:blank', {method: 'PUT'})).json();
        ws = new WebSocket(target.webSocketDebuggerUrl);
        await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject; });
        let id = 0;
        const pending = new Map(), errors = [];
        ws.onmessage = event => {
            const message = JSON.parse(event.data);
            if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.text);
            if (pending.has(message.id)) { const {resolve, reject, timer} = pending.get(message.id); pending.delete(message.id); clearTimeout(timer); message.error ? reject(Error(message.error.message)) : resolve(message.result); }
        };
        const call = (method, params = {}) => new Promise((resolve, reject) => {
            const key = ++id, timer = setTimeout(() => reject(Error('CDP timeout: ' + method)), 10000);
            pending.set(key, {resolve, reject, timer}); ws.send(JSON.stringify({id: key, method, params}));
        });
        await call('Runtime.enable');
        const evaluate = async expression => {
            const result = await call('Runtime.evaluate', {expression, awaitPromise: true, returnByValue: true});
            if (result.exceptionDetails) throw Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
            return result.result.value;
        };
        const css = fs.readFileSync(path.join(__dirname, '../css/searchable-select.css'), 'utf8');
        await evaluate(`document.head.innerHTML='<meta name="viewport" content="width=device-width,initial-scale=1">';`);
        await evaluate(`document.head.innerHTML = '<style>' + ${JSON.stringify(css)} + '</style>'; document.body.className='module-workspace'; document.body.innerHTML = '<main class="module-page"><form class="contact-sheet"><label>Cliente *<select required name="customer_id"><option value="">Selecciona un cliente</option><option value="1">José Pérez</option><option value="2">María López</option><option value="3" disabled>Cliente bloqueado</option></select></label><fieldset disabled><label>Moneda<select name="currency"><option>USD</option></select></label></fieldset><button>Guardar</button></form></main>'; window.changes=0; document.querySelector('select').addEventListener('change',()=>window.changes++);`);
        await evaluate(fs.readFileSync(path.join(__dirname, '../js/searchable-select.js'), 'utf8'));
        const result = await evaluate(`(async () => {
            const check = (condition, message) => { if(!condition) throw Error(message); };
            const wait = () => new Promise(resolve => setTimeout(resolve, 30));
            const select=document.querySelector('select'), input=document.querySelector('[role=combobox]'), form=select.form;
            const type = value => { input.value=value; input.dispatchEvent(new Event('input',{bubbles:true})); };
            const key = value => input.dispatchEvent(new KeyboardEvent('keydown',{key:value,bubbles:true,cancelable:true}));
            check(document.querySelectorAll('[role=combobox]').length===2,'All single selects enhanced');
            check(document.querySelector('fieldset input').disabled,'Disabled fieldset preserved');
            input.focus(); input.click(); type('jose per');
            check(document.querySelectorAll('[role=option]').length===1,'Accent-insensitive multiword search');
            check(select.value===''&&changes===0,'Typing does not change the business value');
            key('Enter'); check(select.value==='1'&&changes===1,'Enter selects once and runs business listeners');
            check(new FormData(form).get('customer_id')==='1','Original ID submitted');
            check([...new FormData(form)].length===1,'Search inputs do not add submitted fields');
            input.click(); type('no existe'); check(document.querySelector('.searchable-select-popup:not([hidden])').textContent.includes('Sin coincidencias'),'Empty results message');
            key('Escape'); check(input.value==='José Pérez'&&select.value==='1','Escape restores selection');
            input.click(); type('lopez'); const option=document.querySelector('[role=option]');
            option.dispatchEvent(new PointerEvent('pointerdown',{bubbles:true,cancelable:true})); option.click();
            check(select.value==='2'&&changes===2,'Pointer selection runs change');
            select.value='1'; select.dispatchEvent(new Event('change',{bubbles:true})); check(input.value==='José Pérez','Programmatic selection sync');
            const newOption=new Option('Ángel nuevo','4'); select.add(newOption); await wait(); input.click(); type('angel');
            check(document.querySelector('[role=option]').textContent==='Ángel nuevo','Updated catalogs searchable');
            key('ArrowDown'); key('Enter'); check(select.value==='4','Arrow and Enter navigation');
            const dynamic=document.createElement('select'); dynamic.name='product'; dynamic.innerHTML='<option value="8">Producto nuevo</option>'; form.append(dynamic); await wait();
            check(dynamic.parentElement.querySelector('[role=combobox]'),'Dynamic product rows enhanced');
            dynamic.parentElement.remove(); await wait(); check(document.querySelectorAll('.searchable-select-popup').length===2,'Removed rows cleaned up');
            select.value=''; select.dispatchEvent(new Event('change',{bubbles:true})); form.reportValidity(); await wait();
            check(document.activeElement===input,'Invalid required select focuses visible search');
            form.reset(); await wait(); check(input.value==='Selecciona un cliente','Reset restores label');
            document.querySelector('fieldset').disabled=false; await wait(); check(!document.querySelector('fieldset input').disabled,'Dynamic disabled state sync');
            return true;
        })()`);
        assert.equal(result, true);
        await call('Emulation.setDeviceMetricsOverride', {width: 390, height: 844, deviceScaleFactor: 1, mobile: true});
        assert.equal(await evaluate(`(() => { const input=document.querySelector('[role=combobox]'); input.click(); const rect=document.querySelector('.searchable-select-popup:not([hidden])').getBoundingClientRect(); return rect.left>=0 && rect.right<=window.innerWidth; })()`), true);
        assert.deepEqual(errors, []);
        console.log('PASS: browser search, accents, keyboard, pointer, submitted IDs, validation, reset, dynamic rows, disabled fields and mobile popup.');
        await call('Browser.close');
    } finally {
        ws?.close(); child.kill();
        await new Promise(resolve => setTimeout(resolve, 500));
        // This exact directory was created above solely for this browser test.
        if (path.dirname(profile) === path.resolve(os.tmpdir()) && path.basename(profile).startsWith('nexo-select-test-')) fs.rmSync(profile, {recursive: true, force: true, maxRetries: 5, retryDelay: 200});
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
