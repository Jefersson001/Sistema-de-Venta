const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),assert=require('node:assert/strict');
const {spawn}=require('node:child_process');
(async()=>{
 const base=process.env.NEXO_TEST_BASE_URL,out=process.env.NEXO_MOBILE_OUTPUT;
 assert.ok(base&&out,'Use php test/mobile-audit.php');
 const routes=JSON.parse(fs.readFileSync(path.join(out,'routes.json'),'utf8'));
 const profile=fs.mkdtempSync(path.join(os.tmpdir(),'konta-mobile-audit-'));
 const chrome=spawn(process.env.NEXO_CHROME||'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',['--headless=new','--disable-gpu','--no-sandbox','--remote-debugging-port=0','--no-first-run','--user-data-dir='+profile,'about:blank'],{windowsHide:true,stdio:'ignore'});
 let ws;const report={date:new Date().toISOString(),widths:[320,390,430],themes:['light','dark'],pages:[],issues:[]};
 try{
  const portFile=path.join(profile,'DevToolsActivePort');for(let i=0;!fs.existsSync(portFile)&&i<100;i++)await new Promise(r=>setTimeout(r,100));
  const port=fs.readFileSync(portFile,'utf8').split('\n')[0];const target=await(await fetch('http://127.0.0.1:'+port+'/json/new?about:blank',{method:'PUT'})).json();
  ws=new WebSocket(target.webSocketDebuggerUrl);await new Promise((r,j)=>{ws.onopen=r;ws.onerror=j;});
  let id=0;const pending=new Map();let pageErrors=[];
  ws.onmessage=event=>{const message=JSON.parse(event.data);if(message.method==='Runtime.exceptionThrown')pageErrors.push(message.params.exceptionDetails.exception?.description||message.params.exceptionDetails.text);const p=pending.get(message.id);if(p){pending.delete(message.id);clearTimeout(p.timer);message.error?p.reject(Error(message.error.message)):p.resolve(message.result);}};
  const call=(method,params={})=>new Promise((resolve,reject)=>{const key=++id,timer=setTimeout(()=>{pending.delete(key);reject(Error('CDP timeout '+method));},20000);pending.set(key,{resolve,reject,timer});ws.send(JSON.stringify({id:key,method,params}));});
  const ev=async expression=>{const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;};
  const until=async expression=>{for(let n=0;n<150;n++){try{if(await ev(expression))return;}catch{}await new Promise(r=>setTimeout(r,100));}throw Error('Page timeout '+expression);};
  const size=width=>call('Emulation.setDeviceMetricsOverride',{width,height:844,deviceScaleFactor:1,mobile:true});
  const screenshot=async name=>{const capture=await call('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(out,name+'.png'),Buffer.from(capture.data,'base64'));};
  await call('Page.enable');await call('Runtime.enable');await call('Emulation.setTouchEmulationEnabled',{enabled:true,maxTouchPoints:1});
  const navigate=async route=>{await call('Page.navigate',{url:base+route});await until('document.readyState==="complete"&&!!document.body');await ev('document.fonts.ready');};
  const audit=()=>ev(`(()=>{
   const visible=e=>{const r=e.getBoundingClientRect(),s=getComputedStyle(e);return r.width>0&&r.height>0&&s.visibility!=='hidden'&&s.display!=='none'&&!e.closest('[hidden]')&&!e.closest('dialog:not([open])');};
   const controls=[...document.querySelectorAll('button,input:not([type=hidden]),select,textarea,a.contact-button,summary.module-navigation-link')].filter(visible);
   const undersized=controls.filter(e=>!e.matches('input[type=checkbox],input[type=radio],.searchable-select-native')&&!e.closest('thead')).filter(e=>{const r=e.getBoundingClientRect();return r.height<36||r.width<24;}).map(e=>({text:(e.getAttribute('aria-label')||e.textContent||e.name).trim().slice(0,65),tag:e.tagName,height:Math.round(e.getBoundingClientRect().height),class:e.className}));
   const smallFields=controls.filter(e=>e.matches('input:not([type=checkbox]):not([type=radio]),select,textarea')&&!e.matches('.searchable-select-native')&&parseFloat(getComputedStyle(e).fontSize)<16).map(e=>e.name||e.getAttribute('aria-label')||e.className);
   const overflow=document.documentElement.scrollWidth>innerWidth+1;
   const outside=overflow?[...document.querySelectorAll('main *,dialog[open] *')].filter(visible).filter(e=>!e.closest('.contact-table-wrap,.workspace-scroll-table,.direct-stock-table,.notebook-tabs,.table-responsive')).filter(e=>{const r=e.getBoundingClientRect();return r.right>innerWidth+2||r.left< -2;}).slice(0,10).map(e=>({tag:e.tagName,class:e.className,text:e.textContent.trim().slice(0,55)})):[];
   return {overflow,scrollWidth:document.documentElement.scrollWidth,width:innerWidth,undersized,smallFields,outside};
  })()`);
  for(const role of ['admin','seller']){
   await size(390);await navigate('/login.php');
   await ev('document.querySelector("[name=login]").value='+JSON.stringify(process.env[role==='admin'?'NEXO_TEST_LOGIN':'NEXO_TEST_SELLER'])+';document.querySelector("[name=password]").value='+JSON.stringify(process.env.NEXO_TEST_PASSWORD)+';document.querySelector("form").requestSubmit()');
   await until('location.pathname.endsWith("/index.php")&&!!document.querySelector(".konta-module-card")');
   for(const route of routes[role]){
    pageErrors=[];const item={role,route,checks:[],tabs:0};report.pages.push(item);
    try{
     await navigate(route);
     const status=await ev('({title:document.title,text:document.body.textContent.slice(0,500),form:!!document.querySelector("form")})');
     item.title=status.title;
     if(/Fatal error|Warning:|Not Found|Acceso denegado/.test(status.text))report.issues.push({role,route,kind:'page',text:status.text.slice(0,250)});
     for(const theme of report.themes){
      await ev('document.documentElement.dataset.kontaTheme='+JSON.stringify(theme));
      for(const width of report.widths){await size(width);const result=await audit();item.checks.push({theme,width,...result});if(result.overflow||result.undersized.length||result.smallFields.length)report.issues.push({role,route,theme,width,...result});}
     }
     await size(320);
     const tabCount=await ev('document.querySelectorAll("[data-notebook-tabs] a").length');
     for(let n=0;n<tabCount;n++){
      const clicked=await ev('(()=>{const a=document.querySelectorAll("[data-notebook-tabs] a")['+n+'];if(a.closest("[hidden]"))return false;a.click();return true;})()');
      if(clicked){item.tabs++;const result=await audit();if(result.overflow)report.issues.push({role,route,kind:'tab',index:n,...result});}
     }
     // Check header and menus open, since closed controls cannot reveal clipping.
     await ev('const panel=document.querySelector("[data-module-header]");if(panel)panel.open=true;');
     const expanded=await audit();if(expanded.overflow)report.issues.push({role,route,kind:'header',...expanded});
     const menuCount=await ev('document.querySelectorAll(".module-navigation details.module-menu,details.transfer-menu,.konta-user,.company-selector").length');
     item.menus=0;
     for(let n=0;n<menuCount;n++){
      await ev('(()=>{const d=document.querySelectorAll(".module-navigation details.module-menu,details.transfer-menu,.konta-user,.company-selector")['+n+'];d.open=true;d.dispatchEvent(new Event("toggle"));})()');
      const result=await audit();item.menus++;
      if(result.overflow)report.issues.push({role,route,kind:'menu',index:n,...result});
      await ev('document.querySelectorAll(".module-navigation details.module-menu,details.transfer-menu,.konta-user,.company-selector")['+n+'].open=false');
     }
     if(route.includes('new=1')&&route.includes('ventas/index'))await screenshot('sale-form-'+role);
     if(route.includes('new=1')&&route.includes('ventas/index')){
      const compact=await ev('(()=>{const b=document.querySelector(".contact-form-actions [data-konta-iconized=save]");return {name:b?.getAttribute("aria-label"),compact:b?.querySelector(".konta-save-compact")&&getComputedStyle(b.querySelector(".konta-save-compact")).display!=="none"};})()');
      if(!compact.compact||!compact.name)report.issues.push({role,route,kind:'compact-save',...compact});
     }
     if(pageErrors.length)report.issues.push({role,route,kind:'javascript',errors:pageErrors});
    }catch(error){report.issues.push({role,route,kind:'exception',error:error.message});}
    if(report.pages.length%10===0){fs.writeFileSync(path.join(out,'report.json'),JSON.stringify(report,null,2));console.log('Revisadas '+report.pages.length+' vistas; incidencias acumuladas: '+report.issues.length);}
   }
   await navigate('/index.php');await ev('document.querySelector(".konta-user form").requestSubmit()');await until('location.pathname.endsWith("/login.php")');
  }
 }finally{
  fs.writeFileSync(path.join(out,'report.json'),JSON.stringify(report,null,2));
  if(ws)ws.close();chrome.kill();await new Promise(r=>chrome.once('exit',r));
  if(path.dirname(path.resolve(profile))!==path.resolve(os.tmpdir())||!path.basename(profile).startsWith('konta-mobile-audit-'))throw Error('Unsafe cleanup');
  fs.rmSync(profile,{recursive:true,force:true,maxRetries:8,retryDelay:200});
 }
 const severe=report.issues.filter(issue=>issue.overflow||['page','javascript','exception'].includes(issue.kind));
 console.log(JSON.stringify({pages:report.pages.length,checks:report.pages.reduce((sum,p)=>sum+p.checks.length,0),issues:report.issues.length,severe:severe.length}));
 if(severe.length)process.exitCode=1;
})().catch(error=>{console.error(error);process.exitCode=1;});
