<?php
/**
 * Inventaire de contrôle — pointer le rayon contre la base.
 *
 * Le pointage se fait dans l'atelier, au téléphone, un vélo à la fois : chaque
 * clic part en arrière-plan et la page ne se recharge jamais. Perdre sa place au
 * 60e vélo sur 90 serait le meilleur moyen de ne jamais finir un inventaire.
 *
 * Un vélo introuvable n'est pas un incident : c'est presque toujours une vente
 * qu'on a oublié de saisir. L'écart est le résultat de l'exercice.
 */

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/catalog.php';
require_once __DIR__ . '/includes/layout.php';

checkAuth();
checkRole(['owner', 'admin']);

$inventory = currentInventory();

// --- Pointage en arrière-plan (fetch) -------------------------------------
if (($_GET['ajax'] ?? '') === 'mark' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    requireCsrf();

    $itemId = (int)($_POST['item_id'] ?? 0);
    $raw    = (string)($_POST['seen'] ?? '');
    $seen   = $raw === '' ? null : (int)$raw;

    if ($inventory === null || $itemId <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false]);
        exit;
    }

    markInventoryItem($itemId, $seen);

    echo json_encode([
        'ok'    => true,
        'stats' => inventoryStats((int)$inventory['id']),
    ], JSON_THROW_ON_ERROR);
    exit;
}

$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['ajax'])) {
    requireCsrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'open') {
        $id = openInventory(trim((string)($_POST['label'] ?? '')) ?: null, currentUser());

        $notice = $id === null
            ? 'Un inventaire est déjà en cours.'
            : 'Inventaire ouvert. La liste des vélos attendus est figée.';

        $inventory = currentInventory();

    } elseif ($action === 'close' && $inventory !== null) {
        closeInventory((int)$inventory['id']);
        $notice    = 'Inventaire clôturé. Il reste consultable comme procès-verbal.';
        $inventory = null;
    }
}

$items = $inventory !== null ? inventoryItems((int)$inventory['id']) : [];
$stats = $inventory !== null ? inventoryStats((int)$inventory['id']) : null;
$past  = closedInventories();

renderHeader('Inventaire', ['css' => ['admin', 'app'], 'icons' => true]);
?>

<?php if ($notice !== ''): ?>
    <div class="alert alert-success"><?= e($notice) ?></div>
<?php endif; ?>

<?php if ($inventory === null): ?>

    <div class="card">
        <div class="card-header"><h2>Démarrer un inventaire</h2></div>

        <p class="muted">
            L'application fige la liste des vélos qu'elle croit présents en magasin — en stock,
            réservés, et vélos de test. Tu passes ensuite dans le rayon et tu pointes ce que tu
            vois réellement.
        </p>

        <p class="legend muted text-sm">
            Un vélo <strong>introuvable</strong> n'est pas une catastrophe : c'est presque toujours
            une vente qu'on a oublié de saisir. C'est justement ce qu'on cherche.
            Un vélo <strong>présent mais absent de la liste</strong> se rattrape en l'ajoutant au
            stock — l'inventaire n'a pas besoin de le connaître.
        </p>

        <form method="post" class="form-inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="open">

            <div class="field">
                <label class="label" for="label">Intitulé <span class="muted text-sm">— facultatif</span></label>
                <input class="input" type="text" id="label" name="label" maxlength="120"
                       placeholder="Inventaire de juillet 2026">
            </div>

            <button type="submit" class="btn">
                <i class="fa-solid fa-clipboard-check"></i> Démarrer
            </button>
        </form>
    </div>

<?php else: ?>

    <div class="grid grid-4">
        <div class="kpi">
            <span class="kpi-label">Attendus</span>
            <span class="kpi-value"><?= (int)$stats['total'] ?></span>
            <span class="kpi-note">selon la base</span>
        </div>
        <div class="kpi">
            <span class="kpi-label">Pointés présents</span>
            <span class="kpi-value js-stat-seen"><?= (int)$stats['seen'] ?></span>
        </div>
        <div class="kpi kpi-alert">
            <span class="kpi-label">Introuvables</span>
            <span class="kpi-value js-stat-missing"><?= (int)$stats['missing'] ?></span>
            <span class="kpi-note">probablement vendus sans saisie</span>
        </div>
        <div class="kpi">
            <span class="kpi-label">Restants</span>
            <span class="kpi-value js-stat-pending"><?= (int)$stats['pending'] ?></span>
            <span class="kpi-note">à pointer</span>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>
                <?= e($inventory['label'] ?: 'Inventaire en cours') ?>
                <span class="muted text-sm">
                    ouvert le <?= e(fmtDate($inventory['started_at'], 'd.m.Y')) ?>
                    <?= $inventory['author'] ? 'par ' . e($inventory['author']) : '' ?>
                </span>
            </h2>

            <form method="post" class="inline-form"
                  onsubmit="return confirm('Clôturer l\'inventaire ? Il restera consultable, mais ne pourra plus être modifié.');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="close">
                <button type="submit" class="btn btn-ghost">
                    <i class="fa-solid fa-flag-checkered"></i> Clôturer
                </button>
            </form>
        </div>

        <input class="input js-filter" type="search"
               data-target=".js-filterable"
               placeholder="Cherche un vélo dans la liste : Topstone, 54, Scott…"
               autocomplete="off">

        <p class="muted text-sm">
            <span class="js-filter-count"><?= count($items) ?></span> vélo(s) affiché(s).
            Chaque clic est enregistré immédiatement : tu peux fermer la page et reprendre plus tard.
        </p>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table class="table js-filterable" data-csrf="<?= e(csrfToken()) ?>"
                   data-url="<?= e(url('inventaire.php?ajax=mark')) ?>">
                <thead>
                    <tr>
                        <th class="col-sm-hide">Catégorie</th>
                        <th>Vélo</th>
                        <th>Taille</th>
                        <th class="col-sm-hide">Statut</th>
                        <th class="num col-sm-hide">Prix</th>
                        <th>Pointage</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item):
                        $state = $item['seen'] === null ? '' : ((int)$item['seen'] === 1 ? 'seen' : 'missing');
                    ?>
                        <tr class="js-item <?= $state !== '' ? 'is-' . $state : '' ?>"
                            data-item="<?= (int)$item['item_id'] ?>">
                            <td class="muted text-sm col-sm-hide"><?= e($item['category']) ?></td>
                            <td>
                                <span class="cell-main"><?= e($item['brand'] . ' ' . $item['model_name']) ?></span>
                                <span class="cell-sub">
                                    <?= e($item['color'] ?? '') ?>
                                    <?= $item['model_year'] ? '· MY' . e((string)$item['model_year']) : '' ?>
                                </span>
                            </td>
                            <td><strong><?= e($item['size'] ?? '—') ?></strong></td>
                            <td class="col-sm-hide">
                                <span class="tag tag-<?= e($item['status']) ?>"><?= e(STATUSES[$item['status']]) ?></span>
                            </td>
                            <td class="num col-sm-hide">
                                <?= e(chf($item['list_price'] !== null ? (float)$item['list_price'] : null, false)) ?>
                            </td>
                            <td>
                                <div class="point-actions">
                                    <button type="button" class="btn btn-ghost btn-point js-seen"
                                            title="Ce vélo est bien là">
                                        <i class="fa-solid fa-check"></i> Là
                                    </button>
                                    <button type="button" class="btn btn-ghost btn-point js-missing"
                                            title="Introuvable en rayon">
                                        <i class="fa-solid fa-question"></i> Absent
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Et après ?</h2></div>
        <p class="muted">
            À la clôture, les vélos <strong>introuvables</strong> sont ceux qu'il faut traiter :
            ouvre leur fiche et enregistre la vente qui a été oubliée (ou supprime-les si c'était
            un doublon de la reprise Excel). Les vélos <strong>trouvés en rayon mais absents de la
            liste</strong> s'ajoutent simplement via
            <a href="<?= e(url('velo.php')) ?>">Ajouter un vélo</a>.
        </p>
    </div>

<?php endif; ?>

<?php if ($past): ?>
    <div class="card">
        <div class="card-header"><h2>Inventaires précédents</h2></div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Inventaire</th>
                        <th>Clôturé le</th>
                        <th class="num">Attendus</th>
                        <th class="num">Présents</th>
                        <th class="num">Introuvables</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($past as $row): ?>
                        <tr>
                            <td>
                                <span class="cell-main"><?= e($row['label'] ?: 'Inventaire') ?></span>
                                <span class="cell-sub"><?= e((string)$row['author']) ?></span>
                            </td>
                            <td><?= e(fmtDate($row['closed_at'], 'd.m.Y')) ?></td>
                            <td class="num"><?= (int)$row['total'] ?></td>
                            <td class="num"><?= (int)$row['seen'] ?></td>
                            <td class="num">
                                <?php if ((int)$row['missing'] > 0): ?>
                                    <span class="tag tag-warn"><?= (int)$row['missing'] ?></span>
                                <?php else: ?>
                                    0
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php renderFooter(['scripts' => [
    url('assets/js/filtre.js')     . '?v=' . APP_VERSION,
    url('assets/js/inventaire.js') . '?v=' . APP_VERSION,
]]); ?>
