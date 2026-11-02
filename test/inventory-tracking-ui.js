const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {spawn} = require('node:child_process');
(async () => {
    const base = process.env.NEXO_TEST_BASE_URL, login = process.env.NEXO_TEST_LOGIN, password = process.env.NEXO_TEST_PASSWORD;
    assert.ok(base && login && password, 'Use the isolated inventory-tracking.php test runner.');
    const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'vent-as-tracking-'));
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

        for(const route of ['lotes.php','lotes.php?initialize=1','lotes.php?new=1','index.php?id='+process.env.NEXO_TRACK_PRODUCT]){
            await call('Page.navigate',{url:base+'/modules/inventario/'+route});
            await until('document.readyState==="complete"&&!!document.querySelector(".module-context")');
            for(const width of [1366,390]){
                await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:false});
                await evaluate('new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r)))');
                assert.equal(await evaluate('document.documentElement.scrollWidth>innerWidth'),false,route+' at '+width);
            }
        }
        await call('Emulation.setDeviceMetricsOverride',{width:1366,height:900,deviceScaleFactor:1,mobile:false});
        await call('Page.navigate',{url:base+'/modules/inventario/operaciones.php?type=receipt&id='+process.env.NEXO_TRACK_OPERATION});
        await until('document.readyState==="complete"&&!!document.querySelector("[data-open-tracking]")');
        await evaluate('document.querySelector("[data-open-tracking]").click()');await until('!!document.querySelector("[data-tracking-dialog][open]")');
        await evaluate('[...document.querySelectorAll("[data-tracking-dialog] button")].find(b=>b.textContent.includes("Agregar l")).click()');
        await evaluate('document.querySelector("[data-tracking-dialog] [data-lot-name]").value="BROWSER-SERIAL";document.querySelector("[data-tracking-dialog] [data-lot-expiration]").value="2030-12-31";document.querySelector("[data-apply-tracking]").click()');
        assert.equal(await evaluate('JSON.parse(document.querySelector("[data-line-tracking]").value)[0].name'),'BROWSER-SERIAL');
        await evaluate('document.querySelector("[data-operation-form]").requestSubmit()');
        await until('document.readyState==="complete"&&document.querySelector(".notice-success")?.textContent.includes("guardada")');
        assert.equal(await evaluate('JSON.parse(document.querySelector("[data-line-tracking]").value)[0].name'),'BROWSER-SERIAL');
        await evaluate('document.querySelector("[data-open-tracking]").click()');
        for(const width of [1366,390]){
            await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:false});
            await evaluate('new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r)))');
            assert.equal(await evaluate('document.documentElement.scrollWidth>innerWidth'),false);
            const bounds=await evaluate('(()=>{const r=document.querySelector("[data-tracking-dialog]").getBoundingClientRect();return [r.left,r.right];})()');assert.ok(bounds[0]>=0&&bounds[1]<=width+1);
        }
        if(process.env.NEXO_TRACK_SCREENSHOT)fs.writeFileSync(process.env.NEXO_TRACK_SCREENSHOT,Buffer.from((await call('Page.captureScreenshot',{format:'png'})).data,'base64'));
        assert.deepEqual(errors,[]);console.log('PASS: tracking list, filters, product settings, initial form and allocation wizard; save/reload and desktop/mobile bounds.');
        await call('Browser.close');
    } finally {
        ws?.close();child.kill();await new Promise(resolve=>setTimeout(resolve,500));
        if(path.dirname(profile)===path.resolve(os.tmpdir())&&path.basename(profile).startsWith('vent-as-tracking-'))fs.rmSync(profile,{recursive:true,force:true,maxRetries:5,retryDelay:200});
    }
})().catch(error=>{console.error(error);process.exitCode=1;});
