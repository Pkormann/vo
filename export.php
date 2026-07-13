<?php
/**
 * Export — sortir les données du magasin, et le prompt qui va avec.
 *
 * L'idée : l'app calcule et présente, mais le raisonnement fin (« faut-il
 * vraiment 8 Topstone en 54 ? ») se fait mieux en discutant. On fournit donc
 * des tableaux déjà agrégés — pas un dump brut — et un prompt contextualisé
 * avec les vrais chiffres, pour que le modèle parte du terrain et non de rien.
 */

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/catalog.php';
require_once __DIR__ . '/includes/period.php';
require_once __DIR__ . '/includes/layout.php';

checkAuth();
checkRole(['owner', 'admin']);

$period   = currentPeriod();
$datasets = exportDatasets();

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
                'categorie'      => $row['category'],
                'famille'        => $row['family'],
                'vendus'         => (int)$row['sold'],
                'en_stock'       => (int)$row['in_stock'],
                'millesimes_anciens' => (int)$row['old_stock'],
                'valeur_stock'   => round((float)$row['stock_value']),
                'mois_de_stock'  => $row['coverage'] === null ? '' : $row['coverage'],
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

    streamCsv('vo_' . $wanted . '_' . $period['from'] . '_' . $period['to'] . '.csv', $rows);
}

// --- Chiffres qui nourrissent le prompt -----------------------------------
$totals     = salesTotals($period['from'], $period['to']);
$prevTotals = salesTotals($period['prev_from'], $period['prev_to']);
$kpis       = stockKpis();
$rotation   = familyRotation($period['from'], $period['to'], $period['months']);
$byCategory = salesByCategory($period['from'], $period['to']);
$byPrev     = salesByCategory($period['prev_from'], $period['prev_to']);

$dormant = [];
$short   = [];
foreach ($rotation as $row) {
    $coverage = $row['coverage'];
    $stock    = (int)$row['in_stock'];

    if ($stock > 0 && ($coverage === null || $coverage > 6)) {
        $dormant[] = sprintf(
            '%s (%s) : %d en stock, %d vendus, %s, %s immobilisés',
            $row['family'],
            $row['category'],
            $stock,
            (int)$row['sold'],
            $coverage === null ? 'aucune vente sur la période' : $coverage . ' mois de stock',
            chf((float)$row['stock_value'])
        );
    } elseif ($stock > 0 && $coverage !== null && $coverage < 2) {
        $short[] = sprintf(
            '%s (%s) : %d en stock seulement, %d vendus, %s mois de stock',
            $row['family'],
            $row['category'],
            $stock,
            (int)$row['sold'],
            $coverage
        );
    }
}

$categoryLines = [];
foreach (CATEGORIES as $category) {
    $now  = $byCategory[$category] ?? 0;
    $then = $byPrev[$category] ?? 0;

    if ($now === 0 && $then === 0) {
        continue;
    }

    $trend = $then > 0
        ? sprintf('%+d %%', round(100 * ($now - $then) / $then))
        : 'pas de comparaison';

    $categoryLines[] = sprintf('- %s : %d vendus (%d un an plus tôt, %s)', $category, $now, $then, $trend);
}

$prompt = <<<PROMPT
Tu es analyste commercial pour un magasin de vélo indépendant, Version Originale Cycles, à
Yverdon-les-Bains (Suisse). Je prépare mes pré-commandes pour la saison suivante et j'ai besoin
d'un regard critique, pas d'un résumé complaisant.

## Contexte du magasin

- Vente de vélos neufs : route, gravel, VTT, e-bikes, cargo, urbain, enfants.
- Marques principales : Cannondale, Scott, Riese & Müller, Cervélo.
- Les vélos se commandent une fois par an, plusieurs mois à l'avance, par millésime
  (« MY27 »). Une erreur de pré-commande se paie pendant deux ans : le stock invendu
  bloque de la trésorerie et se décote quand le millésime suivant arrive.
- La saison commerciale court d'octobre à septembre.

## Période analysée

Du {$period['from']} au {$period['to']} ({$period['label']}), soit
PROMPT;

$prompt .= ' ' . number_format($period['months'], 1) . " mois.\n";
$prompt .= "Comparaison : même durée un an plus tôt ({$period['prev_from']} → {$period['prev_to']}).\n\n";

$prompt .= "## Les chiffres\n\n";
$prompt .= sprintf(
    "- Vélos vendus sur la période : %d (contre %d un an plus tôt).\n",
    $totals['n'],
    $prevTotals['n']
);
$prompt .= sprintf(
    "- Chiffre d'affaires : %s, panier moyen %s.\n",
    chf($totals['ca']),
    chf($totals['n'] > 0 ? $totals['ca'] / $totals['n'] : null)
);
$prompt .= sprintf(
    "- Stock actuel : %d vélos en rayon, %s au prix catalogue, dont %d de millésime ancien.\n\n",
    $kpis['units'],
    chf($kpis['value']),
    $kpis['old_units']
);

$prompt .= "Ventes par catégorie :\n" . implode("\n", $categoryLines) . "\n\n";

if ($dormant) {
    $prompt .= "Familles qui dorment (plus de 6 mois de stock au rythme actuel) :\n- "
        . implode("\n- ", $dormant) . "\n\n";
}

if ($short) {
    $prompt .= "Familles bientôt épuisées (moins de 2 mois de stock) :\n- "
        . implode("\n- ", $short) . "\n\n";
}

$prompt .= <<<'PROMPT'
## Les fichiers joints

- `ventes` : une ligne par vélo vendu (date, catégorie, famille, modèle, taille, prix, client).
- `stock` : une ligne par vélo en rayon (avec son âge en jours quand il est connu).
- `rotation` : par famille — vendus, stock, mois de stock, valeur immobilisée.
- `tailles` : croisement catégorie × taille, vendus et stock.
- `mensuel` : ventes par mois et par catégorie, sur plusieurs années (la saisonnalité).

## Ce que j'attends de toi

1. **Diagnostic par catégorie.** Qu'est-ce qui progresse, qu'est-ce qui décroche ? Distingue
   ce qui baisse parce que la demande faiblit de ce qui baisse parce que je n'avais plus de
   stock à vendre — le fichier `stock` te dit si le rayon était vide. Ne confonds pas les deux.

2. **Analyse par taille.** Pour chaque famille qui compte, quelles tailles partent vraiment et
   lesquelles restent ? Une famille peut être saine en volume et malade en tailles.

3. **Projection de fin de saison.** À partir de `mensuel`, estime la saisonnalité réelle
   (quelle part de l'année est faite à cette date) et projette les ventes de la saison complète,
   par catégorie. Dis explicitement quelle méthode tu emploies.

4. **Recommandation de pré-commande**, sous forme de tableau : famille, quantité proposée,
   répartition par taille, et la justification en une ligne. Le principe est :

       pré-commande = demande attendue − stock qui restera en rayon à l'ouverture de la saison

   Ne me propose pas de commander une famille dont le stock actuel couvre déjà plus de six mois :
   elle doit d'abord être écoulée, éventuellement soldée.

5. **Ce que tu ne peux pas savoir.** Dis-moi franchement quelles conclusions sont fragiles et
   quelles données manquantes changeraient ton avis.

## Règles

- N'invente aucun chiffre. Si une donnée manque, dis-le au lieu de l'estimer en silence.
- Toute affirmation chiffrée doit être traçable aux fichiers joints.
- Sois direct : si une décision passée a été mauvaise, dis-le et explique le mécanisme.
- Termine par les trois questions que tu me poserais pour affiner la recommandation.
PROMPT;

renderHeader('Export et analyse', ['css' => ['admin', 'app'], 'icons' => true]);
?>

<div class="card">
    <?php renderPeriodFilter($period, url('export.php')); ?>
</div>

<div class="card">
    <div class="card-header">
        <h2>Les données</h2>
        <span class="muted text-sm">CSV, UTF-8, ouvrables dans Excel</span>
    </div>

    <p class="muted">
        Des tableaux déjà agrégés, pas un export brut de la base : ils se lisent directement,
        par un humain comme par un modèle de langage.
    </p>

    <div class="table-wrap">
        <table class="table">
            <tbody>
                <?php foreach ($datasets as $key => $set): ?>
                    <tr>
                        <td>
                            <span class="cell-main"><?= e($set['label']) ?></span>
                            <span class="cell-sub"><?= e($set['desc']) ?></span>
                        </td>
                        <td class="num">
                            <a class="btn btn-ghost btn-sell"
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
</div>

<div class="card">
    <div class="card-header">
        <h2>Le prompt</h2>
        <button type="button" class="btn js-copy" data-copy="#prompt-text">
            <i class="fa-solid fa-copy"></i> Copier
        </button>
    </div>

    <p class="muted">
        Télécharge les cinq fichiers, ouvre Claude ou ChatGPT, joins-les, et colle ce texte.
        Il contient déjà les chiffres de la période : le modèle part du terrain, pas de zéro.
    </p>

    <textarea class="input prompt-box" id="prompt-text" rows="18" readonly><?= e($prompt) ?></textarea>

    <p class="muted text-sm">
        Le prompt lui demande explicitement de ne rien inventer, de distinguer une baisse de
        demande d'une rupture de stock, et de finir par les questions qu'il te poserait.
    </p>
</div>

<?php renderFooter(['scripts' => [
    url('assets/js/period.js') . '?v=' . APP_VERSION,
    url('assets/js/copie.js') . '?v=' . APP_VERSION,
]]); ?>
