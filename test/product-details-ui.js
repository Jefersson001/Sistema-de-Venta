const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {spawn} = require('node:child_process');
(async () => {
    const base = process.env.NEXO_TEST_BASE_URL, login = process.env.NEXO_TEST_LOGIN, password = process.env.NEXO_TEST_PASSWORD;
    assert.ok(base && login && password, 'Use the isolated PHP payment proofs test runner.');
    const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'nexo-product-details-'));
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


        const product=process.env.NEXO_DETAILS_PRODUCT;assert.ok(product);
        await call('Page.navigate',{url:base+'/login.php'});
        await until('!!document.querySelector("[name=login]")');
        await evaluate('document.querySelector("[name=login]").value='+JSON.stringify(login)+';document.querySelector("[name=password]").value='+JSON.stringify(password)+';document.querySelector("form").requestSubmit()');
        await until('!location.pathname.endsWith("/login.php")');
        await call('Page.navigate',{url:base+'/modules/inventario/index.php?id='+product});
        await until('document.readyState==="complete" && !!document.querySelector("#product-tab-details")');
        await evaluate('document.querySelector("#product-tab-details").click()');
        assert.equal(await evaluate('document.querySelector("#product-page-details").hidden'),false);
        assert.equal(await evaluate('document.querySelector("[name=barcode]").value'),'0000123456789');
        assert.equal(await evaluate('document.querySelector("[name=weight_kg]").value'),'1.25');
        assert.equal(await evaluate('[...document.querySelectorAll("#product-page-details input")].some(f=>f.required)'),false);
        assert.equal(await evaluate('document.querySelectorAll("#product-page-details input").length'),6);
        await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
        assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),true);
        assert.equal(await evaluate('[...document.querySelectorAll("#product-page-details input")].every(f=>{const r=f.getBoundingClientRect();return r.width>150&&r.left>=0&&r.right<=innerWidth+1;})'),true);
        await evaluate('document.querySelector("[name=weight_kg]").value="2,75";document.querySelector("[name=height_cm]").value="";document.querySelector("[name=color]").value="Verde navegador";document.querySelector("form.contact-sheet").requestSubmit()');
        await until('document.readyState==="complete" && !!document.querySelector(".notice-success")');
        await call('Page.navigate',{url:base+'/modules/inventario/index.php?id='+product});
        await until('document.readyState==="complete" && !!document.querySelector("[name=weight_kg]")');
        assert.equal(await evaluate('document.querySelector("[name=weight_kg]").value'),'2.75');
        assert.equal(await evaluate('document.querySelector("[name=height_cm]").value'),'');
        assert.equal(await evaluate('document.querySelector("[name=color]").value'),'Verde navegador');
        assert.equal(await evaluate('document.querySelector("[name=barcode]").value'),'0000123456789');
        assert.deepEqual(errors,[]);
        console.log('PASS: browser characteristics tab, optional controls, mobile width, comma normalization, clearing and reload.');
        await call('Browser.close');
    } finally {
        ws?.close();child.kill();await new Promise(resolve=>setTimeout(resolve,500));
        if(path.dirname(profile)===path.resolve(os.tmpdir())&&path.basename(profile).startsWith('nexo-product-details-'))fs.rmSync(profile,{recursive:true,force:true,maxRetries:5,retryDelay:200});
    }
})().catch(error=>{console.error(error);process.exitCode=1;});
