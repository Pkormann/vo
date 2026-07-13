<?php
/**
 * Migrations du schéma — pour les bases déjà installées.
 *
 * `install/db.php` crée les tables absentes, mais ne touche jamais aux tables
 * existantes : c'est ici que vivent les évolutions de colonnes.
 *
 * Accès : install/migrate.php?token=<INSTALL_TOKEN>
 * Idempotent : chaque migration vérifie son propre état avant d'agir, et le
 * script est donc rejouable sans risque.
 */

require_once __DIR__ . '/../config/install.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/layout.php';

requireInstallToken();

$connect = db();
$log     = [];
$error   = '';

/** La colonne existe-t-elle déjà ? */
function hasColumn(mysqli $connect, string $table, string $column): bool
{
    $sql  = 'SELECT COUNT(*) AS n FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?';
    $stmt = $connect->prepare($sql);
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)$row['n'] > 0;
}

/**
 * Migrations, dans l'ordre. Chacune porte sa condition : on n'applique rien
 * deux fois, et relancer le script est sans effet quand tout est à jour.
 */
$migrations = [
    'bikes.delivery_at' => [
        'done' => static fn(): bool => hasColumn(db(), tbl('bikes'), 'delivery_at'),
        'sql'  => 'ALTER TABLE ' . tbl('bikes') . '
                   ADD COLUMN delivery_at DATE NULL
                   COMMENT "remise prévue au client, pour un vélo réservé"
                   AFTER sold_at',
        'why'  => 'Date de remise d\'un vélo réservé (vendu, livré plus tard).',
    ],
];

try {
    foreach ($migrations as $name => $migration) {
        if (($migration['done'])()) {
            $log[] = ['name' => $name, 'state' => 'déjà appliquée', 'why' => $migration['why']];
            continue;
        }

        $connect->query($migration['sql']);
        $log[] = ['name' => $name, 'state' => 'appliquée', 'why' => $migration['why']];
    }
} catch (mysqli_sql_exception $e) {
    $error = 'Migration impossible : ' . $e->getMessage();
}

renderHeader('Migration de la base', ['css' => ['login'], 'nav' => false, 'chrome' => false, 'bodyClass' => 'login-body']);
?>

<div class="login-card install-card">
    <div class="login-head">
        <span class="brand-mark">VO</span>
        <h1 class="login-title">Migration</h1>
        <p class="login-sub">Évolutions du schéma existant</p>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($log): ?>
        <ul class="install-log">
            <?php foreach ($log as $entry): ?>
                <li>
                    <span class="mono"><?= e($entry['name']) ?></span>
                    <span class="muted"><?= e($entry['state']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($error === ''): ?>
        <div class="alert alert-success">
            Schéma à jour. Ce script est rejouable : relance-le après chaque déploiement qui
            annonce une migration.
        </div>
        <a class="btn btn-block" href="<?= e(url('index.php')) ?>">Retour à l'application</a>
    <?php endif; ?>
</div>

<?php renderFooter(['chrome' => false]); ?>
