<fieldset class="email-documents email-compose-documents">
<legend>Contenido para compartir</legend>
<p class="email-field-help">Elige los mensajes y los documentos por separado.</p>
<h2 class="document-content-heading">Mensajes</h2>
<div class="email-document-options">
<?php foreach(documentMessageOptions($choices) as $key=>$option): ?>
<label><input type="checkbox" name="documents[]" value="<?= $e($key) ?>" <?= (in_array($key,$selected,true)||($key==='message:'.$kind&&in_array('message',$selected,true)))?'checked':'' ?> <?= !$option['available']?'disabled':'' ?>><span><?= $e($option['label']) ?><?php if(!$option['available']): ?><small>Sin documento relacionado disponible</small><?php endif; ?></span></label>
<?php endforeach; ?>
</div>
<h2 class="document-content-heading">Documentos</h2>
<div class="email-document-options">
<?php foreach($choices as $key=>$choice): ?><label><input type="checkbox" name="documents[]" value="<?= $e($key) ?>" <?= in_array($key,$selected,true)?'checked':'' ?>><span><?= $e($choice['label']) ?></span></label><?php endforeach; ?>
</div>
</fieldset>
