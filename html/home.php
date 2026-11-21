<?php
$quickSale=permissionHas('venta_directa.sales','create')&&permissionContact()>0;
$canReview=permissionScope('venta_directa.requests','confirm')==='all';
$cardCopy=[
 'compra_directa'=>['Compras Directas','Compra y registra recepción y factura del proveedor en un paso.'],
 'dashboard'=>['Dashboard','Resumen del negocio, indicadores y actividad reciente.'],
 'contactos'=>['Contactos & CRM','Centraliza clientes, proveedores y sus datos de contacto.'],
 'inventario'=>['Inventario','Organiza productos, existencias, variantes y movimientos.'],
 'compras'=>['Compras','Da seguimiento a proveedores, órdenes y recepciones.'],
 'ventas'=>['Ventas','Gestiona órdenes de venta, entregas y facturación.'],
 'contabilidad'=>['Contabilidad','Controla cobros, pagos, cuentas pendientes y tesorería.'],
 'restaurante'=>['Restaurante','Gestiona áreas, mesas, pedidos y cuentas abiertas.'],
 'venta_directa'=>['Ventas Directas','Vende desde el móvil, registra cobros y solicita mercancía.'],
 'ajustes'=>['Ajustes','Configura la empresa, los usuarios y las opciones del sistema.'],
];
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#00ad78"><title>Panel de Control | Konta</title>
<?php require __DIR__.'/partials/favicon.php'; ?>
<link rel="stylesheet" href="css/app.css?v=<?= filemtime(dirname(__DIR__).'/css/app.css') ?>">
<link rel="stylesheet" href="css/konta.css?v=<?= filemtime(dirname(__DIR__).'/css/konta.css') ?>">
<script src="js/konta-theme.js?v=<?= filemtime(dirname(__DIR__).'/js/konta-theme.js') ?>"></script>
<script src="js/app.js?v=<?= filemtime(dirname(__DIR__).'/js/app.js') ?>" defer></script><script src="js/navigation-search.js?v=<?= filemtime(dirname(__DIR__).'/js/navigation-search.js') ?>" defer></script>
</head><body class="konta-home"><div class="app-shell">
<?php $homeHref='index.php';$isHomePage=true;require __DIR__.'/partials/top-navigation.php'; ?>
<main class="konta-main">
<section class="konta-launcher" aria-labelledby="modules-title">
<div class="konta-page-head"><div><div class="konta-heading-line"><h1 id="modules-title">Panel de Control</h1><span class="konta-online <?= !$databaseStatus['connected']?'is-offline':'' ?>"><i aria-hidden="true"></i><?= $databaseStatus['connected']?'En línea':'Sin conexión' ?></span></div><p>Accede a los módulos operativos y financieros de tu negocio.</p></div>
<div class="konta-quick-actions">
<?php if($quickSale): ?><a class="konta-button konta-button-primary" href="modules/venta_directa/index.php"><?= kontaIcon('bolt') ?>Nueva venta rápida</a><?php elseif($canReview): ?><a class="konta-button konta-button-primary" href="modules/venta_directa/solicitudes.php"><?= kontaIcon('inventario') ?>Revisar solicitudes</a><?php endif; ?>
</div></div>
<div class="konta-module-grid" data-module-grid>
<?php if(!$modules): ?><div class="notice">No tienes módulos autorizados. Solicita al administrador que revise tus permisos. Puedes acceder a <a href="perfil.php">tu perfil</a>.</div><?php endif; ?>
<?php foreach($modules as $key=>$module): $copy=$cardCopy[$key]??[$module['name'],$module['description']];$note=$homeFootnotes[$key]??['value'=>$module['category'],'detail'=>'Abrir módulo','tone'=>'muted']; ?>
<a class="konta-module-card <?= $key==='venta_directa'?'konta-card-featured':'' ?>" href="<?= authEscape($module['url']) ?>" data-module="<?= authEscape($key) ?>" data-module-card data-module-name="<?= authEscape(mb_strtolower($module['name'])) ?>">
<div class="konta-card-top"><span class="konta-module-icon konta-icon-<?= authEscape($key) ?>"><?= kontaIcon($key) ?></span><span class="konta-open"><?= $key==='venta_directa'?'Venta rápida':'Abrir' ?><?= kontaIcon('external') ?></span></div>
<h2><?= authEscape($copy[0]) ?></h2><p><?= authEscape($copy[1]) ?></p>
<div class="konta-card-foot"><span><?= authEscape($note['value']) ?></span><span class="konta-foot-<?= authEscape($note['tone']) ?>"><?= authEscape($note['detail']) ?><?= $key==='venta_directa'?kontaIcon('arrow'):'' ?></span></div>
</a><?php endforeach; ?>
</div></section>
</main>
<footer class="konta-footer"><span>© <?= date('Y') ?> Konta Software · Gestión empresarial</span><span><?= kontaIcon('shield') ?>Acceso por permisos <i aria-hidden="true">·</i> Tu negocio, conectado.</span></footer>
</div></body></html>
