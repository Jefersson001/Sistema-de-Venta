<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
(static function()use($db,$s,$cookie,$readonlyCookie,$readonlyLogin,$password,$actor,$insert,$group,$user,$contact){
    $prefix='DET-'.bin2hex(random_bytes(4));$today=(new DateTimeImmutable('now',new DateTimeZone('America/Caracas')))->format('Y-m-d');
    $sellerType=(int)$db->query("SELECT id FROM contact_types WHERE system_key='salesperson'")->fetchColumn();
    $own=$insert('contacts',['name'=>$prefix.' vendedor','type_id'=>$sellerType]);$other=$insert('contacts',['name'=>$prefix.' otro vendedor','type_id'=>$sellerType]);
    $db->prepare('UPDATE auth_users SET contact_id=? WHERE id=?')->execute([$own,$user]);
    foreach(['ventas.orders'=>'own','contabilidad.customer_invoices'=>'own','contabilidad.supplier_invoices'=>'all'] as $resource=>$scope)$insert('access_group_permissions',['group_id'=>$group,'permission_code'=>$resource.'.read','record_scope'=>$scope]);
    $product=$insert('inventory_products',['name'=>$prefix.' producto']);$snapshot='<script>alert("x")</script> Producto histórico';
    $order=$insert('sales_quotations',['name'=>$prefix.' orden','customer_id'=>$contact,'salesperson_id'=>$own,'order_date'=>$today]);
    $foreignOrder=$insert('sales_quotations',['name'=>$prefix.' ajena','customer_id'=>$contact,'salesperson_id'=>$other,'order_date'=>$today]);
    $insert('sales_quotation_lines',['quotation_id'=>$order,'product_id'=>$product,'product_name'=>$snapshot,'quantity'=>'2.50','unit_price'=>'15','total_amount'=>'37.50']);
    $invoice=$insert('accounting_invoices',['name'=>$prefix.' factura','contact_id'=>$contact,'salesperson_id'=>$own,'invoice_type'=>'customer','invoice_date'=>$today]);
    $foreignInvoice=$insert('accounting_invoices',['name'=>$prefix.' factura ajena','contact_id'=>$contact,'salesperson_id'=>$other,'invoice_type'=>'customer','invoice_date'=>$today]);
    $supplierInvoice=$insert('accounting_invoices',['name'=>$prefix.' proveedor','contact_id'=>$contact,'invoice_type'=>'supplier','invoice_date'=>$today]);
    $insert('accounting_invoice_lines',['invoice_id'=>$invoice,'product_id'=>$product,'product_name'=>$snapshot,'quantity'=>'3.00','unit_price'=>'11','total_amount'=>'33']);
    foreach([['order',$order,'customer','2.50'],['invoice',$invoice,'customer','3.00']] as [$kind,$id,$type,$quantity]){
        [$status,$body]=authTestRequest($s,'/productos-documento.php?'.http_build_query(compact('kind','id','type')),$readonlyCookie);$json=json_decode($body,true,16,JSON_THROW_ON_ERROR);
        editHttpCheck($status===200&&count($json['products'])===1&&$json['products'][0]===['name'=>$snapshot,'quantity'=>$quantity],'Los productos no corresponden al documento o exigen edición/inventario.');
        editHttpCheck(array_keys($json)===['name','products','scope'],'Se expusieron datos de cabecera o importes ajenos a la consulta.');
    }
    foreach([['order',$foreignOrder],['invoice',$foreignInvoice]] as [$kind,$id])editHttpCheck(authTestRequest($s,'/productos-documento.php?'.http_build_query(compact('kind','id')),$readonlyCookie)[0]===403,'La consulta permite ver productos de otro vendedor.');
    $url='/productos-documento.php?kind=invoice&id='.$invoice;
    editHttpCheck(authTestRequest($s,$url.'&type=supplier',$readonlyCookie)[0]===404,'Se consultó una factura mediante otro tipo.');
    editHttpCheck(authTestRequest($s,$url.'&scope=invalid',$readonlyCookie)[0]===409,'No se detecta un cambio en la selección de empresas.');
    editHttpCheck(authTestRequest($s,'/productos-documento.php?kind=invoice&id='.$supplierInvoice.'&type=supplier',$readonlyCookie)[0]===200,'Falta la consulta de facturas de proveedor.');
    editHttpCheck(authTestRequest($s,$url,$readonlyCookie,['action'=>'write'])[0]===405,'La consulta permite escrituras.');
    foreach(['/productos-documento.php?kind=bad&id=1','/productos-documento.php?kind=order&id[]=1','/productos-documento.php?kind=invoice&id=0'] as $bad)editHttpCheck(authTestRequest($s,$bad,$readonlyCookie)[0]===404,'La consulta acepta parámetros inválidos.');
    $guest=tempnam(sys_get_temp_dir(),'product-guest-');try{editHttpCheck(authTestRequest($s,$url,$guest)[0]===401,'Una visita sin sesión consulta productos.');}finally{unlink($guest);}
    foreach(['/modules/ventas/index.php?q='.$prefix,'/modules/contabilidad/index.php?type=customer&q='.$prefix] as $route){
        [$status,$html]=authTestRequest($s,$route,$readonlyCookie);
        editHttpCheck($status===200&&str_contains($html,'data-record-products-url')&&str_contains($html,'record-products-dialog')&&!str_contains($html,'record-edit-link'),'Falta Ver detalles a un usuario con permiso de consulta sin edición.');
    }
    [, $html]=authTestRequest($s,'/modules/ventas/index.php?q='.$prefix,$cookie);
    $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($dom);
    editHttpCheck($xp->query('//td[last()]/a[contains(@class,"record-edit-link")]/following-sibling::button[@data-record-products-url]')->length>=1,'Ver detalles no está junto a Editar.');
    $otherCompany=$insert('business_company',['name'=>'ZZ '.$prefix.' empresa']);$db->exec('SET @nexo_company_id='.$otherCompany);
    try{
        $otherContact=$insert('contacts',['name'=>$prefix.' cliente externo','type_id'=>$sellerType]);
        $otherProduct=$insert('inventory_products',['name'=>$prefix.' producto externo']);
        $otherCompanyOrder=$insert('sales_quotations',['name'=>$prefix.' orden externa','customer_id'=>$otherContact,'order_date'=>$today]);
        $insert('sales_quotation_lines',['quotation_id'=>$otherCompanyOrder,'product_id'=>$otherProduct,'product_name'=>'PRODUCTO PRIVADO OTRA EMPRESA','quantity'=>'1']);
        $otherCompanyInvoice=$insert('accounting_invoices',['name'=>$prefix.' factura externa','contact_id'=>$otherContact,'invoice_type'=>'customer','invoice_date'=>$today]);
        $insert('accounting_invoice_lines',['invoice_id'=>$otherCompanyInvoice,'product_id'=>$otherProduct,'product_name'=>'PRODUCTO PRIVADO OTRA EMPRESA','quantity'=>'1']);
    }finally{$db->exec('SET @nexo_company_id=1');}
    foreach([['order',$otherCompanyOrder],['invoice',$otherCompanyInvoice]] as [$kind,$id])editHttpCheck(authTestRequest($s,'/productos-documento.php?'.http_build_query(compact('kind','id')),$cookie)[0]===404,'Un administrador consulta productos fuera de las empresas seleccionadas.');
    if(in_array('--browser',$GLOBALS['argv'],true)){
        $env=getenv();foreach(['NEXO_TEST_BASE_URL'=>$s['url'],'NEXO_TEST_LOGIN'=>$readonlyLogin,'NEXO_TEST_PASSWORD'=>$password,'NEXO_PRODUCTS_PREFIX'=>$prefix,'NEXO_PRODUCTS_ORDER'=>$order,'NEXO_PRODUCTS_INVOICE'=>$invoice] as $key=>$value)$env[$key]=(string)$value;
        $process=proc_open(['node',__DIR__.'/record-products-ui.js'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,dirname(__DIR__),$env,['bypass_shell'=>true,'create_new_console'=>false]);fclose($pipes[0]);editHttpCheck(proc_close($process)===0,'Falló la revisión visual de Ver detalles.');
    }
    echo "PASS: productos nativos de órdenes/facturas, solo consulta, cantidades históricas, permisos propios, parámetros, sesión y contexto empresarial.\n";
})();
