const assert=require('node:assert/strict'),fs=require('node:fs'),os=require('node:os'),path=require('node:path');
const {spawn}=require('node:child_process');
(async()=>{
 const base=process.env.NEXO_TEST_BASE_URL,login=process.env.NEXO_TEST_LOGIN,password=process.env.NEXO_TEST_PASSWORD;
 assert.ok(base&&login&&password,'Use php test/konta-entry.php.');
 const profile=fs.mkdtempSync(path.join(os.tmpdir(),'konta-entry-'));
 const chrome=spawn(process.env.NEXO_CHROME||'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',['--headless=new','--disable-gpu','--no-sandbox','--remote-debugging-port=0','--no-first-run','--user-data-dir='+profile,'about:blank'],{windowsHide:true,stdio:'ignore'});
 let ws;
 try{
  const file=path.join(profile,'DevToolsActivePort');for(let i=0;!fs.existsSync(file)&&i<100;i++)await new Promise(r=>setTimeout(r,100));
  const port=fs.readFileSync(file,'utf8').split('\n')[0],target=await(await fetch('http://127.0.0.1:'+port+'/json/new?about:blank',{method:'PUT'})).json();
  ws=new WebSocket(target.webSocketDebuggerUrl);await new Promise((r,j)=>{ws.onopen=r;ws.onerror=j;});
  let next=0;const pending=new Map(),errors=[];
  ws.onmessage=e=>{const m=JSON.parse(e.data);if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);if(pending.has(m.id)){const p=pending.get(m.id);clearTimeout(p.timer);pending.delete(m.id);m.error?p.j(Error(m.error.message)):p.r(m.result);}};
  const call=(method,params={})=>new Promise((r,j)=>{const id=++next,timer=setTimeout(()=>j(Error('CDP timeout '+method)),15000);pending.set(id,{r,j,timer});ws.send(JSON.stringify({id,method,params}));});
  const ev=async expression=>{const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;};
  const until=async expression=>{for(let i=0;i<150;i++){try{if(await ev(expression))return;}catch{}await new Promise(r=>setTimeout(r,100));}throw Error('Timeout '+expression+' '+errors.join('; '));};
  await call('Runtime.enable');await call('Page.enable');
  const size=(width,height)=>call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:width<600});
  const shot=async name=>{const r=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});fs.writeFileSync(path.join(os.tmpdir(),name),Buffer.from(r.data,'base64'));};
  await size(1366,768);await call('Page.navigate',{url:base+'/login.php'});await until('document.readyState==="complete"&&!!document.querySelector("[name=login]")');
  await ev('document.fonts.ready');assert.equal(await ev('document.documentElement.scrollWidth<=innerWidth'),true,'Login horizontal overflow.');
  assert.equal(await ev('document.documentElement.scrollHeight<=innerHeight+2'),true,'Desktop login needs unnecessary scrolling.');
  await ev('document.querySelector(".auth-password-toggle").click()');assert.equal(await ev('document.querySelector("[name=password]").type'),'text');
  assert.equal(await ev('document.querySelector(".auth-password-toggle").getAttribute("aria-pressed")'),'true');await ev('document.querySelector(".auth-password-toggle").click()');
  await shot('konta-login-desktop.png');await size(390,844);
  assert.equal(await ev('document.documentElement.scrollWidth<=innerWidth'),true,'Mobile login horizontal overflow.');await shot('konta-login-mobile.png');
  await ev('document.querySelector("[name=login]").value='+JSON.stringify(login)+';document.querySelector("[name=password]").value='+JSON.stringify(password)+';document.querySelector(".konta-auth-card form").requestSubmit()');
  await until('location.pathname.endsWith("/index.php")&&document.readyState==="complete"&&!!document.querySelector(".konta-module-card")');
  assert.equal(await ev('document.documentElement.scrollWidth<=innerWidth'),true,'Mobile home horizontal overflow.');
  assert.equal(await ev('getComputedStyle(document.querySelector(".konta-rate")).display!=="none"'),true,'Mobile hides exchange rate.');await shot('konta-home-mobile.png');
  await size(1440,1000);await shot('konta-home-desktop.png');
  const columns=await ev('getComputedStyle(document.querySelector(".konta-module-grid")).gridTemplateColumns.split(" ").length');assert.equal(columns,4,'Desktop card layout is not four columns.');
  await ev('document.querySelector("[data-module-search]").value="pagos";document.querySelector("[data-module-search]").dispatchEvent(new Event("input",{bubbles:true}))');await until('document.querySelectorAll(".navigation-search-result").length>0');
  assert.match(await ev('document.querySelector("[role=listbox]").textContent'),/Contabilidad/,'Section search lost breadcrumbs.');
  await ev('document.querySelector("[data-module-search]").dispatchEvent(new KeyboardEvent("keydown",{key:"Escape",bubbles:true}));document.querySelector("[data-konta-theme-toggle]").click()');
  assert.equal(await ev('document.documentElement.dataset.kontaTheme'),'dark');await shot('konta-home-dark.png');
  await call('Page.reload');await until('document.readyState==="complete"&&!!document.querySelector(".konta-module-card")');assert.equal(await ev('document.documentElement.dataset.kontaTheme'),'dark','Theme preference was not preserved.');
  await ev('document.querySelector("[data-konta-theme-toggle]").click();document.querySelector(".company-selector").open=true');assert.equal(await ev('document.querySelector(".company-selector form").getBoundingClientRect().width>100'),true,'Company menu is inaccessible.');
  await ev('document.querySelector(".company-selector").open=false;document.querySelector(".konta-user").open=true;document.querySelector(".konta-user form").requestSubmit()');await until('location.pathname.endsWith("/login.php")&&document.readyState==="complete"');
  assert.deepEqual(errors,[]);console.log('PASS: login compacto, contraseña visible/oculta, login/logout real, panel móvil/escritorio, búsqueda, selector de empresa y modo oscuro persistente.');
 }finally{
  if(ws)ws.close();chrome.kill();await new Promise(r=>chrome.once('exit',r));
  const resolved=path.resolve(profile);if(path.dirname(resolved)!==path.resolve(os.tmpdir())||!path.basename(resolved).startsWith('konta-entry-'))throw Error('Invalid profile cleanup path');fs.rmSync(resolved,{recursive:true,force:true,maxRetries:8,retryDelay:200});
 }
})().catch(e=>{console.error(e);process.exitCode=1;});
