<?php
declare(strict_types=1);

/** Small read-only indicators; each metric retains its own resource scope. */
function homeModuleFootnotes(PDO $db,array $modules): array {
    $notes=[];
    $count=static function(string $sql)use($db):int{return (int)$db->query($sql)->fetchColumn();};
    $quantity=static fn(int $n,string $one,string $many):string=>$n.' '.($n===1?$one:$many);
    foreach($modules as $key=>$module){
        $notes[$key]=['value'=>$module['category'],'detail'=>'Abrir módulo','tone'=>'muted'];
        try{
            switch($key){
                case 'compra_directa':if(permissionHas('compra_directa.purchases')){$n=$count('SELECT COUNT(*) FROM direct_purchase_checkouts d WHERE d.company_id='.(int)companyActiveId().' AND DATE(d.created_at)=CURRENT_DATE() AND '.permissionWhere('compra_directa.purchases','d'));$notes[$key]=['value'=>$quantity($n,'compra hoy','compras hoy'),'detail'=>'Compra rápida','tone'=>'green'];}break;
                case 'dashboard':$notes[$key]=['value'=>$quantity(count($modules),'módulo habilitado','módulos habilitados'),'detail'=>'Tu negocio','tone'=>'green'];break;
                case 'contactos':if(permissionHas('contactos.contacts')){$n=$count("SELECT COUNT(*) FROM contacts c JOIN contact_types t ON t.id=c.type_id WHERE c.active=1 AND t.system_key='client' AND ".permissionWhere('contactos.contacts','c'));$notes[$key]=['value'=>$quantity($n,'cliente','clientes'),'detail'=>'Directorio','tone'=>'blue'];}break;
                case 'inventario':if(permissionHas('inventario.products')){$n=$count('SELECT COUNT(*) FROM inventory_products p WHERE '.permissionWhere('inventario.products','p'));$notes[$key]=['value'=>$quantity($n,'producto','productos'),'detail'=>'Catálogo','tone'=>'amber'];}break;
                case 'compras':if(permissionHas('compras.orders')){$n=$count("SELECT COUNT(*) FROM purchase_orders p WHERE p.status='purchase' AND ".permissionWhere('compras.orders','p'));$notes[$key]=['value'=>$quantity($n,'orden confirmada','órdenes confirmadas'),'detail'=>'Compras','tone'=>'green'];}break;
                case 'ventas':if(permissionHas('ventas.orders')){$n=$count("SELECT COUNT(*) FROM sales_quotations s WHERE s.status='sale' AND s.order_date=CURRENT_DATE() AND ".permissionWhere('ventas.orders','s'));$notes[$key]=['value'=>$quantity($n,'orden hoy','órdenes hoy'),'detail'=>'Seguimiento','tone'=>'rose'];}break;
                case 'venta_directa':if(permissionHas('venta_directa.sales')){$n=$count('SELECT COUNT(*) FROM direct_sale_checkouts d WHERE DATE(d.created_at)=CURRENT_DATE() AND '.permissionWhere('venta_directa.sales','d'));$notes[$key]=['value'=>$quantity($n,'venta hoy','ventas hoy'),'detail'=>'Venta rápida','tone'=>'green'];}break;
                case 'contabilidad':$n=0;$allowed=false;foreach(['customer','supplier'] as $type){$r='contabilidad.'.$type.'_invoices';if(!permissionHas($r))continue;$allowed=true;$n+=$count("SELECT COUNT(*) FROM accounting_invoices i WHERE i.invoice_type='$type' AND i.document_type='invoice' AND i.status='posted' AND i.payment_state NOT IN ('paid','reversed') AND ".permissionWhere($r,'i'));}if($allowed)$notes[$key]=['value'=>$quantity($n,'factura pendiente','facturas pendientes'),'detail'=>$n?'Por revisar':'Al día','tone'=>$n?'amber':'green'];break;
                case 'restaurante':if(permissionHas('restaurante.accounts')){$n=$count("SELECT COUNT(*) FROM restaurant_accounts r WHERE r.status IN ('open','billing') AND ".permissionWhere('restaurante.accounts','r'));$notes[$key]=['value'=>$quantity($n,'cuenta abierta','cuentas abiertas'),'detail'=>'Restaurante','tone'=>'amber'];}break;
                case 'ajustes':$n=count($GLOBALS['company_context']['companies']??[]);$notes[$key]=['value'=>$quantity($n,'empresa autorizada','empresas autorizadas'),'detail'=>'Configurar','tone'=>'muted'];break;
            }
        }catch(Throwable $e){error_log('Indicador de inicio '.$key.': '.$e->getMessage());}
    }
    return $notes;
}
