<?php $canAttach = permissionHas('contactos.contacts', $editing ? 'edit' : 'create'); ?>
<h2 class="notebook-page-title">Fotos y documentos</h2>
<div class="contact-attachment-list" data-contact-attachments>
    <?php foreach ($page['attachments'] ?? [] as $attachment): ?>
    <?php $fileUrl = 'archivo.php?'.http_build_query(['id'=>$record['id'], 'file'=>$attachment['id']]); ?>
    <article class="contact-attachment-row">
        <?php if (str_starts_with($attachment['mime_type'], 'image/')): ?><a href="<?= contactEscape($fileUrl) ?>" target="_blank" rel="noopener"><img class="contact-attachment-thumbnail" src="<?= contactEscape($fileUrl) ?>" alt="<?= contactEscape($attachment['filename']) ?>" loading="lazy"></a><?php else: ?><span class="contact-attachment-kind">Documento</span><?php endif; ?>
        <div class="contact-attachment-info"><strong><?= contactEscape($attachment['filename']) ?></strong><?php if ($attachment['description']): ?><p><?= contactEscape($attachment['description']) ?></p><?php endif; ?><small><?= contactEscape(number_format($attachment['byte_size']/1024, 1, ',', '.')) ?> KB</small></div>
        <div class="contact-attachment-actions"><a class="contact-button" href="<?= contactEscape($fileUrl) ?>" target="_blank" rel="noopener">Abrir</a><a class="contact-button" href="<?= contactEscape($fileUrl.'&download=1') ?>">Descargar</a>
        <?php if ($canAttach): ?><label class="contact-attachment-remove"><input type="checkbox" name="remove_attachments[]" value="<?= (int)$attachment['id'] ?>"> Quitar al guardar</label><?php endif; ?></div>
    </article>
    <?php endforeach; ?>
</div>
<?php if ($canAttach): ?>
<div data-contact-upload-lines></div>
<button type="button" class="contact-button contact-button-link" data-contact-attachment-add>+ Agregar archivo</button>
<p class="section-subtitle" role="status" data-contact-attachment-status></p>
<template data-contact-attachment-template>
    <div class="contact-attachment-upload" data-contact-upload-row>
        <label>Foto o documento *<input type="file" name="contact_files[__INDEX__]" required accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.doc,.docx,.xls,.xlsx,.txt" data-contact-upload></label>
        <label>Descripción<input type="text" name="attachment_descriptions[__INDEX__]" maxlength="200" placeholder="Ej.: Documento de identidad"></label>
        <button type="button" class="contact-button" data-contact-attachment-remove>Quitar línea</button>
    </div>
</template>
<?php endif; ?>
<?php if (empty($page['attachments'])): ?><p class="section-subtitle">Este contacto todavía no tiene archivos guardados.</p><?php endif; ?>
