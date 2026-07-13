<?php
/**
 * Ventes — l'historique interrogeable, sur n'importe quelle plage de dates.
 *
 * La comparaison affichée en tête porte toujours sur la même durée un an plus
 * tôt : c'est la seule façon honnête de comparer six mois de cette année à
 * quoi que ce soit.
 */

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/catalog.php';
require_once __DIR__ . '/includes/period.php';
require_once __DIR__ . '/includes/layout.php';

checkAuth();
checkRole(['owner', 'admin']);

$notice = '';

// Enregistrer une vente depuis cette page : c'est le geste le plus fréquent du
// magasin, il ne doit pas obliger à passer par l'écran du stock.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    if (($_POST['action'] ?? '') === 'sell') {
        $sold = sellBike(
            (int)($_POST['bike_id'] ?? 0),
            (string)($_POST['sold_at'] ?? ''),
            ($_POST['sold_price'] ?? '') !== '' ? (float)$_POST['sold_price'] : null,
            (string)($_POST['customer'] ?? '')
        );

        $notice = $sold
            ? 'Vente enregistrée.'
            : 'Vélo introuvable ou déjà vendu.';
    }
}

$period  = currentPeriod();
$fCat    = (string)($_GET['cat'] ?? '');
$fBrand  = (string)($_GET['brand'] ?? '');
$fSearch = trim((string)($_GET['q'] ?? ''));

$where  = ['b.status = "vendu"', 'b.sold_at BETWEEN ? AND ?'];
$params = [$period['from'], $period['to']];
$types  = 'ss';

if ($fCat !== '' && in_array($fCat, CATEGORIES, true)) {
    $where[]  = 'm.category = ?';
    $params[] = $fCat;
    $types   .= 's';
}

if ($fBrand !== '') {
    $where[]  = 'br.name = ?';
    $params[] = $fBrand;
    $types   .= 's';
}

if ($fSearch !== '') {
    $where[]  = '(m.name LIKE ? OR br.name LIKE ? OR c.name LIKE ?)';
    $like     = '%' . $fSearch . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types   .= 'sss';
}

$sql  = bikeSelect() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY b.sold_at DESC LIMIT 1000';
$stmt = db()->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$sales = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Les totaux de tête portent sur la période entière, sans les filtres de la
// liste : ils répondent à « comment va le magasin », pas « qu'y a-t-il à l'écran ».
$totals     = salesTotals($period['from'], $period['to']);
$prevTotals = salesTotals($period['prev_from'], $period['prev_to']);

$delta = $prevTotals['n'] > 0
    ? round(100 * ($totals['n'] - $prevTotals['n']) / $prevTotals['n'])
    : null;

$revenue = 0.0;
foreach ($sales as $sale) {
    $revenue += (float)($sale['sold_price'] ?? $sale['list_price'] ?? 0);
}

$brands    = db()->query('SELECT name FROM ' . tbl('brands') . ' ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$customers = db()->query('SELECT name FROM ' . tbl('customers') . ' ORDER BY name LIMIT 2000')->fetch_all(MYSQLI_ASSOC);
$inStock   = sellableBikes();

renderHeader('Ventes', ['css' => ['admin', 'app'], 'icons' => true]);
?>

<?php if ($notice !== ''): ?>
    <div class="alert alert-success"><?= e($notice) ?></div>
<?php endif; ?>

<div class="card sale-bar">
    <div>
        <strong>Un vélo vient de partir ?</strong>
        <p class="muted text-sm">
            Enregistre la vente sans passer par le stock. La date du jour est déjà remplie.
        </p>
    </div>

    <button type="button" class="btn js-modal" data-modal="modal-sale"
            <?= $inStock ? '' : 'disabled' ?>>
        <i class="fa-solid fa-tag"></i> Enregistrer une vente
    </button>
</div>

<div class="grid grid-4">
    <div class="kpi">
        <span class="kpi-label">Vendus sur la période</span>
        <span class="kpi-value"><?= (int)$totals['n'] ?></span>
        <span class="kpi-note"><?= e($period['label']) ?></span>
    </div>
    <div class="kpi">
        <span class="kpi-label">Chiffre d'affaires</span>
        <span class="kpi-value"><?= e(chf($totals['ca'], false)) ?></span>
    </div>
    <div class="kpi">
        <span class="kpi-label">Panier moyen</span>
        <span class="kpi-value"><?= e(chf($totals['n'] > 0 ? $totals['ca'] / $totals['n'] : null, false)) ?></span>
    </div>
    <div class="kpi">
        <span class="kpi-label">Vs un an plus tôt</span>
        <span class="kpi-value <?= $delta !== null && $delta < 0 ? 'is-down' : 'is-up' ?>">
            <?= $delta === null ? '—' : ($delta > 0 ? '+' : '') . (int)$delta . '&nbsp;%' ?>
        </span>
        <span class="kpi-note"><?= (int)$prevTotals['n'] ?> vélos alors</span>
    </div>
</div>

<div class="card">
    <?php renderPeriodFilter($period, url('ventes.php'), [
        'cat'   => $fCat,
        'brand' => $fBrand,
        'q'     => $fSearch,
    ]); ?>

    <form class="filters" method="get">
        <input type="hidden" name="range" value="<?= e($period['range']) ?>">
        <input type="hidden" name="from"  value="<?= e($period['from']) ?>">
        <input type="hidden" name="to"    value="<?= e($period['to']) ?>">

        <div class="field">
            <label class="label" for="cat">Catégorie</label>
            <select class="input" id="cat" name="cat">
                <option value="">Toutes</option>
                <?php foreach (CATEGORIES as $category): ?>
                    <option value="<?= e($category) ?>"<?= $fCat === $category ? ' selected' : '' ?>>
                        <?= e($category) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label class="label" for="brand">Marque</label>
            <select class="input" id="brand" name="brand">
                <option value="">Toutes</option>
                <?php foreach ($brands as $brand): ?>
                    <option value="<?= e($brand['name']) ?>"<?= $fBrand === $brand['name'] ? ' selected' : '' ?>>
                        <?= e($brand['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label class="label" for="q">Recherche</label>
            <input class="input" type="search" id="q" name="q" value="<?= e($fSearch) ?>"
                   placeholder="modèle ou client">
        </div>

        <div class="field field-actions">
            <button class="btn" type="submit">Filtrer</button>
            <a class="btn btn-ghost" href="<?= e(url('ventes.php')) ?>">Réinitialiser</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <h2><?= count($sales) ?> vente<?= count($sales) > 1 ? 's' : '' ?> affichée<?= count($sales) > 1 ? 's' : '' ?></h2>
        <span class="muted text-sm"><?= e(chf($revenue)) ?></span>
    </div>

    <?php if (!$sales): ?>
        <p class="empty">Aucune vente pour ces critères.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Catégorie</th>
                        <th>Vélo</th>
                        <th>Taille</th>
                        <th class="num">Prix</th>
                        <th>Client</th>
                        <th>Délai</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sales as $sale):
                        // Délai d'écoulement : temps passé en rayon avant la vente.
                        $days = null;
                        if ($sale['entered_at'] && $sale['sold_at']) {
                            $days = (int)floor((strtotime($sale['sold_at']) - strtotime($sale['entered_at'])) / 86400);
                        }
                    ?>
                        <tr>
                            <td><?= e(fmtDate($sale['sold_at'], 'd.m.Y')) ?></td>
                            <td class="muted text-sm"><?= e($sale['category']) ?></td>
                            <td><strong><?= e($sale['brand'] . ' ' . $sale['model_name']) ?></strong></td>
                            <td><?= e($sale['size'] ?? '—') ?></td>
                            <td class="num">
                                <?= e(chf($sale['sold_price'] !== null
                                        ? (float)$sale['sold_price']
                                        : ($sale['list_price'] !== null ? (float)$sale['list_price'] : null), false)) ?>
                            </td>
                            <td><?= e($sale['customer'] ?? '—') ?></td>
                            <td><?= $days === null ? '<span class="muted">—</span>' : (int)$days . ' j' ?></td>
                            <td>
                                <div class="row-actions">
                                    <a class="btn-icon" href="<?= e(url('velo.php?id=' . (int)$sale['id'])) ?>" title="Modifier">
                                        <i class="fa-solid fa-pen"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="modal" id="modal-sale">
    <div class="modal-box">
        <h2 class="modal-title">Enregistrer une vente</h2>

        <?php if (!$inStock): ?>
            <p class="empty">Aucun vélo en rayon. Ajoute-le d'abord au stock.</p>
        <?php else: ?>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="sell">

                <div class="field">
                    <label class="label" for="bike_id">Vélo vendu</label>
                    <select class="input js-bike-select" id="bike_id" name="bike_id" required>
                        <option value="">— choisir —</option>
                        <?php
                        $currentCategory = null;
                        foreach ($inStock as $bike):
                            if ($bike['category'] !== $currentCategory):
                                if ($currentCategory !== null): ?>
                                    </optgroup>
                                <?php endif;
                                $currentCategory = $bike['category']; ?>
                                <optgroup label="<?= e($currentCategory) ?>">
                            <?php endif; ?>

                            <option value="<?= (int)$bike['id'] ?>"
                                    data-price="<?= e((string)($bike['list_price'] ?? '')) ?>">
                                <?= e($bike['brand'] . ' ' . $bike['model_name']) ?>
                                <?= $bike['size'] ? '· ' . e($bike['size']) : '' ?>
                                <?= $bike['model_year'] ? '· MY' . e((string)$bike['model_year']) : '' ?>
                                <?= $bike['status'] !== 'stock' ? '(' . e(STATUSES[$bike['status']]) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>

                <div class="field">
                    <label class="label" for="sale_date">Date de vente</label>
                    <input class="input" type="date" id="sale_date" name="sold_at"
                           value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required>
                </div>

                <div class="field">
                    <label class="label" for="sale_customer">Client</label>
                    <input class="input" type="text" id="sale_customer" name="customer"
                           list="customer-list" placeholder="Nom, prénom" autocomplete="off">
                    <datalist id="customer-list">
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?= e($customer['name']) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="field">
                    <label class="label" for="sale_price">
                        Prix de vente (CHF)
                        <span class="muted text-sm">— vide : prix catalogue</span>
                    </label>
                    <input class="input js-sale-price" type="number" step="1" id="sale_price"
                           name="sold_price" placeholder="prix catalogue">
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-ghost js-close">Annuler</button>
                    <button type="submit" class="btn">Enregistrer la vente</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php renderFooter(['scripts' => [
    url('assets/js/period.js') . '?v=' . APP_VERSION,
    url('assets/js/modal.js')  . '?v=' . APP_VERSION,
    url('assets/js/vente.js')  . '?v=' . APP_VERSION,
]]); ?>
