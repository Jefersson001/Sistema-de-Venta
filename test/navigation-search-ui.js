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
        assert.equal(await evaluate('document.querySelectorAll("[data-module-card]").length'),8);
        await search('pagos');
        let results=await paths();assert.ok(results.includes('Contabilidad / Clientes / Pagos'));assert.ok(results.includes('Contabilidad / Proveedores / Pagos'));assert.ok(results.includes('Ventas / Comisiones / Pagos de comisiones'));
        assert.equal(await evaluate('document.querySelectorAll("[data-module-card]:not([hidden])").length'),8);
        const first=await evaluate('document.querySelector("[data-module-search]").getAttribute("aria-activedescendant")');await key('ArrowDown');assert.notEqual(await evaluate('document.querySelector("[data-module-search]").getAttribute("aria-activedescendant")'),first);await key('ArrowUp');assert.equal(await evaluate('document.querySelector("[data-module-search]").getAttribute("aria-activedescendant")'),first);
        for(const [width,height] of [[1440,900],[1366,600],[1024,768],[390,844],[320,700]]){
            await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});await search('pagos');
            const bounds=await evaluate('(()=>{const r=document.querySelector("#navigation-search-results").getBoundingClientRect();return {left:r.left,right:r.right,bottom:r.bottom,overflow:document.documentElement.scrollWidth>innerWidth};})()');
            assert.ok(bounds.left>=0&&bounds.right<=width+1&&bounds.bottom<=height+1,JSON.stringify({width,height,bounds}));assert.equal(bounds.overflow,false);
        }
        await key('Escape');assert.equal(await evaluate('document.querySelector("[data-module-search]").getAttribute("aria-expanded")'),'false');
        await evaluate('document.dispatchEvent(new KeyboardEvent("keydown",{key:"k",ctrlKey:true,bubbles:true,cancelable:true}))');assert.equal(await evaluate('document.activeElement.hasAttribute("data-module-search")'),true);
        await search('monedas configuracion');assert.ok((await paths()).includes('Contabilidad / Configuración / Monedas'));
        await search('ordenes venta');assert.ok((await paths()).includes('Ventas / Órdenes de venta'));
        await search('seccion inexistente zz');assert.deepEqual(await paths(),[]);assert.equal(await evaluate('document.querySelector(".navigation-search-empty").textContent.startsWith("No se encontraron")'),true);
        await search('<img src=x onerror=alert(1)>');assert.equal(await evaluate('document.querySelectorAll("#navigation-search-results img").length'),0);
        await search('');await key('ArrowDown');assert.equal(await evaluate('document.querySelector("[data-module-search]").getAttribute("aria-expanded")'),'false');
        await search('pagos de proveedores');assert.deepEqual(await paths(),['Contabilidad / Proveedores / Pagos']);
        await key('Enter');await until('location.pathname.endsWith("/modules/contabilidad/pagos.php")&&location.search.includes("type=supplier")&&document.readyState==="complete"');
        await call('Page.navigate',{url:base+'/index.php'});await until('document.readyState==="complete"&&!!document.querySelector("[data-navigation-search]")');
        await search('contactos archivados');assert.deepEqual(await paths(),['Contactos / Contactos archivados']);await evaluate('document.querySelector(".navigation-search-result").click()');await until('location.pathname.endsWith("/modules/contactos/index.php")&&location.search.includes("status=archived")&&document.readyState==="complete"');
        await evaluate('document.querySelector(".user-menu-summary").click();document.querySelector(".user-menu-options form").requestSubmit()');await until('location.pathname.endsWith("/login.php")');
        await signIn(process.env.NEXO_NAV_SUPPLIER_LOGIN);await search('pagos');assert.deepEqual(await paths(),['Contabilidad / Proveedores / Pagos']);
        assert.equal(await evaluate('document.querySelectorAll("[data-module-card]:not([hidden])").length'),1);
        assert.deepEqual(errors,[]);
        console.log('PASS: search by section and breadcrumb, aliases/accents, cards stay visible, keyboard/Enter/Escape, click navigation, empty results, mobile popup bounds and restricted user.');
        await call('Browser.close');
    } finally {
        ws?.close();child.kill();await new Promise(resolve=>setTimeout(resolve,500));
        if(path.dirname(profile)===path.resolve(os.tmpdir())&&path.basename(profile).startsWith('vent-as-navigation-'))fs.rmSync(profile,{recursive:true,force:true,maxRetries:5,retryDelay:200});
    }
})().catch(error=>{console.error(error);process.exitCode=1;});
