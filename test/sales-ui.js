const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const assert = require('node:assert/strict');

function node(value = '') {
    return { value, hidden: true, disabled: false, textContent: '', events: {},
        children:[],attributes:{},dataset:{},
        addEventListener(event, listener) { this.events[event] = listener; }, focus() { this.focused = true; },
        setAttribute(key,value){this.attributes[key]=value;},append(...items){this.children.push(...items);},
        setCustomValidity(message){this.validationMessage=message;} };
}

function select(options, value = '') {
    const element = node(value);
    element.options = options;
    element.append=option=>options.push(option);
    Object.defineProperty(element, 'selectedOptions', { get: () => options.filter(option => option.value === element.value) });
    return element;
}

let rows = [];
function row(index, productId = '', quantity = '1', price = '0.00') {
    const fields = {
        '[data-line-product]': select([{ value: '', dataset: {} }, { value: '1', dataset: { price: '19.99', tax: '7' } }, { value: '2', dataset: { price: '0.10', tax: '7' } }], productId),
        '[data-line-quantity]': node(quantity), '[data-line-price]': node(price),
        '[data-line-tax]': select([{ value: '', dataset: {} }, { value: '7', dataset: {} }]),
        '[data-line-base]': node(), '[data-line-total]': node(), '[data-remove-line]': node(),
        '[data-line-reference]': node(), '[data-line-commission]': node(),
        '[data-line-commission-unit]':node(), '[data-line-id]':node('0'),
    };
    const named = [fields['[data-line-product]'], fields['[data-line-quantity]'], fields['[data-line-price]'], fields['[data-line-tax]']];
    named.forEach((field, position) => { field.name = `lines[${index}][field${position}]`; });
    return { fields, isConnected:true, querySelector: selector => fields[selector], querySelectorAll: () => named,
        remove() { this.isConnected=false;rows = rows.filter(item => item !== this); } };
}

rows = [row(0, '1', '3.120', '19.99'), row(1, '2', '0.05', '0.10')];
const lines = { querySelectorAll: () => rows, append(fragment) { rows.push(fragment.row); } };
const add = node();
const template = { content: { cloneNode() { const newRow = row('__INDEX__'); return { row: newRow, querySelector: () => newRow }; } } };
const customer = select([{ value: '', dataset: {} }, { value: '8', dataset: { address: 'Calle, Ciudad, Estado, 12345, País', salesperson: '9' } }, { value: '10', dataset: { address: 'Otra dirección', salesperson: '' } }], '8');
const seller = select([{ value: '', dataset: {} }, { value: '9', dataset: { storageLabel: 'Estante / Almacén Principal' } }, { value: '11', dataset: { storageLabel: 'Almacén Secundario' } }]);
const storage = { ...node(), dataset: { preserveStorage: 'false' } };
const invoice = node();
const delivery = node();
const baseTotal = node();
const grandTotal = node();
const commissionTotal = node();
let taxRows=[];const taxTotalRow={parentElement:{insertBefore(row){row.remove=()=>{taxRows=taxRows.filter(item=>item!==row);};taxRows.push(row);}}};
const saveButton=node();const pricingStatus=node();const commissionHelp=node();
const fields = { '[data-quotation-customer]': customer, '[data-quotation-salesperson]': seller,
    '[data-quotation-lines]': lines, '[data-add-line]': add, '[data-quotation-line-template]': template,
    '[data-invoice-address]': invoice, '[data-delivery-address]': delivery,
    '[data-quotation-base]': baseTotal, '[data-quotation-total]': grandTotal, '[data-quotation-storage]': storage, '[data-quotation-commission]': commissionTotal,
    '[data-quotation-total-row]':taxTotalRow,'[data-quotation-pricing-status]':pricingStatus,'[data-quotation-commission-help]':commissionHelp };
const form = { ...node(), querySelector: selector => fields[selector],querySelectorAll:selector=>selector==='[data-quotation-tax-group]'?[...taxRows]:selector==='button[type="submit"]'?[saveButton]:[] };
const actionButtons = [node(), node()];
const dirtyHelp = node();
const statusForm = { querySelectorAll: () => actionButtons, querySelector: () => dirtyHelp };
const document = { querySelector: selector => selector === '[data-quotation-form]' ? form : statusForm,createElement:()=>node() };

const pageEvents={};
const context={document,window:{addEventListener(event,listener){pageEvents[event]=listener;}}};vm.createContext(context);
vm.runInContext(fs.readFileSync(path.join(__dirname,'../js/product-commissions.js'),'utf8'),context);
vm.runInContext(fs.readFileSync(path.join(__dirname, '../js/sales.js'), 'utf8'), context);
assert.equal(seller.value, '9');
assert.equal(storage.value, 'Estante / Almacén Principal');
assert.equal(baseTotal.textContent, '62.38');
assert.equal(grandTotal.textContent, '62.38');
assert.equal(rows[0].fields['[data-line-quantity]'].value,'3.12');
rows[0].fields['[data-line-quantity]'].value='1.000';pageEvents.pageshow();
assert.equal(rows[0].fields['[data-line-quantity]'].value,'1.00','Una cotización restaurada debe mostrar dos decimales.');
assert.equal(grandTotal.textContent,'20.00');
rows[0].fields['[data-line-quantity]'].value='1,5';rows[0].fields['[data-line-quantity]'].events.blur();
assert.equal(rows[0].fields['[data-line-quantity]'].value,'1.50');
assert.equal(grandTotal.textContent,'30.00');
rows[0].fields['[data-line-quantity]'].value='1.125';rows[0].fields['[data-line-quantity]'].events.blur();
assert.equal(rows[0].fields['[data-line-quantity]'].value,'1.125','No se debe ocultar una cantidad inválida redondeándola al editar.');
assert.equal(grandTotal.textContent,'—');
rows[0].fields['[data-line-quantity]'].value='3.12';pageEvents.pageshow();
rows[0].fields['[data-line-reference]'].value='5.00';
rows[0].fields['[data-line-price]'].value='7.00';
rows[0].fields['[data-line-quantity]'].value='3.00';
form.events.input();assert.equal(commissionTotal.textContent,'6.00');
rows[0].fields['[data-line-tax]'].value='7';rows[0].fields['[data-line-tax]'].options[1].dataset.rate='16.00';
form.events.input();assert.equal(commissionTotal.textContent,'6.00','El impuesto no debe incrementar la comisión.');
customer.value='10';customer.events.change();form.events.change();assert.equal(commissionTotal.textContent,'0.00','Sin vendedor no hay comisión.');
customer.value='8';customer.events.change();form.events.change();assert.equal(commissionTotal.textContent,'6.00');
rows[0].fields['[data-line-price]'].value='4.00';form.events.input();assert.equal(commissionTotal.textContent,'0.00','Un descuento no produce comisión negativa.');
rows[0].fields['[data-line-price]'].value='19.99';rows[0].fields['[data-line-quantity]'].value='3.12';rows[0].fields['[data-line-reference]'].value='';rows[0].fields['[data-line-tax]'].value='';rows[0].fields['[data-line-tax]'].options[1].dataset.rate='0';form.events.input();
rows[0].fields['[data-line-tax]'].value = '7';
rows[0].fields['[data-line-tax]'].options[1].dataset.rate = '16.00';
form.events.input();
assert.equal(rows[0].fields['[data-line-base]'].textContent, '62.37');
assert.equal(rows[0].fields['[data-line-total]'].textContent, '72.35');
assert.equal(grandTotal.textContent, '72.36');
rows[0].fields['[data-line-tax]'].options[1].dataset.rate = '0';
form.events.input();
assert.equal(rows[1].fields['[data-line-base]'].textContent, '0.01');
assert.equal(add.hidden, false);
customer.value = '8'; customer.events.change();
assert.equal(invoice.value, 'Calle, Ciudad, Estado, 12345, País');
assert.equal(delivery.value, invoice.value);
assert.equal(seller.value, '9');
customer.value = '10'; customer.events.change();
assert.equal(seller.value, '');
assert.equal(storage.value, '');
customer.value = '8'; customer.events.change();
assert.equal(seller.value, '9');
seller.value = '11'; seller.events.change();
assert.equal(storage.value, 'Almacén Secundario');
storage.dataset.preserveStorage = 'true';
seller.value = '9'; seller.events.change();
assert.equal(storage.value, 'Almacén Secundario');
storage.dataset.preserveStorage = 'false';
add.events.click();
assert.equal(rows.length, 3);
assert.ok(rows[2].fields['[data-line-product]'].name.includes('lines[2]'));
assert.equal(rows[2].fields['[data-line-product]'].focused, true);
assert.ok(actionButtons.every(button => button.disabled));
assert.equal(dirtyHelp.hidden, false);
const last = rows[2];
last.fields['[data-line-product]'].value = '1'; last.fields['[data-line-product]'].events.change();
assert.equal(last.fields['[data-line-price]'].value, '19.99');
assert.equal(last.fields['[data-line-tax]'].value, '7');
assert.equal(grandTotal.textContent, '82.37');
last.fields['[data-line-quantity]'].value = '2,5'; form.events.input();
assert.equal(grandTotal.textContent, '112.36');
last.fields['[data-line-quantity]'].value = '-1'; form.events.input();
assert.equal(grandTotal.textContent, '—');
last.fields['[data-remove-line]'].events.click();
assert.equal(rows.length, 2);
assert.equal(grandTotal.textContent, '62.38');
console.log('PASS: addresses, salesperson, product price/tax, add/remove lines, exact totals and unsaved-change protection.');
const configured=rows[0];
const option=configured.fields['[data-line-product]'].options[1];
option.dataset={price:'5.00',tax:'7',commissionType:'fixed',commissionValue:'2.00'};
configured.fields['[data-line-product]'].value='1';configured.fields['[data-line-quantity]'].value='2.00';
configured.fields['[data-line-product]'].events.change();
assert.equal(configured.fields['[data-line-price]'].value,'7.00');
assert.equal(configured.fields['[data-line-commission-unit]'].value,'2.00');
assert.equal(commissionTotal.textContent,'4.00');
configured.fields['[data-line-price]'].value='8.00';form.events.input();
assert.equal(commissionTotal.textContent,'4.00','La comisión fija no cambia por editar el precio cobrado.');
seller.value='';seller.events.change();assert.equal(configured.fields['[data-line-price]'].value,'5.00');assert.equal(commissionTotal.textContent,'0.00');
seller.value='9';seller.events.change();assert.equal(configured.fields['[data-line-price]'].value,'7.00');
option.dataset.commissionType='percent';option.dataset.commissionValue='40';configured.fields['[data-line-product]'].events.change();
assert.equal(configured.fields['[data-line-price]'].value,'7.00');assert.equal(commissionTotal.textContent,'4.00');
option.dataset.commissionValue='20';option.dataset.price='10.00';
seller.value='11';seller.events.change();assert.equal(configured.fields['[data-line-price]'].value,'7.00','Cambiar de vendedor no modifica una regla guardada o seleccionada.');
configured.fields['[data-line-id]'].value='45';configured.fields['[data-line-product]'].events.change();
assert.equal(configured.fields['[data-line-price]'].value,'12.00');assert.equal(configured.fields['[data-line-id]'].value,'0');
console.log('PASS: precio automático por producto, vendedor/tienda, comisión fija, porcentaje y actualización explícita del producto.');

rows=[configured];
configured.fields['[data-line-quantity]'].value='1.00';
option.dataset={price:'1.00',tax:'7',commissionType:'fixed',commissionValue:'0.20'};
configured.fields['[data-line-tax]'].options[1].dataset={catalogRate:'10.00',catalogName:'IVA'};
configured.fields['[data-line-product]'].events.change();
assert.equal(baseTotal.textContent,'1.20');assert.equal(grandTotal.textContent,'1.32');
assert.equal(taxRows.length,1);assert.equal(taxRows[0].children[0].textContent,'IVA (10.00 %)');assert.equal(taxRows[0].children[1].textContent,'0.12');
const repeated=row(6,'1','1.00','1.20');repeated.fields['[data-line-tax]'].value='7';repeated.fields['[data-line-tax]'].options[1].dataset={rate:'10.00',taxName:'IVA'};rows.push(repeated);
form.events.input();assert.equal(taxRows.length,1);assert.equal(taxRows[0].children[1].textContent,'0.24');assert.equal(grandTotal.textContent,'2.64');
repeated.fields['[data-line-tax]'].options[1].dataset.rate='5.00';form.events.input();assert.equal(taxRows.length,2,'Los porcentajes distintos deben mostrarse por separado.');
rows=[configured];configured.fields['[data-line-tax]'].value='';form.events.input();assert.equal(taxRows.length,0,'Quitar el impuesto debe quitar su desglose.');

context.AbortController=AbortController;context.setTimeout=setTimeout;context.clearTimeout=clearTimeout;context.window={alert(message){throw new Error(message);}};
const requests=[];
context.fetch=(url,options)=>new Promise(resolve=>requests.push({url,options,resolve}));
form.dataset.productEndpoint='producto.php';
function response(id,price,commission,rate='10.00') {return {ok:true,async json(){return {product:{id,sale_price:price,currency_code:'USD',commission_type:'fixed',commission_value:commission},tax:{id:7,name:'IVA',rate}};}};}
(async()=>{
    const pending=configured.fields['[data-line-product]'].events.change();
    assert.equal(saveButton.disabled,true);assert.equal(pricingStatus.hidden,false);assert.ok(configured.fields['[data-line-price]'].validationMessage);
    assert.equal(requests[0].options.cache,'no-store');requests.shift().resolve(response(1,'5.00','2.00'));await pending;
    assert.equal(configured.fields['[data-line-price]'].value,'7.00','La respuesta debe actualizar una configuración cargada antes de editar el producto.');
    assert.equal(configured.fields['[data-line-commission-unit]'].value,'2.00');assert.equal(saveButton.disabled,false);assert.equal(taxRows[0].children[1].textContent,'0.70');
    const first=configured.fields['[data-line-product]'].events.change();
    configured.fields['[data-line-product]'].value='2';const latest=configured.fields['[data-line-product]'].events.change();
    const olderRequest=requests.shift(),newerRequest=requests.shift();newerRequest.resolve(response(2,'1.00','0.20'));await latest;
    olderRequest.resolve(response(1,'9.00','1.00'));await first;
    assert.equal(configured.fields['[data-line-price]'].value,'1.20','Una respuesta anterior no debe reemplazar el último producto seleccionado.');
    assert.equal(grandTotal.textContent,'1.32');assert.equal(taxRows[0].children[1].textContent,'0.12');
    const failed=configured.fields['[data-line-product]'].events.change();requests.shift().resolve({ok:false,async json(){return {error:'Fallo simulado'};}});await failed;
    assert.equal(saveButton.disabled,true);assert.equal(pricingStatus.hidden,false);
    let blocked=false;form.events.submit({preventDefault(){blocked=true;}});assert.equal(blocked,true);
    configured.fields['[data-line-product]'].value='';await configured.fields['[data-line-product]'].events.change();
    assert.equal(saveButton.disabled,false);assert.equal(configured.fields['[data-line-price]'].validationMessage,'');
    console.log('PASS: desglose de impuestos, agrupación, producto actualizado, respuestas concurrentes y bloqueo ante errores de consulta.');
})().catch(error=>{console.error(error);process.exitCode=1;});
