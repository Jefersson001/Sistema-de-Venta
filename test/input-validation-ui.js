const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {spawn} = require('node:child_process');
(async () => {
    const base = process.env.NEXO_TEST_BASE_URL, login = process.env.NEXO_TEST_LOGIN, password = process.env.NEXO_TEST_PASSWORD;
    assert.ok(base && login && password, 'Use the isolated input-validation.php test runner.');
    const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'vent-as-input-'));
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
        ws.onmessage=event=>{const m=JSON.parse(event.data);if(m.method==='Page.javascriptDialogOpening'&&m.params.type==='beforeunload')call('Page.handleJavaScriptDialog',{accept:true}).catch(()=>{});if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);if(pending.has(m.id)){const p=pending.get(m.id);clearTimeout(p.timer);pending.delete(m.id);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);}};
        const call=(method,params={})=>new Promise((resolve,reject)=>{const id=++next;const timer=setTimeout(()=>reject(Error('CDP timeout '+method)),20000);pending.set(id,{resolve,reject,timer});ws.send(JSON.stringify({id,method,params}));});
        const evaluate=async expression=>{const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;};
        const until=async expression=>{for(let i=0;i<100;i++){try{if(await evaluate(expression))return;}catch{}await new Promise(resolve=>setTimeout(resolve,100));}throw Error('Timed out: '+expression+'; errors: '+errors.join('; '));};
        await call('Runtime.enable'); await call('Page.enable');
        await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});



        await call('Network.enable');await call('Network.setBlockedURLs',{urls:['*fonts.googleapis.com*','*fonts.gstatic.com*']});
        async function signIn(username){
            await call('Page.navigate',{url:base+'/login.php'});await until('document.readyState==="complete"&&!!document.querySelector("[name=login]")');
            await evaluate('document.querySelector("[name=login]").value='+JSON.stringify(username)+';document.querySelector("[name=password]").value='+JSON.stringify(password)+';document.querySelector("form").requestSubmit()');
            await until('!location.pathname.endsWith("/login.php")');
            await call('Page.navigate',{url:base+'/index.php'});await until('document.readyState==="complete"&&!!document.querySelector("[data-navigation-search]")');
        }
        const search=query=>evaluate('(()=>{const i=document.querySelector("[data-module-search]");i.focus();i.value='+JSON.stringify(query)+';i.dispatchEvent(new Event("input",{bubbles:true}));})()');
        const paths=()=>evaluate('[...document.querySelectorAll(".navigation-search-result small")].map(e=>e.textContent)');
        const key=name=>evaluate('document.querySelector("[data-module-search]").dispatchEvent(new KeyboardEvent("keydown",{key:'+JSON.stringify(name)+',bubbles:true,cancelable:true}))');
        await signIn(login);

        const focus=async selector=>evaluate('(()=>{const f=document.querySelector('+JSON.stringify(selector)+');f.focus();f.select();})()');
        const insert=text=>call('Input.insertText',{text});
        const value=selector=>evaluate('document.querySelector('+JSON.stringify(selector)+').value');
        const pressKey=async key=>{await call('Input.dispatchKeyEvent',{type:'keyDown',key,text:key});await call('Input.dispatchKeyEvent',{type:'keyUp',key});};
        const paste=async(selector,text)=>evaluate('(()=>{const f=document.querySelector('+JSON.stringify(selector)+');const data=new DataTransfer();data.setData("text/plain",'+JSON.stringify(text)+');const event=new ClipboardEvent("paste",{clipboardData:data,bubbles:true,cancelable:true});f.dispatchEvent(event);return event.defaultPrevented;})()');
        await call('Page.navigate',{url:base+'/modules/contactos/index.php?new=1'});await until('document.readyState==="complete"&&!!document.querySelector("#document-number")');
        await evaluate('document.querySelector("#document-type").value='+JSON.stringify(process.env.NEXO_INPUT_V)+';document.querySelector("#document-type").dispatchEvent(new Event("change",{bubbles:true}))');
        await focus('#document-number');await insert('00123456');await pressKey('a');assert.equal(await value('#document-number'),'00123456');
        assert.equal(await paste('#document-number','123abc'),true);assert.equal(await value('#document-number'),'00123456');
        await focus('#document-number');assert.equal(await paste('#document-number','V-00012345'),true);assert.equal(await value('#document-number'),'00012345');
        await focus('[name=phone]');await insert('+58 (412) 555-0101');await pressKey('b');assert.equal(await value('[name=phone]'),'+58 (412) 555-0101');
        await focus('[name=phone]');await insert('123');assert.equal(await evaluate('document.querySelector("[name=phone]").validity.valid'),false);
        await focus('[name=phone]');await insert('0412-5550101');assert.equal(await evaluate('document.querySelector("[name=phone]").validity.valid'),true);
        await evaluate('document.querySelector("#document-type").value='+JSON.stringify(process.env.NEXO_INPUT_P)+';document.querySelector("#document-type").dispatchEvent(new Event("change",{bubbles:true}))');
        await focus('#document-number');await insert('AB001234');assert.equal(await value('#document-number'),'AB001234');assert.equal(await evaluate('document.querySelector("#document-number").inputMode'),'text');
        await focus('[name=name]');await insert('Cliente 3M / navegador QA');assert.equal(await value('[name=name]'),'Cliente 3M / navegador QA');
        await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});assert.equal(await evaluate('document.documentElement.scrollWidth>innerWidth'),false);
        await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
        await evaluate('document.querySelector("form.contact-sheet").requestSubmit()');await until('document.readyState==="complete"&&!!document.querySelector(".notice-success")');
        assert.equal(await value('#document-number'),'AB001234');assert.equal(await value('[name=phone]'),'0412-5550101');
        await call('Page.navigate',{url:base+'/modules/inventario/index.php?new=1'});await until('document.readyState==="complete"&&!!document.querySelector("[name=sale_price]")');
        await focus('[name=sale_price]');await insert('12,50');await pressKey('e');assert.equal(await value('[name=sale_price]'),'12,50');
        await focus('[name=sale_price]');await insert('1e3');assert.equal(await value('[name=sale_price]'),'12,50');
        assert.equal(await paste('[name=sale_price]','1.000,00'),true);assert.equal(await value('[name=sale_price]'),'12,50');
        await evaluate('document.querySelector("#product-tab-details").click()');await focus('[name=barcode]');await insert('ABC-001');assert.equal(await value('[name=barcode]'),'ABC-001');
        await call('Page.navigate',{url:base+'/modules/inventario/operaciones.php?type=receipt&new=1'});await until('document.readyState==="complete"&&!!document.querySelector("[data-operation-line]")');
        await focus('[name="lines[0][quantity]"]');await insert('1,25');await pressKey('x');assert.equal(await value('[name="lines[0][quantity]"]'),'1,25');
        await evaluate('document.querySelector("[data-operation-add]").click()');const dynamic='[data-operation-line]:last-child [aria-label="Cantidad"]';await focus(dynamic);await insert('2,50');await pressKey('z');assert.equal(await value(dynamic),'2,50');
        await call('Page.navigate',{url:base+'/modules/contactos/index.php?new=1'});await until('document.readyState==="complete"&&!!document.querySelector("[name=longitude]")');
        await focus('[name=longitude]');await insert('-66,903607');assert.equal(await value('[name=longitude]'),'-66,903607');
        assert.deepEqual(errors,[]);console.log('PASS: typing/pasting guards, document prefix and passport, phone format, leading zeros, save/reload, decimal comma, dynamic quantities, alphanumeric barcodes, signed coordinates and mobile bounds.');
        await call('Browser.close');
    } finally {
        ws?.close();child.kill();await new Promise(resolve=>setTimeout(resolve,500));
        if(path.dirname(profile)===path.resolve(os.tmpdir())&&path.basename(profile).startsWith('vent-as-input-'))fs.rmSync(profile,{recursive:true,force:true,maxRetries:5,retryDelay:200});
    }
})().catch(error=>{console.error(error);process.exitCode=1;});
