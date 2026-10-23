const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
const events = {}, timers = new Map(), requests = [];
let timerId = 0;
const search = {name:'q',value:''}, due = {name:'due',value:'all'};
const form = {action:'http://localhost/project/modules/contabilidad/cuentas-pendientes.php',
    addEventListener(type, handler){events[type]=handler;}, querySelectorAll(){return [search,due];}};
const results = {children:[],setAttribute(){},replaceChildren(...children){this.children=children;}};
const status = {textContent:''};
const report = {querySelector(selector){return {'[data-live-filters]':form,'[data-live-results]':results,'[data-live-filter-status]':status}[selector]||null;}};
const windowEvents = {};
const window = {location:{href:form.action},addEventListener(type,handler){windowEvents[type]=handler;},history:{pushState(_state,_title,url){window.location.href=String(url);}}};
const context = {window,URL,AbortController,
    document:{querySelectorAll(){return [report];}},
    FormData:class { *[Symbol.iterator](){yield ['q',search.value];yield ['due',due.value];yield ['company_scope','private-token'];} },
    DOMParser:class {parseFromString(html){return {querySelector(selector){return selector==='[data-live-results]'?{childNodes:[html]}:null;}};}},
    setTimeout(fn,delay){timers.set(++timerId,{fn,delay});return timerId;},clearTimeout(id){timers.delete(id);},
    fetch(url,options){return new Promise(resolve=>requests.push({url:String(url),options,resolve}));}
};
vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname,'../js/live-report.js'),'utf8'),context);
const settle=()=>new Promise(resolve=>setImmediate(resolve));
const input=()=>events.input({target:{matches(){return true;}}});
const respond=(request,html)=>request.resolve({ok:true,status:200,async text(){return html;}});
async function testModuleFilters(){
    const listeners={},pending=[],timers=new Map(),notifications=[];let id=0,focus=0;
    const search={name:'q',type:'search',value:'nuevo',selectionStart:2,selectionEnd:4,focus(){focus++;},setSelectionRange(start,end){this.selectionStart=start;this.selectionEnd=end;}};
    const available={name:'available',type:'checkbox',value:'1',checked:false};
    const status={textContent:''};
    const form={action:'http://localhost/project/modules/contabilidad/configuracion.php?section=old&q=old&page=99&available=1',addEventListener(type,fn){listeners[type]=fn;},contains(node){return node===search;},querySelectorAll(){return [search,available];}};
    const root={children:[],hasAttribute(){return true;},querySelector(selector){return selector==='[data-live-filters]'?form:selector==='[data-live-filter-status]'?status:null;},setAttribute(){},replaceChildren(...nodes){this.children=nodes;},dispatchEvent(event){notifications.push(event.type);}};
    const window={location:{href:form.action},history:{pushState(_a,_b,url){window.location.href=String(url);}},addEventListener(){}};
    const sandbox={window,URL,AbortController,CustomEvent:class{constructor(type){this.type=type;}},document:{activeElement:search,querySelectorAll(){return [root];}},
        FormData:class{*[Symbol.iterator](){yield ['section','accounts'];yield ['q',search.value];if(available.checked)yield ['available','1'];}},
        DOMParser:class{parseFromString(){const next={childNodes:[]};const incomingForm={querySelectorAll(){return [search,available];},replaceWith(node){next.childNodes[0]=node;}};next.childNodes=[incomingForm,'rows'];return {querySelector(selector){return selector==='[data-live-module]'?next:selector==='[data-live-filters]'?incomingForm:null;}};}},
        setTimeout(fn,delay){timers.set(++id,{fn,delay});return id;},clearTimeout(id){timers.delete(id);},fetch(url){return new Promise(resolve=>pending.push({url:String(url),resolve}));}};
    vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname,'../js/live-report.js'),'utf8'),sandbox);
    listeners.change({target:{matches(){return true;}}});
    assert.match(pending[0].url,/section=accounts/);assert.ok(!pending[0].url.includes('page=')&&!pending[0].url.includes('available='));
    pending[0].resolve({ok:true,status:200,async text(){return 'page';}});await settle();
    assert.equal(root.children[0],form,'Se debe conservar el formulario que recibe escritura.');assert.equal(focus,1);assert.equal(search.selectionStart,2);assert.equal(search.selectionEnd,4);
    assert.deepEqual(notifications,['live-filters:updated']);
    available.checked=true;listeners.change({target:{matches(){return true;}}});assert.match(pending[1].url,/available=1/);
    pending[1].resolve({ok:true,status:200,async text(){return 'page';}});await settle();
}
(async()=>{
    search.value='a';input();search.value='ab';input();
    assert.equal(requests.length,0);assert.equal([...timers.values()].filter(timer=>timer.delay===300).length,1);
    [...timers.values()].find(timer=>timer.delay===300).fn();
    assert.equal(requests.length,1);assert.match(requests[0].url,/q=ab/);assert.ok(!requests[0].url.includes('private-token'));
    due.value='overdue';events.change({target:{matches(){return true;}}});
    assert.equal(requests.length,2);assert.ok(requests[0].options.signal.aborted);assert.match(requests[1].url,/due=overdue/);
    respond(requests[1],'new results');await settle();respond(requests[0],'old results');await settle();
    assert.deepEqual(results.children,['new results']);assert.equal(search.value,'ab');assert.match(window.location.href,/due=overdue/);
    search.value='error';input();[...timers.values()].filter(timer=>timer.delay===300).at(-1).fn();
    requests.at(-1).resolve({ok:false,status:503,async text(){return 'service error';}});await settle();
    assert.deepEqual(results.children,['new results']);assert.match(status.textContent,/conservan los últimos/);
    await testModuleFilters();
    console.log('PASS: búsqueda automática, selectores y casillas, rutas/secciones, cancelación, resultados obsoletos, foco y conservación ante fallos.');
})().catch(error=>{console.error(error);process.exitCode=1;});
