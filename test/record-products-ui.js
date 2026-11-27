const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),assert=require('node:assert/strict'),{spawn}=require('node:child_process');
(async()=>{
 const base=process.env.NEXO_TEST_BASE_URL;assert.ok(base,'Run the isolated PHP fixture.');
 const profile=fs.mkdtempSync(path.join(os.tmpdir(),'konta-products-ui-')),out=path.resolve('work/record-products-review');fs.mkdirSync(out,{recursive:true});
 const child=spawn('C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',['--headless=new','--disable-gpu','--no-sandbox','--remote-debugging-port=0','--no-first-run','--user-data-dir='+profile,'about:blank'],{windowsHide:true,stdio:'ignore'});let ws;
 try{
  const portFile=path.join(profile,'DevToolsActivePort');for(let n=0;!fs.existsSync(portFile)&&n<100;n++)await new Promise(r=>setTimeout(r,100));
  const port=fs.readFileSync(portFile,'utf8').split('\n')[0],target=await(await fetch('http://127.0.0.1:'+port+'/json/new?about:blank',{method:'PUT'})).json();ws=new WebSocket(target.webSocketDebuggerUrl);await new Promise((r,j)=>{ws.onopen=r;ws.onerror=j;});
  let id=0;const pending=new Map(),errors=[];ws.onmessage=e=>{const m=JSON.parse(e.data);if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);const p=pending.get(m.id);if(p){clearTimeout(p.timer);pending.delete(m.id);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);}};
  const call=(method,params={})=>new Promise((resolve,reject)=>{const key=++id,timer=setTimeout(()=>reject(Error('CDP timeout '+method)),20000);pending.set(key,{resolve,reject,timer});ws.send(JSON.stringify({id:key,method,params}));});
  const ev=async expression=>{const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;};
  const until=async expression=>{for(let n=0;n<150;n++){if(await ev(expression))return;await new Promise(r=>setTimeout(r,100));}throw Error('Timeout '+expression+' '+await ev('document.body.textContent.slice(-800)'));};
  const navigate=async route=>{await ev('document.documentElement.dataset.navigating="1"');await call('Page.navigate',{url:base+route});await until('document.readyState==="complete"&&!document.documentElement.dataset.navigating');};
  await call('Page.enable');await call('Runtime.enable');await navigate('/login.php');await ev('document.querySelector("[name=login]").value='+JSON.stringify(process.env.NEXO_TEST_LOGIN)+';document.querySelector("[name=password]").value='+JSON.stringify(process.env.NEXO_TEST_PASSWORD)+';document.querySelector("form").requestSubmit()');await until('!location.pathname.endsWith("/login.php")&&document.readyState==="complete"');
  for(const width of [390,1440])for(const kind of ['order','invoice']){
   await call('Emulation.setDeviceMetricsOverride',{width,height:844,deviceScaleFactor:1,mobile:width<600});
   await navigate((kind==='order'?'/modules/ventas/index.php?q=':'/modules/contabilidad/index.php?type=customer&q=')+encodeURIComponent(process.env.NEXO_PRODUCTS_PREFIX));
   assert.equal(await ev('!!document.querySelector(".record-edit-link")'),false,'Read-only fixture received edit access.');
   await ev('document.querySelector("[data-record-products-url]").click()');
   await until('document.querySelector("#record-products-dialog").open&&document.querySelectorAll("[data-record-products-content] tbody tr").length===1');
   const state=await ev(`(()=>{const d=document.querySelector('#record-products-dialog'),r=d.getBoundingClientRect();return {inside:r.left>=0&&r.right<=innerWidth+1,overflow:d.scrollWidth>d.clientWidth+1,headers:[...d.querySelectorAll('th')].map(e=>e.textContent),quantity:d.querySelector('tbody td:last-child').textContent,safe:d.querySelector('tbody td').textContent.includes('<script>')&&!d.querySelector('script'),form:!!d.querySelector('form')};})()`);
   assert.deepEqual(state,{inside:true,overflow:false,headers:['Producto','Cantidad'],quantity:kind==='order'?'2.5':'3',safe:true,form:false});
   const shot=await call('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(out,kind+'-'+width+'.png'),Buffer.from(shot.data,'base64'));
   await ev('document.querySelector("[data-close-record-products]").click()');assert.equal(await ev('!document.querySelector("#record-products-dialog").open&&document.activeElement.hasAttribute("data-record-products-url")&&document.body.style.overflow!=="hidden"'),true,'Close lost focus or scroll.');
   await ev('document.querySelector("[data-record-products-url]").click()');await until('document.querySelector("#record-products-dialog").open');
   await call('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});await call('Input.dispatchKeyEvent',{type:'keyUp',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});await until('!document.querySelector("#record-products-dialog").open');
  }
  assert.deepEqual(errors,[]);console.log('PASS: products-only dialog on phone/desktop, invoice/order quantities, read-only access, safe text, close/Escape and focus restored.');await call('Browser.close');
 }finally{ws?.close();child.kill();await new Promise(r=>setTimeout(r,500));if(path.dirname(profile)===path.resolve(os.tmpdir())&&path.basename(profile).startsWith('konta-products-ui-'))fs.rmSync(profile,{recursive:true,force:true,maxRetries:12,retryDelay:250});}
})().catch(e=>{console.error(e);process.exitCode=1;});
