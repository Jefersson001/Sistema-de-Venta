<dialog class="restaurant-pos-dialog restaurant-checkout-dialog" data-pos-checkout-dialog aria-labelledby="restaurant-checkout-title">
<form method="post" data-pos-command data-pos-checkout-form data-pos-customer-key="<?= $h('nexo-restaurant-customer:'.(int)permissionUser()['id'].':'.companyActiveId()) ?>">
<?php restaurantPosFields($cart,'pos_checkout'); ?>
<div class="restaurant-checkout-sticky"><div class="restaurant-pos-catalog-head"><h2 id="restaurant-checkout-title">Facturar y cobrar</h2><button type="button" class="button" data-pos-close-checkout aria-label="Cerrar">×</button></div>
<div class="restaurant-checkout-total"><span>Total de la mesa</span><strong data-checkout-total><?= $h($cart['total'].' '.$cart['currency']) ?></strong><small>Impuestos incluidos</small></div></div>
<details class="restaurant-checkout-order"><summary>Ver productos del pedido</summary><div data-checkout-items></div></details>
<div class="restaurant-fields">
<label>Cliente de la factura<select name="customer_id" required data-pos-preference="customer_id"><?php if(count($page['clients'])!==1): ?><option value="">Selecciona…</option><?php endif; ?><?php foreach($page['clients'] as $option): ?><option value="<?= (int)$option['id'] ?>"><?= $h($option['name']) ?></option><?php endforeach; ?></select></label>
<label>Ubicación del inventario<select name="location_id" required data-pos-preference="location_id"><?php if(count($page['locations'])!==1): ?><option value="">Selecciona…</option><?php endif; ?><?php foreach($page['locations'] as $option): ?><option value="<?= (int)$option['id'] ?>"><?= $h($option['name']) ?></option><?php endforeach; ?></select></label>
</div>
<label class="restaurant-checkout-remember"><input type="checkbox" data-checkout-default-customer> Usar este cliente por defecto en este dispositivo</label>
<label>Cómo deseas cobrar<select name="split_mode" data-checkout-mode><option value="full">Un pago o varios medios de pago</option><option value="amount">Dividir por importe / persona</option><option value="products">Dividir por productos</option></select></label>
<p class="restaurant-checkout-hint">Se emite una factura de la mesa al cliente seleccionado. Puedes repartir el pago entre varias personas o medios.</p>
<div data-checkout-payments></div>
<div class="restaurant-checkout-add"><button type="button" class="button" data-checkout-add>＋ Agregar otro pago</button><button type="button" class="button" data-checkout-even hidden>Repartir en partes iguales</button></div>
<div class="restaurant-checkout-balance" role="status" aria-live="polite"><span data-checkout-balance></span><span data-checkout-change></span></div>
<p class="restaurant-pos-dialog-error" data-pos-dialog-error role="alert" hidden></p>
<button class="button button-primary" type="submit">Confirmar factura y cobro</button>
<small>Confirma cuando hayas recibido todos los pagos. La mesa quedará pendiente de limpieza.</small>
<template data-checkout-payment-template><section class="restaurant-checkout-payment">
<div class="restaurant-section-heading"><h3 data-payment-title>Pago</h3><button type="button" class="button" data-payment-remove aria-label="Quitar este pago">Quitar</button></div>
<label data-payment-label-field hidden>Persona o referencia<input data-payment-field="label" maxlength="80" placeholder="Ej. Persona 1"></label>
<details data-payment-products hidden><summary>Elegir productos de esta persona</summary><div data-payment-product-lines></div></details>
<div class="restaurant-fields">
<label>Medio de pago<select data-payment-field="method_id" data-pos-preference="method_id" required><?php if(count($page['methods'])!==1): ?><option value="">Selecciona…</option><?php endif; ?><?php foreach($page['methods'] as $option): ?><option value="<?= (int)$option['id'] ?>" data-allow-change="<?= (int)$option['allow_change'] ?>"><?= $h($option['name']) ?></option><?php endforeach; ?></select></label>
<label>Caja o banco<select data-payment-field="account_id" data-pos-preference="account_id" required><?php if(count($page['cashAccounts'])!==1): ?><option value="">Selecciona…</option><?php endif; ?><?php foreach($page['cashAccounts'] as $option): ?><option value="<?= (int)$option['id'] ?>" data-account-type="<?= $h($option['account_type']) ?>"><?= $h($option['name']) ?></option><?php endforeach; ?></select></label>
<label>Importe a pagar<input data-payment-field="amount" type="number" min="0" step="0.01" inputmode="decimal" required></label>
<label data-payment-received-field hidden>Efectivo recibido<input data-payment-field="received_amount" type="number" min="0" step="0.01" inputmode="decimal" placeholder="Importe exacto" disabled></label>
</div><small data-payment-change></small>
</section></template>
</form></dialog>
