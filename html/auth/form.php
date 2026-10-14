<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= $mode==='login'?'Iniciar sesión':($mode==='activate'?'Activar administrador':'Cambiar contraseña') ?> | Nexo</title><link rel="stylesheet" href="<?= authBasePath() ?>/css/app.css?v=<?= filemtime(dirname(__DIR__,2).'/css/app.css') ?>"></head>
<body><main class="auth-page"><section class="contact-sheet auth-card">
    <div class="auth-brand"><span class="brand-mark">N</span><strong>nexo<span>.</span></strong></div>
    <h1><?= $mode==='login'?'Inicia sesión':($mode==='activate'?'Crea tu administrador':'Cambia tu contraseña') ?></h1>
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
            <label class="contact-full"><?= $mode==='password'?'Nueva contraseña':'Contraseña' ?> *<input name="password" type="password" required <?= $mode==='login'?'':'minlength="12"' ?> maxlength="72" autocomplete="<?= $mode==='login'?'current-password':'new-password' ?>"></label>
            <?php if($mode!=='login'): ?><label class="contact-full">Repite la contraseña *<input name="password_confirmation" type="password" required minlength="12" maxlength="72" autocomplete="new-password"><small>Usa una frase de al menos 12 caracteres.</small></label><?php endif; ?>
        </div>
        <div class="contact-form-actions"><button class="contact-button contact-button-primary auth-submit"><?= $mode==='login'?'Entrar':($mode==='activate'?'Crear administrador':'Guardar contraseña') ?></button></div>
    </form>
    <?php if($mode==='login'): ?><p class="section-subtitle auth-help">Si olvidaste tu contraseña, solicita al administrador del negocio que la restablezca.</p><?php elseif($mode==='password'): ?><form class="auth-exit" method="post" action="<?= authBasePath() ?>/logout.php"><input type="hidden" name="csrf" value="<?= authEscape($_SESSION['auth_csrf']) ?>"><button class="contact-button">Cerrar sesión</button></form><?php endif; ?>
</section></main></body></html>
