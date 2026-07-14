<?php
/**
 * Chrome commun : <head>, barre de navigation, pied de page versionné.
 *
 * Page applicative :
 *     renderHeader('Audit', ['css' => ['audit']]);
 *     … contenu …
 *     renderFooter();
 *
 * Page hors application (login) :
 *     renderHeader('Connexion', ['css' => ['login'], 'nav' => false,
 *                                'chrome' => false, 'bodyClass' => 'login-body']);
 *
 * Options : css (string[] locales), cdnCss (string[] URLs), icons (bool → Font Awesome),
 *           nav (bool), chrome (bool, enveloppe <main>+<h1>), bodyClass (string).
 */

require_once __DIR__ . '/helpers.php';

/** Feuille d'icônes : Font Awesome free, en CSS (pas la variante JS). */
const CDN_FONTAWESOME = 'https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.2/css/all.min.css';
const CDN_CHARTJS     = 'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js';

function renderHeader(string $title, array $opts = []): void
{
    $sheets    = array_merge(['base'], $opts['css'] ?? []);
    $cdnCss    = $opts['cdnCss']    ?? [];
    $withNav   = $opts['nav']       ?? true;
    $withMain  = $opts['chrome']    ?? true;
    $bodyClass = $opts['bodyClass'] ?? '';

    if ($opts['icons'] ?? false) {
        $cdnCss[] = CDN_FONTAWESOME;
    }
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
    <?php foreach ($cdnCss as $href): ?>
    <link rel="stylesheet" href="<?= e($href) ?>" crossorigin="anonymous">
    <?php endforeach; ?>
    <?php foreach ($sheets as $sheet): ?>
    <link rel="stylesheet" href="<?= e(url('assets/css/' . $sheet . '.css')) ?>?v=<?= e(APP_VERSION) ?>">
    <?php endforeach; ?>
</head>
<body<?= $bodyClass !== '' ? ' class="' . e($bodyClass) . '"' : '' ?>>
<?php if ($withNav): ?>
<header class="topbar">
    <a class="brand" href="<?= e(url('index.php')) ?>">
        <span class="brand-mark">VO</span>
        <span class="brand-text">Version Originale Cycles</span>
    </a>

    <nav class="nav">
        <?php foreach (navItems() as $item): ?>
            <a href="<?= e(url($item['href'])) ?>"<?= isCurrentPage($item['href']) ? ' class="is-active"' : '' ?>>
                <?= e($item['label']) ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="topbar-user">
        <span class="badge badge-<?= e(currentRole()) ?>"><?= e(currentRole()) ?></span>
        <span class="username"><?= e(currentUser()) ?></span>
        <a class="btn btn-ghost" href="<?= e(url('logout.php')) ?>">Déconnexion</a>
    </div>
</header>
<?php endif; ?>
<?php if ($withMain): ?>
<main class="page">
    <h1 class="page-title"><?= e($title) ?></h1>
<?php endif;
}

/**
 * Entrées de navigation autorisées pour le rôle courant.
 *
 * @return array<int, array{href: string, label: string, roles: string[]}>
 */
function navItems(): array
{
    $all = [
        ['href' => 'index.php',        'label' => 'Tableau de bord', 'roles' => ['owner', 'admin', 'user']],
        ['href' => 'stock.php',        'label' => 'Stock',           'roles' => ['owner', 'admin']],
        ['href' => 'ventes.php',       'label' => 'Ventes',          'roles' => ['owner', 'admin']],
        ['href' => 'rapport.php',      'label' => 'Rapport',         'roles' => ['owner', 'admin']],
        ['href' => 'precommande.php',  'label' => 'Pré-commande',    'roles' => ['owner', 'admin']],
        ['href' => 'marques.php',      'label' => 'Marques',         'roles' => ['owner', 'admin']],
        ['href' => 'export.php',       'label' => 'Export',          'roles' => ['owner', 'admin']],
        ['href' => 'inventaire.php',   'label' => 'Inventaire',      'roles' => ['owner', 'admin']],
        ['href' => 'doublons.php',     'label' => 'Doublons',        'roles' => ['owner', 'admin']],
        ['href' => 'nouveautes.php',   'label' => 'Nouveautés',      'roles' => ['owner', 'admin']],
        ['href' => 'admin/users.php',  'label' => 'Utilisateurs',    'roles' => ['owner']],
        ['href' => 'admin/activite.php', 'label' => 'Activité',      'roles' => ['owner']],
        ['href' => 'admin/audit.php',  'label' => 'Audit',           'roles' => ['owner']],
        ['href' => 'admin/stats.php',  'label' => 'Statistiques',    'roles' => ['owner']],
    ];

    $role = currentRole();

    return array_values(array_filter(
        $all,
        static fn(array $item): bool => in_array($role, $item['roles'], true)
    ));
}

function isCurrentPage(string $href): bool
{
    return basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === basename($href);
}

/**
 * @param array $opts scripts (string[]), chrome (bool, doit refléter renderHeader)
 */
function renderFooter(array $opts = []): void
{
    $withMain = $opts['chrome'] ?? true;
    $scripts  = $opts['scripts'] ?? [];

    if ($withMain) {
        echo "</main>\n";
    }
    ?>
<footer class="footer">
    <span><?= e(APP_NAME) ?></span>
    <span class="footer-version">v<?= e(APP_VERSION) ?> · <?= e(APP_VERSION_DATE) ?></span>
</footer>

<?php foreach ($scripts as $src): ?>
<script src="<?= e($src) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}
