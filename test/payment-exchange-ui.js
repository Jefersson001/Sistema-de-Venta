const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),assert=require('node:assert/strict'),{spawn}=require('node:child_process');
(async()=>{
 const base=process.env.NEXO_TEST_BASE_URL;assert.ok(base,'Use isolated payment-exchange.php runner.');
 const profile=fs.mkdtempSync(path.join(os.tmpdir(),'konta-fx-ui-')),out=path.resolve('work/payment-exchange-review');fs.mkdirSync(out,{recursive:true});
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
  for(const width of [390,1440])for(const [invoiceCurrency,invoiceId] of [['USD',process.env.NEXO_FX_INVOICE],['VES',process.env.NEXO_FX_VES_INVOICE]]){
   await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<600});
   await navigate('/modules/contabilidad/index.php?type=customer&id='+invoiceId);
   await until('!!document.querySelector("[data-invoice-wizard-open=invoice-payment-wizard]")');
   await ev('document.querySelector("[data-invoice-wizard-open=invoice-payment-wizard]").click()');
   assert.equal(await ev('document.querySelector("#invoice-payment-wizard").open'),true);
   await ev(`(()=>{const f=document.querySelector('#invoice-payment-wizard form'),c=f.elements.currency_code;c.value='VES';c.dispatchEvent(new Event('change',{bubbles:true}));f.elements.amount.value='1200.00';f.elements.exchange_rate.value='120.00';f.elements.exchange_rate.dispatchEvent(new Event('input',{bubbles:true}));})()`);
   const preview=await ev(`document.querySelector('[data-payment-fx-preview]').textContent`);assert.ok(preview.includes('10.00 USD')&&preview.includes('200.00 VES'),preview);
   await ev(`(()=>{const f=document.querySelector('#invoice-payment-wizard form');f.elements.payment_date.value='2026-11-08';f.elements.payment_date.dispatchEvent(new Event('change',{bubbles:true}));})()`);
   await until(`document.querySelector('#invoice-payment-wizard [name=exchange_rate]').value==='110.00'&&!document.querySelector('#invoice-payment-wizard button[type=submit]').disabled`);
   assert.equal(await ev(`document.querySelector('#invoice-payment-wizard [name=amount]').value`),'1200.00','Partial entered amount must not be repriced on date change.');
   assert.ok(await ev(`document.querySelector('[data-payment-rate-status]').textContent.includes('2026-11-08')`));
   const bounds=await ev(`(()=>{const d=document.querySelector('#invoice-payment-wizard'),r=d.getBoundingClientRect();return {inside:r.left>=0&&r.right<=innerWidth+1,overflow:d.scrollWidth>d.clientWidth+1};})()`);assert.equal(bounds.inside,true);assert.equal(bounds.overflow,false);checks.push({width,invoiceCurrency,...bounds,preview});
   const shot=await call('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(out,'payment-'+invoiceCurrency+'-'+width+'.png'),Buffer.from(shot.data,'base64'));
   await ev(`document.querySelector('#invoice-payment-wizard [data-invoice-wizard-close]').click()`);
   await navigate('/modules/contabilidad/pagos.php?type=customer&new=1');
   await ev(`(()=>{const f=document.querySelector('[data-payment-rate-endpoint]');f.elements.payment_date.value='2026-11-15';f.elements.payment_date.dispatchEvent(new Event('change',{bubbles:true}));})()`);
   await until(`document.querySelector('[name=exchange_rate]').value==='120.00'`);
  }
  assert.deepEqual(errors,[]);fs.writeFileSync(path.join(out,'report.json'),JSON.stringify(checks,null,2));console.log('PASS: desktop/mobile wizard preview, partial amounts preserved, dated rate lookup and standalone payment form.');
 }finally{if(ws)ws.close();child.kill();await new Promise(resolve=>child.once('exit',resolve));for(let n=0;n<10;n++){try{fs.rmSync(profile,{recursive:true,force:true});break;}catch{await new Promise(r=>setTimeout(r,100));}}}
})().catch(error=>{console.error(error);process.exitCode=1;});
