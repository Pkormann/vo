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

    if ($action === 'sell') {
        $sold = sellBike(
            $bikeId,
            (string)($_POST['sold_at'] ?? ''),
            ($_POST['sold_price'] ?? '') !== '' ? (float)$_POST['sold_price'] : null,
            (string)($_POST['customer'] ?? '')
        );

        $notice = $sold ? 'Vélo marqué vendu.' : 'Ce vélo était déjà vendu.';
    }

    if ($action === 'reserve') {
        $reserved = reserveBike(
            $bikeId,
            (string)($_POST['delivery_at'] ?? ''),
            ($_POST['sold_price'] ?? '') !== '' ? (float)$_POST['sold_price'] : null,
            (string)($_POST['customer'] ?? '')
        );

        $notice = $reserved
            ? 'Vélo réservé. Il compte comme vendu et sort du stock disponible.'
            : 'Ce vélo n\'est plus disponible.';
    }

    if ($action === 'delete') {
        $notice = deleteBike($bikeId)
            ? 'Vélo supprimé du stock. L\'historique des ventes n\'a pas été touché.'
            : 'Vélo introuvable.';
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
$kpis       = stockKpis();
$duplicates = count(suspectedDuplicates());

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

<?php if ($kpis['reserved_undated'] > 0): ?>
    <div class="alert alert-warn">
        <strong><?= (int)$kpis['reserved_undated'] ?> vélo<?= $kpis['reserved_undated'] > 1 ? 's' : '' ?>
        réservé<?= $kpis['reserved_undated'] > 1 ? 's' : '' ?> sans date de vente</strong> —
        vient de la reprise Excel. Un réservé compte comme une vente : sans date, il n'apparaît ni
        dans le stock ni dans les ventes. Ouvre la fiche et renseigne « Vendu le »
        (<a href="<?= e(url('stock.php?status=reserve')) ?>">les voir</a>).
    </div>
<?php endif; ?>

<?php if ($duplicates > 0): ?>
    <div class="alert alert-warn">
        <strong><?= (int)$duplicates ?> vélo<?= $duplicates > 1 ? 's' : '' ?> en rayon
        <?= $duplicates > 1 ? 'sont' : 'est' ?> peut-être déjà vendu<?= $duplicates > 1 ? 's' : '' ?></strong> :
        un vélo identique figure dans l'historique des ventes. Ne les marque pas « vendu », tu compterais
        la vente deux fois — <a href="<?= e(url('doublons.php')) ?>">vérifie-les ici</a>.
    </div>
<?php endif; ?>

<div class="grid grid-4">
    <div class="kpi">
        <span class="kpi-label">Disponibles à la vente</span>
        <span class="kpi-value"><?= (int)$kpis['units'] ?></span>
        <?php if ($kpis['reserved'] > 0): ?>
            <span class="kpi-note"><?= (int)$kpis['reserved'] ?> réservé<?= $kpis['reserved'] > 1 ? 's' : '' ?> en plus, déjà vendu<?= $kpis['reserved'] > 1 ? 's' : '' ?></span>
        <?php endif; ?>
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

    <form class="filters js-autosubmit" method="get">
        <div class="field">
            <label class="label" for="q">Recherche</label>
            <input class="input js-filter" type="search" id="q" value="<?= e($fSearch) ?>"
                   data-target=".js-filterable" placeholder="Filtre à la frappe : Topstone, Scott…"
                   autocomplete="off">
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
            <a class="btn btn-ghost" href="<?= e(url('stock.php')) ?>">Réinitialiser</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <h2><span class="js-filter-count"><?= count($bikes) ?></span> vélo<?= count($bikes) > 1 ? 's' : '' ?></h2>
        <span class="muted text-sm">Valeur affichée : <?= e(chf($shownValue)) ?></span>
    </div>

    <?php if (!$bikes): ?>
        <p class="empty">Aucun vélo ne correspond. Ajuste les filtres, ou ajoute un vélo.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table js-filterable">
                <thead>
                    <tr>
                        <th class="col-sm-hide">Catégorie</th>
                        <th>Vélo</th>
                        <th>Taille</th>
                        <th class="col-sm-hide"><?= hint('millesime', 'MY') ?></th>
                        <th class="num">Prix</th>
                        <th class="col-sm-hide"><?= hint('age', 'Âge') ?></th>
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
                            <td class="muted text-sm col-sm-hide"><?= e($bike['category']) ?></td>
                            <td>
                                <span class="cell-main"><?= e($label) ?></span>
                                <?php if ($bike['color']): ?>
                                    <span class="cell-sub"><?= e($bike['color']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($bike['size'] ?? '—') ?></td>
                            <td class="col-sm-hide"><?= e((string)($bike['model_year'] ?? '—')) ?></td>
                            <td class="num"><?= e(chf($bike['list_price'] !== null ? (float)$bike['list_price'] : null, false)) ?></td>
                            <td class="col-sm-hide">
                                <?php if ($age === null): ?>
                                    <span class="muted">—</span>
                                <?php else: ?>
                                    <span class="<?= $stale ? 'age-stale' : '' ?>"><?= (int)$age ?> j</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="tag tag-<?= e($bike['status']) ?>"><?= e(STATUSES[$bike['status']]) ?></span>
                                <?php if ($bike['status'] === 'reserve'): ?>
                                    <span class="cell-sub">
                                        <?php if ($bike['delivery_at']): ?>
                                            remise le <?= e(fmtDate($bike['delivery_at'], 'd.m.Y')) ?>
                                        <?php else: ?>
                                            date de remise inconnue
                                        <?php endif; ?>
                                        <?= $bike['customer'] ? ' · ' . e($bike['customer']) : '' ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="row-actions">
                                    <?php if (in_array($bike['status'], ['stock', 'test'], true)): ?>
                                        <button type="button"
                                                class="btn btn-sell js-modal"
                                                data-modal="modal-sell"
                                                data-bike-id="<?= (int)$bike['id'] ?>"
                                                data-bike-label="<?= e($label . ' · ' . ($bike['size'] ?? '')) ?>"
                                                data-sold-price="<?= e((string)($bike['list_price'] ?? '')) ?>"
                                                title="Marquer ce vélo vendu, daté d'aujourd'hui">
                                            <i class="fa-solid fa-tag"></i> Vendu
                                        </button>
                                        <button type="button"
                                                class="btn btn-ghost btn-sell js-modal"
                                                data-modal="modal-reserve"
                                                data-bike-id="<?= (int)$bike['id'] ?>"
                                                data-bike-label="<?= e($label . ' · ' . ($bike['size'] ?? '')) ?>"
                                                data-sold-price="<?= e((string)($bike['list_price'] ?? '')) ?>"
                                                title="Vendu, mais remis au client plus tard">
                                            <i class="fa-solid fa-bookmark"></i> Réserver
                                        </button>
                                    <?php endif; ?>

                                    <a class="btn-icon" href="<?= e(url('velo.php?id=' . (int)$bike['id'])) ?>" title="Modifier">
                                        <i class="fa-solid fa-pen"></i>
                                    </a>

                                    <button type="submit" form="form-delete" name="bike_id"
                                            value="<?= (int)$bike['id'] ?>" class="btn-icon"
                                            title="Retirer du stock sans enregistrer de vente (doublon, erreur de saisie)"
                                            onclick="return confirm('Retirer ce vélo du stock ? Aucune vente ne sera enregistrée. À utiliser pour un doublon ou une erreur de saisie.');">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="modal" id="modal-reserve">
    <div class="modal-box">
        <h2 class="modal-title">Réserver</h2>
        <p class="muted js-bike-label"></p>

        <p class="legend muted text-sm">
            Le vélo est <strong>vendu</strong> : il sort du stock disponible et compte dans les
            ventes dès aujourd'hui. La date de remise n'est qu'une information logistique.
        </p>

        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reserve">
            <input type="hidden" name="bike_id" class="js-bike-id" value="">

            <div class="field">
                <label class="label" for="delivery_at">Remise prévue au client</label>
                <input class="input" type="date" id="delivery_at" name="delivery_at"
                       min="<?= e(date('Y-m-d')) ?>">
            </div>

            <div class="field">
                <label class="label" for="reserve_customer">
                    Client <span class="muted text-sm">— facultatif</span>
                </label>
                <input class="input" type="text" id="reserve_customer" name="customer"
                       list="customer-list" placeholder="Nom, prénom" autocomplete="off">
            </div>

            <div class="field">
                <label class="label" for="reserve_price">
                    Prix convenu (CHF) <span class="muted text-sm">— vide : prix catalogue</span>
                </label>
                <input class="input js-sold-price" type="number" step="1" id="reserve_price"
                       name="sold_price" placeholder="prix catalogue">
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-ghost js-close">Annuler</button>
                <button type="submit" class="btn">Confirmer la réservation</button>
            </div>
        </form>
    </div>
</div>

<form method="post" id="form-delete">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="delete">
</form>

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

<?php renderFooter(['scripts' => [
    url('assets/js/modal.js')  . '?v=' . APP_VERSION,
    url('assets/js/filtre.js') . '?v=' . APP_VERSION,
]]); ?>
