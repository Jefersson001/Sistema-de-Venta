<?php
// Reuse the same destinations as the home search so names and routes agree.
require_once dirname(__DIR__, 2).'/php/navigation-search.php';
$contextRoute = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$contextEntry = null;
$contextScore = -1;
$contextQuery = $_GET;
foreach (['section', 'type', 'kind'] as $contextKey) {
    if (!isset($contextQuery[$contextKey]) && isset($modulePageData[$contextKey])) $contextQuery[$contextKey] = $modulePageData[$contextKey];
}
foreach (navigationSearchCatalog() as $candidate) {
    if ($candidate['module'] !== $moduleKey || parse_url($candidate['route'], PHP_URL_PATH) !== $contextRoute) continue;
    parse_str(parse_url($candidate['route'], PHP_URL_QUERY) ?? '', $candidateQuery);
    $score = 0;
    foreach ($candidateQuery as $key => $value) {
        if ($key === 'section' && !isset($contextQuery[$key])) continue 2;
        if (isset($contextQuery[$key]) && $contextQuery[$key] !== $value) continue 2;
        if (isset($contextQuery[$key])) ++$score;
    }
    if ($score > $contextScore) { $contextEntry = $candidate; $contextScore = $score; }
}
$contextSegments = $contextEntry['segments'] ?? [$isConfiguration ? 'Configuración' : 'Vista general'];
if ($contextRoute === 'tesoreria.php' && !isset($_GET['kind'])) $contextSegments = ['Tesorería', 'Movimientos de dinero'];
$contextForm = isset($_GET['new']) ? 'Nuevo registro' : (isset($_GET['id']) ? 'Ficha del registro' : null);
$contextEscape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<div class="module-context" data-current-section="<?= $contextEscape($contextEntry['route'] ?? $contextRoute) ?>">
    <span class="module-context-art module-art-<?= $contextEscape($moduleKey) ?>" aria-hidden="true"></span>
    <div class="module-context-copy">
        <strong><?= $contextEscape($module['name']) ?></strong>
        <nav class="module-breadcrumb" aria-label="Ruta de navegación">
            <ol><li><a href="../../index.php">Inicio</a></li>
                <li><?= $contextEscape($module['name']) ?></li>
                <?php foreach ($contextSegments as $contextIndex => $contextSegment): ?>
                <li <?= !$contextForm && $contextIndex === array_key_last($contextSegments) ? 'aria-current="page"' : '' ?>><?php if ($contextForm && $contextEntry && $contextIndex === array_key_last($contextSegments)): ?><a href="<?= $contextEscape($contextEntry['route']) ?>"><?= $contextEscape($contextSegment) ?></a><?php else: ?><?= $contextEscape($contextSegment) ?><?php endif; ?></li>
                <?php endforeach; ?>
                <?php if ($contextForm): ?><li aria-current="page"><?= $contextEscape($contextForm) ?></li><?php endif; ?>
            </ol>
        </nav>
    </div>
</div>
