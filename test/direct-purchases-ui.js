const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),assert=require('node:assert/strict'),{spawn}=require('node:child_process');
(async()=>{
 const base=process.env.NEXO_TEST_BASE_URL;assert.ok(base,'Use isolated PHP runner.');
 const profile=fs.mkdtempSync(path.join(os.tmpdir(),'konta-purchase-ui-')),out=path.resolve('work/direct-purchases-review');fs.mkdirSync(out,{recursive:true});
 const child=spawn(process.env.NEXO_CHROME||'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',['--headless=new','--disable-gpu','--no-sandbox','--remote-debugging-port=0','--no-first-run','--user-data-dir='+profile,'about:blank'],{windowsHide:true,stdio:'ignore'});let ws;
 try{
  const portFile=path.join(profile,'DevToolsActivePort');for(let n=0;!fs.existsSync(portFile)&&n<100;n++)await new Promise(r=>setTimeout(r,100));
  const port=fs.readFileSync(portFile,'utf8').split('\n')[0],target=await(await fetch('http://127.0.0.1:'+port+'/json/new?about:blank',{method:'PUT'})).json();ws=new WebSocket(target.webSocketDebuggerUrl);await new Promise((r,j)=>{ws.onopen=r;ws.onerror=j;});
  let id=0;const pending=new Map(),errors=[];ws.onmessage=e=>{const m=JSON.parse(e.data);if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);const p=pending.get(m.id);if(p){clearTimeout(p.timer);pending.delete(m.id);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);}};
  const call=(method,params={})=>new Promise((resolve,reject)=>{const key=++id,timer=setTimeout(()=>reject(Error('CDP timeout '+method)),20000);pending.set(key,{resolve,reject,timer});ws.send(JSON.stringify({id:key,method,params}));});
  const ev=async expression=>{const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;};
  const until=async expression=>{for(let n=0;n<150;n++){try{if(await ev(expression))return;}catch{}await new Promise(r=>setTimeout(r,100));}throw Error('Timed out '+expression+' '+errors.join('; '));};
  const navigate=async route=>{await call('Page.navigate',{url:base+route});await until('document.readyState==="complete"');};
  await call('Page.enable');await call('Runtime.enable');await navigate('/login.php');await ev('document.querySelector("[name=login]").value='+JSON.stringify(process.env.NEXO_TEST_LOGIN)+';document.querySelector("[name=password]").value='+JSON.stringify(process.env.NEXO_TEST_PASSWORD)+';document.querySelector("form").requestSubmit()');await until('!!document.querySelector(".konta-module-card")');
  await navigate('/modules/compra_directa/index.php');await until('!!document.querySelector(".purchase-product")');
  await ev(`(()=>{const f=document.querySelector('[data-purchase-checkout]');for(const [name,value] of Object.entries(${JSON.stringify({supplier_id:process.env.NEXO_PURCHASE_SUPPLIER,destination_location_id:process.env.NEXO_PURCHASE_LOCATION,payment_term_id:process.env.NEXO_PURCHASE_TERM})})){f.elements[name].value=value;f.elements[name].dispatchEvent(new Event('change',{bubbles:true}));}})()`);
  assert.equal(await ev('document.querySelector("[data-partner-phone]").value'),'04121234567');
  await ev(`document.querySelector('[data-purchase-search]').value='Producto compra rápida';document.querySelector('[data-purchase-search]').dispatchEvent(new Event('input'));document.querySelector('.purchase-product button').click()`);
  assert.equal(await ev('document.querySelectorAll(".purchase-cart-line").length'),1);
  await ev(`(()=>{const inputs=document.querySelectorAll('.purchase-cart-line input');inputs[0].value='2';inputs[1].value='8.50';for(const input of inputs)input.dispatchEvent(new Event('input',{bubbles:true}));})()`);
  await navigate('/modules/compra_directa/index.php');await until('document.querySelectorAll(".purchase-cart-line").length===1');
  assert.equal(await ev(`JSON.parse(document.querySelector('[data-purchase-lines]').value)[0].unit_price`),'8.50');
  const checks=[];
  for(const theme of ['light','dark'])for(const width of [320,390,768,1440]){
   await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<600});await ev('document.documentElement.dataset.kontaTheme='+JSON.stringify(theme));
   const result=await ev(`(()=>{const fields=[...document.querySelectorAll('.purchase-cart input,.purchase-cart select')];return {overflow:document.documentElement.scrollWidth>innerWidth+1,small:innerWidth<600?fields.filter(e=>parseFloat(getComputedStyle(e).fontSize)<16).length:0};})()`);assert.equal(result.overflow,false,theme+' '+width+' overflow');assert.equal(result.small,0,'Small inputs');checks.push({theme,width,...result});
   if(width===390||width===1440){const capture=await call('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(out,'purchase-'+width+'-'+theme+'.png'),Buffer.from(capture.data,'base64'));}
  }
  await ev('document.querySelector("[data-purchase-checkout]").requestSubmit()');await until('!!document.querySelector("[data-purchase-completed]")?.dataset.purchaseCompleted');
  assert.match(await ev('document.querySelector("main").textContent'),/17.00 USD/);assert.equal(await ev('!!document.querySelector(\'a[href*="type=receipt"]\')'),true);
  await navigate('/modules/compra_directa/index.php');await until('!!document.querySelector("[data-purchase-checkout]")');assert.equal(await ev('document.querySelectorAll(".purchase-cart-line").length'),0);
  assert.deepEqual(errors,[]);fs.writeFileSync(path.join(out,'report.json'),JSON.stringify({checks,errors},null,2));console.log('PASS: compra en navegador, teléfono, búsqueda, carrito, borrador conservado, recepción/factura, borrador limpiado y 8 tamaños/temas sin desbordamiento.');await call('Browser.close');
 }finally{ws?.close();child.kill();await new Promise(r=>setTimeout(r,1500));if(path.dirname(profile)===path.resolve(os.tmpdir())&&path.basename(profile).startsWith('konta-purchase-ui-'))fs.rmSync(profile,{recursive:true,force:true,maxRetries:12,retryDelay:250});}
})().catch(e=>{console.error(e);process.exitCode=1;});
