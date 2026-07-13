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

$sizes    = salesBySize($period['from'], $period['to']);
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
        <span class="kpi-label">Bientôt épuisées</span>
        <span class="kpi-value"><?= count($tension) ?></span>
        <span class="kpi-note">
            familles dont il reste moins de <?= (int)SEUIL_TENSION ?> mois de stock :
            rupture probable avant la fin de saison
        </span>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-header">
            <h2>Ventes par catégorie</h2>
            <span class="muted text-sm">nombre de vélos · <?= e($period['label']) ?></span>
        </div>
        <div class="chart"><canvas id="chart-categories"></canvas></div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>Ventes mois par mois</h2>
            <span class="muted text-sm">nombre de vélos</span>
        </div>
        <div class="chart"><canvas id="chart-months"></canvas></div>
        <p class="legend muted text-sm">
            À quel moment de l'année les vélos partent. C'est ce qui permet de projeter la fin
            de saison : en juillet, on ne multiplie pas les ventes par deux, on regarde ce que
            les années passées ont fait au second semestre.
        </p>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-header">
            <h2>Où dort la valeur du stock</h2>
            <span class="muted text-sm">francs, au prix catalogue · aujourd'hui</span>
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
                            <th><?= hint('famille') ?></th>
                            <th class="num"><?= hint('en_stock', 'Stock') ?></th>
                            <th class="num"><?= hint('vendus', 'Vendus') ?></th>
                            <th class="num"><?= hint('mois_de_stock', 'Mois de stock') ?></th>
                            <th class="num"><?= hint('valeur_stock', 'Valeur') ?></th>
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
        <h2>Ventes et stock par taille</h2>
        <span class="muted text-sm">nombre de vélos</span>
    </div>

    <p class="legend muted text-sm">
        Une famille peut être saine en volume et malade en tailles. Les tailles alphabétiques
        (S/M/L) et numériques (51/54) coexistent selon les modèles : elles ne sont pas mélangées.
        La colonne <strong>écart</strong> compare le stock au rythme de vente — un stock positif
        sur une taille qui ne part pas, c'est de l'argent immobilisé.
    </p>

    <?php if (!$sizes): ?>
        <p class="empty">Aucune donnée de taille sur cette période.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Catégorie</th>
                        <th>Taille</th>
                        <th class="num"><?= hint('vendus', 'Vendus') ?></th>
                        <th class="num"><?= hint('en_stock', 'En stock') ?></th>
                        <th class="num"><?= hint('mois_de_stock', 'Mois de stock') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sizes as $row):
                        $sold  = (int)$row['sold'];
                        $stock = (int)$row['in_stock'];

                        $months = $sold > 0
                            ? round($stock / ($sold / $period['months']), 1)
                            : null;
                    ?>
                        <tr>
                            <td class="muted text-sm"><?= e($row['category']) ?></td>
                            <td><strong><?= e($row['size']) ?></strong></td>
                            <td class="num"><?= $sold ?></td>
                            <td class="num"><?= $stock ?></td>
                            <td class="num">
                                <?php if ($stock === 0): ?>
                                    <span class="muted">—</span>
                                <?php elseif ($months === null): ?>
                                    <span class="tag tag-danger">ne se vend pas</span>
                                <?php elseif ($months > SEUIL_DORMANT): ?>
                                    <span class="tag tag-warn"><?= e(number_format($months, 1)) ?></span>
                                <?php else: ?>
                                    <?= e(number_format($months, 1)) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header">
        <h2>Rotation par famille</h2>
        <span class="muted text-sm">
            ventes du <?= e(fmtDate($period['from'], 'd.m.Y')) ?> au <?= e(fmtDate($period['to'], 'd.m.Y')) ?>,
            soit <?= e(number_format($period['months'], 1)) ?> mois
        </span>
    </div>

    <p class="legend muted text-sm">
        <strong>Mois de stock</strong> : combien de temps le stock actuel tiendrait au rythme de vente
        de la période. Au-delà de <?= (int)SEUIL_DORMANT ?> mois la famille <strong>dort</strong> (ne pas
        recommander, écouler d'abord) ; en dessous de <?= (int)SEUIL_TENSION ?> mois elle est
        <strong>bientôt épuisée</strong> (risque de rupture avant la fin de saison).
    </p>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Catégorie</th>
                    <th><?= hint('famille') ?></th>
                    <th class="num"><?= hint('vendus', 'Vendus') ?></th>
                    <th class="num"><?= hint('en_stock', 'En stock') ?></th>
                    <th class="num"><?= hint('millesimes_anciens', 'Millésimes anciens') ?></th>
                    <th class="num"><?= hint('valeur_stock', 'Valeur stock') ?></th>
                    <th class="num"><?= hint('mois_de_stock', 'Mois de stock') ?></th>
                    <th><?= hint('verdict', 'Verdict') ?></th>
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
                        $verdict = ['tag-danger', 'épuisé'];
                    } elseif ($coverage === null) {
                        $verdict = ['tag-danger', 'ne se vend pas'];
                    } elseif ($coverage > SEUIL_DORMANT) {
                        $verdict = ['tag-warn', 'dort'];
                    } elseif ($coverage < SEUIL_TENSION) {
                        $verdict = ['tag-warn', 'bientôt épuisé'];
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
