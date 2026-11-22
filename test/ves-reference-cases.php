<?php
// Included by payment-exchange.php; every record lives in its disposable database.
$vesInvoice=$invoice('10000.00','VES');$vesPayments=[];
fxCheck($read($vesInvoice)['usd_reference_amount']==='100.00','VES invoice did not freeze its original USD equivalent.');
// Every indexed payment needs a usable USD/VES rate, including receipts entered in USD.
$noRate=$invoice('1000.00','VES');$i=$read($noRate);$rejected=false;
try{paymentFromInvoice($db,$noRate,(int)$i['version'],'register_payment',array_replace($base,['amount'=>'10.00','currency_code'=>'USD','exchange_rate'=>'']));}catch(InvalidArgumentException){$rejected=true;}
fxCheck($rejected&&!invoicePaymentDetails($db,$noRate)['payments'],'Indexed USD receipt silently accepted a missing valuation rate.');
$unknown=$invoice('1000.00','VES');$db->prepare('UPDATE accounting_invoices SET exchange_rate=NULL,usd_reference_amount=NULL WHERE id=?')->execute([$unknown]);$i=$read($unknown);$rejected=false;
try{paymentFromInvoice($db,$unknown,(int)$i['version'],'register_payment',array_replace($base,['amount'=>'1000.00']));}catch(InvalidArgumentException){$rejected=true;}
fxCheck($rejected&&!invoicePaymentDetails($db,$unknown)['payments'],'VES invoice without historical USD reference was paid using an unrelated current rate.');

// Vuelto is actual cash returned, independent of the exchange difference.
$cashMethod=$insert('accounting_payment_methods',['name'=>'Efectivo FX QA','allow_change'=>1]);$cashInvoice=$invoice('1000.00','VES');$i=$read($cashInvoice);
paymentFromInvoice($db,$cashInvoice,(int)$i['version'],'register_payment',array_replace($base,['amount'=>'1100.00','received_amount'=>'1200.00','change_amount'=>'100.00','method_id'=>$cashMethod]));
$cashAllocation=invoicePaymentDetails($db,$cashInvoice)['payments'][0];$cashPayment=loadAccountingPayment($db,(int)$cashAllocation['payment_id']);
fxCheck($cashPayment['amount']==='1100.00'&&$cashPayment['available']==='0.00'&&$cashAllocation['fx_difference']==='100.00','Vuelto was counted as surplus or differential.');
transitionAccountingPayment($db,$cashPayment['id'],(int)$cashPayment['version'],'settle_payment');
$cashEntries=$db->query('SELECT SUM(amount) FROM accounting_treasury_entries WHERE payment_id='.(int)$cashPayment['id'])->fetchColumn();fxCheck($cashEntries==='1100.00'&&$read($cashInvoice)['payment_state']==='paid','Net cash after vuelto did not settle indexed invoice.');
foreach([['4400.00','110.00','40.00','4000.00','400.00'],['3600.00','120.00','30.00','3000.00','600.00'],['3900.00','130.00','30.00','3000.00','900.00']] as [$amount,$rate,$usdCovered,$historical,$difference]){
    $i=$read($vesInvoice);paymentFromInvoice($db,$vesInvoice,(int)$i['version'],'register_payment',array_replace($base,['amount'=>$amount,'exchange_rate'=>$rate]));
    $allocations=invoicePaymentDetails($db,$vesInvoice)['payments'];$a=end($allocations);$p=loadAccountingPayment($db,(int)$a['payment_id']);$vesPayments[]=$p['id'];
    fxCheck($a['usd_reference_amount']===$usdCovered&&$a['amount']===$historical&&$a['fx_difference']===$difference&&$p['available']==='0.00','Indexed VES partial payment has wrong debt, native application or false credit.');
    transitionAccountingPayment($db,$p['id'],(int)$p['version'],'settle_payment');
}
fxCheck($read($vesInvoice)['total_amount']==='10000.00'&&$read($vesInvoice)['payment_state']==='paid','VES original total changed or invoice did not close.');
fxCheck(paymentDebtPosition($db,$read($vesInvoice))['unpaid']==='0.00'&&paymentExchangeTotals(invoicePaymentDetails($db,$vesInvoice)['payments'])['VES']==='1900.00','VES differential or final USD balance incorrect.');
fxCheck($db->query('SELECT SUM(amount) FROM accounting_treasury_entries WHERE payment_id IN('.implode(',',$vesPayments).')')->fetchColumn()==='11900.00','Indexed VES payments generated fictitious treasury adjustments.');
$p=loadAccountingPayment($db,$vesPayments[2]);reverseAccountingPayment($db,$p['id'],(int)$p['version'],['reason'=>'VES reversal QA','movement_date'=>'2026-11-22']);
fxCheck(paymentDebtPosition($db,$read($vesInvoice))['unpaid']==='30.00'&&paymentExchangeTotals(invoicePaymentDetails($db,$vesInvoice)['payments'])['VES']==='1000.00','VES reversal did not restore USD debt and differential.');
$p=loadAccountingPayment($db,$vesPayments[0]);transitionAccountingPayment($db,$p['id'],(int)$p['version'],'unapply_payment',['invoice_id'=>$vesInvoice]);
fxCheck(paymentDebtPosition($db,$read($vesInvoice))['unpaid']==='70.00'&&loadAccountingPayment($db,$p['id'])['available']==='4400.00','VES unapply did not restore payment availability and USD debt.');
$p=loadAccountingPayment($db,$p['id']);transitionAccountingPayment($db,$p['id'],(int)$p['version'],'apply_payment',['invoice_id'=>$vesInvoice,'amount'=>'4400.00']);
fxCheck(paymentDebtPosition($db,$read($vesInvoice))['unpaid']==='30.00','Reapplication duplicated reference coverage.');

// The historical VES total may be smaller than the actual VES payment or its USD valuation.
foreach([['VES','1100.00','110.00','100.00','0.00'],['USD','10.00','110.00','100.00','0.00'],['VES','1320.00','120.00','200.00','120.00'],['VES','900.00','90.00','-100.00','0.00']] as [$code,$amount,$rate,$difference,$surplus]){
    $id=$invoice('1000.00','VES');$i=$read($id);paymentFromInvoice($db,$id,(int)$i['version'],'register_payment',array_replace($base,['amount'=>$amount,'currency_code'=>$code,'exchange_rate'=>$rate]));
    $a=invoicePaymentDetails($db,$id)['payments'][0];$p=loadAccountingPayment($db,(int)$a['payment_id']);
    fxCheck($a['amount']==='1000.00'&&$a['usd_reference_amount']==='10.00'&&$a['fx_difference']===$difference&&$p['available']===$surplus,'VES full payment uses historical cap instead of fixed USD debt.');
    transitionAccountingPayment($db,$p['id'],(int)$p['version'],'settle_payment');fxCheck($read($id)['payment_state']==='paid','Full VES/USD payment did not close VES invoice.');
}

// A real standalone payment can be applied incrementally and to more than one VES invoice.
$splitA=$invoice('1000.00','VES');$splitB=$invoice('500.00','VES');$payment=insertAccountingPayment($db,array_replace($base,['amount'=>'1800.00','received_amount'=>'1800.00','exchange_rate'=>'110.00']),'pending');
foreach([[$splitA,'550.00'],[$splitA,'550.00'],[$splitB,'550.00']] as [$id,$part]){$p=loadAccountingPayment($db,$payment);transitionAccountingPayment($db,$payment,(int)$p['version'],'apply_payment',['invoice_id'=>$id,'amount'=>$part]);}
$p=loadAccountingPayment($db,$payment);fxCheck(count($p['allocations'])===2&&$p['allocations'][0]['usd_reference_amount']==='10.00'&&$p['allocations'][1]['usd_reference_amount']==='5.00'&&$p['available']==='150.00','Standalone VES split reference or surplus incorrect.');

// Preserve final cents when the rounded USD reference does not divide evenly into the VES total.
$odd=$invoice('1000.01','VES');
foreach(['550.00','550.00'] as $part){$i=$read($odd);paymentFromInvoice($db,$odd,(int)$i['version'],'register_payment',array_replace($base,['amount'=>$part]));$rows=invoicePaymentDetails($db,$odd)['payments'];$a=end($rows);$p=loadAccountingPayment($db,(int)$a['payment_id']);transitionAccountingPayment($db,$p['id'],(int)$p['version'],'settle_payment');}
fxCheck($read($odd)['payment_state']==='paid'&&invoicePaymentDetails($db,$odd)['settled']==='1000.01','Final cent left an artificial balance.');

$tiny=$invoice('0.02','VES');$db->prepare('UPDATE accounting_invoices SET exchange_rate=? WHERE id=?')->execute(['0.67',$tiny]);invoiceFreezeUsdDebt($db,$read($tiny));
foreach([1,2,3] as $count){$i=$read($tiny);paymentFromInvoice($db,$tiny,(int)$i['version'],'register_payment',array_replace($base,['amount'=>'0.01','currency_code'=>'USD','exchange_rate'=>'1.00']));$rows=invoicePaymentDetails($db,$tiny)['payments'];$a=end($rows);$p=loadAccountingPayment($db,(int)$a['payment_id']);transitionAccountingPayment($db,$p['id'],(int)$p['version'],'settle_payment');fxCheck(($read($tiny)['payment_state']==='paid')===($count===3),'Native-cent rounding closed USD debt too early.');}
fxCheck(invoicePaymentDetails($db,$tiny)['settled']==='0.02','Native cumulative cent allocation drifted.');

// Credit notes reduce the reference; refunds are valued at their own payment date.
$creditedInvoice=$invoice('10000.00','VES');$i=$read($creditedInvoice);paymentFromInvoice($db,$creditedInvoice,(int)$i['version'],'register_payment',array_replace($base,['amount'=>'4400.00']));
$a=invoicePaymentDetails($db,$creditedInvoice)['payments'][0];$p=loadAccountingPayment($db,(int)$a['payment_id']);transitionAccountingPayment($db,$p['id'],(int)$p['version'],'settle_payment');
$credit=$invoice('2000.00','VES');$db->prepare("UPDATE accounting_invoices SET document_type='credit',reversal_of_id=? WHERE id=?")->execute([$creditedInvoice,$credit]);invoiceFreezeUsdDebt($db,$read($credit));
fxCheck(paymentDebtPosition($db,$read($creditedInvoice))['balance']==='40.00','Credit did not reduce USD reference debt.');
$i=$read($creditedInvoice);paymentFromInvoice($db,$creditedInvoice,(int)$i['version'],'register_payment',array_replace($base,['amount'=>'4800.00','exchange_rate'=>'120.00']));
$rows=invoicePaymentDetails($db,$creditedInvoice)['payments'];$a=end($rows);$p=loadAccountingPayment($db,(int)$a['payment_id']);transitionAccountingPayment($db,$p['id'],(int)$p['version'],'settle_payment');
fxCheck($read($creditedInvoice)['payment_state']==='paid','Credit plus indexed payments did not close invoice.');
$refund=$invoice('8000.00','VES');$db->prepare("UPDATE accounting_invoices SET document_type='credit',reversal_of_id=? WHERE id=?")->execute([$creditedInvoice,$refund]);invoiceFreezeUsdDebt($db,$read($refund));
fxCheck(paymentDebtPosition($db,$read($refund))['balance']==='80.00','Refundable credit USD debt incorrect.');
$i=$read($refund);paymentFromInvoice($db,$refund,(int)$i['version'],'register_payment',array_replace($base,['amount'=>'10400.00','exchange_rate'=>'130.00','direction'=>'outbound']));
$a=invoicePaymentDetails($db,$refund)['payments'][0];fxCheck($a['usd_reference_amount']==='80.00'&&$a['amount']==='8000.00'&&$a['fx_difference']==='2400.00','Indexed credit refund incorrectly capped at original VES amount.');

// Supplier invoices share the same reference arithmetic, with real outbound cash movements.
$supplier=$insert('contacts',['name'=>'Proveedor FX QA','type_id'=>$db->query("SELECT id FROM contact_types WHERE system_key='supplier'")->fetchColumn()]);
$bill=$invoice('1000.00','VES');$db->prepare("UPDATE accounting_invoices SET invoice_type='supplier',contact_id=? WHERE id=?")->execute([$supplier,$bill]);
$i=$read($bill);paymentFromInvoice($db,$bill,(int)$i['version'],'register_payment',array_replace($base,['amount'=>'1100.00','exchange_rate'=>'110.00','partner_type'=>'supplier','direction'=>'outbound','contact_id'=>$supplier]));
$a=invoicePaymentDetails($db,$bill)['payments'][0];fxCheck($a['usd_reference_amount']==='10.00'&&$a['fx_difference']==='100.00','Supplier VES payment did not use USD reference.');
$p=loadAccountingPayment($db,(int)$a['payment_id']);transitionAccountingPayment($db,$p['id'],(int)$p['version'],'settle_payment');fxCheck($read($bill)['payment_state']==='paid','Supplier VES bill did not close.');

// Public HTML and dated lookups remain available for the indexed document.
// Use the production creation and confirmation flow, including a credit generated from its lines.
$product=$insert('inventory_products',['name'=>'Servicio VES QA','product_type'=>'service','sale_enabled'=>1,'purchase_enabled'=>1,'invoice_policy'=>'ordered']);
$order=$insert('sales_quotations',['name'=>'FX-ORDER-'.bin2hex(random_bytes(4)),'customer_id'=>$client,'order_date'=>'2026-11-08','status'=>'sale','currency_code'=>'VES','currency_primary_code'=>'USD','currency_alternate_code'=>'VES','exchange_rate'=>'100','base_amount'=>'1000','total_amount'=>'1000']);
$insert('sales_quotation_lines',['quotation_id'=>$order,'product_id'=>$product,'product_name'=>'Servicio VES QA','quantity'=>'1','unit_price'=>'1000','base_amount'=>'1000','tax_amount'=>'0','total_amount'=>'1000','position'=>0,'product_type'=>'service','invoice_policy'=>'ordered']);
$created=workflowCreateInvoice($db,'sale',$order);fxCheck(invoiceHasUsdDebt($read($created)),'Native invoice creation failed to initialize USD debt.');
$route='/modules/contabilidad/index.php?type=customer&id='.$created;[, $createdHtml]=authTestRequest($server,$route,$cookie);
fxCheck(authTestRequest($server,$route,$cookie,['csrf'=>authTestToken($createdHtml),'action'=>'confirm_invoice','version'=>$read($created)['version'],'invoice_date'=>'2026-11-08','due_date'=>'2026-11-15'])[0]===303,'Could not confirm native VES invoice.');
fxCheck($read($created)['exchange_rate']==='110.00'&&$read($created)['usd_reference_amount']==='9.09','Invoice confirmation did not freeze its original dated USD reference.');
$createdCredit=createCreditNote($db,$created,(int)$read($created)['version'],'Referencia VES QA');fxCheck($read($createdCredit)['usd_reference_amount']==='9.09','Generated credit did not inherit original USD valuation.');
[, $createdHtml]=authTestRequest($server,$route,$cookie);
fxCheck(authTestRequest($server,$route,$cookie,['csrf'=>authTestToken($createdHtml),'action'=>'register_payment','version'=>$read($created)['version'],'amount'=>'999.90','currency_code'=>'VES','exchange_rate'=>'110','payment_date'=>'2026-11-08','account_id'=>$account,'method_id'=>$method])[0]===303,'VES wizard could not register payment through its native HTTP action.');
$createdAllocation=invoicePaymentDetails($db,$created)['payments'][0];fxCheck($createdAllocation['amount']==='1000.00'&&$createdAllocation['usd_reference_amount']==='9.09','HTTP registration lost rounded original USD reference.');
fxCheck(authTestRequest($server,$route,$cookie,['csrf'=>authTestToken($createdHtml),'action'=>'settle_payment','version'=>$read($created)['version'],'payment_id'=>$createdAllocation['id']])[0]===303&&$read($created)['payment_state']==='paid','HTTP confirmation did not settle indexed VES invoice.');
$vesPath='/modules/contabilidad/index.php?type=customer&id='.$vesInvoice;
[$status,$vesHtml]=authTestRequest($server,$vesPath,$cookie);
fxCheck($status===200&&str_contains($vesHtml,'Referencia original en USD')&&str_contains($vesHtml,'indexed_usd_total'),'Indexed invoice summary/preview missing.');
$snapshot=$db->query('SELECT * FROM accounting_invoice_payments WHERE invoice_id='.$vesInvoice)->fetchAll();$usdMigration($db);$usdMigration($db);
fxCheck($snapshot===$db->query('SELECT * FROM accounting_invoice_payments WHERE invoice_id='.$vesInvoice)->fetchAll(),'Retry changed recorded VES payment reference or exchange snapshots.');
echo "PASS: indexed VES invoices, installments, USD/VES receipts, surplus, decreasing rates, final cents, unapply/reversal, supplier payments and credit refunds.\n";
