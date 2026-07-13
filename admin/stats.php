<?php
/**
 * Statistiques de connexion — réservé au rôle owner.
 *
 * Toutes les données sont agrégées en SQL puis passées à Chart.js en JSON inline.
 */

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/layout.php';

checkAuth();
checkRole(['owner']);

const PERIODS = [7, 30, 90];

$connect = db();
$table   = tbl('login_attempts');

$period = (int)($_GET['period'] ?? 30);
if (!in_array($period, PERIODS, true)) {
    $period = 30;
}

/**
 * Exécute une agrégation bornée à la période courante.
 *
 * @return array<int, array<string, mixed>>
 */
function aggregate(mysqli $connect, string $sql, int $period): array
{
    $stmt = $connect->prepare($sql);
    $stmt->bind_param('i', $period);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

$since = 'created_at > DATE_SUB(NOW(), INTERVAL ? DAY)';

// --- KPIs ------------------------------------------------------------------

$totals = aggregate($connect,
    'SELECT COUNT(*) AS total, SUM(success = 1) AS ok, SUM(success = 0) AS ko
     FROM ' . $table . ' WHERE ' . $since, $period)[0];

$total = (int)$totals['total'];
$ok    = (int)$totals['ok'];
$ko    = (int)$totals['ko'];
$rate  = $total > 0 ? round($ok / $total * 100, 1) : 0.0;

// --- Activité quotidienne (jours sans tentative inclus, à zéro) -------------

$daily = aggregate($connect,
    'SELECT DATE(created_at) AS day, SUM(success = 1) AS ok, SUM(success = 0) AS ko
     FROM ' . $table . ' WHERE ' . $since . '
     GROUP BY day ORDER BY day', $period);

$byDay = [];
foreach ($daily as $row) {
    $byDay[$row['day']] = ['ok' => (int)$row['ok'], 'ko' => (int)$row['ko']];
}

$labels = $seriesOk = $seriesKo = [];
for ($i = $period - 1; $i >= 0; $i--) {
    $day       = date('Y-m-d', strtotime("-$i days"));
    $labels[]  = date('d.m', strtotime($day));
    $seriesOk[] = $byDay[$day]['ok'] ?? 0;
    $seriesKo[] = $byDay[$day]['ko'] ?? 0;
}

// --- Répartitions ----------------------------------------------------------

$byOs = aggregate($connect,
    'SELECT COALESCE(os, "Inconnu") AS k, COUNT(*) AS n
     FROM ' . $table . ' WHERE ' . $since . '
     GROUP BY k ORDER BY n DESC', $period);

$byDevice = aggregate($connect,
    'SELECT COALESCE(device, "Inconnu") AS k, COUNT(*) AS n
     FROM ' . $table . ' WHERE ' . $since . '
     GROUP BY k ORDER BY n DESC', $period);

$topUsers = aggregate($connect,
    'SELECT username AS k, SUM(success = 1) AS ok, SUM(success = 0) AS ko, COUNT(*) AS n
     FROM ' . $table . ' WHERE ' . $since . '
     GROUP BY k ORDER BY n DESC LIMIT 8', $period);

$topIps = aggregate($connect,
    'SELECT ip AS k, SUM(success = 1) AS ok, SUM(success = 0) AS ko, COUNT(*) AS n
     FROM ' . $table . ' WHERE ' . $since . '
     GROUP BY k ORDER BY n DESC LIMIT 10', $period);

// Couleurs par plateforme : identifiables d'un coup d'œil, pas décoratives.
const OS_COLORS = [
    'Windows' => '#0078d4', 'macOS' => '#555555', 'iOS'     => '#007aff',
    'Android' => '#3ddc84', 'Linux' => '#e95420', 'Inconnu' => '#bbbbbb',
];

const DEVICE_COLORS = [
    'Desktop' => '#64748b', 'Mobile' => '#48bb78', 'Tablette' => '#f6ad55', 'Inconnu' => '#bbbbbb',
];

/**
 * Prépare un jeu de données doughnut pour Chart.js.
 */
function doughnutData(array $rows, array $palette): array
{
    return [
        'labels' => array_column($rows, 'k'),
        'values' => array_map('intval', array_column($rows, 'n')),
        'colors' => array_map(
            static fn(string $key): string => $palette[$key] ?? '#bbbbbb',
            array_column($rows, 'k')
        ),
    ];
}

$chartData = [
    'daily'  => ['labels' => $labels, 'ok' => $seriesOk, 'ko' => $seriesKo],
    'os'     => doughnutData($byOs, OS_COLORS),
    'device' => doughnutData($byDevice, DEVICE_COLORS),
];

renderHeader('Statistiques de connexion', ['css' => ['admin'], 'icons' => true]);
?>

<div class="card">
    <div class="card-header">
        <h2>Période</h2>
        <nav class="period-switch">
            <?php foreach (PERIODS as $days): ?>
                <a href="?period=<?= $days ?>" class="<?= $days === $period ? 'is-active' : '' ?>">
                    <?= $days ?> jours
                </a>
            <?php endforeach; ?>
        </nav>
    </div>

    <div class="grid grid-4">
        <div class="kpi">
            <div class="kpi-label">Tentatives</div>
            <div class="kpi-value"><?= $total ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Réussies</div>
            <div class="kpi-value is-ok"><?= $ok ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Échouées</div>
            <div class="kpi-value is-ko"><?= $ko ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Taux de succès</div>
            <div class="kpi-value"><?= e(number_format($rate, 1, ',', ' ')) ?> %</div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Activité quotidienne</h2></div>
    <div class="chart chart-tall"><canvas id="chartDaily"></canvas></div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-header"><h2>Systèmes d'exploitation</h2></div>
        <div class="chart"><canvas id="chartOs"></canvas></div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Appareils</h2></div>
        <div class="chart"><canvas id="chartDevice"></canvas></div>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-header"><h2>Top identifiants</h2></div>

        <?php if (!$topUsers): ?>
            <p class="empty">Aucune donnée.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr><th>Identifiant</th><th class="num">OK</th><th class="num">Échecs</th><th class="num">Total</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($topUsers as $row): ?>
                        <tr class="<?= (int)$row['ko'] > (int)$row['ok'] ? 'is-suspect' : '' ?>">
                            <td><?= e($row['k']) ?></td>
                            <td class="num"><?= (int)$row['ok'] ?></td>
                            <td class="num"><?= (int)$row['ko'] ?></td>
                            <td class="num"><strong><?= (int)$row['n'] ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-header"><h2>Top adresses IP</h2></div>

        <?php if (!$topIps): ?>
            <p class="empty">Aucune donnée.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr><th>IP</th><th class="num">OK</th><th class="num">Échecs</th><th class="num">Total</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($topIps as $row): ?>
                        <tr class="<?= (int)$row['ko'] > 3 ? 'is-suspect' : '' ?>">
                            <td class="mono"><?= e($row['k']) ?></td>
                            <td class="num"><?= (int)$row['ok'] ?></td>
                            <td class="num"><?= (int)$row['ko'] ?></td>
                            <td class="num"><strong><?= (int)$row['n'] ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script type="application/json" id="stats-data">
    <?= json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>
</script>

<?php renderFooter(['scripts' => [
    CDN_CHARTJS,
    url('assets/js/stats.js') . '?v=' . APP_VERSION,
]]); ?>
