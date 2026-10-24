// Run through modules-browser.php with a temporary Chrome debugging profile.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

(async () => {
    const fixtures = process.argv[2];
    const output = process.argv[3];
    const endpoint = 'http://127.0.0.1:' + (process.env.NEXO_CDP_PORT || '9337');
    const target = await (await fetch(endpoint + '/json/new?about:blank', { method: 'PUT', signal: AbortSignal.timeout(5000) })).json();
    const ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject; });
    let sequence = 0;
    const pending = new Map();
    const browserErrors = [];
    ws.onmessage = event => {
        const message = JSON.parse(event.data);
        if (message.method === 'Runtime.exceptionThrown') browserErrors.push(message.params.exceptionDetails.text);
        const request = pending.get(message.id);
        if (!request) return;
        pending.delete(message.id); clearTimeout(request.timeout);
        if (message.error) request.reject(new Error(message.error.message));
        else request.resolve(message.result);
    };
    const call = (method, params = {}) => new Promise((resolve, reject) => {
        const id = ++sequence;
        const timeout = setTimeout(() => { pending.delete(id); reject(new Error('Timeout: ' + method)); }, 15000);
        pending.set(id, { resolve, reject, timeout }); ws.send(JSON.stringify({ id, method, params }));
    });
    const evaluate = async expression => {
        const result = await call('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
        if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
        return result.result.value;
    };
    const until = async expression => {
        const deadline = Date.now() + 15000;
        while (Date.now() < deadline) {
            try { if (await evaluate(expression)) return; } catch (error) {
                if (!/context|navigat/i.test(error.message)) throw error;
            }
            await new Promise(resolve => setTimeout(resolve, 100));
        }
        throw new Error('Page condition timed out: ' + expression);
    };
    const navigate = async name => {
        console.log('CHECK ' + name);
        await call('Page.navigate', { url: 'about:blank' });
        const tree = await call('Page.getFrameTree');
        await call('Page.setDocumentContent', { frameId: tree.frameTree.frame.id, html: fs.readFileSync(path.join(fixtures,name+'.html'),'utf8') });
        await until(`document.readyState === 'complete'`);
    };
    const viewport = (width, height, mobile = false) => call('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile });
    const screenshot = async name => {
        if (!output) return;
        fs.mkdirSync(output, { recursive: true });
        await evaluate(`window.scrollTo(0,0); new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))`);
        const capture = await call('Page.captureScreenshot', { captureBeyondViewport: true, format: 'png' });
        fs.writeFileSync(path.join(output, name + '.png'), Buffer.from(capture.data, 'base64'));
    };
    try {
        await call('Page.enable'); await call('Runtime.enable');
        await viewport(1440, 1050);
        await navigate('home');
        assert.equal(await evaluate(`document.body.classList.contains('module-workspace')`), false, 'Home must retain its own design.');
        assert.equal(await evaluate(`!!document.querySelector('.top-navigation-primary .company-selector-icon')`), false);
        assert.equal(await evaluate(`!!document.querySelector('.top-navigation-status .company-selector')`), true);
        assert.equal(await evaluate(`!!(document.querySelector('.exchange-rate-card').compareDocumentPosition(document.querySelector('.company-selector')) & Node.DOCUMENT_POSITION_FOLLOWING)`), true);
        assert.equal(await evaluate(`!!document.querySelector('.user-menu-options a[href$="cambiar-contrasena.php"]')`), false);
        assert.equal(await evaluate(`document.querySelector('.connection-indicator').textContent.trim()`), '');
        assert.equal(await evaluate(`document.querySelector('.connection-indicator').getAttribute('aria-label')`), 'Base de datos conectada');
        assert.equal(await evaluate(`document.querySelector('[data-daily-rate-label]').textContent`), '1 USD = 100 VES');
        await evaluate(`document.querySelector('.company-selector > summary').click()`);
        assert.equal(await evaluate(`document.querySelector('.company-selector').open`), true);
        await screenshot('cabecera-empresas');
        await evaluate(`document.querySelector('.user-menu > summary').click()`);
        assert.equal(await evaluate(`document.querySelector('.company-selector').open`), false);
        await evaluate(`document.querySelector('.brand').click()`);

        await navigate('sale');
        assert.ok(await evaluate(`document.querySelector('[data-line-product]').getBoundingClientRect().width>=280`), 'Product selectors must have readable width.');
        assert.equal(await evaluate(`!!document.querySelector('.module-navigation-link > .workspace-icon')`), true);
        assert.equal(await evaluate(`!!document.querySelector('.transfer-menu > summary > .workspace-icon')`), true);
        await evaluate(`document.querySelectorAll('.module-navigation details > summary')[0].click()`);
        assert.equal(await evaluate(`document.querySelectorAll('.module-navigation details')[0].open`), true);
        await evaluate(`document.querySelectorAll('.module-navigation details > summary')[1].click()`);
        assert.equal(await evaluate(`[...document.querySelectorAll('.module-navigation details')].filter(menu=>menu.open).length`), 1);
        assert.equal(await evaluate(`document.querySelectorAll('.module-navigation details')[1].open`), true);
        await evaluate(`document.querySelector('h1').click()`);
        assert.equal(await evaluate(`[...document.querySelectorAll('.module-navigation details')].some(menu=>menu.open)`), false);
        await evaluate(`const summary=document.querySelector('.module-navigation details > summary');summary.click();summary.focus();summary.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}))`);
        assert.equal(await evaluate(`document.querySelector('.module-navigation details').open`), false);
        assert.equal(await evaluate(`document.activeElement.matches('.module-navigation summary')`), true);
        await evaluate(`document.querySelector('.workspace-details').open=true;document.querySelector('.module-navigation details > summary').click()`);
        assert.equal(await evaluate(`document.querySelector('.workspace-details').open`), true, 'Opening a menu must preserve document sections.');
        await evaluate(`document.querySelector('h1').click()`);
        assert.equal(await evaluate(`document.querySelectorAll('.workspace-form-section').length`), 2);
        await evaluate(`document.querySelector('[data-quotation-form]').reportValidity()`);
        await until(`!!document.querySelector('[name="customer_id"][aria-invalid="true"]')`);
        assert.ok(await evaluate(`document.getElementById(document.querySelector('[name="customer_id"]').getAttribute('aria-describedby')).textContent.includes('Selecciona')`));
        await evaluate(`const c=document.querySelector('[name="customer_id"]');c.value=[...c.options].find(o=>o.value).value;c.dispatchEvent(new Event('change',{bubbles:true}));`);
        await until(`!document.querySelector('[name="customer_id"]').hasAttribute('aria-invalid')`);
        await evaluate(`document.querySelector('#quotation-tab-other').click()`);
        assert.equal(await evaluate(`getComputedStyle(document.querySelector('.workspace-document-summary')).display !== 'none' && !document.querySelector('.workspace-document-summary').closest('[hidden]')`), true, 'Totals must remain visible on another tab.');
        // Multiple invalid tabs must reveal the first error, not the last one.
        await evaluate(`for(const control of document.querySelector('[data-quotation-form]').elements){if(control.willValidate && control.required && !control.value){if(control.tagName==='SELECT')control.value=[...control.options].find(o=>o.value)?.value||'';else control.value='1';}}for(const id of ['quotation-page-lines','quotation-page-other']){const label=document.createElement('label');label.textContent='Campo de prueba';const input=document.createElement('input');input.required=true;input.name='test-'+id;label.append(input);document.getElementById(id).append(label);}document.querySelector('#quotation-tab-other').click();document.querySelector('[data-quotation-form]').reportValidity()`);
        await until(`document.activeElement.name === 'test-quotation-page-lines'`);
        assert.equal(await evaluate(`document.getElementById('quotation-page-lines').hidden`), false);
        await evaluate(`for(const field of document.querySelectorAll('input[name^="test-"]'))field.parentElement.remove();`);
        await screenshot('venta-escritorio');

        await navigate('contact');
        await evaluate(`{const field=document.querySelector('[name="email"]');field.value='correo-invalido';field.reportValidity()}`);
        await until(`!!document.querySelector('[name="email"][aria-invalid="true"]')`);
        assert.ok(await evaluate(`document.querySelector('.workspace-field-error').textContent.includes('correo válido')`));
        await evaluate(`{const field=document.querySelector('[name="email"]');field.value='revision@example.com';field.dispatchEvent(new Event('input',{bubbles:true}));}`);
        await until(`!document.querySelector('[name="email"]').hasAttribute('aria-invalid')`);
        await navigate('contacts');
        await until(`!!document.querySelector('.workspace-record-list')`);
        await screenshot('contactos-escritorio');
        await viewport(390, 844, true);
        assert.equal(await evaluate(`getComputedStyle(document.querySelector('.workspace-record-list')).display`), 'block');
        assert.equal(await evaluate(`document.documentElement.scrollWidth <= 390`), true, 'Mobile lists must not overflow the page.');
        assert.ok(await evaluate(`[...document.querySelectorAll('.workspace-record-list tbody tr:first-child td')].every(cell=>cell.dataset.columnLabel)`));
        await evaluate(`document.querySelector('.company-selector > summary').click()`);
        assert.equal(await evaluate(`(()=>{const box=document.querySelector('[data-company-selector]').getBoundingClientRect();return box.left>=0 && box.right<=390 && box.top>=document.querySelector('.company-selector > summary').getBoundingClientRect().bottom;})()`), true, 'Company menu must open below the icon inside the mobile viewport.');
        await screenshot('empresas-movil');
        await evaluate(`document.querySelector('h1').click()`);
        await screenshot('contactos-movil');
        await navigate('purchase');
        assert.equal(await evaluate(`document.documentElement.scrollWidth <= 390`), true, 'Mobile purchase form must not overflow.');
        assert.equal(await evaluate(`getComputedStyle(document.querySelector('.contact-form-actions')).position`), 'sticky');
        await screenshot('compra-movil');
        await viewport(1440, 1050);
        await navigate('payment');
        assert.equal(await evaluate(`document.querySelectorAll('.workspace-form-section').length`), 3);
        await screenshot('pago-escritorio');
        await navigate('treasury');
        assert.equal(await evaluate(`document.querySelectorAll('.workspace-form-section').length`), 3);
        assert.ok(await evaluate(`!!document.querySelector('[name="destination_account_id"]')`));
        await navigate('emptyproducts');
        assert.ok(await evaluate(`document.querySelector('.workspace-empty > strong').textContent.includes('No hay registros')`));
        assert.equal(await evaluate(`document.querySelector('.workspace-empty-action').getAttribute('href')`), 'index.php?new=1');
        await screenshot('productos-vacios-escritorio');
        await viewport(390,844,true);
        assert.equal(await evaluate(`document.documentElement.scrollWidth<=390`), true, 'Empty lists must fit mobile screens.');
        await screenshot('productos-vacios-movil');
        assert.deepEqual(browserErrors, [], 'No browser JavaScript exceptions.');
        console.log('PASS: real rendered views, desktop/mobile layouts, inline validation, hidden tabs, persistent totals, form sections and unchanged home.');
    } finally {
        await call('Page.close').catch(() => {});
        ws.close();
    }
})().catch(error => { console.error(error.stack); process.exitCode = 1; });
