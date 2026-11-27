const fs=require('fs'),os=require('os'),path=require('path'),{spawn}=require('child_process');
(async()=>{
 const profile=fs.mkdtempSync(path.join(os.tmpdir(),'konta-commercial-'));
 const child=spawn('C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',['--headless=new','--disable-gpu','--no-sandbox','--remote-debugging-port=0','--no-first-run','--user-data-dir='+profile,'about:blank'],{windowsHide:true,stdio:'ignore'});let ws;
 try{
  const portFile=path.join(profile,'DevToolsActivePort');for(let n=0;!fs.existsSync(portFile)&&n<100;n++)await new Promise(r=>setTimeout(r,100));
  const port=fs.readFileSync(portFile,'utf8').split('\n')[0],target=await(await fetch('http://127.0.0.1:'+port+'/json/new?about:blank',{method:'PUT'})).json();
  ws=new WebSocket(target.webSocketDebuggerUrl);await new Promise((r,j)=>{ws.onopen=r;ws.onerror=j;});let id=0;const pending=new Map();
  ws.onmessage=e=>{const m=JSON.parse(e.data),p=pending.get(m.id);if(p){pending.delete(m.id);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);}};
  const call=(method,params={})=>new Promise((resolve,reject)=>{const key=++id;pending.set(key,{resolve,reject});ws.send(JSON.stringify({id:key,method,params}));});
  const ev=async expression=>{const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error(r.exceptionDetails.text);return r.result.value;};
  const until=async expression=>{for(let n=0;n<160;n++){if(await ev(expression))return;await new Promise(r=>setTimeout(r,100));}throw Error('No respondió la vista de demostración.');};
  await call('Page.enable');await call('Runtime.enable');await call('Emulation.setDeviceMetricsOverride',{width:1600,height:1080,deviceScaleFactor:1,mobile:false});
  await call('Page.navigate',{url:process.env.NEXO_DEMO_URL+'/login.php'});await until('!!document.querySelector("[name=login]")');
  await ev('document.querySelector("[name=login]").value='+JSON.stringify(process.env.NEXO_DEMO_LOGIN)+';document.querySelector("[name=password]").value='+JSON.stringify(process.env.NEXO_DEMO_PASSWORD)+';document.querySelector("form").requestSubmit()');
  await until('document.querySelectorAll(".konta-module-card").length>=9');
  await ev(`document.querySelectorAll('.konta-module-card').forEach(c=>{const meta=c.querySelector('.konta-module-card-meta');if(meta)meta.remove();});document.documentElement.style.scrollbarWidth='none';window.scrollTo(0,0)`);
  const shot=await call('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(__dirname,'home-demo.png'),Buffer.from(shot.data,'base64'));
  console.log('Captura del inicio actual: datos de demostración, diez módulos y marca Konta.');
 }finally{if(ws)ws.close();child.kill();await new Promise(r=>child.once('exit',r));for(let n=0;n<10;n++){try{fs.rmSync(profile,{recursive:true,force:true});break;}catch{await new Promise(r=>setTimeout(r,100));}}}
})().catch(e=>{console.error(e);process.exitCode=1});
