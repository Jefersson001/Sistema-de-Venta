<?php
// Runs within the disposable fixture created by test/outstanding.php.
$sellerA=(string)$people['salesperson'];$sellerB=(string)$people['salesperson2'];
$filtered=loadOutstandingReport($one,outstandingFilters(['q'=>$prefix,'salesperson_id'=>$sellerA]));
$amounts=array_column($filtered['customer']['currencies'],null,'code');
balanceCheck($amounts['USD']['balance']==='50.00'&&$amounts['VES']['balance']==='500.00','Seller filter mixed customer invoice balances.');
balanceCheck($filtered['supplier']['currencies'][0]['balance']==='70.00','Seller filter changed supplier debt.');
$commissions=outstandingCommissionReport($one,outstandingFilters(['q'=>$prefix,'salesperson_id'=>$sellerA]));
balanceCheck($commissions['currencies']===['USD'=>'7.50','VES'=>'50.00']&&$commissions['count']===2,'Seller A commission amounts incorrect.');
$commissionsB=outstandingCommissionReport($one,outstandingFilters(['q'=>$prefix,'salesperson_id'=>$sellerB]));
balanceCheck($commissionsB['currencies']===['USD'=>'10.00']&&$commissionsB['count']===1,'Seller B commissions mixed with A.');
balanceCheck(outstandingCommissionReport($one,outstandingFilters(['salesperson_id'=>'none']))['count']===0,'Unassigned filter must not include seller commissions.');
$unassigned=loadOutstandingReport($one,outstandingFilters(['q'=>$prefix,'salesperson_id'=>'none']));balanceCheck($unassigned['customer']['count']===1&&$unassigned['supplier']['currencies'][0]['balance']==='70.00','Unassigned filter or independent suppliers incorrect.');
[$status,$filteredHtml]=authTestRequest($s,$path.'?'.http_build_query(['q'=>$prefix,'salesperson_id'=>$sellerA]),$cookie);
balanceCheck($status===200&&str_contains($filteredHtml,'name="salesperson_id"')&&str_contains($filteredHtml,'data-outstanding-commission="'.$a.'"')&&str_contains($filteredHtml,'data-outstanding-commission="'.$vesCommission.'"')&&!str_contains($filteredHtml,'data-outstanding-commission="'.$otherSeller.'"')&&str_contains($filteredHtml,'data-outstanding-invoice="'.$supplier.'"'),'Filtered report omitted own commissions or supplier section.');
[$status,$restricted]=authTestRequest($s,$path.'?salesperson_id='.$sellerB,$commissionCookie);
balanceCheck($status===200&&!str_contains($restricted,'data-outstanding-commission=')&&!str_contains($restricted,'data-outstanding-invoice=')&&!str_contains($restricted,$prefix.' salesperson2'),'Own commission permissions leaked another seller.');
[$status,$ownHtml]=authTestRequest($s,$path.'?salesperson_id='.$sellerA,$commissionCookie);balanceCheck($status===200&&str_contains($ownHtml,'data-outstanding-commission="'.$a.'"')&&!str_contains($ownHtml,'id="accounts-supplier"'),'Commission-only report requires unrelated invoice permissions.');
foreach(['salesperson_id[]=1','salesperson_id=invalid'] as $invalid)balanceCheck(authTestRequest($s,$path.'?'.$invalid,$cookie)[0]===422,'Invalid seller selector accepted.');

// Twenty-one distinct clients verify filtered pagination retains the salesperson.
$db->exec('SET @nexo_company_id=1');
for($n=1;$n<=21;$n++){
    $db->prepare('INSERT INTO contacts(name,type_id) VALUES(?,?)')->execute([$prefix.' pagination '.$n,$types['client']]);$paginationClient=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO accounting_invoices(name,invoice_type,contact_id,invoice_address,invoice_date,base_amount,total_amount,status,currency_code,salesperson_id) VALUES(?,'customer',?,'',?,1,1,'posted','USD',?)")->execute([$prefix.'PAGE'.$n,$paginationClient,$today,$sellerA]);
}
[$status,$pageHtml]=authTestRequest($s,$path.'?'.http_build_query(['q'=>$prefix.'PAGE','salesperson_id'=>$sellerA]),$cookie);
$doc=new DOMDocument();@$doc->loadHTML('<?xml encoding="UTF-8">'.$pageHtml);$xp=new DOMXPath($doc);$next=$xp->query('//nav[contains(@class,"contact-pagination")]//a[contains(@href,"customer_page=2")]')->item(0);
balanceCheck($status===200&&$next!==null&&str_contains($next->getAttribute('href'),'salesperson_id='.$sellerA),'Pagination lost report seller filter.');
[$status,$pageHtml]=authTestRequest($s,$path.'?'.http_build_query(['q'=>$prefix.'PAGE','salesperson_id'=>$sellerA,'customer_page'=>2]),$cookie);
balanceCheck($status===200&&substr_count($pageHtml,'data-outstanding-invoice=')===1,'Second filtered report page incorrect.');
echo "PASS: report seller filter, independent supplier debts, commission balances by currency, own permissions, unassigned, invalid input and pagination.\n";

$db->exec('SET @nexo_company_id=1');
$db->prepare('INSERT INTO contacts(name,type_id) VALUES(?,?)')->execute([$prefix.' otro proveedor',$types['supplier']]);$supplierB=(int)$db->lastInsertId();
$db->prepare("INSERT INTO accounting_invoices(name,invoice_type,contact_id,invoice_address,invoice_date,base_amount,total_amount,status,currency_code) VALUES(?,'supplier',?,'',?,25,25,'posted','USD')")->execute([$prefix.'SUPB',$supplierB,$today]);$supplierBillB=(int)$db->lastInsertId();
$combined=outstandingFilters(['q'=>$prefix,'salesperson_id'=>$sellerA,'supplier_id'=>(string)$supplierB]);
$supplierReport=loadOutstandingReport($one,$combined);
balanceCheck($supplierReport['supplier']['count']===1&&$supplierReport['supplier']['currencies'][0]['balance']==='25.00','Supplier selection did not filter its own invoices.');
balanceCheck(array_column($supplierReport['customer']['currencies'],null,'code')['VES']['balance']==='500.00','Supplier filter changed receivables.');
balanceCheck(outstandingCommissionReport($one,$combined)['currencies']===['USD'=>'7.50','VES'=>'50.00'],'Supplier selection changed seller commissions.');
[$status,$providerHtml]=authTestRequest($s,$path.'?'.http_build_query($combined),$cookie);
balanceCheck($status===200&&str_contains($providerHtml,'name="supplier_id"')&&str_contains($providerHtml,'data-outstanding-invoice="'.$supplierBillB.'"')&&!str_contains($providerHtml,'data-outstanding-invoice="'.$supplier.'"')&&str_contains($providerHtml,'data-outstanding-commission="'.$a.'"'),'Combined seller/supplier HTML incorrect.');
[, $ownProviderHtml]=authTestRequest($s,$path.'?supplier_id='.$supplierB,$commissionCookie);balanceCheck(!str_contains($ownProviderHtml,'name="supplier_id"')&&!str_contains($ownProviderHtml,$prefix.' otro proveedor'),'Supplier control leaked through commission-only permissions.');
foreach(['supplier_id[]=1','supplier_id=invalid','supplier_id=0'] as $invalid)balanceCheck(authTestRequest($s,$path.'?'.$invalid,$cookie)[0]===422,'Invalid supplier selector accepted.');
[$status,$providerCsv]=authTestRequest($s,'/datos.php?'.http_build_query(['model'=>'outstanding','action'=>'export','source'=>'/project'.$path.'?'.http_build_query($combined),'download'=>1,'fields'=>['name','balance','currency_code']]),$cookie);
balanceCheck($status===200&&str_contains($providerCsv,$prefix.'SUPB;25.00;USD')&&!str_contains($providerCsv,$prefix.'SUP;'),'Supplier export ignored active filter.');
echo "PASS: independent supplier selection, combined seller filter, commission preservation, supplier permissions, invalid values and filtered export.\n";

$dated=outstandingFilters(['q'=>$prefix,'salesperson_id'=>$sellerA,'supplier_id'=>(string)$supplierB,'date_from'=>$yesterday,'date_to'=>$yesterday]);
$datedReport=loadOutstandingReport($one,$dated);$datedCommissions=outstandingCommissionReport($one,$dated);
balanceCheck($datedReport['customer']['count']===1&&$datedReport['customer']['groups'][0]['invoices'][0]['id']===$a&&$datedReport['supplier']['count']===0,'Inclusive invoice date range mixed dates or ignored supplier selection.');
balanceCheck($datedCommissions['currencies']===['USD'=>'7.50']&&$datedCommissions['count']===1,'Commission filter did not use source invoice date.');
$fromOnly=loadOutstandingReport($one,outstandingFilters(['q'=>$prefix,'date_from'=>$today]));
foreach($fromOnly['customer']['groups'] as $g)foreach($g['invoices'] as $invoice)balanceCheck($invoice['invoice_date']>=$today,'Open-ended From included an older invoice.');
$toOnly=loadOutstandingReport($one,outstandingFilters(['q'=>$prefix,'salesperson_id'=>$sellerA,'date_to'=>$yesterday]));balanceCheck($toOnly['customer']['count']===1,'Open-ended To did not work.');
[$status,$dateHtml]=authTestRequest($s,$path.'?'.http_build_query($dated),$cookie);
balanceCheck($status===200&&str_contains($dateHtml,'name="date_from"')&&str_contains($dateHtml,'name="date_to"')&&str_contains($dateHtml,'data-outstanding-invoice="'.$a.'"')&&!str_contains($dateHtml,'data-outstanding-invoice="'.$vesCommission.'"')&&str_contains($dateHtml,'data-outstanding-commission="'.$a.'"'),'Date fields or filtered HTML incorrect.');
$paginationDates=['q'=>$prefix.'PAGE','salesperson_id'=>$sellerA,'date_from'=>$today,'date_to'=>$today];
[, $dateHtml]=authTestRequest($s,$path.'?'.http_build_query($paginationDates),$cookie);@$doc->loadHTML('<?xml encoding="UTF-8">'.$dateHtml);$xp=new DOMXPath($doc);$next=$xp->query('//nav[contains(@class,"contact-pagination")]//a[contains(@href,"customer_page=2")]')->item(0);
balanceCheck($next!==null&&str_contains($next->getAttribute('href'),'date_from='.$today)&&str_contains($next->getAttribute('href'),'date_to='.$today),'Pagination lost date range.');
[$status,$dateCsv]=authTestRequest($s,'/datos.php?'.http_build_query(['model'=>'outstanding','action'=>'export','source'=>'/project'.$path.'?'.http_build_query($dated),'download'=>1,'fields'=>['name','balance','currency_code']]),$cookie);
balanceCheck($status===200&&str_contains($dateCsv,$prefix.'A;50.00;USD')&&!str_contains($dateCsv,$prefix.'SUPB;')&&!str_contains($dateCsv,$prefix.'COMVES;'),'CSV export ignored invoice date boundaries.');
foreach(['date_from=2026-02-30','date_to[]=1','date_from='.$today.'&date_to='.$yesterday] as $invalid)balanceCheck(authTestRequest($s,$path.'?'.$invalid,$cookie)[0]===422,'Invalid invoice date range accepted.');
echo "PASS: inclusive invoice dates, one-sided ranges, commissions, combined filters, date pagination, export and invalid date rejection.\n";
