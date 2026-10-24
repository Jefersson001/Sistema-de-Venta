// Run with php test/restaurant.php <screenshot-directory> and a temporary CDP browser.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
(async () => {
    const fixtureServer=http.createServer((request,response)=>{
        const match=/^\/(map|pos|configuration|new|account|accounts|table)\.html$/.exec(request.url);
        if(!match){response.writeHead(404);response.end();return;}
        response.writeHead(200,{'Content-Type':'text/html; charset=utf-8','Cache-Control':'no-store'});
        response.end(fs.readFileSync(path.join(process.argv[2],match[1]+'.html')));
    });
    await new Promise(resolve=>fixtureServer.listen(0,'127.0.0.1',resolve));
    const fixtureOrigin='http://127.0.0.1:'+fixtureServer.address().port;
    const endpoint = 'http://127.0.0.1:' + (process.env.NEXO_CDP_PORT || '9337');
    const target = await (await fetch(endpoint + '/json/new?about:blank', {method:'PUT', signal:AbortSignal.timeout(5000)})).json();
    const socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve,reject) => {socket.onopen=resolve;socket.onerror=reject;});
    let sequence=0, onPageLoaded=null;
    const pending=new Map(), errors=[];
    socket.onmessage=event=>{
        const message=JSON.parse(event.data);
        if(message.method==='Page.loadEventFired'&&onPageLoaded){const loaded=onPageLoaded;onPageLoaded=null;loaded();}
        if(message.method==='Runtime.exceptionThrown')errors.push(message.params.exceptionDetails.text);
        const request=pending.get(message.id);if(!request)return;
        pending.delete(message.id);clearTimeout(request.timeout);
        if(message.error)request.reject(new Error(message.error.message));else request.resolve(message.result);
    };
    const call=(method,params={})=>new Promise((resolve,reject)=>{
        const id=++sequence;const timeout=setTimeout(()=>{pending.delete(id);reject(new Error('Timeout '+method));},15000);
        pending.set(id,{resolve,reject,timeout});socket.send(JSON.stringify({id,method,params}));
    });
    const evaluate=async expression=>{
        const result=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});
        if(result.exceptionDetails)throw new Error(result.exceptionDetails.exception?.description||result.exceptionDetails.text);
        return result.result.value;
    };
    const navigate=async name=>{
        // Serve the real rendered fixture on HTTP, preserving native sessionStorage between views.
        const loaded=new Promise((resolve,reject)=>{const timeout=setTimeout(()=>reject(new Error('Navigation timeout')),5000);onPageLoaded=()=>{clearTimeout(timeout);resolve();};});
        await call('Page.navigate',{url:fixtureOrigin+'/'+name+'.html'});await loaded;
        await evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))');
    };
    const capture=async name=>{
        fs.mkdirSync(process.argv[3],{recursive:true});
        await evaluate('window.scrollTo(0,0);new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))');
        const result=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});
        fs.writeFileSync(path.join(process.argv[3],name+'.png'),Buffer.from(result.data,'base64'));
    };
    try{
        await call('Page.enable');await call('Runtime.enable');
        for(const width of [1440,390]){
            await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width===390});
            for(const name of (process.argv[4]==='pos'?['map','pos']:['map','configuration','new','account','accounts','table','pos'])){
                console.log('CHECK restaurant '+name+' '+width);
                await navigate(name);
                assert.ok(await evaluate('!!document.querySelector(".restaurant-workspace")'));
                assert.ok(await evaluate('document.documentElement.scrollWidth')<=width,'Horizontal page overflow: '+name);
                if(name==='map'){
                    assert.ok(await evaluate('document.querySelectorAll(".restaurant-table").length')>=(process.argv[4]==='pos'?1:6));
                    const minWidth=await evaluate('Math.min(...[...document.querySelectorAll(".restaurant-table")].map(t=>t.getBoundingClientRect().width))');
                    assert.ok(minWidth>=110,'Table targets too narrow.');
                    await evaluate('{const select=document.querySelector("[data-restaurant-area]");select.value=select.options[1].value;select.dispatchEvent(new Event("change"));}');
                    assert.equal(await evaluate('[...document.querySelectorAll("[data-restaurant-area-id]")].filter(a=>!a.hidden).length'),1);
                }
                if(name==='new'){
                    assert.ok(await evaluate('!!document.querySelector("[name=company_scope]")'));
                    assert.ok(await evaluate('document.querySelector("[name=request_key]").value.length===64'));
                    assert.ok(await evaluate('document.querySelector(".restaurant-workspace [type=submit]").getBoundingClientRect().height')>=42);
                }
                if(name==='account')assert.equal(await evaluate('!!document.querySelector("[value=close_empty_account]")'),false,'Closed account offered another close.');
                if(name==='accounts'){
                    assert.equal(await evaluate('[...document.querySelectorAll("[data-restaurant-account-state=closed]")].every(a=>a.hidden)'),true);
                    await evaluate('document.querySelector("[data-restaurant-account-filter=all]").click()');
                    assert.equal(await evaluate('[...document.querySelectorAll("[data-restaurant-account-state=closed]")].every(a=>!a.hidden)'),true);
                }
                if(name==='pos'){
                    assert.ok(await evaluate('!!document.querySelector("[data-pos-cart]")'));
                    assert.ok(await evaluate('document.querySelectorAll(".restaurant-pos-line").length>0'));
                    const categories=await evaluate('JSON.parse(document.getElementById("pos-category-fixture")?.textContent||"null")');
                    if(categories){
                        const visibleProducts=()=>evaluate('[...document.querySelectorAll("[data-pos-product]")].filter(p=>!p.hidden).map(p=>Number(p.querySelector("[name=product_id]").value)).sort((a,b)=>a-b)');
                        const choose=async id=>evaluate(`document.querySelector('[data-pos-category="${id}"]').click()`);
                        await choose(categories.root);
                        assert.deepEqual(await visibleProducts(),categories.rootProducts.toSorted((a,b)=>a-b),'A parent must include its own products and all descendants.');
                        assert.equal(await evaluate(`document.querySelector('[data-pos-subcategories="${categories.root}"]').hidden`),false);
                        await choose(categories.kitchen);
                        assert.deepEqual(await visibleProducts(),categories.chickenProducts);
                        assert.equal(await evaluate(`document.querySelector('[data-pos-subcategories="${categories.kitchen}"]').hidden`),false);
                        await choose(categories.chicken);
                        assert.deepEqual(await visibleProducts(),categories.chickenProducts);
                        await choose(categories.drinks);
                        assert.deepEqual(await visibleProducts(),categories.drinksProducts);
                        assert.equal(await evaluate(`document.querySelector('[data-pos-subcategories="${categories.kitchen}"]').hidden`),true,'Switching branches must close unrelated subcategories.');
                        await choose(categories.bar);
                        assert.deepEqual(await visibleProducts(),categories.barProducts);
                        assert.equal(await evaluate(`document.querySelector('[data-pos-subcategories="${categories.root}"]').hidden`),true);
                        assert.equal(await evaluate('document.querySelector("[data-pos-category=uncategorized]")'),null);
                        assert.equal(await evaluate(`document.querySelector('[data-pos-category="${categories.shopCategory}"]')`),null,'Unmarked principal categories must not appear.');
                        await choose('');
                        assert.ok(!(await visibleProducts()).includes(categories.uncategorizedProduct));
                        assert.ok(!(await visibleProducts()).includes(categories.shopProduct),'Todos must exclude unmarked families.');
                        await choose(categories.root);
                        await evaluate('{const search=document.querySelector("[data-pos-search]");search.value="producto-inexistente-abc123";search.dispatchEvent(new Event("input"));}');
                        assert.equal(await evaluate('document.querySelector("[data-pos-no-results]").hidden'),false);
                        await navigate('pos');
                        assert.equal(await evaluate(`document.querySelector('[data-pos-category="${categories.root}"]').getAttribute('aria-pressed')`),'true','Keep the selected category between tables.');
                        assert.deepEqual(await visibleProducts(),categories.rootProducts.toSorted((a,b)=>a-b));
                        await choose('');
                        console.log('PASS category hierarchy, branch switching, search and remembered selection '+width);
                    }
                    await evaluate('{const input=document.querySelector("[data-pos-search]");input.value=document.querySelector(".restaurant-pos-line-title strong").textContent;input.dispatchEvent(new Event("input"));}');
                    assert.equal(await evaluate('[...document.querySelectorAll("[data-pos-product]")].filter(p=>!p.hidden).length'),1);
                    await evaluate('document.querySelector("[data-pos-open-checkout]").click()');
                    assert.equal(await evaluate('document.querySelector("[data-pos-checkout-dialog]").open'),true);
                    assert.ok(await evaluate('document.querySelector("[data-pos-checkout-dialog]").getBoundingClientRect().width')<=width);
                    await evaluate('document.querySelector("[data-pos-close-checkout]").click()');
                    const command = await evaluate(`(async () => {
                        const form = [...document.querySelectorAll('[data-pos-product]')].find(product => !product.hidden);
                        const endpoint = 'http://127.0.0.1/project/modules/restaurante/cuenta.php?id=123';
                        form.setAttribute('action', endpoint);
                        const previousKey = form.querySelector('[name=request_key]').value;
                        const html = document.querySelector('[data-pos-cart]').innerHTML;
                        let sent;
                        const originalFetch = window.fetch;
                        window.fetch = async (url, options) => {
                            sent = {url: String(url), action: options.body.get('action'), csrf: options.body.get('csrf'), key: options.body.get('request_key')};
                            return {ok:true, json:async()=>({ok:true, html, version:42, closed:false})};
                        };
                        try {
                            form.requestSubmit();
                            for(let i=0;i<20 && !document.querySelector('[data-pos-saving]').hidden;i++) await new Promise(resolve=>setTimeout(resolve,10));
                            return {sent, previousKey, version:form.querySelector('[name=version]').value,
                                error:!document.querySelector('[data-pos-error]').hidden,
                                enabled:!form.querySelector('button').disabled};
                        } finally {window.fetch=originalFetch;}
                    })()`);
                    assert.equal(command.sent.url,'http://127.0.0.1/project/modules/restaurante/cuenta.php?id=123','The hidden action field must not replace the request URL.');
                    assert.equal(command.sent.action,'pos_add');
                    assert.equal(command.sent.key,command.previousKey);
                    assert.ok(command.sent.csrf);
                    assert.equal(command.version,'42');
                    assert.equal(command.error,false);
                    assert.equal(command.enabled,true);
                }
                await capture(name+'-'+width);
            }
        }
        assert.deepEqual(errors,[],'Uncaught browser exceptions');
        console.log('PASS restaurant browser: desktop/mobile layout, map filters, account filters, accessible controls and no page overflow.');
    }finally{
        fixtureServer.closeAllConnections();
        await call('Page.close').catch(()=>{});socket.close();await new Promise(resolve=>fixtureServer.close(resolve));
    }
})().catch(error=>{console.error(error);process.exitCode=1;});
