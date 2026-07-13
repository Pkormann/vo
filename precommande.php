<?php
/**
 * Pré-commande — l'outil de décision.
 *
 * La proposition n'est pas un oracle : c'est une arithmétique explicite, que
 * Raoul corrige ligne à ligne. On garde côte à côte ce que l'outil suggérait et
 * ce qui a été retenu, pour pouvoir juger la méthode l'an prochain.
 */

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/catalog.php';
require_once __DIR__ . '/includes/layout.php';

checkAuth();
checkRole(['owner', 'admin']);

$season = (int)($_GET['season'] ?? (int)date('Y') + 1);
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $season    = (int)($_POST['season'] ?? $season);
    $lines     = $_POST['qty'] ?? [];
    $suggested = $_POST['suggested'] ?? [];
    $saved     = 0;

    $stmt = db()->prepare(
        'INSERT INTO ' . tbl('preorders') . ' (season, category, family, size, qty, suggested)
         VALUES (?, ?, ?, "", ?, ?)
         ON DUPLICATE KEY UPDATE qty = VALUES(qty), suggested = VALUES(suggested)'
    );

    foreach ((array)$lines as $key => $qty) {
        // La clé porte « Catégorie|Famille » : les deux forment l'identité de la ligne.
        [$category, $family] = array_pad(explode('|', (string)$key, 2), 2, '');

        if ($category === '' || $family === '' || !in_array($category, CATEGORIES, true)) {
            continue;
        }

        $qtyInt  = max(0, (int)$qty);
        $sugInt  = (int)($suggested[$key] ?? 0);

        $stmt->bind_param('issii', $season, $category, $family, $qtyInt, $sugInt);
        $stmt->execute();
        $saved++;
    }
    $stmt->close();

    $notice = $saved . ' ligne' . ($saved > 1 ? 's' : '') . ' enregistrée' . ($saved > 1 ? 's' : '') . '.';
}

$year        = (int)date('Y');
$progress    = seasonProgress($year - 1);
$suggestions = preorderSuggestions($year);

// Ce qui a déjà été décidé pour cette saison.
$stmt = db()->prepare(
    'SELECT category, family, qty, note FROM ' . tbl('preorders') . ' WHERE season = ? AND size = ""'
);
$stmt->bind_param('i', $season);
$stmt->execute();
$decided = [];
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $decided[$row['category'] . '|' . $row['family']] = $row;
}
$stmt->close();

$totalSuggested = 0;
$totalDecided   = 0;
foreach ($suggestions as $row) {
    $totalSuggested += (int)$row['suggested'];
    $key             = $row['category'] . '|' . $row['family'];
    $totalDecided   += (int)($decided[$key]['qty'] ?? 0);
}

renderHeader('Pré-commande ' . $season, ['css' => ['admin', 'app'], 'icons' => true]);
?>

<?php if ($notice !== ''): ?>
    <div class="alert alert-success"><?= e($notice) ?></div>
<?php endif; ?>

<div class="card method">
    <h2>Comment la proposition est calculée</h2>
    <p>
        La demande attendue pour <?= (int)$year ?> est déduite du rythme observé, corrigé de la
        saisonnalité réelle : à ce jour, <strong><?= (int)round($progress * 100) ?> %</strong> des ventes
        d'une année sont normalement faites (mesuré sur <?= (int)$year - 1 ?>).
    </p>
    <p class="formula">
        pré-commande = demande attendue − ce qui restera en rayon à l'ouverture de la saison
    </p>
    <p class="muted text-sm">
        C'est la soustraction qui manquait au fichier MY27 : la saison 2026 est partie avec
        90 vélos route disponibles pour une demande de 45 par an, parce que la pré-commande avait été
        calée sur la demande sans retrancher le stock reporté.
    </p>
</div>

<div class="grid grid-4">
    <div class="kpi">
        <span class="kpi-label">Saison</span>
        <span class="kpi-value"><?= (int)$season ?></span>
    </div>
    <div class="kpi">
        <span class="kpi-label">Proposition de l'outil</span>
        <span class="kpi-value"><?= (int)$totalSuggested ?></span>
        <span class="kpi-note">vélos, toutes familles</span>
    </div>
    <div class="kpi">
        <span class="kpi-label">Retenu</span>
        <span class="kpi-value"><?= (int)$totalDecided ?></span>
        <span class="kpi-note">après ton arbitrage</span>
    </div>
    <div class="kpi">
        <span class="kpi-label">Écart</span>
        <span class="kpi-value"><?= $totalDecided - $totalSuggested > 0 ? '+' : '' ?><?= (int)($totalDecided - $totalSuggested) ?></span>
    </div>
</div>

<form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="season" value="<?= (int)$season ?>">

    <div class="card">
        <div class="card-header">
            <h2>Proposition par famille</h2>
            <button type="submit" class="btn"><i class="fa-solid fa-floppy-disk"></i> Enregistrer</button>
        </div>

        <div class="table-wrap">
            <table class="table table-preorder">
                <thead>
                    <tr>
                        <th>Catégorie</th>
                        <th><?= hint('famille') ?></th>
                        <th class="num"><?= hint('vendus', 'Vendus ' . $year) ?></th>
                        <th class="num"><?= hint('demande_attendue', 'Demande attendue') ?></th>
                        <th class="num"><?= hint('en_stock', 'En stock') ?></th>
                        <th class="num"><?= hint('restera', 'Restera en rayon') ?></th>
                        <th class="num"><?= hint('proposition', 'Proposition') ?></th>
                        <th class="num"><?= hint('a_commander', 'À commander') ?></th>
                        <th>Répartition des tailles</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($suggestions as $row):
                        $key       = $row['category'] . '|' . $row['family'];
                        $suggested = (int)$row['suggested'];
                        $retained  = $decided[$key]['qty'] ?? $suggested;
                        $split     = splitBySize((int)$retained, $row['size_mix']);
                    ?>
                        <tr>
                            <td class="muted text-sm"><?= e($row['category']) ?></td>
                            <td><strong><?= e($row['family']) ?></strong></td>
                            <td class="num"><?= (int)$row['sold'] ?></td>
                            <td class="num"><?= (int)$row['expected'] ?></td>
                            <td class="num"><?= (int)$row['in_stock'] ?></td>
                            <td class="num"><?= (int)$row['residual'] ?></td>
                            <td class="num">
                                <span class="<?= $suggested === 0 ? 'tag tag-warn' : 'muted' ?>">
                                    <?= $suggested === 0 ? 'ne rien commander' : (int)$suggested ?>
                                </span>
                            </td>
                            <td class="num">
                                <input type="hidden" name="suggested[<?= e($key) ?>]" value="<?= $suggested ?>">
                                <input class="input input-qty" type="number" min="0" max="200"
                                       name="qty[<?= e($key) ?>]" value="<?= (int)$retained ?>">
                            </td>
                            <td class="sizes">
                                <?php if (!$split): ?>
                                    <span class="muted text-sm">—</span>
                                <?php else: ?>
                                    <?php foreach ($split as $size => $n): ?>
                                        <span class="size-chip"><?= e($size) ?><b><?= (int)$n ?></b></span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</form>

<?php renderFooter(); ?>
