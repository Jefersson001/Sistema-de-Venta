const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {spawn} = require('node:child_process');
(async () => {
    const base = process.env.NEXO_TEST_BASE_URL, login = process.env.NEXO_TEST_LOGIN, password = process.env.NEXO_TEST_PASSWORD;
    assert.ok(base && login && password, 'Use the isolated PHP payment proofs test runner.');
    const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'nexo-phases-test-'));
    const child = spawn(process.env.NEXO_CHROME || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', ['--headless=new', '--disable-gpu', '--no-sandbox', '--remote-debugging-port=0', '--no-first-run', '--user-data-dir=' + profile, 'about:blank'], {windowsHide:true, stdio:'ignore'});
    let ws;
    try {
        const portFile = path.join(profile, 'DevToolsActivePort');
        for(let i=0; !fs.existsSync(portFile)&&i<100; i++) await new Promise(resolve=>setTimeout(resolve,100));
        const port=fs.readFileSync(portFile,'utf8').split('\n')[0];
        const target=await(await fetch('http://127.0.0.1:'+port+'/json/new?about:blank',{method:'PUT'})).json();
        ws=new WebSocket(target.webSocketDebuggerUrl);
        await new Promise((resolve,reject)=>{ws.onopen=resolve;ws.onerror=reject;});
        let next=0; const pending=new Map(), errors=[];
        ws.onmessage=event=>{const m=JSON.parse(event.data);if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);if(pending.has(m.id)){const p=pending.get(m.id);clearTimeout(p.timer);pending.delete(m.id);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);}};
        const call=(method,params={})=>new Promise((resolve,reject)=>{const id=++next;const timer=setTimeout(()=>reject(Error('CDP timeout '+method)),10000);pending.set(id,{resolve,reject,timer});ws.send(JSON.stringify({id,method,params}));});
        const evaluate=async expression=>{const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;};
        const until=async expression=>{for(let i=0;i<100;i++){try{if(await evaluate(expression))return;}catch{}await new Promise(resolve=>setTimeout(resolve,100));}throw Error('Timed out: '+expression+'; errors: '+errors.join('; '));};
        await call('Runtime.enable'); await call('Page.enable');
        await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});


        const contact=process.env.NEXO_PHASE_CONTACT;assert.ok(contact);
        await call('Page.navigate',{url:base+'/login.php'});
        await until('!!document.querySelector("[name=login]")');
        await evaluate('document.querySelector("[name=login]").value='+JSON.stringify(login)+';document.querySelector("[name=password]").value='+JSON.stringify(password)+';document.querySelector("form").requestSubmit()');
        await until('!location.pathname.endsWith("/login.php")');
        await call('Page.navigate',{url:base+'/modules/contactos/index.php?id='+contact});
        await until('document.readyState==="complete" && !!document.querySelector("[data-add-contact-address]")');
        assert.equal(await evaluate('!!document.querySelector("[name=municipality_id]")'),true);
        await evaluate('document.querySelector("#tab-addresses").click();document.querySelector("[data-add-contact-address]").click();');
        await evaluate(`(() => {const row=document.querySelector('[data-contact-address-lines]').lastElementChild;row.querySelector('[name$="[label]"]').value='Entrega navegador';row.querySelector('[name$="[address]"]').value='Entrega desde navegador';row.querySelector('[data-address-default=delivery]').click();document.querySelector('form.contact-sheet').requestSubmit();})()`);
        await until('document.readyState==="complete" && !!document.querySelector(".notice-success")');
        assert.equal(await evaluate('document.body.textContent.includes("Direcciones del contacto")'),true);
        await call('Page.navigate',{url:base+'/modules/ventas/index.php?new=1'});
        await until('document.readyState==="complete" && !!document.querySelector("[data-order-address=delivery]")');
        await evaluate('document.querySelector("[name=customer_id]").value='+JSON.stringify(contact)+';document.querySelector("[name=customer_id]").dispatchEvent(new Event("change",{bubbles:true}));');
        assert.equal(await evaluate('document.querySelector("[data-invoice-address]").value.includes("Calle principal nueva")'),true);
        assert.equal(await evaluate('document.querySelector("[data-delivery-address]").value'), 'Entrega desde navegador');
        assert.equal(await evaluate('[...document.querySelector("[data-order-address=delivery]").options].some(o=>o.textContent.includes("archivada"))'),false);
        await evaluate('(() => {const field=document.querySelector("[data-order-address=delivery]");field.value=[...field.options].find(o=>o.textContent==="Principal").value;field.dispatchEvent(new Event("change",{bubbles:true}));})()');
        assert.equal(await evaluate('document.querySelector("[data-delivery-address]").value.includes("Calle principal nueva")'),true);
        await evaluate(`(async()=>{const url=new URL('../contactos/index.php?id='+${JSON.stringify(contact)},location.href),response=await fetch(url),html=await response.text(),doc=new DOMParser().parseFromString(html,'text/html'),contactForm=doc.querySelector('form.contact-sheet');contactForm.querySelector('[name=address]').value='Calle actualizada desde otra pestaña';const saved=await fetch(new URL(contactForm.getAttribute('action'),url),{method:'POST',body:new FormData(contactForm)});if(!saved.ok)throw Error('Contact refresh fixture failed');})()`);
        assert.equal(await evaluate('document.querySelector("[data-invoice-address]").value.includes("Calle principal nueva")'),true);
        await evaluate('document.querySelector("[data-update-order-addresses]").click()');
        await until('document.querySelector("[data-invoice-address]").value.includes("Calle actualizada desde otra pestaña")');
        assert.equal(await evaluate('document.querySelector("[data-refresh-order-address=invoice]").value'), '1');
        await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
        assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),true);
        assert.deepEqual(errors,[]);
        console.log('PASS: browser contact address rows, primary/alternate defaults, municipality field, sale address selection, archived exclusions and mobile layout.');
        await call('Browser.close');
    } finally {
        ws?.close();child.kill();await new Promise(resolve=>setTimeout(resolve,500));
        if(path.dirname(profile)===path.resolve(os.tmpdir())&&path.basename(profile).startsWith('nexo-phases-test-'))fs.rmSync(profile,{recursive:true,force:true,maxRetries:5,retryDelay:200});
    }
})().catch(error=>{console.error(error);process.exitCode=1;});
