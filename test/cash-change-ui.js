const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
class Control{
    constructor(value=''){this.value=value;this.handlers={};this.dataset={};this.readOnly=false;this.disabled=false;this.hidden=false;}
    addEventListener(event,handler){this.handlers[event]=handler;}
    setCustomValidity(value){this.validity=value;}
    fire(event){this.handlers[event]?.();}
}
function setup(locked=false){
    const amount=new Control('18.00'),received=new Control(locked?'20.00':'18.00'),change=new Control(locked?'2.00':'0.00');
    const method=new Control(),account=new Control(),direction=new Control('inbound'),currency=new Control('USD'),error=new Control(),label={textContent:'Importe'};
    method.selectedOptions=[{dataset:{allowChange:'1'}}];account.selectedOptions=[{dataset:{accountType:'cash'}}];
    const fields=[new Control(),new Control()];
    const selectors={'[name="amount"]':amount,'[name="received_amount"]':received,'[name="change_amount"]':change,'[name="method_id"]':method,'[name="account_id"]':account,'[name="direction"]':direction,'[name="currency_code"]':currency,'[data-cash-change-error]':error,'[data-cash-net-label]':label};
    const form={dataset:{cashLocked:locked?'1':'0',cashSaved:locked?'1':'0',cashBalance:'18.00',cashBalanceCurrency:'USD'},querySelector(selector){return selectors[selector]||null;},querySelectorAll(){return fields;}};
    vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname,'../js/cash-change.js'),'utf8'),{document:{querySelectorAll(){return [form];},querySelector(){return null;}}});
    return {amount,received,change,method,account,direction,error,fields,label};
}
const ui=setup();ui.received.value='20.00';ui.received.fire('input');
assert.equal(ui.change.value,'2.00');assert.equal(ui.amount.value,'18.00');assert.equal(ui.amount.readOnly,true);
ui.change.value='0';ui.change.fire('input');assert.equal(ui.amount.value,'20.00','Vuelto cero debe mantener el excedente como pago disponible.');
ui.received.value='30.00';ui.received.fire('input');assert.equal(ui.change.value,'0');assert.equal(ui.amount.value,'30.00','Debe respetarse el vuelto escrito por el usuario.');
ui.change.value='30';ui.change.fire('input');assert.equal(ui.error.hidden,false);assert.ok(ui.change.validity);
ui.method.selectedOptions[0].dataset.allowChange='0';ui.method.fire('change');assert.equal(ui.received.disabled,true);assert.equal(ui.change.disabled,true);assert.equal(ui.amount.readOnly,false);assert.equal(ui.change.validity,'');
ui.method.selectedOptions[0].dataset.allowChange='1';ui.account.selectedOptions[0].dataset.accountType='bank';ui.method.fire('change');assert.equal(ui.fields[0].hidden,true);
ui.account.selectedOptions[0].dataset.accountType='cash';ui.account.fire('change');assert.equal(ui.fields[0].hidden,false);assert.equal(ui.amount.value,'18.00');
ui.direction.value='outbound';ui.direction.fire('change');assert.equal(ui.fields[0].hidden,true);
const saved=setup(true);saved.method.selectedOptions[0].dataset.allowChange='0';saved.method.fire('change');assert.equal(saved.fields[0].hidden,false);assert.equal(saved.received.disabled,true);assert.equal(saved.received.value,'20.00');assert.equal(saved.change.value,'2.00');
console.log('PASS: método/caja/sentido, sugerencia de vuelto, neto exacto, cero manual, importes inválidos y pagos históricos bloqueados.');
