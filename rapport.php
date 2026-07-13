<?php
/**
 * Rapport — le bilan vivant : ce qui se vend, ce qui dort, ce qui manque.
 *
 * Tout est relatif à la période interrogée (voir includes/period.php), sauf le
 * stock, qui est une photo de l'instant : on compare toujours les ventes d'une
 * période au stock d'aujourd'hui, puisque c'est celui-là qu'il faut écouler.
 *
 * Deux indicateurs portent la page :
 *   couverture = stock ÷ (ventes ÷ mois de la période)  → mois de stock au rythme observé
 *   dormant    = couverture > SEUIL_DORMANT, ou stock sans aucune vente
 */

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/catalog.php';
require_once __DIR__ . '/includes/period.php';
require_once __DIR__ . '/includes/layout.php';

checkAuth();
checkRole(['owner', 'admin']);

/** Mois de stock au-delà desquels une famille est considérée comme dormante. */
const SEUIL_DORMANT = 6.0;

/** En deçà, la famille risque la rupture avant la fin de la saison. */
const SEUIL_TENSION = 2.0;

$period = currentPeriod();

$rotation = familyRotation($period['from'], $period['to'], $period['months']);
$kpis     = stockKpis();

$totals     = salesTotals($period['from'], $period['to']);
$prevTotals = salesTotals($period['prev_from'], $period['prev_to']);

$deltaVolume = $prevTotals['n'] > 0
    ? round(100 * ($totals['n'] - $prevTotals['n']) / $prevTotals['n'])
    : null;

$dormant      = [];
$tension      = [];
$dormantValue = 0.0;

foreach ($rotation as $row) {
    $coverage = $row['coverage'];
    $inStock  = (int)$row['in_stock'];

    if ($inStock === 0) {
        // Vendue mais vide en rayon : c'est l'autre visage de la tension.
        if ((int)$row['sold'] > 0) {
            $tension[] = $row;
        }
        continue;
    }

    if ($coverage === null || $coverage > SEUIL_DORMANT) {
        $dormant[]     = $row;
        $dormantValue += (float)$row['stock_value'];
    } elseif ($coverage < SEUIL_TENSION) {
        $tension[] = $row;
    }
}

usort($tension, static fn(array $a, array $b): int => $b['sold'] <=> $a['sold']);

$soldThis = salesByCategory($period['from'], $period['to']);
$soldPrev = salesByCategory($period['prev_from'], $period['prev_to']);

// La saisonnalité se lit toujours sur des années pleines : c'est le seul cadre
// où « décembre » veut dire quelque chose.
$year       = (int)date('Y');
$monthsThis = salesByMonth($year);
$monthsPrev = salesByMonth($year - 1);

$dormantShare = $kpis['value'] > 0 ? round(100 * $dormantValue / $kpis['value']) : 0;

$chartData = [
    'categories' => CATEGORIES,
    'soldThis'   => array_values($soldThis),
    'soldPrev'   => array_values($soldPrev),
    'labelThis'  => $period['label'],
    'labelPrev'  => 'même période, un an plus tôt',
    'yearThis'   => $year,
    'yearPrev'   => $year - 1,
    'monthsThis' => array_values(array_map(static fn(array $m): int => $m['n'], $monthsThis)),
    'monthsPrev' => array_values(array_map(static fn(array $m): int => $m['n'], $monthsPrev)),
    'stockValue' => [],
];

foreach (CATEGORIES as $category) {
    $value = 0.0;
    foreach ($rotation as $row) {
        if ($row['category'] === $category) {
            $value += (float)$row['stock_value'];
        }
    }
    $chartData['stockValue'][] = round($value);
}

renderHeader('Rapport', ['css' => ['admin', 'app'], 'icons' => true]);
?>

<div class="card">
    <?php renderPeriodFilter($period, url('rapport.php')); ?>
</div>

<div class="grid grid-4">
    <div class="kpi">
        <span class="kpi-label">Vendus sur la période</span>
        <span class="kpi-value"><?= (int)$totals['n'] ?></span>
        <span class="kpi-note">
            <?= $deltaVolume === null
                ? 'pas de comparaison possible'
                : e(sprintf('%+d %% vs un an plus tôt (%d)', $deltaVolume, $prevTotals['n'])) ?>
        </span>
    </div>
    <div class="kpi">
        <span class="kpi-label">Chiffre d'affaires</span>
        <span class="kpi-value"><?= e(chf($totals['ca'], false)) ?></span>
        <span class="kpi-note">
            panier moyen <?= e(chf($totals['n'] > 0 ? $totals['ca'] / $totals['n'] : null)) ?>
        </span>
    </div>
    <div class="kpi kpi-alert">
        <span class="kpi-label">Stock qui dort</span>
        <span class="kpi-value"><?= e(chf($dormantValue, false)) ?></span>
        <span class="kpi-note"><?= (int)$dormantShare ?> % de la valeur du stock</span>
    </div>
    <div class="kpi">
        <span class="kpi-label">Familles en tension</span>
        <span class="kpi-value"><?= count($tension) ?></span>
        <span class="kpi-note">moins de <?= (int)SEUIL_TENSION ?> mois de stock</span>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-header">
            <h2>Ventes par catégorie</h2>
            <span class="muted text-sm"><?= e($period['label']) ?></span>
        </div>
        <div class="chart"><canvas id="chart-categories"></canvas></div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>Saisonnalité</h2>
            <span class="muted text-sm">années pleines</span>
        </div>
        <div class="chart"><canvas id="chart-months"></canvas></div>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-header">
            <h2>Où dort la valeur du stock</h2>
            <span class="muted text-sm">photo d'aujourd'hui</span>
        </div>
        <div class="chart"><canvas id="chart-stock"></canvas></div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>Ce qui dort</h2>
            <span class="muted text-sm">plus de <?= (int)SEUIL_DORMANT ?> mois de stock</span>
        </div>

        <?php if (!$dormant): ?>
            <p class="empty">Rien ne dort sur cette période.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Famille</th>
                            <th class="num">Stock</th>
                            <th class="num">Vendus</th>
                            <th class="num">Couverture</th>
                            <th class="num">Valeur</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dormant as $row): ?>
                            <tr>
                                <td>
                                    <span class="cell-main"><?= e($row['family']) ?></span>
                                    <span class="cell-sub"><?= e($row['category']) ?></span>
                                </td>
                                <td class="num"><?= (int)$row['in_stock'] ?></td>
                                <td class="num"><?= (int)$row['sold'] ?></td>
                                <td class="num">
                                    <?php if ($row['coverage'] === null): ?>
                                        <span class="tag tag-danger">aucune vente</span>
                                    <?php else: ?>
                                        <span class="tag tag-warn"><?= e(number_format((float)$row['coverage'], 1)) ?> mois</span>
                                    <?php endif; ?>
                                </td>
                                <td class="num"><?= e(chf((float)$row['stock_value'], false)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>Rotation par famille</h2>
        <span class="muted text-sm">
            ventes du <?= e(fmtDate($period['from'], 'd.m.Y')) ?> au <?= e(fmtDate($period['to'], 'd.m.Y')) ?>,
            soit <?= e(number_format($period['months'], 1)) ?> mois
        </span>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Catégorie</th>
                    <th>Famille</th>
                    <th class="num">Vendus</th>
                    <th class="num">En stock</th>
                    <th class="num">Millésimes anciens</th>
                    <th class="num">Valeur stock</th>
                    <th class="num">Couverture</th>
                    <th>Verdict</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rotation as $row):
                    $coverage = $row['coverage'];
                    $inStock  = (int)$row['in_stock'];

                    if ($inStock === 0 && (int)$row['sold'] === 0) {
                        continue;
                    }

                    if ($inStock === 0) {
                        $verdict = ['tag-danger', 'rupture'];
                    } elseif ($coverage === null) {
                        $verdict = ['tag-danger', 'ne se vend pas'];
                    } elseif ($coverage > SEUIL_DORMANT) {
                        $verdict = ['tag-warn', 'dort'];
                    } elseif ($coverage < SEUIL_TENSION) {
                        $verdict = ['tag-warn', 'tendu'];
                    } else {
                        $verdict = ['tag-ok', 'sain'];
                    }
                ?>
                    <tr>
                        <td class="muted text-sm"><?= e($row['category']) ?></td>
                        <td><strong><?= e($row['family']) ?></strong></td>
                        <td class="num"><?= (int)$row['sold'] ?></td>
                        <td class="num"><?= $inStock ?></td>
                        <td class="num"><?= (int)$row['old_stock'] ?: '—' ?></td>
                        <td class="num"><?= e(chf((float)$row['stock_value'], false)) ?></td>
                        <td class="num">
                            <?= $coverage === null ? '∞' : e(number_format((float)$coverage, 1)) ?>
                        </td>
                        <td><span class="tag <?= e($verdict[0]) ?>"><?= e($verdict[1]) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script type="application/json" id="chart-data"><?= json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?></script>

<?php renderFooter(['scripts' => [
    CDN_CHARTJS,
    url('assets/js/period.js') . '?v=' . APP_VERSION,
    url('assets/js/rapport.js') . '?v=' . APP_VERSION,
]]); ?>
