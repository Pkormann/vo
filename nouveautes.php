<?php
/**
 * Nouveautés — ce qui a changé dans l'application, livraison après livraison.
 *
 * Le contenu vit dans config/nouveautes.php, écrit pour un utilisateur du
 * magasin. Il est délibérément distinct de CHANGELOG.md, qui est technique et
 * n'est d'ailleurs jamais déployé.
 */

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/nouveautes.php';
require_once __DIR__ . '/includes/layout.php';

checkAuth();
checkRole(['owner', 'admin']);

// « Nouveau » = livré après la dernière visite de cette page. La marque est
// posée en cookie : un réglage par utilisateur en base serait plus rigoureux,
// mais ne vaut pas une colonne pour un confort d'affichage.
$lastSeen = (string)($_COOKIE['vo_seen_version'] ?? '');

setcookie('vo_seen_version', APP_VERSION, [
    'expires'  => time() + 31536000,
    'path'     => '/',
    'secure'   => isHttps(),
    'httponly' => true,
    'samesite' => 'Strict',
]);

renderHeader('Nouveautés', ['css' => ['admin', 'app'], 'icons' => true]);
?>

<div class="card">
    <p class="muted">
        Ce qui a changé dans l'outil, du plus récent au plus ancien. Une idée, une gêne, un besoin :
        écris-le, la plupart des choses se font vite.
    </p>
</div>

<div class="card">
    <div class="card-header">
        <h2>Historique des livraisons</h2>
        <span class="muted text-sm">version installée : <?= e(APP_VERSION) ?></span>
    </div>

    <ol class="releases">
        <?php foreach (NOUVEAUTES as $entry):
            $isNew = $lastSeen !== '' && version_compare($entry['version'], $lastSeen, '>');
        ?>
            <li class="release">
                <div class="release-head">
                    <strong><?= e($entry['titre']) ?></strong>
                    <?php if ($isNew): ?>
                        <span class="tag tag-ok">nouveau</span>
                    <?php endif; ?>
                </div>
                <p class="release-text muted"><?= e($entry['texte']) ?></p>
                <span class="release-meta muted text-sm">
                    version <?= e($entry['version']) ?> · <?= e(fmtDate($entry['date'], 'd.m.Y')) ?>
                </span>
            </li>
        <?php endforeach; ?>
    </ol>
</div>

<?php renderFooter(); ?>
