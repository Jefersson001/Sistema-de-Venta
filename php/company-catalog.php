<?php
declare(strict_types=1);

/** Business data is private to a company; identities and reference vocabularies are shared. */
function companyTables(): array
{
    return [
        'accounting_cash_concepts','accounting_currencies','accounting_currency_rates','accounting_currency_settings',
        'accounting_invoice_lines','accounting_invoice_payments','accounting_invoice_reversals','accounting_invoices',
        'accounting_payment_accounts','accounting_payment_methods','accounting_payment_terms','accounting_payments',
        'accounting_treasury_documents','accounting_treasury_entries','business_taxes','contacts',
        'inventory_categories','inventory_locations','inventory_operation_lines','inventory_operations',
        'inventory_products','inventory_reservations','inventory_stock','inventory_stock_moves','inventory_warehouses',
        'product_supplier_prices','purchase_order_lines','purchase_orders','sales_commission_payments',
        'sales_quotation_lines','sales_quotations',
    ];
}

function companySharedTables(): array
{
    return ['business_company','company_user_access','company_schema_tables','company_schema_state',
        'auth_users','auth_user_groups','auth_state','auth_login_limits','access_groups','access_permissions',
        'access_group_permissions','contact_countries','contact_states','contact_cities','contact_types','contact_document_types'];
}

function companyResourceTable(string $resource): ?string
{
    return match($resource){
        'restaurante.accounts'=>'restaurant_accounts','restaurante.tables'=>'restaurant_tables',
        'contactos.contacts'=>'contacts','inventario.products'=>'inventory_products',
        'ventas.orders'=>'sales_quotations','compras.orders'=>'purchase_orders',
        'inventario.deliveries','inventario.receipts','inventario.internal'=>'inventory_operations',
        'contabilidad.customer_invoices','contabilidad.supplier_invoices','ventas.commissions'=>'accounting_invoices',
        'contabilidad.customer_payments','contabilidad.supplier_payments'=>'accounting_payments',
        'contabilidad.income','contabilidad.expense','contabilidad.transfer','contabilidad.opening'=>'accounting_treasury_documents',
        'ventas.commission_payments'=>'sales_commission_payments',default=>null,
    };
}

function companyTableLabel(string $table): string
{
    return [
        'contacts'=>'Contactos','inventory_products'=>'Productos','inventory_categories'=>'Categorías',
        'inventory_warehouses'=>'Almacenes','inventory_locations'=>'Ubicaciones','inventory_operations'=>'Operaciones de inventario',
        'inventory_operation_lines'=>'Líneas de inventario','inventory_stock'=>'Existencias','inventory_stock_moves'=>'Movimientos de existencias',
        'inventory_reservations'=>'Reservas','product_supplier_prices'=>'Precios de proveedores','business_taxes'=>'Impuestos',
        'sales_quotations'=>'Ventas y cotizaciones','sales_quotation_lines'=>'Líneas de ventas',
        'purchase_orders'=>'Compras','purchase_order_lines'=>'Líneas de compras','accounting_invoices'=>'Facturas y notas de crédito',
        'accounting_invoice_lines'=>'Líneas de facturas','accounting_invoice_payments'=>'Aplicaciones de pagos',
        'accounting_invoice_reversals'=>'Reversiones de facturas','accounting_payments'=>'Pagos',
        'accounting_payment_accounts'=>'Cajas y bancos','accounting_payment_methods'=>'Medios de pago','accounting_payment_terms'=>'Plazos de pago',
        'accounting_cash_concepts'=>'Conceptos de tesorería','accounting_treasury_documents'=>'Documentos de tesorería',
        'accounting_treasury_entries'=>'Movimientos de tesorería','accounting_currencies'=>'Monedas',
        'accounting_currency_rates'=>'Tasas de cambio','accounting_currency_settings'=>'Configuración de monedas',
        'sales_commission_payments'=>'Pagos de comisiones',
    ][$table]??'Registros adicionales';
}

function companyRouteTable(string $path,array $query): ?string
{
    $route=implode('/',array_slice(explode('/',$path),-2));
    if(basename($path)==='configuracion.php')return match($query['section']??''){
        'restaurant_areas'=>'restaurant_areas','restaurant_tables'=>'restaurant_tables',
        'categories'=>'inventory_categories','warehouses'=>'inventory_warehouses','locations'=>'inventory_locations',
        'payment_terms'=>'accounting_payment_terms','accounts'=>'accounting_payment_accounts','methods'=>'accounting_payment_methods',
        'concepts'=>'accounting_cash_concepts','currencies'=>'accounting_currencies','taxes'=>'business_taxes',default=>null,
    };
    return match($route){
        'restaurante/cuenta.php','restaurante/comprobante.php'=>'restaurant_accounts','restaurante/mesa.php'=>'restaurant_tables',
        'contactos/index.php'=>'contacts','inventario/index.php','ventas/producto.php'=>'inventory_products',
        'ventas/index.php'=>'sales_quotations','compras/index.php','compras/orden.php'=>'purchase_orders',
        'inventario/operaciones.php'=>'inventory_operations','contabilidad/index.php','ventas/comisiones.php'=>'accounting_invoices',
        'contabilidad/pagos.php'=>'accounting_payments','contabilidad/tesoreria.php'=>'accounting_treasury_documents',
        'ventas/pagos-comisiones.php'=>'sales_commission_payments',default=>null,
    };
}
