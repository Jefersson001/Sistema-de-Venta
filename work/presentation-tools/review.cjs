const fs=require('fs'),os=require('os'),path=require('path'),{spawn}=require('child_process');
(async()=>{
 const profile=fs.mkdtempSync(path.join(os.tmpdir(),'konta-deck-review-'));
 const child=spawn('C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',['--headless=new','--disable-gpu','--no-sandbox','--remote-debugging-port=0','--no-first-run','--user-data-dir='+profile,'about:blank'],{windowsHide:true,stdio:'ignore'});let ws;
 try{
  const portFile=path.join(profile,'DevToolsActivePort');for(let n=0;!fs.existsSync(portFile)&&n<100;n++)await new Promise(r=>setTimeout(r,100));
  const port=fs.readFileSync(portFile,'utf8').split('\n')[0],target=await(await fetch('http://127.0.0.1:'+port+'/json/new?about:blank',{method:'PUT'})).json();
  ws=new WebSocket(target.webSocketDebuggerUrl);await new Promise((r,j)=>{ws.onopen=r;ws.onerror=j;});let id=0;const pending=new Map();
  ws.onmessage=e=>{const m=JSON.parse(e.data),p=pending.get(m.id);if(p){pending.delete(m.id);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);}};
  const call=(method,params={})=>new Promise((resolve,reject)=>{const key=++id;pending.set(key,{resolve,reject});ws.send(JSON.stringify({id:key,method,params}));});
  const ev=async expression=>{const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error(r.exceptionDetails.text);return r.result.value;};
  const until=async expression=>{for(let n=0;n<100;n++){if(await ev(expression))return;await new Promise(r=>setTimeout(r,50));}throw Error('No se pudo revisar la diapositiva.');};
  await call('Page.enable');await call('Runtime.enable');await call('Emulation.setDeviceMetricsOverride',{width:1600,height:900,deviceScaleFactor:1,mobile:false});
  const checks=[];
  for(let i=1;i<=13;i++){
   const name='slide-'+String(i).padStart(2,'0');const file=path.join(__dirname,'preview',name+'.html');await call('Page.navigate',{url:require('url').pathToFileURL(file).href});await until('document.readyState==="complete"');
   const overflow=await ev(`Array.from(document.querySelectorAll('.text')).filter(e=>e.scrollHeight>e.clientHeight+3||e.scrollWidth>e.clientWidth+3).map(e=>({text:e.textContent,height:e.clientHeight,scroll:e.scrollHeight,width:e.clientWidth,scrollWidth:e.scrollWidth}))`);
   checks.push({slide:i,overflow});
   const shot=await call('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(__dirname,'preview',name+'.png'),Buffer.from(shot.data,'base64'));
  }
  const tiles=checks.map(({slide:i})=>`<div><div class="frame"><img src="slide-${String(i).padStart(2,'0')}.png"></div><b>${i}</b></div>`).join('');
  fs.writeFileSync(path.join(__dirname,'preview/contact-sheet.html'),`<style>body{margin:0;padding:20px;background:#dce6ee;font:14px Arial;display:grid;grid-template-columns:repeat(3,1fr);gap:20px}img{width:100%;display:block}b{display:block;padding:5px}.frame{border:1px solid #a0b2c0}</style>${tiles}`);
  await call('Emulation.setDeviceMetricsOverride',{width:1600,height:1700,deviceScaleFactor:1,mobile:false});await call('Page.navigate',{url:require('url').pathToFileURL(path.join(__dirname,'preview/contact-sheet.html')).href});await until('document.readyState==="complete"');
  const sheet=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});fs.writeFileSync(path.join(__dirname,'preview/contact-sheet.png'),Buffer.from(sheet.data,'base64'));
  fs.writeFileSync(path.join(__dirname,'review-report.json'),JSON.stringify(checks,null,2));console.log(JSON.stringify(checks));
 }finally{if(ws)ws.close();child.kill();await new Promise(r=>child.once('exit',r));for(let n=0;n<10;n++){try{fs.rmSync(profile,{recursive:true,force:true});break;}catch{await new Promise(r=>setTimeout(r,100));}}}
})().catch(e=>{console.error(e);process.exitCode=1});
