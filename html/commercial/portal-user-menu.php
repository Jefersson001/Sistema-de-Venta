<?php
$menuBase=$base??authBasePath();
$menuName=$menuName??$user['full_name'];
$menuSubtitle=$menuSubtitle??$user['business_name'];
$menuInitial=$initial??mb_strtoupper(mb_substr(trim($menuName),0,1));
$menuAccountUrl=$menuAccountUrl??($menuBase.'/portal-demo.php?section=cuenta');
$menuAccountActive=$menuAccountActive??($accountPage??false);
$menuLogoutAction=$menuLogoutAction??($menuBase.'/portal-demo.php');
?><div class="commercial-account-tools"><?php require __DIR__.'/message-notifications.php'; ?><details class="workspace-user-menu"><summary aria-label="Mi cuenta: <?= $e($menuName) ?>"><span class="workspace-user-avatar"><?= $e($menuInitial) ?></span><span class="workspace-user-name"><strong><?= $e($menuName) ?></strong><small><?= $e($menuSubtitle) ?></small></span><span class="workspace-user-chevron" aria-hidden="true"></span></summary><div class="workspace-user-options"><a href="<?= $menuAccountUrl ?>" <?= $menuAccountActive?'aria-current="page"':'' ?>><?= kontaIcon('contactos') ?>Mi cuenta</a><form method="post" action="<?= $menuLogoutAction ?>"><input type="hidden" name="csrf" value="<?= $e($_SESSION['auth_csrf']) ?>"><input type="hidden" name="action" value="logout"><button type="submit"><?= kontaIcon('back') ?>Cerrar sesión</button></form></div></details></div>
