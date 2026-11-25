<?php if(isset($page['sellerFilter'])): $sellerFilter=$page['sellerFilter'];$sellerEscape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); ?>
<label>Vendedor<select name="salesperson_id" data-auto-submit>
    <option value="" <?= $sellerFilter['selected']===''?'selected':'' ?>>Todos los vendedores</option>
    <option value="none" <?= $sellerFilter['selected']==='none'?'selected':'' ?>>Sin vendedor asignado</option>
    <?php foreach($sellerFilter['options'] as $sellerOption): ?><option value="<?= (int)$sellerOption['id'] ?>" <?= $sellerFilter['selected']===(string)$sellerOption['id']?'selected':'' ?>><?= $sellerEscape($sellerOption['name']) ?></option><?php endforeach; ?>
    <?php if(!in_array($sellerFilter['selected'],['','none'],true)&&!in_array($sellerFilter['selected'],array_map('strval',array_column($sellerFilter['options'],'id')),true)): ?><option value="<?= $sellerEscape($sellerFilter['selected']) ?>" selected>Vendedor no disponible</option><?php endif; ?>
</select></label>
<?php endif; ?>
