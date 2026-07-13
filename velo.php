<?php
/**
 * Fiche vélo : création à la réception, correction ensuite.
 *
 * La saisie est volontairement plate — marque, modèle, taille, prix — et les
 * référentiels (marque, modèle, client) se créent au vol : on ne demande jamais
 * à l'utilisateur d'aller peupler un catalogue avant de pouvoir travailler.
 */

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/catalog.php';
require_once __DIR__ . '/includes/layout.php';

checkAuth();
checkRole(['owner', 'admin']);

$id     = (int)($_GET['id'] ?? 0);
$errors = [];
$bike   = null;

if ($id > 0) {
    $stmt = db()->prepare(bikeSelect() . ' WHERE b.id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $bike = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$bike) {
        header('Location: ' . url('stock.php'));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    if (($_POST['action'] ?? '') === 'delete' && $id > 0) {
        deleteBike($id);

        header('Location: ' . url('stock.php'));
        exit;
    }

    $brand    = trim((string)($_POST['brand'] ?? ''));
    $model    = trim((string)($_POST['model'] ?? ''));
    $category = (string)($_POST['category'] ?? '');
    $year     = $_POST['model_year'] !== '' ? (int)$_POST['model_year'] : null;
    $size     = trim((string)($_POST['size'] ?? ''));
    $color    = trim((string)($_POST['color'] ?? ''));
    $status   = (string)($_POST['status'] ?? 'stock');
    $listP    = $_POST['list_price']     !== '' ? (float)$_POST['list_price']     : null;
    $purchP   = $_POST['purchase_price'] !== '' ? (float)$_POST['purchase_price'] : null;
    $soldP    = $_POST['sold_price']     !== '' ? (float)$_POST['sold_price']     : null;
    $entered  = (string)($_POST['entered_at'] ?? '') ?: null;
    $sold     = (string)($_POST['sold_at'] ?? '') ?: null;
    $notes    = trim((string)($_POST['notes'] ?? ''));
    $customer = customerId((string)($_POST['customer'] ?? ''));

    if ($brand === '')                              { $errors[] = 'La marque est obligatoire.'; }
    if ($model === '')                              { $errors[] = 'Le modèle est obligatoire.'; }
    if (!in_array($category, CATEGORIES, true))     { $errors[] = 'Catégorie inconnue.'; }
    if (!isset(STATUSES[$status]))                  { $errors[] = 'Statut inconnu.'; }
    if ($status === 'vendu' && $sold === null)      { $errors[] = 'Un vélo vendu a forcément une date de vente.'; }

    if (!$errors) {
        $modelId = modelId($brand, $model, $year, $category, $listP);

        if ($id > 0) {
            $stmt = db()->prepare(
                'UPDATE ' . tbl('bikes') . '
                 SET model_id = ?, size = ?, color = ?, status = ?, entered_at = ?, sold_at = ?,
                     list_price = ?, purchase_price = ?, sold_price = ?, customer_id = ?, notes = ?
                 WHERE id = ?'
            );
            // 12 variables : i s s s s s d d d i s i
            $stmt->bind_param(
                'isssssdddisi',
                $modelId, $size, $color, $status, $entered, $sold,
                $listP, $purchP, $soldP, $customer, $notes, $id
            );
        } else {
            $stmt = db()->prepare(
                'INSERT INTO ' . tbl('bikes') . '
                 (model_id, size, color, status, entered_at, sold_at, list_price, purchase_price,
                  sold_price, customer_id, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            // 11 variables : i s s s s s d d d i s
            $stmt->bind_param(
                'isssssdddis',
                $modelId, $size, $color, $status, $entered, $sold,
                $listP, $purchP, $soldP, $customer, $notes
            );
        }

        $stmt->execute();
        $stmt->close();

        header('Location: ' . url('stock.php'));
        exit;
    }
}

$brands    = db()->query('SELECT name FROM ' . tbl('brands') . ' ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$models    = db()->query('SELECT DISTINCT name FROM ' . tbl('models') . ' ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$customers = db()->query('SELECT name FROM ' . tbl('customers') . ' ORDER BY name LIMIT 2000')->fetch_all(MYSQLI_ASSOC);

/** Valeur du formulaire : ce qui vient d'être posté, sinon le vélo en base, sinon vide. */
function field(string $key, ?array $bike, string $default = ''): string
{
    if (isset($_POST[$key])) {
        return (string)$_POST[$key];
    }

    return (string)($bike[$key] ?? $default);
}

renderHeader($id > 0 ? 'Modifier un vélo' : 'Ajouter un vélo', ['css' => ['admin', 'app'], 'icons' => true]);
?>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="post" class="card form-bike">
    <?= csrfField() ?>

    <div class="grid grid-2">
        <div class="field">
            <label class="label" for="brand">Marque</label>
            <input class="input" type="text" id="brand" name="brand" list="brand-list" required
                   value="<?= e(field('brand', $bike)) ?>" autocomplete="off">
            <datalist id="brand-list">
                <?php foreach ($brands as $brand): ?>
                    <option value="<?= e($brand['name']) ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </div>

        <div class="field">
            <label class="label" for="model">Modèle</label>
            <input class="input" type="text" id="model" name="model" list="model-list" required
                   value="<?= e(field('model_name', $bike)) ?>" autocomplete="off"
                   placeholder="Topstone Crb 3">
            <datalist id="model-list">
                <?php foreach ($models as $model): ?>
                    <option value="<?= e($model['name']) ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </div>

        <div class="field">
            <label class="label" for="category">Catégorie</label>
            <select class="input" id="category" name="category" required>
                <?php foreach (CATEGORIES as $category): ?>
                    <option value="<?= e($category) ?>"<?= field('category', $bike) === $category ? ' selected' : '' ?>>
                        <?= e($category) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label class="label" for="model_year">Millésime</label>
            <input class="input" type="number" id="model_year" name="model_year" min="2015" max="2030"
                   value="<?= e(field('model_year', $bike, (string)((int)date('Y') + 1))) ?>">
        </div>

        <div class="field">
            <label class="label" for="size">Taille</label>
            <input class="input" type="text" id="size" name="size" maxlength="10"
                   value="<?= e(field('size', $bike)) ?>" placeholder="54, M, XL…">
        </div>

        <div class="field">
            <label class="label" for="color">Couleur</label>
            <input class="input" type="text" id="color" name="color" maxlength="60"
                   value="<?= e(field('color', $bike)) ?>">
        </div>

        <div class="field">
            <label class="label" for="status">Statut</label>
            <select class="input" id="status" name="status" required>
                <?php foreach (STATUSES as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= field('status', $bike, 'stock') === $key ? ' selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label class="label" for="entered_at">Entré en stock le</label>
            <input class="input" type="date" id="entered_at" name="entered_at"
                   value="<?= e(field('entered_at', $bike, date('Y-m-d'))) ?>">
        </div>

        <div class="field">
            <label class="label" for="list_price">Prix catalogue (CHF)</label>
            <input class="input" type="number" step="1" id="list_price" name="list_price"
                   value="<?= e(field('list_price', $bike) !== '' ? (string)(int)field('list_price', $bike) : '') ?>">
        </div>

        <div class="field">
            <label class="label" for="purchase_price">
                Prix d'achat (CHF)
                <span class="muted text-sm">— vide : estimé via le rabais de la marque</span>
            </label>
            <input class="input" type="number" step="1" id="purchase_price" name="purchase_price"
                   value="<?= e(field('purchase_price', $bike) !== '' ? (string)(int)field('purchase_price', $bike) : '') ?>">
        </div>

        <div class="field">
            <label class="label" for="sold_at">Vendu le</label>
            <input class="input" type="date" id="sold_at" name="sold_at"
                   value="<?= e(field('sold_at', $bike)) ?>">
        </div>

        <div class="field">
            <label class="label" for="sold_price">Prix de vente (CHF)</label>
            <input class="input" type="number" step="1" id="sold_price" name="sold_price"
                   value="<?= e(field('sold_price', $bike) !== '' ? (string)(int)field('sold_price', $bike) : '') ?>">
        </div>

        <div class="field">
            <label class="label" for="customer">Client</label>
            <input class="input" type="text" id="customer" name="customer" list="customer-list"
                   value="<?= e(field('customer', $bike)) ?>" autocomplete="off">
            <datalist id="customer-list">
                <?php foreach ($customers as $customer): ?>
                    <option value="<?= e($customer['name']) ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </div>

        <div class="field">
            <label class="label" for="notes">Remarque</label>
            <input class="input" type="text" id="notes" name="notes" maxlength="255"
                   value="<?= e(field('notes', $bike)) ?>">
        </div>
    </div>

    <div class="form-actions">
        <a class="btn btn-ghost" href="<?= e(url('stock.php')) ?>">Annuler</a>
        <button type="submit" class="btn"><?= $id > 0 ? 'Enregistrer' : 'Ajouter le vélo' ?></button>
    </div>
</form>

<?php if ($id > 0): ?>
    <form method="post" class="card danger-zone"
          onsubmit="return confirm('Supprimer définitivement ce vélo ?');">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete">
        <div>
            <strong>Supprimer ce vélo</strong>
            <p class="muted text-sm">Irréversible. À réserver aux erreurs de saisie, pas aux vélos vendus.</p>
        </div>
        <button type="submit" class="btn btn-danger">
            <i class="fa-solid fa-trash"></i> Supprimer
        </button>
    </form>
<?php endif; ?>

<?php renderFooter(); ?>
