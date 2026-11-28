<?php $initial=mb_strtoupper(mb_substr(trim($user['full_name']),0,1)); ?>
<aside class="community-navigation" aria-label="Mi espacio">

<p class="community-nav-label">MI ESPACIO</p><nav><a <?= !$accountPage&&!$newRequestPage?'aria-current="page"':'' ?> href="<?= $base ?>/portal-demo.php#mis-solicitudes"><?= kontaIcon('closing') ?>Mis solicitudes<span><?= $total ?></span></a><?php if($canRequest): ?><a href="<?= $base ?>/portal-demo.php?section=solicitar" <?= $newRequestPage?'aria-current="page"':'' ?>><?= kontaIcon('plus') ?>Solicitar demo</a><?php endif; ?><a href="<?= $base ?>/conoce-konta.php"><?= kontaIcon('inventario') ?>Conocer el sistema</a></nav>

</aside>
