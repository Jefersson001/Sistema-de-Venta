<?php $companyContext=$GLOBALS['company_context']??null; if($companyContext): ?>
<details class="company-selector"><summary><?php foreach($companyContext['companies'] as $choice)if((int)$choice['id']===$companyContext['active'])echo authEscape($choice['name']?:'Empresa '.$choice['id']); ?><?php if(count($companyContext['selected'])>1): ?> <span class="company-selection-count">+<?= count($companyContext['selected'])-1 ?></span><?php endif; ?> ▾</summary>
<form method="post" action="<?= authBasePath() ?>/empresas.php" data-company-selector>
<input type="hidden" name="csrf" value="<?= authEscape($_SESSION['auth_csrf']) ?>"><input type="hidden" name="next" value="<?= authEscape($_SERVER['REQUEST_URI']??authBasePath().'/index.php') ?>">
<input type="hidden" name="active_company" value="<?= (int)$companyContext['active'] ?>">
<?php $orderedCompanies=array_merge(array_filter($companyContext['companies'],static fn($c)=>(int)$c['id']===$companyContext['active']),array_filter($companyContext['companies'],static fn($c)=>(int)$c['id']!==$companyContext['active'])); ?>
<?php foreach($orderedCompanies as $choice): $principal=(int)$choice['id']===$companyContext['active']; ?><div class="company-choice <?= $principal?'company-choice-primary':'' ?>">
<input type="checkbox" name="companies[]" value="<?= (int)$choice['id'] ?>" aria-label="Consultar <?= authEscape($choice['name']?:'Empresa '.$choice['id']) ?>" <?= in_array((int)$choice['id'],$companyContext['selected'],true)?'checked':'' ?>>
<button type="submit" name="primary_company" value="<?= (int)$choice['id'] ?>" class="company-choice-name" <?= $principal?'aria-current="true"':'' ?> title="Usar como empresa principal"><?= authEscape($choice['name']?:'Empresa '.$choice['id']) ?><?php if($principal): ?><small>Principal</small><?php endif; ?></button></div><?php endforeach; ?>
<noscript><button class="contact-button" type="submit">Aplicar selección</button></noscript></form></details>
<script>window.nexoCompanyScope=<?= json_encode(companyScopeToken(),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;</script>
<script src="<?= authBasePath() ?>/js/multi-company.js?v=<?= filemtime(dirname(__DIR__,2).'/js/multi-company.js') ?>"></script>
<?php endif; ?>
