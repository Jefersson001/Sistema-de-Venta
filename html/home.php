<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f5f7fb">
    <title>Inicio | Nexo</title>
    <link rel="stylesheet" href="css/app.css?v=<?= filemtime(dirname(__DIR__) . '/css/app.css') ?>">
    <script src="js/app.js?v=<?= filemtime(dirname(__DIR__) . '/js/app.js') ?>" defer></script>
</head>
<body>
    <div class="app-shell">
        <?php $homeHref = 'index.php'; $isHomePage = true; require __DIR__ . '/partials/top-navigation.php'; ?>
        <main class="main-area">
            <div class="page-content">
                <section class="module-section module-launcher" aria-labelledby="modules-title">
                    <div class="section-heading">
                        <div>
                            <p class="eyebrow">PANEL DE CONTROL / Mis módulos</p>
                        </div>
                        <label class="module-search">
                            <span aria-hidden="true">⌕</span>
                            <span class="visually-hidden">Buscar módulo</span>
                            <input type="search" placeholder="Buscar módulo..." autocomplete="off" data-module-search>
                            <kbd>/</kbd>
                        </label>
                    </div>

                    <div class="module-grid" data-module-grid>
                        <?php if(!$modules): ?><div class="notice contact-full">El acceso operativo de tu grupo se habilitará en la siguiente fase. Puedes consultar <a class="inline-link" href="perfil.php">tu perfil</a> y gestionar tu contraseña.</div><?php endif; ?>
                        <?php foreach ($modules as $key => $module): ?>
                            <a class="module-card" href="modules/<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>/index.php" data-module-card data-module-name="<?= htmlspecialchars(mb_strtolower($module['name']), ENT_QUOTES, 'UTF-8') ?>">
                                <div class="module-card-top">
                                    <span class="module-icon icon-<?= htmlspecialchars($module['color'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($module['initials'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="module-status"><span></span> <?= in_array($key, ['contactos', 'dashboard', 'ajustes'], true) ? 'Disponible' : 'En desarrollo' ?></span>
                                </div>
                                <div class="module-card-copy">
                                    <div class="module-title-row">
                                        <h2><?= htmlspecialchars($module['name'], ENT_QUOTES, 'UTF-8') ?></h2>
                                        <span class="card-arrow" aria-hidden="true">↗</span>
                                    </div>
                                    <p><?= htmlspecialchars($module['description'], ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                                <div class="module-card-footer">
                                    <span class="module-category"><?= htmlspecialchars($module['category'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="module-open">Abrir módulo <span aria-hidden="true">→</span></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                        <p class="empty-search" data-empty-search hidden>No se encontraron módulos con ese nombre.</p>
                    </div>
                </section>


            </div>
        </main>
    </div>
</body>
</html>
