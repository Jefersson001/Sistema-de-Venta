<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= $mode==='login'?'Iniciar sesión':($mode==='activate'?'Activar administrador':'Cambiar contraseña') ?> | Vent-As</title><?php require dirname(__DIR__).'/partials/favicon.php'; ?><link rel="stylesheet" href="<?= authBasePath() ?>/css/app.css?v=<?= filemtime(dirname(__DIR__,2).'/css/app.css') ?>"><?php require dirname(__DIR__).'/partials/theme.php'; ?>
<link rel="stylesheet" href="<?= authBasePath() ?>/css/auth.css?v=<?= filemtime(dirname(__DIR__,2).'/css/auth.css') ?>">
<script src="<?= authBasePath() ?>/js/auth-form.js?v=<?= filemtime(dirname(__DIR__,2).'/js/auth-form.js') ?>" defer></script>
</head>
<body class="vent-auth<?= $mode==='login'?' vent-auth-login':'' ?>"><main class="auth-page"><div class="auth-layout">
<aside class="auth-story" aria-label="Vent-As, gestión empresarial">
    <div class="auth-brand"><span class="brand-mark brand-mark--vent-as" aria-hidden="true"></span><div><strong>Vent-<span>As</span></strong><small>GESTIÓN EMPRESARIAL</small></div></div>
    <div class="auth-story-copy"><span class="auth-eyebrow">CONECTA CADA PARTE DE TU NEGOCIO</span><h2>Tu gestión.<br>Más simple.<br><em>Más conectada.</em></h2><p>Ventas, inventario y finanzas en un solo lugar. Todo lo que necesitas para seguir creciendo.</p></div>
    <div class="auth-visual" aria-hidden="true"><div class="auth-visual-top"><span class="auth-visual-symbol">V<span>↗</span></span><div><strong>Todo conectado</strong><small>Una visión de tu negocio</small></div><span class="auth-visual-dot"></span></div><div class="auth-chart"><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div><div class="auth-visual-modules"><span>Ventas</span><span>Inventario</span><span>Contabilidad</span></div></div>
    <p class="auth-story-footer">Menos pasos. Más posibilidades.</p>
</aside>
<section class="contact-sheet auth-card" aria-labelledby="auth-title">
    <span class="auth-access-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="5" y="10" width="14" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 4v3"/></svg></span>
    <p class="auth-form-eyebrow"><?= $mode==='login'?'BIENVENIDO A VENT-AS':'TU CUENTA VENT-AS' ?></p>
    <h1 id="auth-title"><?= $mode==='login'?'Inicia sesión':($mode==='activate'?'Crea tu administrador':'Cambia tu contraseña') ?></h1>
    <p class="section-subtitle"><?= $mode==='login'?'Accede a la gestión de tu negocio.':($mode==='activate'?'Esta será la primera cuenta administradora del negocio.':($user['must_change_password']?'Elige una contraseña personal para reemplazar la temporal y continuar.':'Introduce tu contraseña actual y elige una nueva.')) ?></p>
    <?php foreach($errors as $error): ?><p class="notice notice-error" role="alert"><?= authEscape($error) ?></p><?php endforeach; ?>
    <form method="post" action="<?= authBasePath().($mode==='login'?'/login.php':($mode==='activate'?'/activar.php':'/cambiar-contrasena.php')) ?>">
        <input type="hidden" name="csrf" value="<?= authEscape($_SESSION['auth_csrf']) ?>">
        <?php if($mode==='login'): ?><input type="hidden" name="next" value="<?= authEscape($next) ?>"><?php endif; ?>
        <div class="contact-form-grid">
            <?php if($mode==='activate'): ?><label class="contact-full">Nombre *<input name="name" required maxlength="160" autocomplete="name" value="<?= authEscape($input['name']??'') ?>"></label><?php endif; ?>
            <?php if($mode!=='password'): ?><label class="contact-full">Usuario *<input name="login" required maxlength="120" autocomplete="username" autocapitalize="none" spellcheck="false" value="<?= authEscape($input['login']??'') ?>"></label><?php endif; ?>
            <?php if($mode==='activate'): ?><label class="contact-full">Correo<input type="email" name="email" maxlength="160" autocomplete="email" value="<?= authEscape($input['email']??'') ?>"></label><?php endif; ?>
            <?php if($mode==='password'): ?><label class="contact-full">Contraseña actual *<input name="current_password" type="password" required maxlength="72" autocomplete="current-password"></label><?php endif; ?>
            <div class="contact-full auth-password-field"><label for="auth-password"><?= $mode==='password'?'Nueva contraseña':'Contraseña' ?> *</label><div class="auth-password-control"><input id="auth-password" name="password" type="password" required <?= $mode==='login'?'':'minlength="8"' ?> maxlength="72" autocomplete="<?= $mode==='login'?'current-password':'new-password' ?>"><button type="button" class="auth-password-toggle" aria-controls="auth-password" aria-pressed="false" hidden>Mostrar</button></div></div>
            <?php if($mode!=='login'): ?><label class="contact-full">Repite la contraseña *<input name="password_confirmation" type="password" required minlength="8" maxlength="72" autocomplete="new-password"><small>Usa una frase de al menos 8 caracteres.</small></label><?php endif; ?>
        </div>
        <div class="contact-form-actions"><button class="contact-button contact-button-primary auth-submit"><?= $mode==='login'?'Entrar a Vent-As':($mode==='activate'?'Crear administrador':'Guardar contraseña') ?><span aria-hidden="true">→</span></button></div>
    </form>
    <?php if($mode==='login'): ?><p class="section-subtitle auth-help">Si olvidaste tu contraseña, solicita al administrador del negocio que la restablezca.</p><?php elseif($mode==='password'): ?><form class="auth-exit" method="post" action="<?= authBasePath() ?>/logout.php"><input type="hidden" name="csrf" value="<?= authEscape($_SESSION['auth_csrf']) ?>"><button class="contact-button">Cerrar sesión</button></form><?php endif; ?>
</section></div><footer class="auth-page-footer">Vent-As <span>·</span> Gestión empresarial</footer></main></body></html>
