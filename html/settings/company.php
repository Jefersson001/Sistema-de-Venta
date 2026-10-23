<?php $editing=!empty($record['id']); ?>
<section aria-labelledby="company-title">
<div class="contact-toolbar"><div><p class="eyebrow">AJUSTES</p><h1 id="company-title"><?= $page['view']==='list'?'Empresas':$e($record['name']?:'Nueva empresa') ?></h1></div>
<?php if($page['view']==='list'&&$page['administrator']): ?><a class="contact-button contact-button-primary" href="empresa.php?new=1">Nueva empresa</a><?php endif; ?></div>
<?php if($page['view']==='list'): ?>
<div class="contact-table-wrap"><table class="contact-table"><thead><tr><th>Empresa</th><th>Estado de selección</th></tr></thead><tbody>
<?php foreach($page['rows'] as $company): ?><tr><td><a class="contact-name" href="empresa.php?id=<?= (int)$company['id'] ?>"><?= $e($company['name']?:'Empresa '.$company['id']) ?></a></td><td><?= (int)$company['id']===companyActiveId()?'Activa':(in_array((int)$company['id'],$GLOBALS['company_context']['selected'],true)?'Seleccionada':'Disponible') ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php else: ?>
<form class="contact-sheet" action="empresa.php?<?= $editing?'id='.(int)$record['id']:'new=1' ?>" method="post" enctype="multipart/form-data" data-company-form>
<input type="hidden" name="csrf" value="<?= $e($page['csrf']) ?>"><input type="hidden" name="action" value="save_company"><input type="hidden" name="version" value="<?= (int)($record['version']??0) ?>">
<div class="contact-form-grid">
<?php foreach(['name'=>'Nombre comercial *','legal_name'=>'Razón social','tax_id'=>'Documento / RIF','phone'=>'Teléfono','email'=>'Correo','website'=>'Sitio web'] as $field=>$label): ?><label><?= $label ?><input name="<?= $field ?>" value="<?= $e($record[$field]??'') ?>" maxlength="<?= ['name'=>160,'legal_name'=>180,'tax_id'=>40,'phone'=>40,'email'=>160,'website'=>200][$field] ?>" type="<?= ['email'=>'email','website'=>'url','phone'=>'tel'][$field]??'text' ?>" <?= $field==='name'?'required':'' ?>></label><?php endforeach; ?>
<label class="contact-full">Dirección<textarea name="address" maxlength="1000" rows="3"><?= $e($record['address']??'') ?></textarea></label>
<?php if($editing): ?><label>Logo<input type="file" name="logo" accept="image/png,image/jpeg,image/webp"><small>Hasta 2 MB.</small></label><?php if($record['has_logo']??false): ?><img class="company-logo" src="logo.php?id=<?= (int)$record['id'] ?>&amp;v=<?= (int)$record['version'] ?>" alt="Logo"><label><input type="checkbox" name="remove_logo" value="1">Quitar logo</label><?php endif; ?><?php endif; ?>
</div><div class="contact-form-actions"><button class="contact-button contact-button-primary">Guardar empresa</button><a class="contact-button" href="empresa.php">Volver</a></div></form>
<?php if($editing&&$page['administrator']): ?>
<form class="contact-sheet" method="post"><input type="hidden" name="csrf" value="<?= $e($page['csrf']) ?>"><input type="hidden" name="action" value="save_company_users"><input type="hidden" name="version" value="<?= (int)$record['version'] ?>">
<h2>Usuarios autorizados</h2><p>Los administradores tienen acceso a todas las empresas. Los demás usuarios necesitan una autorización explícita.</p><div class="access-user-group-options">
<?php foreach($page['userRows'] as $account): ?><div><label><input type="checkbox" name="users[]" value="<?= (int)$account['id'] ?>" <?= in_array((int)$account['id'],$page['companyUsers'],true)?'checked':'' ?>><span><?= $e($account['name'].' · '.$account['login'].($account['active']?'':' · Inactivo')) ?></span></label><label>Vendedor en esta empresa<select name="company_contacts[<?= (int)$account['id'] ?>]"><option value="">Sin vendedor específico</option><?php foreach($page['contacts'] as $contact): ?><option value="<?= (int)$contact['id'] ?>" <?= (int)($page['userContacts'][$account['id']]??0)===(int)$contact['id']?'selected':'' ?>><?= $e($contact['name']) ?></option><?php endforeach; ?></select></label></div><?php endforeach; ?>
</div><button class="contact-button">Guardar accesos</button></form>
<details class="contact-sheet"><summary>Eliminar esta empresa y todos sus registros</summary><p>Esta acción es definitiva. Se eliminarán sus operaciones, documentos, productos, contactos, existencias y configuraciones. Los usuarios y las demás empresas se conservarán.</p>
<div class="contact-table-wrap"><table class="contact-table"><thead><tr><th>Datos relacionados</th><th>Registros</th></tr></thead><tbody><?php foreach($page['impact'] as $table=>$count): ?><tr><td><?= $e(companyTableLabel($table)) ?></td><td><?= (int)$count ?></td></tr><?php endforeach; ?></tbody></table></div>
<form method="post"><input type="hidden" name="csrf" value="<?= $e($page['csrf']) ?>"><input type="hidden" name="action" value="delete_company"><input type="hidden" name="version" value="<?= (int)$record['version'] ?>"><input type="hidden" name="impact_hash" value="<?= $e(companyImpactHash($page['impact'])) ?>">
<label>Para confirmar, escribe exactamente: <?= $e($record['name']) ?><input name="confirmation" required autocomplete="off"></label><button class="contact-button">Eliminar empresa y sus registros</button></form></details>
<?php endif; ?>
<?php endif; ?></section>
