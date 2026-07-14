<?php
/**
 * Journal des actions métier — qui a fait quoi, et quand.
 *
 * À distinguer d'`audit.php`, qui journalise les *connexions* : la sécurité et
 * l'exploitation ne se lisent pas dans le même écran.
 */

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/activity.php';
require_once __DIR__ . '/../includes/catalog.php';
require_once __DIR__ . '/../includes/layout.php';

checkAuth();
checkRole(['owner']);

purgeActivity();

$fUser   = (string)($_GET['user'] ?? '');
$fAction = (string)($_GET['action'] ?? '');
$fDays   = max(1, min(730, (int)($_GET['days'] ?? 30)));

$rows = activityLog($fUser, $fAction, $fDays);

$users = db()->query(
    'SELECT DISTINCT username FROM ' . tbl('activity') . '
     WHERE username IS NOT NULL ORDER BY username'
)->fetch_all(MYSQLI_ASSOC);

$byAction = db()->query(
    'SELECT action, COUNT(*) AS n FROM ' . tbl('activity') . '
     WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
     GROUP BY action ORDER BY n DESC'
)->fetch_all(MYSQLI_ASSOC);

$today = (int)(db()->query(
    'SELECT COUNT(*) AS n FROM ' . tbl('activity') . ' WHERE DATE(created_at) = CURDATE()'
)->fetch_assoc()['n'] ?? 0);

renderHeader('Activité', ['css' => ['admin', 'app'], 'icons' => true]);
?>

<div class="grid grid-4">
    <div class="kpi">
        <span class="kpi-label">Actions aujourd'hui</span>
        <span class="kpi-value"><?= (int)$today ?></span>
    </div>
    <div class="kpi">
        <span class="kpi-label">Affichées</span>
        <span class="kpi-value"><?= count($rows) ?></span>
        <span class="kpi-note">sur <?= (int)$fDays ?> jour<?= $fDays > 1 ? 's' : '' ?></span>
    </div>
    <div class="kpi">
        <span class="kpi-label">Action la plus fréquente</span>
        <span class="kpi-value text-sm">
            <?= $byAction ? e(ACTIONS[$byAction[0]['action']] ?? $byAction[0]['action']) : '—' ?>
        </span>
        <span class="kpi-note">30 derniers jours</span>
    </div>
    <div class="kpi">
        <span class="kpi-label">Utilisateurs actifs</span>
        <span class="kpi-value"><?= count($users) ?></span>
    </div>
</div>

<div class="card">
    <form class="filters js-autosubmit" method="get">
        <div class="field">
            <label class="label" for="user">Utilisateur</label>
            <select class="input" id="user" name="user">
                <option value="">Tous</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?= e($user['username']) ?>"<?= $fUser === $user['username'] ? ' selected' : '' ?>>
                        <?= e($user['username']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label class="label" for="action">Action</label>
            <select class="input" id="action" name="action">
                <option value="">Toutes</option>
                <?php foreach (ACTIONS as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= $fAction === $key ? ' selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label class="label" for="days">Période</label>
            <select class="input" id="days" name="days">
                <?php foreach ([7 => '7 jours', 30 => '30 jours', 90 => '90 jours', 365 => '1 an'] as $value => $label): ?>
                    <option value="<?= (int)$value ?>"<?= $fDays === $value ? ' selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field field-actions">
            <a class="btn btn-ghost" href="<?= e(url('admin/activite.php')) ?>">Réinitialiser</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <h2><?= count($rows) ?> action<?= count($rows) > 1 ? 's' : '' ?></h2>
        <span class="muted text-sm">Journal conservé 2 ans, puis purgé</span>
    </div>

    <?php if (!$rows): ?>
        <p class="empty">Aucune action sur cette période.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Quand</th>
                        <th>Qui</th>
                        <th>Quoi</th>
                        <th>Détail</th>
                        <th class="col-sm-hide">IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= e(fmtDate($row['created_at'], 'd.m.Y H:i')) ?></td>
                            <td><strong><?= e($row['username'] ?? '—') ?></strong></td>
                            <td>
                                <span class="cell-main"><?= e(ACTIONS[$row['action']] ?? $row['action']) ?></span>
                                <?php if ($row['entity'] === 'velo' && $row['entity_id']): ?>
                                    <span class="cell-sub">
                                        <a href="<?= e(url('velo.php?id=' . (int)$row['entity_id'])) ?>">
                                            vélo #<?= (int)$row['entity_id'] ?>
                                        </a>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="muted text-sm"><?= e($row['detail'] ?? '') ?></td>
                            <td class="muted text-sm col-sm-hide mono"><?= e($row['ip'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php renderFooter(['scripts' => [url('assets/js/filtre.js') . '?v=' . APP_VERSION]]); ?>
