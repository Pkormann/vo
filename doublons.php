<?php
/**
 * Doublons de la reprise Excel.
 *
 * Un vélo vendu dans le fichier des ventes, mais jamais retiré du fichier de
 * stock, existe deux fois en base : une ligne « en rayon », une ligne « vendue ».
 * Le marquer vendu créerait une deuxième vente — c'est la ligne de stock qu'il
 * faut supprimer.
 *
 * La détection est une suspicion, jamais une certitude : le magasin peut avoir un
 * second exemplaire identique. Rien n'est donc supprimé automatiquement.
 */

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/catalog.php';
require_once __DIR__ . '/includes/layout.php';

checkAuth();
checkRole(['owner', 'admin']);

$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $ids     = array_map('intval', (array)($_POST['ids'] ?? []));
    $deleted = 0;

    foreach ($ids as $id) {
        if (deleteBike($id)) {
            $deleted++;
        }
    }

    $notice = $deleted === 0
        ? 'Aucun vélo sélectionné.'
        : $deleted . ' vélo' . ($deleted > 1 ? 's' : '') . ' supprimé' . ($deleted > 1 ? 's' : '')
          . ' du stock. L\'historique des ventes n\'a pas été touché.';
}

$duplicates = suspectedDuplicates();

renderHeader('Doublons', ['css' => ['admin', 'app'], 'icons' => true]);
?>

<?php if ($notice !== ''): ?>
    <div class="alert alert-success"><?= e($notice) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h2>Ce que cette page cherche</h2></div>

    <p class="muted">
        Un vélo qui figure <strong>encore en rayon</strong> alors qu'un vélo identique — même modèle,
        même taille — apparaît <strong>déjà comme vendu</strong>. Le cas typique de la reprise des
        Excel : le vélo était vendu dans le fichier des ventes, mais n'avait jamais été retiré du
        fichier de stock.
    </p>

    <p class="legend muted text-sm">
        <strong>Ne les marque surtout pas « vendu »</strong> : la vente existe déjà dans l'historique,
        tu la compterais deux fois. C'est la ligne de <em>stock</em> qu'il faut supprimer — l'historique
        des ventes n'est jamais touché.
    </p>

    <p class="muted text-sm">
        Attention : c'est une <strong>suspicion</strong>, pas une certitude. Si tu as réellement deux
        exemplaires identiques en rayon, la ligne est légitime — vérifie avant de supprimer.
    </p>
</div>

<?php if (!$duplicates): ?>
    <div class="card">
        <p class="empty">Aucun doublon détecté. Le stock et les ventes sont cohérents.</p>
    </div>
<?php else: ?>
    <form method="post" onsubmit="return confirm('Supprimer les vélos sélectionnés du stock ? Les ventes correspondantes restent intactes.');">
        <?= csrfField() ?>

        <div class="card">
            <div class="card-header">
                <h2><?= count($duplicates) ?> doublon<?= count($duplicates) > 1 ? 's' : '' ?> probable<?= count($duplicates) > 1 ? 's' : '' ?></h2>
                <button type="submit" class="btn btn-danger">
                    <i class="fa-solid fa-trash"></i> Supprimer la sélection du stock
                </button>
            </div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" class="js-check-all" aria-label="Tout sélectionner"></th>
                            <th>Vélo en rayon</th>
                            <th>Taille</th>
                            <th><?= hint('millesime', 'MY') ?></th>
                            <th class="num">Prix</th>
                            <th>Vendu le</th>
                            <th class="num">
                                <span class="hint" title="Nombre de ventes déjà enregistrées pour ce modèle dans cette taille">Ventes</span>
                            </th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($duplicates as $bike): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="ids[]" value="<?= (int)$bike['id'] ?>"
                                           class="js-check" checked
                                           aria-label="Supprimer ce vélo du stock">
                                </td>
                                <td>
                                    <span class="cell-main"><?= e($bike['brand'] . ' ' . $bike['model_name']) ?></span>
                                    <span class="cell-sub">
                                        <?= e($bike['category']) ?>
                                        <?= $bike['status'] !== 'stock' ? ' · ' . e(STATUSES[$bike['status']]) : '' ?>
                                    </span>
                                </td>
                                <td><?= e($bike['size'] ?? '—') ?></td>
                                <td><?= e((string)($bike['model_year'] ?? '—')) ?></td>
                                <td class="num">
                                    <?= e(chf($bike['list_price'] !== null ? (float)$bike['list_price'] : null, false)) ?>
                                </td>
                                <td><?= e(fmtDate($bike['last_sold_at'], 'd.m.Y')) ?></td>
                                <td class="num"><?= (int)$bike['sold_count'] ?></td>
                                <td>
                                    <div class="row-actions">
                                        <a class="btn-icon" href="<?= e(url('velo.php?id=' . (int)$bike['id'])) ?>"
                                           title="Ouvrir la fiche pour vérifier">
                                            <i class="fa-solid fa-pen"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p class="muted text-sm">
                Tout est coché par défaut. Décoche les lignes que tu veux garder — par exemple un vrai
                second exemplaire encore en rayon.
            </p>
        </div>
    </form>
<?php endif; ?>

<?php renderFooter(['scripts' => [url('assets/js/doublons.js') . '?v=' . APP_VERSION]]); ?>
