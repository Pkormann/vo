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

/**
 * Favicon : le carré VO, en SVG encodé dans l'URL.
 *
 * Un SVG plutôt qu'un .ico : net à toutes les tailles, aucun fichier binaire à
 * versionner, et il suit la charte (noir, blanc, rien d'autre).
 */
function faviconUri(): string
{
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
         . '<rect width="64" height="64" rx="12" fill="#111214"/>'
         . '<text x="32" y="43" font-family="Helvetica,Arial,sans-serif" font-size="30" '
         . 'font-weight="700" fill="#ffffff" text-anchor="middle">VO</text>'
         . '</svg>';

    return 'data:image/svg+xml,' . rawurlencode($svg);
}

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
    <meta name="theme-color" content="#111214">
    <title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
    <link rel="icon" href="<?= e(faviconUri()) ?>">
    <link rel="apple-touch-icon" href="<?= e(faviconUri()) ?>">
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

    <button type="button" class="nav-toggle js-nav-toggle" aria-label="Menu" aria-expanded="false">
        <i class="fa-solid fa-bars"></i>
    </button>

    <div class="nav-overlay js-nav-close"></div>

    <nav class="nav js-nav">
        <?php foreach (navItems() as $item): ?>
            <a href="<?= e(url($item['href'])) ?>"<?= isCurrentPage($item['href']) ? ' class="is-active"' : '' ?>>
                <?= e($item['label']) ?>
            </a>
        <?php endforeach; ?>

        <?php foreach (navMenus() as $menu): ?>
            <?php if (!$menu['items']) { continue; } ?>
            <div class="menu">
                <button type="button" class="menu-toggle js-menu<?= navMenuActive($menu) ? ' is-active' : '' ?>">
                    <?= e($menu['label']) ?> <i class="fa-solid fa-chevron-down"></i>
                </button>
                <div class="menu-list">
                    <?php foreach ($menu['items'] as $item): ?>
                        <a href="<?= e(url($item['href'])) ?>"<?= isCurrentPage($item['href']) ? ' class="is-active"' : '' ?>>
                            <?= e($item['label']) ?>
                            <span class="menu-desc"><?= e($item['desc']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- Visible seulement dans le tiroir mobile : la barre du haut n'a pas
             la place d'y garder la déconnexion. -->
        <div class="nav-account">
            <span class="username"><?= e(currentUser()) ?></span>
            <a class="btn btn-ghost btn-block" href="<?= e(url('logout.php')) ?>">Déconnexion</a>
        </div>
    </nav>

    <div class="topbar-user">
        <a class="btn-icon news<?= hasUnseenRelease() ? ' has-news' : '' ?>"
           href="<?= e(url('nouveautes.php')) ?>" title="Nouveautés">
            <i class="fa-solid fa-gift"></i>
        </a>
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
 * Navigation directe : le travail quotidien, et rien d'autre.
 *
 * « Tableau de bord » n'y figure pas : le logo VO y mène déjà, et une entrée de
 * plus pour la même page, c'est une entrée de trop.
 *
 * @return array<int, array{href: string, label: string, roles: string[]}>
 */
function navItems(): array
{
    return allowedNav([
        ['href' => 'stock.php',       'label' => 'Stock',        'roles' => ['owner', 'admin']],
        ['href' => 'ventes.php',      'label' => 'Ventes',       'roles' => ['owner', 'admin']],
        ['href' => 'rapport.php',     'label' => 'Rapport',      'roles' => ['owner', 'admin']],
        ['href' => 'precommande.php', 'label' => 'Pré-commande', 'roles' => ['owner', 'admin']],
    ]);
}

/**
 * Menus déroulants : ce qu'on ouvre de temps en temps, pas tous les jours.
 *
 * Quatorze entrées à plat étaient devenues illisibles. Le critère de rangement
 * est la fréquence d'usage, pas la parenté technique.
 *
 * @return array<int, array{label: string, items: array}>
 */
function navMenus(): array
{
    return [
        [
            'label' => 'Outils',
            'items' => allowedNav([
                ['href' => 'inventaire.php', 'label' => 'Inventaire', 'desc' => 'Pointer le rayon',          'roles' => ['owner', 'admin']],
                ['href' => 'doublons.php',   'label' => 'Doublons',   'desc' => 'Nettoyer la reprise Excel', 'roles' => ['owner', 'admin']],
                ['href' => 'marques.php',    'label' => 'Marques',    'desc' => 'Rabais et coût du stock',   'roles' => ['owner', 'admin']],
                ['href' => 'export.php',     'label' => 'Export',     'desc' => 'Données et analyse par IA', 'roles' => ['owner', 'admin']],
            ]),
        ],
        [
            'label' => 'Admin',
            'items' => allowedNav([
                ['href' => 'admin/import.php',   'label' => 'Import CSV',   'desc' => 'Reprise des classeurs',   'roles' => ['owner']],
                ['href' => 'admin/users.php',    'label' => 'Utilisateurs', 'desc' => 'Comptes et rôles',        'roles' => ['owner']],
                ['href' => 'admin/activite.php', 'label' => 'Activité',     'desc' => 'Qui a fait quoi',         'roles' => ['owner']],
                ['href' => 'admin/audit.php',    'label' => 'Audit',        'desc' => 'Tentatives de connexion', 'roles' => ['owner']],
                ['href' => 'admin/stats.php',    'label' => 'Statistiques', 'desc' => 'Connexions, appareils',   'roles' => ['owner']],
            ]),
        ],
    ];
}

/** Filtre une liste d'entrées de navigation selon le rôle courant. */
function allowedNav(array $items): array
{
    $role = currentRole();

    return array_values(array_filter(
        $items,
        static fn(array $item): bool => in_array($role, $item['roles'], true)
    ));
}

/** Le menu contient-il la page courante ? Sert à le marquer actif quand il est replié. */
function navMenuActive(array $menu): bool
{
    foreach ($menu['items'] as $item) {
        if (isCurrentPage($item['href'])) {
            return true;
        }
    }

    return false;
}

/** Une version a-t-elle été livrée depuis la dernière visite des nouveautés ? */
function hasUnseenRelease(): bool
{
    $seen = (string)($_COOKIE['vo_seen_version'] ?? '');

    return $seen === '' || version_compare(APP_VERSION, $seen, '>');
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

    // Le menu fait partie du chrome : il est présent sur toute page qui a une
    // barre de navigation, sans que chaque page ait à y penser.
    if ($opts['nav'] ?? true) {
        array_unshift($scripts, url('assets/js/menu.js') . '?v=' . APP_VERSION);
    }

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
