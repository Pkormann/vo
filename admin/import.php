<?php
/**
 * Import CSV — reprise des fichiers Excel du magasin.
 *
 * Le CSV est produit en local depuis les classeurs, puis téléversé ici : les
 * données commerciales ne transitent donc jamais par le dépôt.
 *
 * L'import est rejouable : chaque ligne porte une empreinte (import_key) et
 * une ligne déjà connue est ignorée, jamais dupliquée. On peut donc réimporter
 * un fichier corrigé sans nettoyer la base avant.
 */

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/catalog.php';
require_once __DIR__ . '/../includes/activity.php';
require_once __DIR__ . '/../includes/layout.php';

checkAuth();
checkRole(['owner']);

/** Colonnes attendues, dans cet ordre. */
const IMPORT_COLUMNS = [
    'marque', 'categorie', 'modele', 'millesime', 'taille', 'couleur',
    'prix_catalogue', 'statut', 'entre_le', 'vendu_le', 'prix_vente', 'client', 'remarque',
];

$report = null;
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $file = $_FILES['csv'] ?? null;

    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $error = 'Aucun fichier reçu, ou téléversement interrompu.';
    } elseif ($file['size'] > 4 * 1024 * 1024) {
        $error = 'Fichier trop volumineux (4 Mo maximum).';
    } else {
        $handle = fopen($file['tmp_name'], 'r');

        if ($handle === false) {
            $error = 'Fichier illisible.';
        } else {
            $header = fgetcsv($handle);

            if (!$header || array_map('strtolower', array_map('trim', $header)) !== IMPORT_COLUMNS) {
                $error = 'En-tête inattendu. Colonnes requises : ' . implode(', ', IMPORT_COLUMNS);
                fclose($handle);
            } else {
                $report = ['created' => 0, 'skipped' => 0, 'rejected' => []];
                $line   = 1;

                $insert = db()->prepare(
                    'INSERT INTO ' . tbl('bikes') . '
                     (model_id, size, color, status, entered_at, sold_at, list_price, sold_price,
                      customer_id, notes, import_key)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );

                while (($row = fgetcsv($handle)) !== false) {
                    $line++;

                    if (count($row) < count(IMPORT_COLUMNS)) {
                        $report['rejected'][] = "ligne $line : colonnes manquantes";
                        continue;
                    }

                    $data = array_combine(IMPORT_COLUMNS, array_slice($row, 0, count(IMPORT_COLUMNS)));
                    $data = array_map('trim', $data);

                    $brand    = $data['marque'];
                    $model    = $data['modele'];
                    $category = $data['categorie'];
                    $status   = $data['statut'] ?: 'stock';

                    if ($brand === '' || $model === '') {
                        $report['rejected'][] = "ligne $line : marque ou modèle vide";
                        continue;
                    }

                    if (!in_array($category, CATEGORIES, true)) {
                        $report['rejected'][] = "ligne $line : catégorie « {$category} » inconnue";
                        continue;
                    }

                    if (!isset(STATUSES[$status])) {
                        $report['rejected'][] = "ligne $line : statut « {$status} » inconnu";
                        continue;
                    }

                    // L'empreinte porte sur la ligne source : deux vélos réellement
                    // identiques (même modèle, même taille, même date) sont vus comme
                    // un seul. C'est le prix de la rejouabilité, et c'est assumé :
                    // le doublon exact est plus rare que le double import.
                    $key = md5(implode('|', $data));

                    $year    = $data['millesime']      !== '' ? (int)$data['millesime']        : null;
                    $listP   = $data['prix_catalogue'] !== '' ? (float)$data['prix_catalogue'] : null;
                    $soldP   = $data['prix_vente']     !== '' ? (float)$data['prix_vente']     : null;
                    $entered = $data['entre_le']       !== '' ? $data['entre_le']              : null;
                    $sold    = $data['vendu_le']       !== '' ? $data['vendu_le']              : null;
                    $size    = $data['taille'];
                    $color   = $data['couleur'];
                    $notes   = $data['remarque'];

                    $modelId    = modelId($brand, $model, $year, $category, $listP);
                    $customerId = customerId($data['client']);

                    try {
                        $insert->bind_param(
                            'isssssddiss',
                            $modelId, $size, $color, $status, $entered, $sold,
                            $listP, $soldP, $customerId, $notes, $key
                        );
                        $insert->execute();
                        $report['created']++;
                    } catch (mysqli_sql_exception $e) {
                        // 1062 = clé dupliquée : la ligne est déjà en base, c'est le
                        // comportement attendu d'un ré-import, pas une erreur.
                        if ((int)$e->getCode() === 1062) {
                            $report['skipped']++;
                        } else {
                            $report['rejected'][] = "ligne $line : " . $e->getMessage();
                        }
                    }
                }

                $insert->close();
                fclose($handle);

                logAction('import', 'csv', null,
                    $report['created'] . ' créé(s), ' . $report['skipped'] . ' ignoré(s), '
                    . count($report['rejected']) . ' rejet(s)');
            }
        }
    }
}

$counts = db()->query(
    'SELECT
        (SELECT COUNT(*) FROM ' . tbl('bikes') . ')                          AS bikes,
        (SELECT COUNT(*) FROM ' . tbl('bikes') . ' WHERE status = "vendu")   AS sold,
        (SELECT COUNT(*) FROM ' . tbl('models') . ')                         AS models,
        (SELECT COUNT(*) FROM ' . tbl('brands') . ')                         AS brands,
        (SELECT COUNT(*) FROM ' . tbl('customers') . ')                      AS customers'
)->fetch_assoc();

renderHeader('Import CSV', ['css' => ['admin', 'app'], 'icons' => true]);
?>

<?php if ($error !== ''): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($report !== null): ?>
    <div class="alert alert-success">
        <strong><?= (int)$report['created'] ?> vélo(s) importé(s).</strong>
        <?= (int)$report['skipped'] ?> ligne(s) déjà connue(s), ignorée(s).
        <?= count($report['rejected']) ?> rejet(s).
    </div>

    <?php if ($report['rejected']): ?>
        <div class="card">
            <div class="card-header"><h2>Lignes rejetées</h2></div>
            <ul class="install-log">
                <?php foreach (array_slice($report['rejected'], 0, 50) as $rejected): ?>
                    <li><span class="mono"><?= e($rejected) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
<?php endif; ?>

<div class="grid grid-4">
    <div class="kpi">
        <span class="kpi-label">Vélos en base</span>
        <span class="kpi-value"><?= (int)$counts['bikes'] ?></span>
    </div>
    <div class="kpi">
        <span class="kpi-label">dont vendus</span>
        <span class="kpi-value"><?= (int)$counts['sold'] ?></span>
    </div>
    <div class="kpi">
        <span class="kpi-label">Modèles</span>
        <span class="kpi-value"><?= (int)$counts['models'] ?></span>
    </div>
    <div class="kpi">
        <span class="kpi-label">Clients</span>
        <span class="kpi-value"><?= (int)$counts['customers'] ?></span>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Téléverser un CSV</h2></div>

    <p class="muted">
        Encodage UTF-8, séparateur virgule, première ligne d'en-tête. Un import déjà passé
        peut être rejoué : les lignes identiques sont ignorées.
    </p>

    <table class="table">
        <thead>
            <tr><th>Colonne</th><th>Contenu</th></tr>
        </thead>
        <tbody>
            <tr><td class="mono">marque</td><td>Cannondale, Scott, R&amp;M…</td></tr>
            <tr><td class="mono">categorie</td><td><?= e(implode(' · ', CATEGORIES)) ?></td></tr>
            <tr><td class="mono">modele</td><td>libellé complet, ex. <em>Topstone Crb 3</em></td></tr>
            <tr><td class="mono">millesime</td><td>année du modèle, ex. 2026</td></tr>
            <tr><td class="mono">taille</td><td>54, M, XL… (vide accepté)</td></tr>
            <tr><td class="mono">couleur</td><td>facultatif</td></tr>
            <tr><td class="mono">prix_catalogue</td><td>nombre, sans séparateur</td></tr>
            <tr><td class="mono">statut</td><td><?= e(implode(' · ', array_keys(STATUSES))) ?></td></tr>
            <tr><td class="mono">entre_le</td><td>AAAA-MM-JJ, vide si inconnu</td></tr>
            <tr><td class="mono">vendu_le</td><td>AAAA-MM-JJ, obligatoire si statut = vendu</td></tr>
            <tr><td class="mono">prix_vente</td><td>facultatif</td></tr>
            <tr><td class="mono">client</td><td>nom, prénom — la fiche client est créée au besoin</td></tr>
            <tr><td class="mono">remarque</td><td>facultatif</td></tr>
        </tbody>
    </table>

    <form method="post" enctype="multipart/form-data" class="form-inline">
        <?= csrfField() ?>
        <div class="field">
            <label class="label" for="csv">Fichier CSV</label>
            <input class="input" type="file" id="csv" name="csv" accept=".csv,text/csv" required>
        </div>
        <button type="submit" class="btn"><i class="fa-solid fa-upload"></i> Importer</button>
    </form>
</div>

<?php renderFooter(); ?>
