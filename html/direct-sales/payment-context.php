<?php
$pay=$page['payment_details'];$sale=$page['sale_record'];$debt=$page['payment_debt'];$balance=$debt['unpaid'];$toCover=$debt['balance'];$collectionCurrency=$debt['currency'];
$canPayDirect=permissionHas('venta_directa.sales','register_payment')&&permissionHas('contabilidad.customer_invoices','register_payment')&&$sale['invoice_status']==='posted'&&bccomp($toCover,'0',2)>0;
$fxInvoice=['currency_code'=>$collectionCurrency,'primary_code'=>$sale['currency_primary_code'],'alternate_code'=>$sale['currency_alternate_code'],'exchange_rate'=>$sale['exchange_rate'],'balance'=>$toCover];
if($debt['indexed'])$fxInvoice+=['indexed_native_total'=>$sale['total_amount'],'indexed_usd_total'=>$sale['usd_reference_amount'],'native_balance'=>bcsub(bcsub(bcsub($sale['total_amount'],$pay['credited'],2),$pay['settled'],2),$pay['pending'],2),'native_paid'=>bcadd($pay['settled'],$pay['pending'],2),'usd_paid'=>bcadd($debt['settled'],$debt['pending'],2)];
