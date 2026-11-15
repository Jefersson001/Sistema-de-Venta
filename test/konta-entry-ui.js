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
  await ev('document.querySelector(".company-selector").open=false');
  const modulePages=['dashboard/index.php','contactos/index.php','contactos/index.php?new=1','inventario/index.php','inventario/index.php?new=1','inventario/operaciones.php?type=delivery','compras/index.php?new=1','ventas/index.php?new=1','contabilidad/index.php?type=customer','contabilidad/pagos.php?type=customer','restaurante/index.php','venta_directa/index.php','venta_directa/cierre.php','ajustes/index.php','ajustes/grupos.php'];
  for(const route of modulePages){
   await size(1366,900);await call('Page.navigate',{url:base+'/modules/'+route});
   await until('document.readyState==="complete"&&!!document.querySelector(".module-workspace")');
   assert.equal(await ev('document.title.endsWith("Konta")'),true,'Old title '+route);
   assert.equal(await ev(`!!document.querySelector('link[href*="konta-workspace.css"]')`),true,'Missing shared design '+route);
   assert.equal(await ev('document.querySelectorAll("[data-module-header]").length'),1,'Header duplicated '+route);
   assert.equal(await ev('document.documentElement.scrollWidth<=innerWidth+1'),true,'Desktop overflow '+route);
   await ev('document.querySelector("[data-module-header]").open=true');
   assert.equal(await ev('document.querySelector(".konta-header").getBoundingClientRect().height>30'),true,'Header inaccessible '+route);
   await ev('document.querySelector("[data-module-header]").open=false');
   if(route==='contactos/index.php')await shot('konta-contacts-desktop.png');
   if(route==='ventas/index.php?new=1')await shot('konta-sale-desktop.png');
   await size(390,844);
   assert.equal(await ev('document.documentElement.scrollWidth<=innerWidth+1'),true,'Mobile overflow '+route);
   if(route==='contactos/index.php'){
    assert.equal(await ev('!!document.querySelector(".workspace-record-list")'),true,'Contacts do not become mobile records.');
    await ev('document.querySelector("[data-record-select-all]").click()');
    assert.equal(await ev('[...document.querySelectorAll("[data-record-select]")].every(input=>input.checked)'),true,'Mobile bulk selection broke.');
    await ev('document.querySelector("[data-record-select-all]").click()');await shot('konta-contacts-mobile.png');
   }
   if(route==='contactos/index.php?new=1'){
    assert.equal(await ev('!!document.querySelector("[data-konta-iconized=save] .konta-action-icon")'),true,'Save icon missing.');
    await ev('document.querySelector("[data-notebook-tabs] a:last-child").click()');
    assert.equal(await ev('document.querySelector("[data-notebook-tabs] a:last-child").getAttribute("aria-selected")'),'true','Compact tabs no longer switch pages.');
    await shot('konta-contact-form-mobile.png');
   }
   if(route==='ventas/index.php?new=1'){
    await shot('konta-sale-mobile.png');
    await ev('document.querySelector("[data-unregistered-customer]").click()');
    await ev('document.querySelector("[data-create-sales-customer]").click()');
    await until('document.querySelector("[data-sales-customer-dialog]").open');
    await until('document.querySelector("[data-sales-customer-frame]").contentDocument?.readyState==="complete"&&!!document.querySelector("[data-sales-customer-frame]").contentDocument?.querySelector("input[name=name]")');
    assert.equal(await ev('(()=>{const r=document.querySelector("[data-sales-customer-dialog]").getBoundingClientRect();return r.left>=0&&r.right<=innerWidth&&r.top>=0&&r.bottom<=innerHeight+1;})()'),true,'Customer dialog exceeds mobile viewport.');
    assert.equal(await ev('(()=>{const f=document.querySelector("[data-sales-customer-frame]");return f.contentDocument.documentElement.scrollWidth<=f.clientWidth+1;})()'),true,'Embedded customer form overflows.');
    await shot('konta-customer-dialog-mobile.png');
    await ev('document.querySelector("[data-close-sales-customer]").click()');
    assert.equal(await ev('document.querySelector("[data-sales-customer-dialog]").open'),false,'Dialog close action broke.');
   }
  }
  await ev('document.querySelector("[data-module-header]").open=false;document.dispatchEvent(new KeyboardEvent("keydown",{key:"k",ctrlKey:true,bubbles:true}))');
  await until('document.querySelector(".konta-header").getBoundingClientRect().height>30');
  assert.equal(await ev('document.activeElement===document.querySelector("[data-module-search]")'),true,'Shortcut cannot open a hidden header.');
  await ev('document.querySelector("[data-module-search]").value="pagos";document.querySelector("[data-module-search]").dispatchEvent(new Event("input",{bubbles:true}))');
  await until('document.querySelectorAll(".navigation-search-result").length>0');
  assert.match(await ev('document.querySelector("[role=listbox]").textContent'),/Contabilidad/,'Interior section search failed.');
  await ev('document.querySelector("[data-module-search]").dispatchEvent(new KeyboardEvent("keydown",{key:"Escape",bubbles:true}));document.querySelector("[data-konta-theme-toggle]").click()');
  await call('Page.navigate',{url:base+'/modules/contactos/index.php'});await until('document.readyState==="complete"&&!!document.querySelector(".contact-table")');
  assert.equal(await ev('document.documentElement.dataset.kontaTheme'),'dark','Interior lost theme.');await shot('konta-contacts-dark.png');
  for(const route of ['contactos/index.php?q=konta_no_matching_contact_91827','restaurante/index.php','contabilidad/configuracion.php']){
   await call('Page.navigate',{url:base+'/modules/'+route});await until('document.readyState==="complete"&&!!document.querySelector(".module-workspace")');
   assert.equal(await ev('document.documentElement.scrollWidth<=innerWidth+1'),true,'Dark page overflows '+route);
   assert.equal(await ev('document.documentElement.dataset.kontaTheme'),'dark');
   if(route.includes('q=')){
    assert.equal(await ev('!!document.querySelector(".workspace-empty")'),true,'Empty list feedback missing.');await shot('konta-empty-dark.png');
   }
   if(route==='restaurante/index.php'){
    assert.equal(await ev('getComputedStyle(document.querySelector(".restaurant-stat")).backgroundColor===getComputedStyle(document.querySelector(".restaurant-panel")).backgroundColor'),true,'Restaurant counters retain a light background.');
    await shot('konta-restaurant-dark.png');
   }
   if(route==='contabilidad/configuracion.php')await shot('konta-configuration-dark.png');
  }
  await ev('document.querySelector("[data-module-header]").open=true;document.querySelector("[data-konta-theme-toggle]").click();document.querySelector(".konta-user").open=true;document.querySelector(".konta-user form").requestSubmit()');await until('location.pathname.endsWith("/login.php")&&document.readyState==="complete"');
  assert.deepEqual(errors,[]);console.log('PASS: login y panel Konta; 15 vistas internas en escritorio y móvil, cabecera opcional, búsqueda de secciones, tema persistente y cierre de sesión.');
 }finally{
  if(ws)ws.close();chrome.kill();await new Promise(r=>chrome.once('exit',r));
  const resolved=path.resolve(profile);if(path.dirname(resolved)!==path.resolve(os.tmpdir())||!path.basename(resolved).startsWith('konta-entry-'))throw Error('Invalid profile cleanup path');fs.rmSync(resolved,{recursive:true,force:true,maxRetries:8,retryDelay:200});
 }
})().catch(e=>{console.error(e);process.exitCode=1;});
