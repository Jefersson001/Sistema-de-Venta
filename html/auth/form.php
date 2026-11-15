<?php require_once dirname(__DIR__,2).'/php/ui-icons.php'; ?>
<!doctype html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#00ad78"><title><?= $mode==='login'?'Iniciar sesión':($mode==='activate'?'Activar administrador':'Cambiar contraseña') ?> | Konta</title>
<?php require dirname(__DIR__).'/partials/favicon.php'; ?>
<link rel="stylesheet" href="<?= authBasePath() ?>/css/app.css?v=<?= filemtime(dirname(__DIR__,2).'/css/app.css') ?>"><link rel="stylesheet" href="<?= authBasePath() ?>/css/konta.css?v=<?= filemtime(dirname(__DIR__,2).'/css/konta.css') ?>">
<script src="<?= authBasePath() ?>/js/konta-theme.js?v=<?= filemtime(dirname(__DIR__,2).'/js/konta-theme.js') ?>"></script><script src="<?= authBasePath() ?>/js/auth-form.js?v=<?= filemtime(dirname(__DIR__,2).'/js/auth-form.js') ?>" defer></script>
</head><body class="konta-auth">
<header class="konta-auth-header"><?php require dirname(__DIR__).'/partials/konta-brand.php'; ?><div><span class="konta-auth-header-copy">Tu negocio, conectado.</span><button class="konta-icon-button" type="button" data-konta-theme-toggle aria-label="Usar modo oscuro" aria-pressed="false"><?= kontaIcon('moon') ?></button></div></header>
<main class="konta-auth-main">
<aside class="konta-auth-story" aria-label="Konta, gestión empresarial">
<span class="konta-eyebrow"><i aria-hidden="true"></i> GESTIÓN PARA TU DÍA A DÍA</span>
<h1>Tu negocio,<br>más simple.<br><em>Todo conectado.</em></h1>
<p>Ventas, inventario y finanzas en un mismo lugar. Menos pasos para trabajar; más claridad para decidir.</p>
<div class="konta-auth-preview" aria-hidden="true"><div class="konta-preview-head"><span class="konta-mini-mark">K</span><strong>Panel de Control</strong><span class="konta-preview-online">En línea</span></div><div class="konta-preview-body"><div class="konta-preview-modules"><span><?= kontaIcon('ventas') ?>Ventas</span><span><?= kontaIcon('inventario') ?>Inventario</span><span><?= kontaIcon('contabilidad') ?>Finanzas</span></div><div class="konta-preview-chart"><span>Una visión de tu negocio</span><svg viewBox="0 0 360 80" fill="none"><path d="M0 75 50 64 98 68 148 39 196 47 246 25 296 31 356 5v75H0Z" fill="#00ad7812"/><path d="m0 75 50-11 48 4 50-29 48 8 50-22 50 6 60-26" stroke="#00ad78" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg></div><div class="konta-preview-caption"><?= kontaIcon('check') ?>Cada parte de tu negocio, en su lugar.</div></div></div>
<div class="konta-auth-trust"><?= kontaIcon('shield') ?><span>Acceso por usuario y empresa</span><i aria-hidden="true"></i><span>Control por permisos</span></div>
</aside>
<section class="konta-auth-card" aria-labelledby="auth-title">
<span class="konta-auth-lock"><?= kontaIcon('lock') ?></span><span class="konta-eyebrow"><?= $mode==='login'?'BIENVENIDO DE NUEVO':'TU CUENTA KONTA' ?></span>
<h2 id="auth-title"><?= $mode==='login'?'Inicia sesión en Konta':($mode==='activate'?'Crea tu administrador':'Cambia tu contraseña') ?></h2>
<p class="konta-auth-subtitle"><?= $mode==='login'?'Ingresa tus datos para acceder a tu espacio de trabajo.':($mode==='activate'?'Esta será la primera cuenta administradora del negocio.':($user['must_change_password']?'Elige una contraseña personal para reemplazar la temporal y continuar.':'Introduce tu contraseña actual y elige una nueva.')) ?></p>
<?php foreach($errors as $error): ?><p class="notice notice-error" role="alert"><?= authEscape($error) ?></p><?php endforeach; ?>
<form method="post" action="<?= authBasePath().($mode==='login'?'/login.php':($mode==='activate'?'/activar.php':'/cambiar-contrasena.php')) ?>">
<input type="hidden" name="csrf" value="<?= authEscape($_SESSION['auth_csrf']) ?>"><?php if($mode==='login'): ?><input type="hidden" name="next" value="<?= authEscape($next) ?>"><?php endif; ?>
<?php if($mode==='activate'): ?><label>Nombre *<input name="name" required maxlength="160" autocomplete="name" value="<?= authEscape($input['name']??'') ?>"></label><?php endif; ?>
<?php if($mode!=='password'): ?><label>Usuario *<input name="login" required maxlength="120" autocomplete="username" autocapitalize="none" spellcheck="false" placeholder="Tu usuario" value="<?= authEscape($input['login']??'') ?>"></label><?php endif; ?>
<?php if($mode==='activate'): ?><label>Correo<input type="email" name="email" maxlength="160" autocomplete="email" value="<?= authEscape($input['email']??'') ?>"></label><?php endif; ?>
<?php if($mode==='password'): ?><label>Contraseña actual *<input name="current_password" type="password" required maxlength="72" autocomplete="current-password"></label><?php endif; ?>
<div class="konta-password-field"><label for="auth-password"><?= $mode==='password'?'Nueva contraseña':'Contraseña' ?> *</label><div class="konta-password-control"><input id="auth-password" name="password" type="password" required <?= $mode==='login'?'':'minlength="8"' ?> maxlength="72" placeholder="Tu contraseña" autocomplete="<?= $mode==='login'?'current-password':'new-password' ?>"><button type="button" class="auth-password-toggle" aria-controls="auth-password" aria-pressed="false" hidden>Mostrar</button></div></div>
<?php if($mode!=='login'): ?><label>Repite la contraseña *<input name="password_confirmation" type="password" required minlength="8" maxlength="72" autocomplete="new-password"><small>Usa una frase de al menos 8 caracteres.</small></label><?php endif; ?>
<?php if($mode==='login'): ?><details class="konta-recovery"><summary>¿Olvidaste tu contraseña?</summary><p>Solicita al administrador del negocio que la restablezca.</p></details><?php endif; ?>
<button class="konta-button konta-button-primary konta-auth-submit" type="submit"><?= $mode==='login'?'Entrar al sistema':($mode==='activate'?'Crear administrador':'Guardar contraseña') ?><?= kontaIcon('arrow') ?></button>
</form><p class="konta-auth-footnote"><?= kontaIcon('shield') ?>Tu acceso está vinculado a tus permisos.</p>
<?php if($mode==='password'): ?><form class="konta-auth-exit" method="post" action="<?= authBasePath() ?>/logout.php"><input type="hidden" name="csrf" value="<?= authEscape($_SESSION['auth_csrf']) ?>"><button class="konta-text-button">Cerrar sesión</button></form><?php endif; ?>
</section></main><footer class="konta-auth-footer">© <?= date('Y') ?> Konta Software · Gestión empresarial</footer></body></html>
