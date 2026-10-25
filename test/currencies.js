const fs=require('node:fs');const vm=require('node:vm');const assert=require('node:assert/strict');
const context={document:{querySelector:()=>null,querySelectorAll:()=>[]}};vm.createContext(context);vm.runInContext(fs.readFileSync(require('node:path').join(__dirname,'../js/currency.js'),'utf8'),context);
const convert=context.convertOrderMoney;const pair={primary_code:'USD',alternate_code:'VES',exchange_rate:'77.14'};
assert.equal(convert('100.05','USD','VES',pair),'7717.86');assert.equal(convert('7717.86','VES','USD',pair),'100.05');
assert.equal(convert('10','USD','USD',pair),'10.00');assert.equal(convert('0','USD','VES',{...pair,exchange_rate:null}),'0.00');
assert.equal(convert('9999999999999999.99','USD','VES',{...pair,exchange_rate:'0.12'}),'1200000000000000.00');
assert.throws(()=>convert('10','USD','VES',{...pair,exchange_rate:null}),/Configura/);assert.throws(()=>convert('10','EUR','USD',pair),/Configura/);
assert.equal(convert('10','USD','VES',{primary_code:'VES',alternate_code:'USD',exchange_rate:'0.01'}),'1000.00');
console.log('PASS: conversiones USD/VES, redondeo, importes grandes exactos, tasas requeridas y principal/alternativa intercambiables.');
