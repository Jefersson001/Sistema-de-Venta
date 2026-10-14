<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Mi perfil | Nexo</title><link rel="stylesheet" href="<?= authBasePath() ?>/css/app.css?v=<?= filemtime(dirname(__DIR__,2).'/css/app.css') ?>"><script src="<?= authBasePath() ?>/js/app.js" defer></script></head>
<body><div class="app-shell"><?php $homeHref=authBasePath().'/index.php';$isHomePage=false;require dirname(__DIR__).'/partials/top-navigation.php'; ?>
<main class="main-area"><div class="page-content"><div class="contact-toolbar"><div><p class="eyebrow">MI CUENTA</p><h1><?= authEscape($user['name']) ?></h1></div><a class="contact-button" href="<?= authBasePath() ?>/cambiar-contrasena.php">Cambiar contraseña</a></div>
    <?php if($flash): ?><p class="notice" role="status"><?= authEscape($flash) ?></p><?php endif; ?>
    <div class="contact-sheet"><div class="contact-form-grid"><label>Nombre<input readonly value="<?= authEscape($user['name']) ?>"></label><label>Usuario<input readonly value="<?= authEscape($user['login']) ?>"></label><label>Correo<input readonly value="<?= authEscape($user['email']) ?>"></label></div>
        <h2 class="catalog-group-title">Grupos asignados</h2><div class="access-user-groups"><?php foreach($user['groups'] as $group): ?><span class="contact-tag"><?= authEscape($group['name']) ?><?= $group['active']?'':' · Archivado' ?></span><?php endforeach; ?><?php if(!$user['groups']): ?><p class="section-subtitle">Sin grupos asignados.</p><?php endif; ?></div>
    </div>
    <?php if(!$user['administrator']): ?><p class="notice">El acceso operativo de los grupos se habilitará en la siguiente fase. Tu cuenta y el cambio de contraseña ya están disponibles.</p><?php endif; ?>
</div></main></div></body></html>
