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
require_once __DIR__ . '/includes/activity.php';
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

    $action = (string)($_POST['action'] ?? '');
    $author = currentUser();

    if ($action === 'reset') {
        // La version en cours est archivée avant d'être écartée : « restaurer
        // l'original » reste donc lui-même réversible.
        saveSetting('prompt_analyse', '', $author);
        $notice = 'Prompt d\'origine restauré. La version précédente est conservée dans l\'historique.';

    } elseif ($action === 'save') {
        $changed = saveSetting('prompt_analyse', (string)($_POST['prompt'] ?? ''), $author);

        if ($changed) {
            logAction('prompt', 'prompt', null, 'modification enregistrée');
        }
        $notice  = $changed
            ? 'Prompt enregistré. La version précédente est conservée dans l\'historique.'
            : 'Aucune modification à enregistrer.';

    } elseif ($action === 'restore') {
        $version = settingVersion((int)($_POST['version_id'] ?? 0));

        if ($version === null) {
            $notice = 'Cette version n\'existe plus.';
        } else {
            saveSetting('prompt_analyse', $version, $author);
            $notice = 'Version restaurée.';
        }
    }
}

$prompt   = setting('prompt_analyse', PROMPT_ANALYSE);
$isCustom = $prompt !== PROMPT_ANALYSE;
$history  = settingHistory('prompt_analyse');

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

    logAction('export', 'csv', null, $set['label'] . ' — ' . count($rows) . ' ligne(s)');

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

        <p class="muted text-sm">
            Édite sans crainte : chaque enregistrement archive la version qu'il remplace, et le
            texte d'origine vit dans le code — il ne peut pas être perdu.
        </p>
    </div>
</form>

<div class="card">
    <div class="card-header">
        <h2>Historique du prompt</h2>
        <span class="muted text-sm"><?= count($history) ?> version<?= count($history) > 1 ? 's' : '' ?> archivée<?= count($history) > 1 ? 's' : '' ?></span>
    </div>

    <div class="table-wrap">
        <table class="table">
            <tbody>
                <tr class="row-primary">
                    <td>
                        <span class="cell-main">
                            Version d'origine
                            <?= $isCustom ? '' : '<span class="tag tag-stock">en cours</span>' ?>
                        </span>
                        <span class="cell-sub">
                            Le texte livré avec l'application. Il vit dans le dépôt : il est
                            toujours restaurable, quoi qu'il arrive à la base.
                        </span>
                    </td>
                    <td class="num">
                        <?php if ($isCustom): ?>
                            <form method="post" class="inline-form"
                                  onsubmit="return confirm('Revenir au prompt d\'origine ? La version actuelle sera archivée, tu pourras y revenir.');">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="reset">
                                <button type="submit" class="btn btn-ghost btn-sell">
                                    <i class="fa-solid fa-rotate-left"></i> Restaurer
                                </button>
                            </form>
                        <?php else: ?>
                            <span class="muted text-sm">actif</span>
                        <?php endif; ?>
                    </td>
                </tr>

                <?php foreach ($history as $version): ?>
                    <tr>
                        <td>
                            <span class="cell-main">
                                <?= e(fmtDate($version['created_at'], 'd.m.Y à H:i')) ?>
                                <?php if ($version['author']): ?>
                                    <span class="muted">— <?= e($version['author']) ?></span>
                                <?php endif; ?>
                            </span>
                            <details class="details">
                                <summary>Voir ce texte (<?= number_format(mb_strlen($version['value']), 0, '.', "'") ?> caractères)</summary>
                                <pre class="version-preview"><?= e($version['value']) ?></pre>
                            </details>
                        </td>
                        <td class="num">
                            <form method="post" class="inline-form"
                                  onsubmit="return confirm('Restaurer cette version ? La version actuelle sera archivée.');">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="restore">
                                <input type="hidden" name="version_id" value="<?= (int)$version['id'] ?>">
                                <button type="submit" class="btn btn-ghost btn-sell">
                                    <i class="fa-solid fa-clock-rotate-left"></i> Restaurer
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if (!$history): ?>
        <p class="muted text-sm">
            Aucune version archivée pour l'instant : le prompt n'a jamais été modifié.
        </p>
    <?php endif; ?>
</div>

<?php renderFooter(['scripts' => [
    url('assets/js/period.js') . '?v=' . APP_VERSION,
    url('assets/js/copie.js') . '?v=' . APP_VERSION,
]]); ?>
