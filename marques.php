<?php
/**
 * Marques et achats — le seul endroit où se saisit un taux de rabais.
 *
 * Un taux par marque suffit à transformer un stock exprimé en prix catalogue en
 * un stock exprimé en argent réellement engagé. C'est le minimum de saisie pour
 * le maximum d'information : douze nombres, une fois par an.
 */

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/catalog.php';
require_once __DIR__ . '/includes/activity.php';
require_once __DIR__ . '/includes/layout.php';

checkAuth();
checkRole(['owner', 'admin']);

$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $rates = (array)($_POST['discount'] ?? []);
    $stmt  = db()->prepare('UPDATE ' . tbl('brands') . ' SET discount_rate = ? WHERE id = ?');

    foreach ($rates as $id => $rate) {
        $id   = (int)$id;
        $rate = trim((string)$rate);
        $value = $rate === '' ? null : max(0.0, min(90.0, (float)$rate));

        $stmt->bind_param('di', $value, $id);
        $stmt->execute();
    }
    $stmt->close();

    logAction('rabais', 'marques', null, count($rates) . ' marque(s)');

    $notice = 'Taux de rabais enregistrés.';
}

$brands = db()->query(
    'SELECT br.id, br.name, br.discount_rate,
            SUM(b.status IN ("stock","reserve"))                                                  AS in_stock,
            SUM(CASE WHEN b.status IN ("stock","reserve")
                     THEN COALESCE(b.list_price, m.list_price, 0) ELSE 0 END)                     AS stock_value,
            SUM(b.status = "vendu" AND YEAR(b.sold_at) = YEAR(CURDATE()))                         AS sold_this_year
     FROM ' . tbl('brands') . ' br
     LEFT JOIN ' . tbl('models') . ' m ON m.brand_id = br.id
     LEFT JOIN ' . tbl('bikes') . '  b ON b.model_id = m.id
     GROUP BY br.id, br.name, br.discount_rate
     ORDER BY stock_value DESC, br.name'
)->fetch_all(MYSQLI_ASSOC);

$catalogueValue = 0.0;
$engagedValue   = 0.0;
$unknownRate    = 0;

foreach ($brands as $brand) {
    $value            = (float)$brand['stock_value'];
    $catalogueValue  += $value;
    $rate             = $brand['discount_rate'] !== null ? (float)$brand['discount_rate'] : null;

    if ($rate === null) {
        $unknownRate += (int)$brand['in_stock'];
        continue;
    }

    $engagedValue += $value * (1 - $rate / 100);
}

renderHeader('Marques et achats', ['css' => ['admin', 'app'], 'icons' => true]);
?>

<?php if ($notice !== ''): ?>
    <div class="alert alert-success"><?= e($notice) ?></div>
<?php endif; ?>

<div class="grid grid-4">
    <div class="kpi">
        <span class="kpi-label"><?= hint('valeur_rayon', 'Valeur en rayon') ?></span>
        <span class="kpi-value"><?= e(chf($catalogueValue, false)) ?></span>
        <span class="kpi-note">au prix catalogue</span>
    </div>
    <div class="kpi">
        <span class="kpi-label"><?= hint('a_coute', 'Ce que le stock a coûté') ?></span>
        <span class="kpi-value"><?= e(chf($engagedValue, false)) ?></span>
        <span class="kpi-note">prix d'achat estimé, rabais déduit</span>
    </div>
    <div class="kpi">
        <span class="kpi-label"><?= hint('marge_potentielle', 'Marge brute potentielle') ?></span>
        <span class="kpi-value"><?= e(chf($catalogueValue - $engagedValue, false)) ?></span>
        <span class="kpi-note">si tout part au prix affiché</span>
    </div>
    <div class="kpi <?= $unknownRate > 0 ? 'kpi-alert' : '' ?>">
        <span class="kpi-label">Vélos sans taux connu</span>
        <span class="kpi-value"><?= (int)$unknownRate ?></span>
        <span class="kpi-note">exclus de l'estimation</span>
    </div>
</div>

<form method="post">
    <?= csrfField() ?>

    <div class="card">
        <div class="card-header">
            <h2>Rabais fournisseur par marque</h2>
            <button type="submit" class="btn"><i class="fa-solid fa-floppy-disk"></i> Enregistrer</button>
        </div>

        <p class="muted">
            Le pourcentage retranché au prix catalogue pour obtenir le prix d'achat. Laisser vide
            si le taux n'est pas connu : la marque sera simplement exclue des estimations, plutôt
            que de fausser le total.
        </p>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Marque</th>
                        <th class="num">En stock</th>
                        <th class="num">Vendus cette année</th>
                        <th class="num">Valeur en rayon</th>
                        <th class="num"><?= hint('rabais', 'Rabais (%)') ?></th>
                        <th class="num"><?= hint('a_coute', 'A coûté') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($brands as $brand):
                        $rate    = $brand['discount_rate'] !== null ? (float)$brand['discount_rate'] : null;
                        $value   = (float)$brand['stock_value'];
                        $engaged = $rate !== null ? $value * (1 - $rate / 100) : null;
                    ?>
                        <tr>
                            <td><strong><?= e($brand['name']) ?></strong></td>
                            <td class="num"><?= (int)$brand['in_stock'] ?></td>
                            <td class="num"><?= (int)$brand['sold_this_year'] ?></td>
                            <td class="num"><?= e(chf($value, false)) ?></td>
                            <td class="num">
                                <input class="input input-qty" type="number" step="0.5" min="0" max="90"
                                       name="discount[<?= (int)$brand['id'] ?>]"
                                       value="<?= $rate !== null ? e(rtrim(rtrim(number_format($rate, 1, '.', ''), '0'), '.')) : '' ?>"
                                       placeholder="—">
                            </td>
                            <td class="num"><?= e(chf($engaged, false)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</form>

<?php renderFooter(); ?>
