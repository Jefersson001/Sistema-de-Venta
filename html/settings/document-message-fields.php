<section class="workspace-form-section"><h2>Mensajes predeterminados</h2>
<div class="document-template-grid">
<?php foreach(documentMessageKinds() as $messageKind=>$messageLabel): $messageField='message_template_'.$messageKind; ?>
    <label><?= $e($messageLabel) ?><textarea name="<?= $messageField ?>" rows="10" maxlength="10000" required><?= $e($settings[$messageField]) ?></textarea></label>
<?php endforeach; ?>
</div></section>
