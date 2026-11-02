const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {spawn} = require('node:child_process');
(async () => {
    const base = process.env.NEXO_TEST_BASE_URL, login = process.env.NEXO_TEST_LOGIN, password = process.env.NEXO_TEST_PASSWORD;
    assert.ok(base && login && password, 'Use the isolated navigation search test runner.');
    const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'vent-as-navigation-'));
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

        for(const route of ['index.php?id='+process.env.NEXO_TEMPLATE_ID,'informes.php?view=stock&product_id='+process.env.NEXO_TEMPLATE_ID,'index.php?id='+process.env.NEXO_TEMPLATE_VARIANT]){
            await call('Page.navigate',{url:base+'/modules/inventario/'+route});await until('document.readyState==="complete"&&!!document.querySelector(".module-context")');
            if(route.startsWith('index.php?id='+process.env.NEXO_TEMPLATE_ID)){
                assert.equal(await evaluate('document.querySelector("[data-product-stat=on-hand] .product-stat-value").textContent.trim()'),'18.00');
                await evaluate('document.querySelector("#product-tab-variants").click()');
                assert.ok(await evaluate('document.querySelector("#product-page-variants").innerText.includes("Torta de fresa QA")'));
            }
            for(const width of [1366,390]){await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:false});await evaluate('new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r)))');assert.equal(await evaluate('document.documentElement.scrollWidth>innerWidth'),false,route+' at '+width);}
        }
        assert.deepEqual(errors,[]);console.log('PASS: real template total, variants tab, family stock report and desktop/mobile layout.');
        await call('Browser.close');
    } finally {
        ws?.close();child.kill();await new Promise(resolve=>setTimeout(resolve,500));
        if(path.dirname(profile)===path.resolve(os.tmpdir())&&path.basename(profile).startsWith('vent-as-navigation-'))fs.rmSync(profile,{recursive:true,force:true,maxRetries:5,retryDelay:200});
    }
})().catch(error=>{console.error(error);process.exitCode=1;});
