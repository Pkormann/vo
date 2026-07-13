<?php
/**
 * Journal des tentatives de connexion — réservé au rôle owner.
 */

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/bruteforce.php';
require_once __DIR__ . '/../includes/layout.php';

checkAuth();
checkRole(['owner']);

const AUDIT_LIMIT = 500;

$connect = db();
$table   = tbl('login_attempts');

// --- Filtres ---------------------------------------------------------------

$from     = (string)($_GET['from'] ?? date('Y-m-d', strtotime('-7 days')));
$to       = (string)($_GET['to'] ?? date('Y-m-d'));
$username = trim((string)($_GET['username'] ?? ''));
$ip       = trim((string)($_GET['ip'] ?? ''));
$result   = (string)($_GET['result'] ?? '');   // '', 'ok', 'ko'

$where  = ['created_at >= ?', 'created_at < DATE_ADD(?, INTERVAL 1 DAY)'];
$params = [$from, $to];
$types  = 'ss';

if ($username !== '') {
    $where[]  = 'username LIKE ?';
    $params[] = '%' . $username . '%';
    $types   .= 's';
}

if ($ip !== '') {
    $where[]  = 'ip LIKE ?';
    $params[] = '%' . $ip . '%';
    $types   .= 's';
}

if ($result === 'ok' || $result === 'ko') {
    $where[]  = 'success = ?';
    $params[] = $result === 'ok' ? 1 : 0;
    $types   .= 'i';
}

$sql = 'SELECT created_at, username, ip, success, device, os, user_agent
        FROM ' . $table . '
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY created_at DESC
        LIMIT ' . AUDIT_LIMIT;

$stmt = $connect->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// --- KPIs du jour ----------------------------------------------------------

$today = $connect->query(
    'SELECT SUM(success = 1) AS ok, SUM(success = 0) AS ko
     FROM ' . $table . ' WHERE DATE(created_at) = CURDATE()'
)->fetch_assoc();

$blocked = bf_blocked_ips($connect);

renderHeader('Audit des connexions', ['css' => ['admin'], 'icons' => true]);
?>

<div class="grid grid-4">
    <div class="kpi">
        <div class="kpi-label">Connexions réussies (aujourd'hui)</div>
        <div class="kpi-value is-ok"><?= (int)($today['ok'] ?? 0) ?></div>
    </div>
    <div class="kpi">
        <div class="kpi-label">Échecs (aujourd'hui)</div>
        <div class="kpi-value is-ko"><?= (int)($today['ko'] ?? 0) ?></div>
    </div>
    <div class="kpi">
        <div class="kpi-label">IP bloquées maintenant</div>
        <div class="kpi-value <?= $blocked ? 'is-warn' : '' ?>"><?= count($blocked) ?></div>
    </div>
    <div class="kpi">
        <div class="kpi-label">Statistiques</div>
        <div class="kpi-value">
            <a class="kpi-link" href="<?= e(url('admin/stats.php')) ?>">
                <i class="fa-solid fa-chart-column" aria-hidden="true"></i>
            </a>
        </div>
    </div>
</div>

<?php if ($blocked): ?>
    <div class="alert alert-warn">
        <strong><?= count($blocked) ?> IP actuellement bloquée(s)</strong> —
        <?php foreach ($blocked as $i => $b): ?>
            <span class="mono"><?= e($b['ip']) ?></span> (<?= (int)$b['fails'] ?> échecs)<?= $i < count($blocked) - 1 ? ', ' : '' ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h2>Filtres</h2></div>

    <form method="get" class="form-inline">
        <div class="field">
            <label class="label" for="from">Du</label>
            <input class="input" type="date" id="from" name="from" value="<?= e($from) ?>">
        </div>
        <div class="field">
            <label class="label" for="to">Au</label>
            <input class="input" type="date" id="to" name="to" value="<?= e($to) ?>">
        </div>
        <div class="field">
            <label class="label" for="f-username">Identifiant</label>
            <input class="input" type="text" id="f-username" name="username" value="<?= e($username) ?>">
        </div>
        <div class="field">
            <label class="label" for="f-ip">IP</label>
            <input class="input" type="text" id="f-ip" name="ip" value="<?= e($ip) ?>">
        </div>
        <div class="field">
            <label class="label" for="f-result">Résultat</label>
            <select class="select" id="f-result" name="result">
                <option value="">Tous</option>
                <option value="ok"<?= $result === 'ok' ? ' selected' : '' ?>>Succès</option>
                <option value="ko"<?= $result === 'ko' ? ' selected' : '' ?>>Échec</option>
            </select>
        </div>

        <button type="submit" class="btn">Filtrer</button>
        <a class="btn btn-ghost" href="<?= e(url('admin/audit.php')) ?>">Réinitialiser</a>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <h2>Journal</h2>
        <span class="muted text-sm">
            <?= count($rows) ?> ligne(s)<?= count($rows) === AUDIT_LIMIT ? ' — limite atteinte' : '' ?>
        </span>
    </div>

    <?php if (!$rows): ?>
        <p class="empty">Aucune tentative sur cette période.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Date</th>
                    <th>Identifiant</th>
                    <th>IP</th>
                    <th>Résultat</th>
                    <th>Appareil</th>
                    <th>OS</th>
                    <th>User-agent</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="text-sm"><?= e(fmtDate($row['created_at'], 'd.m.Y H:i:s')) ?></td>
                        <td><?= e($row['username']) ?></td>
                        <td class="mono"><?= e($row['ip']) ?></td>
                        <td>
                            <span class="badge badge-<?= $row['success'] ? 'ok' : 'ko' ?>">
                                <?= $row['success'] ? 'OK' : 'Échec' ?>
                            </span>
                        </td>
                        <td class="muted text-sm"><?= e($row['device'] ?: '—') ?></td>
                        <td class="muted text-sm"><?= e($row['os'] ?: '—') ?></td>
                        <td class="ua mono" title="<?= e($row['user_agent']) ?>"><?= e($row['user_agent'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php renderFooter(); ?>
