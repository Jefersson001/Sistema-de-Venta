<?php $page=$modulePageData; $e='settingsEscape'; $record=$page['record']; ?>
<?php foreach($page['errors'] as $message): ?><p class="notice notice-error" role="alert"><?= $e($message) ?></p><?php endforeach; ?>
<?php if($page['flash']): ?><p class="notice" role="status"><?= $e($page['flash']) ?></p><?php endif; ?>
<?php if($page['available']): ?>
    <?php if($page['area']==='users'): ?>
        <?php require __DIR__.'/users.php'; ?>
    <?php elseif($page['area']==='groups'): ?>
        <?php require __DIR__.'/groups.php'; ?>
    <?php elseif($page['area']==='company'): ?>
        <?php require __DIR__.'/company.php'; ?>
    <?php else: $section=$page['sections'][$page['section']]; ?>
        <section aria-labelledby="settings-title">
            <div class="contact-toolbar"><div><p class="eyebrow">AJUSTES</p><h1 id="settings-title">Ajustes generales</h1></div></div>
            <div class="contact-config-layout">
                <nav class="contact-config-nav" aria-label="Secciones de ajustes generales">
                    <?php foreach($page['sections'] as $key=>$item): ?><a href="index.php?section=<?= $key ?>" <?= $page['section']===$key?'aria-current="page"':'' ?>><?= $e($item['name']) ?></a><?php endforeach; ?>
                </nav>
                <div class="contact-config-content">
                    <div class="section-heading"><div><h2><?= $e($section['name']) ?></h2><p class="section-subtitle"><?= $e($section['description']) ?></p></div></div>
                    <?php if($page['section']==='general'): ?>
                        <div class="contact-sheet settings-business-summary">
                            <?php if($record['has_logo']): ?><img class="company-logo" src="logo.php?v=<?= (int)$record['version'] ?>" alt="Logo de <?= $e($record['name']) ?>"><?php endif; ?>
                            <div><strong><?= $e($record['name']?:'Configura tu empresa') ?></strong><p class="section-subtitle"><?= $e($record['legal_name']?:'Identidad del negocio') ?></p>
                                <?php if($page['currencies']): ?><p class="section-subtitle">Principal: <?= $e($page['currencies']['primary_code']) ?> · Alterna: <?= $e($page['currencies']['alternate_code']) ?></p><?php endif; ?>
                            </div><a class="contact-button" href="empresa.php?id=1">Editar empresa</a>
                        </div>
                    <?php endif; ?>
                    <div class="catalog-options">
                        <?php foreach($section['links'] as [$name,$description,$href]): ?><a class="catalog-option" href="<?= $e($href) ?>"><strong><?= $e($name) ?></strong><p class="section-subtitle"><?= $e($description) ?></p><span>Abrir <span aria-hidden="true">→</span></span></a><?php endforeach; ?>
                        <?php if(!$section['links']): ?><p class="section-subtitle">No tienes opciones de configuración autorizadas en esta sección. Consulta con el administrador para ampliar tus accesos.</p><?php endif; ?>
                    </div>
                </div>
            </div>
        </section>
    <?php endif; ?>
<?php endif; ?>
