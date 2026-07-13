<?php
/**
 * Stock — la vue de travail quotidienne.
 *
 * Deux gestes seulement : chercher un vélo, le marquer vendu. Tout le reste
 * (création, correction) passe par velo.php.
 */

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/catalog.php';
require_once __DIR__ . '/includes/layout.php';

checkAuth();
checkRole(['owner', 'admin']);

$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = (string)($_POST['action'] ?? '');
    $bikeId = (int)($_POST['bike_id'] ?? 0);

    if ($action === 'sell' && $bikeId > 0) {
        $soldAt    = (string)($_POST['sold_at'] ?? date('Y-m-d'));
        $soldPrice = $_POST['sold_price'] !== '' ? (float)$_POST['sold_price'] : null;
        $customer  = customerId((string)($_POST['customer'] ?? ''));

        $stmt = db()->prepare(
            'UPDATE ' . tbl('bikes') . '
             SET status = "vendu", sold_at = ?, sold_price = ?, customer_id = ?
             WHERE id = ?'
        );
        $stmt->bind_param('sdii', $soldAt, $soldPrice, $customer, $bikeId);
        $stmt->execute();
        $stmt->close();

        $notice = 'Vélo marqué vendu.';
    }

    if ($action === 'delete' && $bikeId > 0) {
        $stmt = db()->prepare('DELETE FROM ' . tbl('bikes') . ' WHERE id = ?');
        $stmt->bind_param('i', $bikeId);
        $stmt->execute();
        $stmt->close();

        $notice = 'Vélo supprimé.';
    }
}

// --- Filtres -------------------------------------------------------------
$fCat    = (string)($_GET['cat'] ?? '');
$fBrand  = (string)($_GET['brand'] ?? '');
$fStatus = (string)($_GET['status'] ?? 'present');
$fYear   = (string)($_GET['year'] ?? '');
$fSearch = trim((string)($_GET['q'] ?? ''));

$where  = [];
$params = [];
$types  = '';

if ($fStatus === 'present') {
    $where[] = 'b.status IN ("stock","reserve","test")';
} elseif ($fStatus !== '' && isset(STATUSES[$fStatus])) {
    $where[]  = 'b.status = ?';
    $params[] = $fStatus;
    $types   .= 's';
}

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

if ($fYear !== '') {
    $where[]  = 'm.model_year = ?';
    $params[] = (int)$fYear;
    $types   .= 'i';
}

if ($fSearch !== '') {
    $where[]  = '(m.name LIKE ? OR br.name LIKE ? OR m.family LIKE ?)';
    $like     = '%' . $fSearch . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types   .= 'sss';
}

$sql = bikeSelect();
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY m.category, br.name, m.name, b.size LIMIT 500';

$stmt = db()->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$bikes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$brands    = db()->query('SELECT name FROM ' . tbl('brands') . ' ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$customers = db()->query('SELECT name FROM ' . tbl('customers') . ' ORDER BY name LIMIT 2000')->fetch_all(MYSQLI_ASSOC);
$kpis      = stockKpis();

$shownValue = 0.0;
foreach ($bikes as $bike) {
    if (in_array($bike['status'], STATUSES_PRESENT, true)) {
        $shownValue += (float)($bike['list_price'] ?? 0);
    }
}

renderHeader('Stock', ['css' => ['admin', 'app'], 'icons' => true]);
?>

<?php if ($notice !== ''): ?>
    <div class="alert alert-success"><?= e($notice) ?></div>
<?php endif; ?>

<div class="grid grid-4">
    <div class="kpi">
        <span class="kpi-label">Vélos en magasin</span>
        <span class="kpi-value"><?= (int)$kpis['units'] ?></span>
    </div>
    <div class="kpi">
        <span class="kpi-label">Valeur catalogue</span>
        <span class="kpi-value"><?= e(chf($kpis['value'], false)) ?></span>
    </div>
    <div class="kpi">
        <span class="kpi-label">Millésimes anciens</span>
        <span class="kpi-value"><?= (int)$kpis['old_units'] ?></span>
    </div>
    <div class="kpi">
        <span class="kpi-label">Vélos de test</span>
        <span class="kpi-value"><?= (int)$kpis['test_units'] ?></span>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>Filtres</h2>
        <a class="btn" href="<?= e(url('velo.php')) ?>">
            <i class="fa-solid fa-plus"></i> Ajouter un vélo
        </a>
    </div>

    <form class="filters" method="get">
        <div class="field">
            <label class="label" for="q">Recherche</label>
            <input class="input" type="search" id="q" name="q" value="<?= e($fSearch) ?>"
                   placeholder="Topstone, Scott…">
        </div>

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
            <label class="label" for="status">Statut</label>
            <select class="input" id="status" name="status">
                <option value="present"<?= $fStatus === 'present' ? ' selected' : '' ?>>En magasin</option>
                <?php foreach (STATUSES as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= $fStatus === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
                <option value="all"<?= $fStatus === 'all' ? ' selected' : '' ?>>Tous</option>
            </select>
        </div>

        <div class="field">
            <label class="label" for="year">Millésime</label>
            <input class="input" type="number" id="year" name="year" value="<?= e($fYear) ?>"
                   min="2015" max="2030" placeholder="2025">
        </div>

        <div class="field field-actions">
            <button class="btn" type="submit">Filtrer</button>
            <a class="btn btn-ghost" href="<?= e(url('stock.php')) ?>">Réinitialiser</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <h2><?= count($bikes) ?> vélo<?= count($bikes) > 1 ? 's' : '' ?></h2>
        <span class="muted text-sm">Valeur affichée : <?= e(chf($shownValue)) ?></span>
    </div>

    <?php if (!$bikes): ?>
        <p class="empty">Aucun vélo ne correspond. Ajuste les filtres, ou ajoute un vélo.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Catégorie</th>
                        <th>Vélo</th>
                        <th>Taille</th>
                        <th>MY</th>
                        <th class="num">Prix</th>
                        <th>Âge</th>
                        <th>Statut</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bikes as $bike):
                        $age   = stockAgeDays($bike['entered_at']);
                        $label = $bike['brand'] . ' ' . $bike['model_name'];
                        // Au-delà d'un an sur place, le vélo est un problème, pas un stock.
                        $stale = $age !== null && $age > 365 && $bike['status'] !== 'vendu';
                    ?>
                        <tr>
                            <td class="muted text-sm"><?= e($bike['category']) ?></td>
                            <td>
                                <strong><?= e($label) ?></strong>
                                <?php if ($bike['color']): ?>
                                    <span class="muted text-sm"><?= e($bike['color']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($bike['size'] ?? '—') ?></td>
                            <td><?= e((string)($bike['model_year'] ?? '—')) ?></td>
                            <td class="num"><?= e(chf($bike['list_price'] !== null ? (float)$bike['list_price'] : null, false)) ?></td>
                            <td>
                                <?php if ($age === null): ?>
                                    <span class="muted">—</span>
                                <?php else: ?>
                                    <span class="<?= $stale ? 'age-stale' : '' ?>"><?= (int)$age ?> j</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="tag tag-<?= e($bike['status']) ?>"><?= e(STATUSES[$bike['status']]) ?></span></td>
                            <td>
                                <div class="row-actions">
                                    <?php if ($bike['status'] !== 'vendu'): ?>
                                        <button type="button"
                                                class="btn-icon js-modal"
                                                data-modal="modal-sell"
                                                data-bike-id="<?= (int)$bike['id'] ?>"
                                                data-bike-label="<?= e($label . ' · ' . ($bike['size'] ?? '')) ?>"
                                                data-sold-price="<?= e((string)($bike['list_price'] ?? '')) ?>"
                                                title="Marquer vendu">
                                            <i class="fa-solid fa-tag"></i>
                                        </button>
                                    <?php endif; ?>

                                    <a class="btn-icon" href="<?= e(url('velo.php?id=' . (int)$bike['id'])) ?>" title="Modifier">
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

<div class="modal" id="modal-sell">
    <div class="modal-box">
        <h2 class="modal-title">Marquer vendu</h2>
        <p class="muted js-bike-label"></p>

        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="sell">
            <input type="hidden" name="bike_id" class="js-bike-id" value="">

            <div class="field">
                <label class="label" for="sold_at">Date de vente</label>
                <input class="input" type="date" id="sold_at" name="sold_at" value="<?= e(date('Y-m-d')) ?>" required>
            </div>

            <div class="field">
                <label class="label" for="customer">Client</label>
                <input class="input" type="text" id="customer" name="customer" list="customer-list"
                       placeholder="Nom, prénom" autocomplete="off">
                <datalist id="customer-list">
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?= e($customer['name']) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div class="field">
                <label class="label" for="sold_price">Prix de vente (CHF)</label>
                <input class="input js-sold-price" type="number" step="1" id="sold_price" name="sold_price"
                       placeholder="laisser vide = prix catalogue">
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-ghost js-close">Annuler</button>
                <button type="submit" class="btn">Confirmer la vente</button>
            </div>
        </form>
    </div>
</div>

<?php renderFooter(['scripts' => [url('assets/js/modal.js') . '?v=' . APP_VERSION]]); ?>
