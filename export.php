<?php
/**
 * Export et analyse assistée.
 *
 * Le prompt ne contient **aucun chiffre**, volontairement : les données arrivent
 * par les CSV joints. Un prompt qui embarque les chiffres périme à chaque vente
 * et ne s'améliore pas ; un prompt qui porte la méthode se retravaille au fil
 * des saisons. C'est un actif, pas un consommable.
 *
 * Il est éditable ici même et persiste en base (vo_settings). Le défaut du code
 * (config/prompt.php) reste la référence, restaurable à tout moment.
 */

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/prompt.php';
require_once __DIR__ . '/includes/catalog.php';
require_once __DIR__ . '/includes/period.php';
require_once __DIR__ . '/includes/layout.php';

checkAuth();
checkRole(['owner', 'admin']);

$period   = currentPeriod();
$datasets = exportDatasets();
$notice   = '';

// --- Édition du prompt ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    if (($_POST['action'] ?? '') === 'reset') {
        saveSetting('prompt_analyse', '');
        $notice = 'Prompt réinitialisé : le texte livré avec l\'application fait de nouveau foi.';
    } elseif (($_POST['action'] ?? '') === 'save') {
        saveSetting('prompt_analyse', (string)($_POST['prompt'] ?? ''));
        $notice = 'Prompt enregistré.';
    }
}

$prompt   = setting('prompt_analyse', PROMPT_ANALYSE);
$isCustom = $prompt !== PROMPT_ANALYSE;

// --- Téléchargement -------------------------------------------------------
$wanted = (string)($_GET['dl'] ?? '');

if ($wanted !== '' && isset($datasets[$wanted])) {
    $set  = $datasets[$wanted];
    $rows = [];

    if ($set['sql'] !== null) {
        $stmt = db()->prepare($set['sql']);
        if ($set['dated']) {
            $stmt->bind_param('ss', $period['from'], $period['to']);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } elseif ($wanted === 'rotation') {
        foreach (familyRotation($period['from'], $period['to'], $period['months']) as $row) {
            $rows[] = [
                'categorie'          => $row['category'],
                'famille'            => $row['family'],
                'vendus'             => (int)$row['sold'],
                'en_stock'           => (int)$row['in_stock'],
                'millesimes_anciens' => (int)$row['old_stock'],
                'valeur_stock'       => round((float)$row['stock_value']),
                'mois_de_stock'      => $row['coverage'] === null ? '' : $row['coverage'],
            ];
        }
    } elseif ($wanted === 'tailles') {
        foreach (salesBySize($period['from'], $period['to']) as $row) {
            $rows[] = [
                'categorie' => $row['category'],
                'taille'    => $row['size'],
                'vendus'    => (int)$row['sold'],
                'en_stock'  => (int)$row['in_stock'],
            ];
        }
    }

    $suffix = $set['dated'] ? '_' . $period['from'] . '_' . $period['to'] : '';
    streamCsv('vo_' . $wanted . $suffix . '.csv', $rows);
}

renderHeader('Export et analyse', ['css' => ['admin', 'app'], 'icons' => true]);
?>

<?php if ($notice !== ''): ?>
    <div class="alert alert-success"><?= e($notice) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2>Marche à suivre</h2>
    </div>
    <ol class="steps muted">
        <li>Télécharge <strong>l'export complet</strong> — il contient tout : stock, ventes, historique.</li>
        <li>Ouvre Claude ou ChatGPT, joins le fichier.</li>
        <li>Copie le prompt ci-dessous et colle-le.</li>
    </ol>
    <p class="muted text-sm">
        Le prompt ne contient aucun chiffre : il porte la méthode, les données arrivent par le
        fichier. C'est ce qui te permet de l'affiner saison après saison sans qu'il se périme.
    </p>
</div>

<div class="card">
    <div class="card-header">
        <h2>Les données</h2>
        <span class="muted text-sm">CSV, UTF-8, ouvrables dans Excel</span>
    </div>

    <div class="table-wrap">
        <table class="table">
            <tbody>
                <?php foreach ($datasets as $key => $set): ?>
                    <tr<?= $key === 'complet' ? ' class="row-primary"' : '' ?>>
                        <td>
                            <span class="cell-main"><?= e($set['label']) ?></span>
                            <span class="cell-sub"><?= e($set['desc']) ?></span>
                            <?php if ($set['dated']): ?>
                                <span class="cell-sub">Limité à la période : <?= e($period['label']) ?>.</span>
                            <?php endif; ?>
                        </td>
                        <td class="num">
                            <a class="btn <?= $key === 'complet' ? '' : 'btn-ghost' ?> btn-sell"
                               href="<?= e(url('export.php?dl=' . $key . '&' . http_build_query([
                                   'range' => $period['range'],
                                   'from'  => $period['from'],
                                   'to'    => $period['to'],
                               ]))) ?>">
                                <i class="fa-solid fa-download"></i> Télécharger
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <details class="details">
        <summary>Changer la période des exports agrégés</summary>
        <?php renderPeriodFilter($period, url('export.php')); ?>
    </details>
</div>

<form method="post">
    <?= csrfField() ?>

    <div class="card">
        <div class="card-header">
            <h2>
                Le prompt
                <?php if ($isCustom): ?>
                    <span class="tag tag-stock">modifié</span>
                <?php endif; ?>
            </h2>

            <div class="row-actions">
                <button type="button" class="btn js-copy" data-copy="#prompt-text">
                    <i class="fa-solid fa-copy"></i> Copier
                </button>
                <button type="submit" class="btn btn-ghost" name="action" value="save">
                    <i class="fa-solid fa-floppy-disk"></i> Enregistrer les modifications
                </button>
            </div>
        </div>

        <p class="muted">
            Modifie-le librement : il est à toi. Il demande un diagnostic par catégorie et par
            taille, une projection de fin de saison, une pré-commande chiffrée
            (<span class="mono">demande attendue − stock résiduel</span>), et surtout la liste
            des données qui nous manquent pour décider correctement.
        </p>

        <textarea class="input prompt-box" id="prompt-text" name="prompt" rows="22"><?= e($prompt) ?></textarea>

        <?php if ($isCustom): ?>
            <div class="form-actions">
                <button type="submit" class="btn btn-ghost" name="action" value="reset"
                        onclick="return confirm('Revenir au prompt livré avec l\'application ? Tes modifications seront perdues.');">
                    <i class="fa-solid fa-rotate-left"></i> Restaurer le prompt d'origine
                </button>
            </div>
        <?php endif; ?>
    </div>
</form>

<?php renderFooter(['scripts' => [
    url('assets/js/period.js') . '?v=' . APP_VERSION,
    url('assets/js/copie.js') . '?v=' . APP_VERSION,
]]); ?>
