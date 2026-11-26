const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),assert=require('node:assert/strict'),{spawn}=require('node:child_process');
(async()=>{
 const base=process.env.NEXO_TEST_BASE_URL;assert.ok(base,'Use isolated commission-general-payments.php runner.');
 const profile=fs.mkdtempSync(path.join(os.tmpdir(),'konta-commission-ui-')),out=path.resolve('work/commission-general-review');fs.mkdirSync(out,{recursive:true});
 const child=spawn(process.env.NEXO_CHROME||'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',['--headless=new','--disable-gpu','--no-sandbox','--remote-debugging-port=0','--no-first-run','--user-data-dir='+profile,'about:blank'],{windowsHide:true,stdio:'ignore'});let ws;
 try{
  const portFile=path.join(profile,'DevToolsActivePort');for(let n=0;!fs.existsSync(portFile)&&n<100;n++)await new Promise(r=>setTimeout(r,100));
  const port=fs.readFileSync(portFile,'utf8').split('\n')[0],target=await(await fetch('http://127.0.0.1:'+port+'/json/new?about:blank',{method:'PUT'})).json();ws=new WebSocket(target.webSocketDebuggerUrl);await new Promise((r,j)=>{ws.onopen=r;ws.onerror=j;});
  let id=0;const pending=new Map(),errors=[];ws.onmessage=e=>{const m=JSON.parse(e.data);if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);const p=pending.get(m.id);if(p){clearTimeout(p.timer);pending.delete(m.id);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);}};
  const call=(method,params={})=>new Promise((resolve,reject)=>{const key=++id,timer=setTimeout(()=>reject(Error('CDP timeout '+method)),20000);pending.set(key,{resolve,reject,timer});ws.send(JSON.stringify({id:key,method,params}));});
  const ev=async expression=>{const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;};
  const until=async expression=>{for(let n=0;n<150;n++){if(await ev(expression))return;await new Promise(r=>setTimeout(r,100));}throw Error('Timed out '+expression+' '+errors.join('; ')+' '+await ev('location.href+" "+document.body.textContent.slice(-1000)'));};
  const navigate=async route=>{await call('Page.navigate',{url:base+route});await until('document.readyState==="complete"');};
  await call('Page.enable');await call('Runtime.enable');await navigate('/login.php');
  await ev('document.querySelector("[name=login]").value='+JSON.stringify(process.env.NEXO_TEST_LOGIN)+';document.querySelector("[name=password]").value='+JSON.stringify(process.env.NEXO_TEST_PASSWORD)+';document.querySelector("form").requestSubmit()');await until('!!document.querySelector(".konta-module-card")');

  const checks=[];
  for(const width of [390,1440]){
   await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<600});
   await navigate('/modules/ventas/pagos-comisiones.php?new=1&seller_id='+process.env.NEXO_TEST_SELLER+'&commission_currency=USD');
   await until('!!document.querySelector("[data-commission-payment-form]")');
   await until('!!document.querySelector("[data-commission-preview]").textContent');
   await ev('document.querySelector("[data-commission-amount]").value="2.00";document.querySelector("[data-commission-currency]").value="VES";document.querySelector("[data-commission-rate]").value="110.00";document.querySelector("[data-commission-rate]").dispatchEvent(new Event("input",{bubbles:true}))');
   const preview=await ev('document.querySelector("[data-commission-preview]").textContent');assert.ok(preview.includes('220.00 VES'),preview);
   const bounds=await ev('({width:innerWidth,overflow:document.documentElement.scrollWidth>innerWidth+1,fields:[...document.querySelectorAll("[data-commission-payment-form] input:not([type=hidden]),[data-commission-payment-form] select")].every(e=>{const r=e.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1})})');
   assert.equal(bounds.overflow,false);assert.equal(bounds.fields,true);checks.push({width,...bounds,preview});
   const shot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});fs.writeFileSync(path.join(out,'general-'+width+'.png'),Buffer.from(shot.data,'base64'));
  }
  assert.deepEqual(errors,[]);fs.writeFileSync(path.join(out,'report.json'),JSON.stringify(checks,null,2));console.log('PASS: desktop/mobile general commission form, conversion preview, controls inside viewport and no browser errors.');
 }finally{if(ws)ws.close();child.kill();await new Promise(resolve=>child.once('exit',resolve));for(let n=0;n<10;n++){try{fs.rmSync(profile,{recursive:true,force:true});break;}catch{await new Promise(r=>setTimeout(r,100));}}}
})().catch(error=>{console.error(error);process.exitCode=1;});
