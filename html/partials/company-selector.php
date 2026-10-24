<?php $companyContext=$GLOBALS['company_context']??null; if($companyContext): ?>
<?php $activeCompanyLabel='Empresa '.$companyContext['active'];foreach($companyContext['companies'] as $choice)if((int)$choice['id']===$companyContext['active'])$activeCompanyLabel=$choice['name']?:'Empresa '.$choice['id']; ?>
<details class="company-selector"><summary title="Empresas · <?= authEscape($activeCompanyLabel) ?>" aria-label="Seleccionar empresas. Empresa principal: <?= authEscape($activeCompanyLabel) ?>">
<svg class="company-selector-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 21V5a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v16M17 9h2a1 1 0 0 1 1 1v11M2 21h20M8 7h1m3 0h1M8 11h1m3 0h1M8 15h1m3 0h1M9 21v-3h3v3"/></svg>
<span class="visually-hidden"><?= authEscape($activeCompanyLabel) ?></span>
<?php if(count($companyContext['selected'])>1): ?><span class="company-selection-count">+<?= count($companyContext['selected'])-1 ?></span><?php endif; ?>
<svg class="company-selector-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m3 4.5 3 3 3-3"/></svg></summary>
<form method="post" action="<?= authBasePath() ?>/empresas.php" data-company-selector>
<div class="company-menu-heading"><strong>Mis empresas</strong><span>Elige la principal y las que deseas consultar.</span></div>
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
