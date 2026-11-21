const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),assert=require('node:assert/strict'),{spawn}=require('node:child_process');
(async()=>{
 const base=process.env.NEXO_TEST_BASE_URL;assert.ok(base,'Use isolated ticket runner.');
 const profile=fs.mkdtempSync(path.join(os.tmpdir(),'konta-ticket-ui-')),out=path.resolve('work/invoice-ticket-review');fs.mkdirSync(out,{recursive:true});
 const child=spawn(process.env.NEXO_CHROME||'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',['--headless=new','--disable-gpu','--no-sandbox','--remote-debugging-port=0','--no-first-run','--user-data-dir='+profile,'about:blank'],{windowsHide:true,stdio:'ignore'});let ws;
 try{
  const portFile=path.join(profile,'DevToolsActivePort');for(let n=0;!fs.existsSync(portFile)&&n<100;n++)await new Promise(r=>setTimeout(r,100));
  const port=fs.readFileSync(portFile,'utf8').split('\n')[0],target=await(await fetch('http://127.0.0.1:'+port+'/json/new?about:blank',{method:'PUT'})).json();ws=new WebSocket(target.webSocketDebuggerUrl);await new Promise((r,j)=>{ws.onopen=r;ws.onerror=j;});
  let id=0;const pending=new Map(),errors=[];ws.onmessage=e=>{const m=JSON.parse(e.data);if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);const p=pending.get(m.id);if(p){clearTimeout(p.timer);pending.delete(m.id);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);}};
  const call=(method,params={})=>new Promise((resolve,reject)=>{const key=++id,timer=setTimeout(()=>reject(Error('CDP timeout '+method)),20000);pending.set(key,{resolve,reject,timer});ws.send(JSON.stringify({id:key,method,params}));});
  const ev=async expression=>{const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;};
  const until=async expression=>{for(let n=0;n<150;n++){try{if(await ev(expression))return;}catch{}await new Promise(r=>setTimeout(r,100));}throw Error('Timed out '+expression+' '+errors.join('; ')+' '+await ev('location.href+" "+document.body.textContent.slice(0,700)'));};
  const navigate=async route=>{await call('Page.navigate',{url:base+route});await until('document.readyState==="complete"');};
  await call('Page.enable');await call('Runtime.enable');await navigate('/login.php');await ev('document.querySelector("[name=login]").value='+JSON.stringify(process.env.NEXO_TEST_LOGIN)+';document.querySelector("[name=password]").value='+JSON.stringify(process.env.NEXO_TEST_PASSWORD)+';document.querySelector("form").requestSubmit()');await until('!!document.querySelector(".konta-module-card")');
  // New browser sessions may default to the last company created by this fixture.
  await ev(`(()=>{const f=document.querySelector('[data-company-selector]');document.body.dataset.companySwitchPending='1';for(const c of f.querySelectorAll('[name="companies[]"]'))c.checked=c.value==='1';f.querySelector('[name=active_company]').value='1';f.requestSubmit();})()`);
  await until('document.readyState==="complete"&&!document.body.dataset.companySwitchPending&&document.querySelector("[name=active_company]")?.value==="1"');
  const checks=[];
  for(const paper of [80,58]){
   const source='/project/modules/contabilidad/index.php?type=customer&id='+process.env.NEXO_TICKET_INVOICE;
   await navigate('/acciones.php?'+new URLSearchParams({source,action:'print',format:'ticket',width:String(paper)}));await until('!!document.querySelector(".invoice-ticket")');
   for(const width of [320,390,1440]){
    await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<600});
    assert.equal(await ev('document.documentElement.scrollWidth<=innerWidth+1'),true,'Ticket preview overflows '+paper+' '+width);
    checks.push({paper,width});
    if(width===390){const shot=await call('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(out,'ticket-'+paper+'.png'),Buffer.from(shot.data,'base64'));}
   }
   await ev('window.dispatchEvent(new Event("beforeprint"))');
   const pdf=await call('Page.printToPDF',{printBackground:true,preferCSSPageSize:true,displayHeaderFooter:false});const buffer=Buffer.from(pdf.data,'base64');assert.equal(buffer.subarray(0,4).toString(),'%PDF');
   const box=buffer.toString('latin1').match(/\/MediaBox\s*\[\s*0\s+0\s+([\d.]+)/);assert.ok(box,'PDF page size missing');assert.ok(Math.abs(Number(box[1])-paper*72/25.4)<2,'Wrong PDF paper width '+box[1]);fs.writeFileSync(path.join(out,'ticket-'+paper+'.pdf'),buffer);
  }
  assert.deepEqual(errors,[]);fs.writeFileSync(path.join(out,'report.json'),JSON.stringify({checks,errors},null,2));console.log('PASS: preview móvil/escritorio sin desbordamiento y PDF térmico con ancho real de 58/80 mm.');await call('Browser.close');
 }finally{ws?.close();child.kill();await new Promise(r=>setTimeout(r,1500));if(path.dirname(profile)===path.resolve(os.tmpdir())&&path.basename(profile).startsWith('konta-ticket-ui-'))fs.rmSync(profile,{recursive:true,force:true,maxRetries:12,retryDelay:250});}
})().catch(e=>{console.error(e);process.exitCode=1;});
