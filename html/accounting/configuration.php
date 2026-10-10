<?php $page = $modulePageData; ?>
<?php if ($page['flash']): ?><p class="notice notice-success" role="status"><?= accountingEscape($page['flash']) ?></p><?php endif; ?>
<?php if ($page['errors']): ?><div class="notice notice-error" role="alert"><ul><?php foreach ($page['errors'] as $error): ?><li><?= accountingEscape($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($page['available']): ?>
<section aria-labelledby="accounting-title">
    <div class="contact-toolbar"><div><p class="eyebrow">CONTABILIDAD · CONFIGURACIÓN</p>
        <?php if ($page['view'] !== 'home'): ?><a class="overview-link" href="<?= $page['view'] === 'form' ? 'configuracion.php?section=payment_terms' : 'configuracion.php' ?>">← <?= $page['view'] === 'form' ? 'Términos de pago' : 'Configuración' ?></a><?php endif; ?>
        <h1 id="accounting-title"><?= $page['view'] === 'home' ? 'Configuración' : 'Términos de pago' ?></h1></div>
        <?php if ($page['view'] === 'list'): ?><a class="contact-button contact-button-primary" href="configuracion.php?section=payment_terms&amp;new=1">+ Nuevo término de pago</a><?php endif; ?>
    </div>
    <?php if ($page['view'] === 'home'): ?>
        <div class="catalog-options"><a class="catalog-option" href="configuracion.php?section=payment_terms"><strong>Términos de pago</strong><span><?= $page['total'] ?> registros <span aria-hidden="true">→</span></span></a></div>
    <?php elseif ($page['view'] === 'form'): ?>
        <form class="contact-sheet" method="post" action="configuracion.php?section=payment_terms<?= isset($page['record']['id']) ? '&amp;id=' . (int) $page['record']['id'] : '&amp;new=1' ?>">
            <input type="hidden" name="csrf" value="<?= accountingEscape($page['csrf']) ?>"><input type="hidden" name="action" value="save_payment_term">
            <h2><?= isset($page['record']['id']) ? 'Editar término de pago' : 'Nuevo término de pago' ?></h2>
            <label>Nombre *<input name="name" required maxlength="120" value="<?= accountingEscape($page['record']['name'] ?? '') ?>"></label>
            <div class="contact-form-actions"><button class="contact-button contact-button-primary" type="submit">Guardar</button><a class="contact-button" href="configuracion.php?section=payment_terms">Volver al listado</a></div>
        </form>
    <?php else: ?>
        <form class="contact-filters" method="get" action="configuracion.php"><input type="hidden" name="section" value="payment_terms"><label>Buscar<input name="q" type="search" maxlength="120" value="<?= accountingEscape($page['search']) ?>" placeholder="Nombre del término de pago"></label><button class="contact-button" type="submit">Buscar</button><a class="contact-button" href="configuracion.php?section=payment_terms">Limpiar</a></form>
        <div class="contact-table-wrap" role="region" aria-label="Términos de pago" tabindex="0"><table class="contact-table"><thead><tr><th scope="col">Nombre</th><th scope="col">Acción</th></tr></thead><tbody>
            <?php foreach ($page['terms'] as $term): ?><tr><td><a class="contact-name" href="configuracion.php?section=payment_terms&amp;id=<?= (int) $term['id'] ?>"><?= accountingEscape($term['name']) ?></a></td><td><a class="inline-link" href="configuracion.php?section=payment_terms&amp;id=<?= (int) $term['id'] ?>">Editar<span class="visually-hidden"> <?= accountingEscape($term['name']) ?></span></a></td></tr><?php endforeach; ?>
            <?php if (! $page['terms']): ?><tr><td colspan="2" class="contact-empty">No hay términos de pago. Pulsa «Nuevo término de pago» para crear uno.</td></tr><?php endif; ?>
        </tbody></table></div>
        <nav class="contact-pagination" aria-label="Páginas de términos de pago"><span>Página <?= $page['currentPage'] ?> de <?= $page['pages'] ?></span><?php foreach (['Anterior' => $page['currentPage'] - 1, 'Siguiente' => $page['currentPage'] + 1] as $label => $number): ?><?php if ($number >= 1 && $number <= $page['pages']): ?><a class="contact-button" href="configuracion.php?<?= accountingEscape(http_build_query(['section' => 'payment_terms', 'q' => $page['search'], 'page' => $number])) ?>"><?= $label ?></a><?php endif; ?><?php endforeach; ?></nav>
    <?php endif; ?>
</section>
<?php endif; ?>
