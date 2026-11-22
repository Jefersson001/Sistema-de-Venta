const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),assert=require('node:assert/strict'),{spawn}=require('node:child_process');
(async()=>{
 const base=process.env.NEXO_TEST_BASE_URL;assert.ok(base,'Use isolated ticket runner.');
 const profile=fs.mkdtempSync(path.join(os.tmpdir(),'konta-whatsapp-ui-')),out=path.resolve('work/whatsapp-review');fs.mkdirSync(out,{recursive:true});
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
  await ev(`(()=>{const f=document.querySelector('[data-company-selector]');document.body.dataset.pendingSwitch='1';for(const c of f.querySelectorAll('[name="companies[]"]'))c.checked=c.value==='1';f.querySelector('[name=active_company]').value='1';f.requestSubmit();})()`);
  await until('document.readyState==="complete"&&!document.body.dataset.pendingSwitch&&document.querySelector("[name=active_company]")?.value==="1"');
  const checks=[];
  const routes=[
   ['/modules/contabilidad/index.php?type=customer&id='+process.env.NEXO_MAIL_INVOICE,'invoice:'+process.env.NEXO_MAIL_INVOICE],
   ['/modules/ventas/index.php?id='+process.env.NEXO_MAIL_ORDER,'order:'+process.env.NEXO_MAIL_ORDER],
   ['/modules/inventario/operaciones.php?type=delivery&id='+process.env.NEXO_MAIL_DELIVERY,'operation:'+process.env.NEXO_MAIL_DELIVERY]
  ];
  for(const width of [390,1440])for(const theme of ['light','dark']){
   await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<600});
   await navigate('/modules/ajustes/whatsapp.php');await until('!!document.querySelector("[name=country_code]")');await ev('localStorage.setItem("konta-theme",'+JSON.stringify(theme)+');document.documentElement.dataset.kontaTheme='+JSON.stringify(theme));
   assert.equal(await ev('document.documentElement.scrollWidth<=innerWidth+1'),true,'Settings overflow');
   let shot=await call('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(out,'settings-'+width+'-'+theme+'.png'),Buffer.from(shot.data,'base64'));
   for(const [route,expected] of routes){
    await navigate(route);await until('!!document.querySelector("[data-open-document-share]")');await ev('document.documentElement.dataset.kontaTheme='+JSON.stringify(theme)+';document.querySelector("[data-open-document-share]").click()');
    await until('document.querySelector("[data-document-share-dialog]").open&&document.querySelector("[data-document-share-dialog] iframe").contentDocument?.readyState==="complete"&&!!document.querySelector("[data-document-share-dialog] iframe").contentDocument?.querySelector("[name=phone]")');
    const state=await ev(`(()=>{const dialog=document.querySelector('[data-document-share-dialog]'),d=dialog.querySelector('iframe').contentDocument,r=dialog.getBoundingClientRect();return {inside:r.left>=0&&r.right<=innerWidth+1,overflow:d.documentElement.scrollWidth>d.documentElement.clientWidth+1,checked:[...d.querySelectorAll('[name="documents[]"]:checked')].map(i=>i.value),phone:d.querySelector('[name=phone]').value};})()`);
    assert.equal(state.inside,true);assert.equal(state.overflow,false);assert.deepEqual(state.checked,['message']);assert.equal(state.phone,'0412 1234567');checks.push({route,width,theme,...state});
    const layout=await ev(`(()=>{const d=document.querySelector('[data-document-share-dialog] iframe').contentDocument,f=d.querySelector('.email-wizard-footer').getBoundingClientRect();return {footer:f.top>=0&&f.bottom<=d.documentElement.clientHeight+1,editor:!!d.querySelector('[name=message_template]'),metadata:!!d.querySelector('.email-compose-heading p'),preview:d.querySelector('.email-message-preview').textContent};})()`);
    assert.equal(layout.footer,true);assert.equal(layout.editor,false);assert.equal(layout.metadata,false);assert.ok(layout.preview.includes('Producto de prueba')||expected.startsWith('operation:'));checks.push({width,theme,footer:layout.footer});
    if(expected.startsWith('invoice:')){
     const withPdf=width===1440&&theme==='light';
     await ev(`(()=>{const d=document.querySelector('[data-document-share-dialog] iframe').contentDocument,f=d.querySelector('#whatsapp-documents-form');d.wizardOriginalForm=f;d.querySelector('[name=phone]').value='+58 (412) 123-4567';d.querySelector('.email-message-preview').open=true;${withPdf?`d.querySelector('[value="${expected}"]').checked=true;`:''}})()`);
     await ev(`(()=>{const d=document.querySelector('[data-document-share-dialog] iframe').contentDocument;d.querySelector('form').requestSubmit();})()`);
     await until('document.querySelector("[data-document-share-dialog] iframe").contentDocument?.querySelector("[data-share-repeat-confirmation]")?.open');
     await ev(`document.querySelector('[data-document-share-dialog] iframe').contentDocument.querySelector('[data-share-repeat-no]').click()`);
     assert.equal(await ev(`document.querySelector('[data-document-share-dialog] iframe').contentDocument.querySelector('[data-share-repeat-confirmation]').open`),false);
     await ev(`document.querySelector('[data-document-share-dialog] iframe').contentDocument.querySelector('#whatsapp-documents-form').requestSubmit()`);
     await ev(`document.querySelector('[data-document-share-dialog] iframe').contentDocument.querySelector('[name=confirm_share]').click()`);
     try{await until('document.querySelector("[data-document-share-dialog] iframe").contentDocument?.querySelector("#whatsapp-documents-form")?.dataset.ready==="1"');}catch(error){throw Error(error.message+' Wizard: '+await ev(`document.querySelector('[data-document-share-dialog] iframe').contentDocument?.querySelector('[data-share-error]')?.textContent`));}
     const preserved=await ev(`(()=>{const d=document.querySelector('[data-document-share-dialog] iframe').contentDocument;return {same:d.wizardOriginalForm===d.querySelector('#whatsapp-documents-form'),phone:d.querySelector('[name=phone]').value,checked:[...d.querySelectorAll('[name="documents[]"]:checked')].map(c=>c.value),preview:d.querySelector('.email-message-preview').open,buttons:[...d.querySelectorAll('.email-wizard-footer button,.email-wizard-footer a')].filter(b=>!b.hidden).map(b=>b.textContent)};})()`);
     assert.equal(preserved.same,true);assert.equal(preserved.phone,'+58 (412) 123-4567');assert.deepEqual(preserved.checked,withPdf?['message',expected]:['message']);assert.equal(preserved.preview,true);assert.deepEqual(preserved.buttons,['Abrir WhatsApp','Copiar','Cancelar']);
     assert.equal(await ev(`(()=>{const d=document.querySelector('[data-document-share-dialog] iframe').contentDocument;return [...d.querySelectorAll('#whatsapp-documents-form input:not([type=hidden])')].every(input=>input.disabled);})()`),true,'Prepared phone and choices remained editable');
     assert.equal(await ev(`!!document.querySelector('[data-document-share-dialog] iframe').contentDocument.querySelector('[data-share-url]')`),false,'Text-only created a public URL');
     assert.equal(await ev(`document.querySelector('[data-document-share-dialog] iframe').contentDocument.querySelector('[data-whatsapp-chat]').href.startsWith('https://wa.me/584121234567?text=')`),true);
     assert.equal(await ev(`(()=>{const d=document.querySelector('[data-document-share-dialog] iframe').contentDocument,t=d.querySelector('[data-share-message]').textContent;return t.includes('Banco QA')&&t.includes('50,00')&&t.includes('80,00');})()`),true,'Prepared rich WhatsApp message incomplete');
     assert.equal(await ev(`(()=>{const d=document.querySelector('[data-document-share-dialog] iframe').contentDocument;return d.documentElement.scrollWidth<=d.documentElement.clientWidth+1;})()`),true,'Prepared message overflow');
     shot=await call('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(out,'ready-'+width+'-'+theme+'.png'),Buffer.from(shot.data,'base64'));
     await ev(`document.querySelector('[data-document-share-dialog] iframe').contentDocument.querySelector('[data-dismiss-share-wizard]').click()`);
     assert.equal(await ev('document.querySelector("[data-document-share-dialog]").open'),true,'First cancel closed the dialog');
     assert.equal(await ev(`(()=>{const d=document.querySelector('[data-document-share-dialog] iframe').contentDocument;return d.querySelector('[data-whatsapp-chat]').hidden&&!d.querySelector('[data-share-prepare]').hidden&&d.wizardOriginalForm===d.querySelector('#whatsapp-documents-form');})()`),true,'First cancel did not restore initial buttons');
     assert.equal(await ev(`(()=>{const d=document.querySelector('[data-document-share-dialog] iframe').contentDocument;return [...d.querySelectorAll('#whatsapp-documents-form input:not([type=hidden])')].every(input=>!input.disabled);})()`),true,'Cancel did not restore editing');
    }
    shot=await call('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(out,expected.split(':')[0]+'-'+width+'-'+theme+'.png'),Buffer.from(shot.data,'base64'));
    await ev(`document.querySelector('[data-document-share-dialog] iframe').contentDocument.querySelector('[data-dismiss-share-wizard]').click()`);assert.equal(await ev('document.querySelector("[data-document-share-dialog]").open'),false);
   }
  }
  assert.deepEqual(errors,[]);fs.writeFileSync(path.join(out,'report.json'),JSON.stringify({checks,errors},null,2));console.log('PASS: WhatsApp settings and invoice/order/delivery dialogs on phone/desktop, light/dark, prepared chat, no overflow or real sending.');await call('Browser.close');
 }finally{ws?.close();child.kill();await new Promise(r=>setTimeout(r,1500));if(path.dirname(profile)===path.resolve(os.tmpdir())&&path.basename(profile).startsWith('konta-whatsapp-ui-'))fs.rmSync(profile,{recursive:true,force:true,maxRetries:12,retryDelay:250});}
})().catch(e=>{console.error(e);process.exitCode=1;});
