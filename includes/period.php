<?php
/**
 * Période d'interrogation, partagée par toutes les pages d'analyse.
 *
 * Une période, c'est un début, une fin, et — c'est le point important — une
 * période de comparaison : la même durée, décalée d'un an. Comparer six mois de
 * 2026 à douze mois de 2025 ne veut rien dire ; ici, la comparaison est toujours
 * à durée égale et à saison égale.
 */

require_once __DIR__ . '/helpers.php';

/** Raccourcis proposés dans l'interface. La clé est ce qui circule en `?range=`. */
const PERIOD_PRESETS = [
    'ytd'       => "Depuis le 1er janvier",
    'last12'    => '12 derniers mois',
    'prev_year' => 'Année précédente',
    'season'    => 'Saison en cours (oct. → sept.)',
    'all'       => 'Tout l\'historique',
    'custom'    => 'Dates au choix',
];

/**
 * Période courante, lue dans l'URL. Toujours valide : en cas d'entrée absurde
 * (fin avant début, date illisible), on retombe sur le défaut plutôt que de
 * renvoyer une plage vide qui passerait pour « aucune vente ».
 *
 * @return array{from:string, to:string, prev_from:string, prev_to:string,
 *               months:float, range:string, label:string}
 */
function currentPeriod(?array $query = null): array
{
    $query = $query ?? $_GET;
    $range = (string)($query['range'] ?? 'ytd');
    $today = date('Y-m-d');
    $year  = (int)date('Y');

    if (!isset(PERIOD_PRESETS[$range])) {
        $range = 'ytd';
    }

    switch ($range) {
        case 'prev_year':
            $from = ($year - 1) . '-01-01';
            $to   = ($year - 1) . '-12-31';
            break;

        case 'last12':
            $from = date('Y-m-d', strtotime('-12 months'));
            $to   = $today;
            break;

        case 'season':
            // La saison du vélo court d'octobre à septembre : c'est le rythme des
            // millésimes, donc celui des pré-commandes.
            $start = (int)date('n') >= 10 ? $year : $year - 1;
            $from  = $start . '-10-01';
            $to    = $today;
            break;

        case 'all':
            $from = firstSaleDate() ?? ($year . '-01-01');
            $to   = $today;
            break;

        case 'custom':
            $from = normalizeDate((string)($query['from'] ?? ''), $year . '-01-01');
            $to   = normalizeDate((string)($query['to'] ?? ''), $today);

            if ($from > $to) {
                [$from, $to] = [$to, $from];
            }
            break;

        case 'ytd':
        default:
            $range = 'ytd';
            $from  = $year . '-01-01';
            $to    = $today;
            break;
    }

    $days   = max(1, (int)round((strtotime($to) - strtotime($from)) / 86400) + 1);
    $months = round($days / 30.44, 2);

    return [
        'from'      => $from,
        'to'        => $to,
        'prev_from' => date('Y-m-d', strtotime($from . ' -1 year')),
        'prev_to'   => date('Y-m-d', strtotime($to . ' -1 year')),
        'months'    => max(0.1, $months),
        'range'     => $range,
        'label'     => $range === 'custom'
            ? fmtDate($from, 'd.m.Y') . ' → ' . fmtDate($to, 'd.m.Y')
            : PERIOD_PRESETS[$range],
    ];
}

/** Date SQL valide, ou la valeur de repli. */
function normalizeDate(string $value, string $fallback): string
{
    $ts = strtotime($value);

    return ($value !== '' && $ts !== false) ? date('Y-m-d', $ts) : $fallback;
}

/** Première vente connue : sert de borne à « tout l'historique ». */
function firstSaleDate(): ?string
{
    $row = db()->query(
        'SELECT MIN(sold_at) AS d FROM ' . tbl('bikes') . ' WHERE status = "vendu"'
    )->fetch_assoc();

    return $row['d'] ?? null;
}

/**
 * Sélecteur de période. Le même sur toutes les pages : l'utilisateur n'a qu'une
 * chose à apprendre. Les paramètres de la page appelante sont conservés en
 * champs cachés, pour ne pas perdre les filtres en changeant de période.
 *
 * @param array<string, string> $keep paramètres à faire suivre (catégorie, marque…)
 */
function renderPeriodFilter(array $period, string $action, array $keep = []): void
{
    ?>
    <form class="filters period-filter" method="get" action="<?= e($action) ?>">
        <?php foreach ($keep as $name => $value): ?>
            <?php if ($value !== ''): ?>
                <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
            <?php endif; ?>
        <?php endforeach; ?>

        <div class="field">
            <label class="label" for="range">Période</label>
            <select class="input js-range" id="range" name="range">
                <?php foreach (PERIOD_PRESETS as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= $period['range'] === $key ? ' selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field js-custom-dates"<?= $period['range'] === 'custom' ? '' : ' hidden' ?>>
            <label class="label" for="from">Du</label>
            <input class="input" type="date" id="from" name="from" value="<?= e($period['from']) ?>">
        </div>

        <div class="field js-custom-dates"<?= $period['range'] === 'custom' ? '' : ' hidden' ?>>
            <label class="label" for="to">Au</label>
            <input class="input" type="date" id="to" name="to" value="<?= e($period['to']) ?>">
        </div>

        <div class="field field-actions">
            <button class="btn" type="submit">Appliquer</button>
        </div>

        <p class="period-summary muted text-sm">
            <?= e(fmtDate($period['from'], 'd.m.Y')) ?> → <?= e(fmtDate($period['to'], 'd.m.Y')) ?>
            (<?= e(number_format($period['months'], 1)) ?> mois) ·
            comparé à <?= e(fmtDate($period['prev_from'], 'd.m.Y')) ?> → <?= e(fmtDate($period['prev_to'], 'd.m.Y')) ?>
        </p>
    </form>
    <?php
}
