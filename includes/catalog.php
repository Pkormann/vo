<?php
/**
 * Domaine magasin : vélos, modèles, marques, clients.
 *
 * Un vélo est un exemplaire physique unique. Il entre en stock (entered_at),
 * puis il est vendu (sold_at). « Le stock » et « les ventes » ne sont donc pas
 * deux objets : ce sont deux filtres sur la même table.
 *
 * Toutes les mesures de rotation vivent ici, jamais dans les pages.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers.php';

/** Catégories commerciales, dans l'ordre d'affichage. */
const CATEGORIES = ['Route', 'Gravel', 'E-bikes', 'VTT', 'Cargo', 'Urbain', 'Kids'];

/** Statuts d'un exemplaire. `stock` et `reserve` sont physiquement présents. */
const STATUSES = [
    'stock'   => 'En stock',
    'reserve' => 'Réservé',
    'test'    => 'Test / démo',
    'vendu'   => 'Vendu',
];

/** Statuts qui occupent physiquement le magasin. */
const STATUSES_PRESENT = ['stock', 'reserve', 'test'];

/**
 * Famille commerciale déduite d'un libellé de modèle.
 *
 * Le stock écrit « S6 EVO 2 » là où les ventes écrivent « SuperSix Crb. 2 » :
 * sans ce repliement, les deux fichiers ne se rejoignent jamais. La première
 * expression qui matche gagne, donc l'ordre est significatif (« Addict RC »
 * avant « Addict », « Synapse Neo » avant « Synapse »).
 */
function familyOf(string $label): string
{
    static $rules = [
        'SuperSix'     => '/supersix|\bs6\b|s6 ?evo/i',
        'SuperX'       => '/\bsuperx\b/i',
        'Topstone'     => '/topstone/i',
        'Synapse Neo'  => '/synapse neo/i',
        'Synapse'      => '/synapse/i',
        'CAAD'         => '/caad/i',
        'Scalpel'      => '/scalpel/i',
        'Moterra'      => '/moterra/i',
        'Tesoro'       => '/tesoro/i',
        'Habit'        => '/bad ?habit|\bhabit\b/i',
        'Bad Boy'      => '/bad ?boy/i',
        'Cargowagen'   => '/cargowagen/i',
        'FlyingV'      => '/flying ?v/i',
        'Addict RC'    => '/addict rc/i',
        'Addict Gravel'=> '/addict gravel/i',
        'Addict'       => '/addict/i',
        'Foil'         => '/foil/i',
        'Scale'        => '/\bscale\b/i',
        'Spark'        => '/spark/i',
        'Ransom'       => '/ransom/i',
        'Contessa'     => '/contessa/i',
        'Lumen'        => '/lumen/i',
        'Patron'       => '/patron/i',
        'Passage'      => '/passage/i',
        'Fastlane'     => '/fastlane/i',
        'Solace'       => '/solace/i',
        'Strike'       => '/strike/i',
        'Metrix'       => '/metrix/i',
        'Sub'          => '/\bsub\b/i',
        'Contrail'     => '/contrail/i',
        'Aspect'       => '/aspect/i',
        'Aspero'       => '/aspero/i',
        'R5'           => '/\br5\b/i',
        'S5'           => '/\bs5\b/i',
        'Soloist'      => '/soloist/i',
        'Multicharger' => '/multicharger|multitinker/i',
        'Charger'      => '/charger/i',
        'Nevo'         => '/nevo/i',
        'Culture'      => '/culture/i',
        'Roadster'     => '/roadster/i',
        'Cargo R&M'    => '/load ?\d|packster|transporte/i',
        'Bullitt'      => '/bullitt/i',
        'Quick'        => '/quick/i',
    ];

    foreach ($rules as $family => $pattern) {
        if (preg_match($pattern, $label)) {
            return $family;
        }
    }

    // Faute de règle, le premier mot fait office de famille : suffisant pour
    // regrouper les modèles marginaux sans les noyer dans un fourre-tout.
    $words = preg_split('/[\s.,]+/', trim($label));
    return ucfirst($words[0] ?? 'Divers');
}

/** Marque connue, créée à la volée si besoin. Renvoie son id. */
function brandId(string $name): int
{
    $name = trim($name);
    $stmt = db()->prepare('SELECT id FROM ' . tbl('brands') . ' WHERE name = ? LIMIT 1');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        return (int)$row['id'];
    }

    $stmt = db()->prepare('INSERT INTO ' . tbl('brands') . ' (name) VALUES (?)');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $id = (int)db()->insert_id;
    $stmt->close();

    return $id;
}

/** Client connu, créé à la volée. Renvoie son id, ou null si le nom est vide. */
function customerId(?string $name): ?int
{
    $name = trim((string)$name);
    if ($name === '') {
        return null;
    }

    $stmt = db()->prepare('SELECT id FROM ' . tbl('customers') . ' WHERE name = ? LIMIT 1');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        return (int)$row['id'];
    }

    $stmt = db()->prepare('INSERT INTO ' . tbl('customers') . ' (name) VALUES (?)');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $id = (int)db()->insert_id;
    $stmt->close();

    return $id;
}

/**
 * Modèle du catalogue, créé à la volée. La clé est (marque, libellé, millésime) :
 * un même vélo en MY26 et MY27 sont deux modèles, ce qui est voulu — les prix
 * et les specs changent d'une année à l'autre.
 */
function modelId(string $brand, string $name, ?int $year, string $category, ?float $listPrice = null): int
{
    $brandId = brandId($brand);
    $name    = trim($name);

    $stmt = db()->prepare(
        'SELECT id FROM ' . tbl('models') . ' WHERE brand_id = ? AND name = ? AND model_year <=> ? LIMIT 1'
    );
    $stmt->bind_param('isi', $brandId, $name, $year);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        return (int)$row['id'];
    }

    $family = familyOf($name);
    $stmt   = db()->prepare(
        'INSERT INTO ' . tbl('models') . ' (brand_id, category, family, name, model_year, list_price)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('isssid', $brandId, $category, $family, $name, $year, $listPrice);
    $stmt->execute();
    $id = (int)db()->insert_id;
    $stmt->close();

    return $id;
}

/**
 * Marque un exemplaire vendu. Le geste le plus fréquent du magasin, donc le seul
 * endroit où il est écrit : stock.php et ventes.php appellent tous deux ceci.
 *
 * Le prix de vente laissé vide n'est pas une erreur : il signifie « au prix
 * catalogue », et les lectures retombent sur `list_price`. On ne recopie donc
 * pas le catalogue dans `sold_price`, sinon on ne saurait plus distinguer un
 * prix réellement négocié d'un prix jamais saisi.
 *
 * @return bool false si l'identifiant ne correspond à aucun vélo à vendre.
 */
function sellBike(int $bikeId, string $soldAt, ?float $soldPrice, ?string $customerName): bool
{
    if ($bikeId <= 0) {
        return false;
    }

    $customer = customerId($customerName);
    $date     = normalizeSaleDate($soldAt);

    $stmt = db()->prepare(
        'UPDATE ' . tbl('bikes') . '
         SET status = "vendu", sold_at = ?, sold_price = ?, customer_id = ?
         WHERE id = ? AND status <> "vendu"'
    );
    $stmt->bind_param('sdii', $date, $soldPrice, $customer, $bikeId);
    $stmt->execute();
    $touched = $stmt->affected_rows;
    $stmt->close();

    return $touched > 0;
}

/** Date de vente valide : aujourd'hui par défaut, jamais dans le futur. */
function normalizeSaleDate(string $value): string
{
    $ts = strtotime($value);

    if ($value === '' || $ts === false) {
        return date('Y-m-d');
    }

    return date('Y-m-d', min($ts, time()));
}

/** Vélos encore en rayon, prêts à être vendus. Groupés par catégorie pour la saisie. */
function sellableBikes(): array
{
    $sql = bikeSelect() . ' WHERE b.status IN ("stock","reserve","test")
                            ORDER BY m.category, br.name, m.name, b.size';

    return db()->query($sql)->fetch_all(MYSQLI_ASSOC);
}

/**
 * Coût d'achat d'un exemplaire : le prix réel s'il est connu, sinon une
 * estimation à partir du rabais fournisseur de la marque. Renvoie null quand
 * ni l'un ni l'autre n'est disponible — on ne devine pas.
 */
function purchaseCost(?float $purchasePrice, ?float $listPrice, ?float $discountRate): ?float
{
    if ($purchasePrice !== null) {
        return $purchasePrice;
    }

    if ($listPrice === null || $discountRate === null) {
        return null;
    }

    return round($listPrice * (1 - $discountRate / 100), 2);
}

/** Une ligne de vélo, jointe à son modèle, sa marque et son client. */
function bikeSelect(): string
{
    return 'SELECT b.*, m.name AS model_name, m.family, m.category, m.model_year,
                   br.name AS brand, br.discount_rate, c.name AS customer
            FROM ' . tbl('bikes') . ' b
            JOIN ' . tbl('models') . ' m  ON m.id = b.model_id
            JOIN ' . tbl('brands') . ' br ON br.id = m.brand_id
            LEFT JOIN ' . tbl('customers') . ' c ON c.id = b.customer_id';
}

/**
 * Rotation par famille : ventes sur la période, stock présent, couverture.
 *
 * La couverture est le nombre de mois de stock au rythme de vente observé sur la
 * période interrogée. C'est l'indicateur qui décide d'une pré-commande : au-delà
 * de six mois, on ne commande pas, on solde.
 *
 * Le stock, lui, est une photo à l'instant présent : il ne dépend pas de la
 * période. Interroger « mars à juin » compare donc les ventes de ce trimestre au
 * stock d'aujourd'hui — c'est bien ce qu'on veut pour décider quoi commander.
 *
 * @return array<int, array<string, mixed>>
 */
function familyRotation(string $from, string $to, float $monthsObserved): array
{
    $sql = 'SELECT m.category, m.family,
                   SUM(b.status = "vendu" AND b.sold_at BETWEEN ? AND ?)     AS sold,
                   SUM(b.status IN ("stock","reserve"))                      AS in_stock,
                   SUM(CASE WHEN b.status IN ("stock","reserve") THEN COALESCE(b.list_price, m.list_price, 0) ELSE 0 END) AS stock_value,
                   SUM(b.status IN ("stock","reserve") AND m.model_year <= ?) AS old_stock
            FROM ' . tbl('bikes') . ' b
            JOIN ' . tbl('models') . ' m ON m.id = b.model_id
            GROUP BY m.category, m.family
            HAVING sold > 0 OR in_stock > 0
            ORDER BY stock_value DESC';

    $oldYear = (int)date('Y') - 1;
    $stmt    = db()->prepare($sql);
    $stmt->bind_param('ssi', $from, $to, $oldYear);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $months = max(0.1, $monthsObserved);

    foreach ($rows as &$row) {
        $sold  = (int)$row['sold'];
        $stock = (int)$row['in_stock'];

        // Sans vente sur la période, la couverture est infinie : on la marque à
        // null plutôt que de la forcer à un nombre qui mentirait.
        $row['coverage'] = $sold > 0
            ? round($stock / ($sold / $months), 1)
            : ($stock > 0 ? null : 0.0);
    }

    return $rows;
}

/** Compteurs de tête pour le tableau de bord. */
function stockKpis(): array
{
    $sql = 'SELECT
              SUM(b.status IN ("stock","reserve"))                         AS units,
              SUM(CASE WHEN b.status IN ("stock","reserve")
                       THEN COALESCE(b.list_price, m.list_price, 0) END)   AS value,
              SUM(b.status IN ("stock","reserve") AND m.model_year <= ?)   AS old_units,
              SUM(b.status = "test")                                       AS test_units
            FROM ' . tbl('bikes') . ' b
            JOIN ' . tbl('models') . ' m ON m.id = b.model_id';

    $oldYear = (int)date('Y') - 1;
    $stmt    = db()->prepare($sql);
    $stmt->bind_param('i', $oldYear);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return [
        'units'      => (int)($row['units'] ?? 0),
        'value'      => (float)($row['value'] ?? 0),
        'old_units'  => (int)($row['old_units'] ?? 0),
        'test_units' => (int)($row['test_units'] ?? 0),
    ];
}

/** Ventes d'une année, agrégées par mois (1..12), trous inclus à zéro. */
function salesByMonth(int $year): array
{
    $stmt = db()->prepare(
        'SELECT MONTH(sold_at) AS m, COUNT(*) AS n, SUM(COALESCE(sold_price, list_price, 0)) AS ca
         FROM ' . tbl('bikes') . '
         WHERE status = "vendu" AND YEAR(sold_at) = ?
         GROUP BY MONTH(sold_at)'
    );
    $stmt->bind_param('i', $year);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $out = array_fill(1, 12, ['n' => 0, 'ca' => 0.0]);
    foreach ($rows as $row) {
        $out[(int)$row['m']] = ['n' => (int)$row['n'], 'ca' => (float)$row['ca']];
    }

    return $out;
}

/** Ventes par catégorie sur une plage de dates. Toutes les catégories, trous à zéro. */
function salesByCategory(string $from, string $to): array
{
    $stmt = db()->prepare(
        'SELECT m.category, COUNT(*) AS n
         FROM ' . tbl('bikes') . ' b
         JOIN ' . tbl('models') . ' m ON m.id = b.model_id
         WHERE b.status = "vendu" AND b.sold_at BETWEEN ? AND ?
         GROUP BY m.category'
    );
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $out = array_fill_keys(CATEGORIES, 0);
    foreach ($rows as $row) {
        $out[$row['category']] = (int)$row['n'];
    }

    return $out;
}

/** Volume et chiffre d'affaires sur une plage de dates. */
function salesTotals(string $from, string $to): array
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) AS n, COALESCE(SUM(COALESCE(sold_price, list_price, 0)), 0) AS ca
         FROM ' . tbl('bikes') . '
         WHERE status = "vendu" AND sold_at BETWEEN ? AND ?'
    );
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ['n' => (int)$row['n'], 'ca' => (float)$row['ca']];
}

/**
 * Part de la saison déjà écoulée, mesurée sur les ventes réelles de l'an dernier.
 *
 * Annualiser une vente en juillet en multipliant par 12/7 supposerait que les
 * vélos se vendent autant en janvier qu'en mai. C'est faux : le métier est
 * saisonnier. On mesure donc la part des ventes de l'année précédente réalisées
 * avant le même jour, et on s'en sert de règle de trois.
 *
 * Renvoie une fraction entre 0.05 et 1. Faute d'historique, on retombe sur le
 * prorata linéaire, faute de mieux.
 */
function seasonProgress(int $referenceYear, ?string $today = null): float
{
    $today = $today ?? date('Y-m-d');

    $stmt = db()->prepare(
        'SELECT
            SUM(DAYOFYEAR(sold_at) <= DAYOFYEAR(?)) AS until_now,
            COUNT(*)                                AS total
         FROM ' . tbl('bikes') . '
         WHERE status = "vendu" AND YEAR(sold_at) = ?'
    );
    $stmt->bind_param('si', $today, $referenceYear);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $total = (int)($row['total'] ?? 0);
    if ($total < 20) {
        return max(0.05, (int)date('z', strtotime($today)) / 365);
    }

    return max(0.05, min(1.0, (int)$row['until_now'] / $total));
}

/**
 * Répartition des tailles vendues pour une famille, sur les N dernières années.
 * Sert à éclater une quantité commandée en tailles, sans la deviner.
 *
 * @return array<string, int> taille => nombre vendu, décroissant
 */
function sizeMix(string $category, string $family, int $sinceYear): array
{
    $stmt = db()->prepare(
        'SELECT b.size, COUNT(*) AS n
         FROM ' . tbl('bikes') . ' b
         JOIN ' . tbl('models') . ' m ON m.id = b.model_id
         WHERE b.status = "vendu" AND m.category = ? AND m.family = ?
           AND YEAR(b.sold_at) >= ? AND b.size IS NOT NULL AND b.size <> ""
         GROUP BY b.size
         ORDER BY n DESC'
    );
    $stmt->bind_param('ssi', $category, $family, $sinceYear);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $mix = [];
    foreach ($rows as $row) {
        $mix[(string)$row['size']] = (int)$row['n'];
    }

    return $mix;
}

/**
 * Proposition de pré-commande, famille par famille.
 *
 *   demande attendue  = ventes de l'année, annualisées via la saisonnalité réelle
 *   stock résiduel    = ce qui restera en rayon quand la saison prochaine s'ouvrira
 *   proposition       = demande attendue − stock résiduel   (jamais négative)
 *
 * Le raisonnement corrige exactement l'erreur du fichier MY27 : la pré-commande
 * 2026 avait été calée sur la demande sans soustraire les 48 vélos reportés.
 *
 * @return array<int, array<string, mixed>>
 */
function preorderSuggestions(int $year, ?string $today = null): array
{
    $today    = $today ?? date('Y-m-d');
    $progress = seasonProgress($year - 1, $today);

    // La pré-commande se raisonne sur l'année en cours, quelle que soit la période
    // que l'utilisateur consulte ailleurs : c'est la saison qui décide, pas l'écran.
    $rows = familyRotation($year . '-01-01', $today, max(0.1, $progress * 12));

    foreach ($rows as &$row) {
        $sold    = (int)$row['sold'];
        $inStock = (int)$row['in_stock'];

        // Demande annuelle attendue, déduite du rythme observé et de la saison.
        $expected = (int)round($sold / $progress);

        // Ce que le reste de la saison devrait encore absorber.
        $remaining = max(0, $expected - $sold);

        // Ce qui restera donc en rayon au moment de la livraison MY suivante.
        $residual = max(0, $inStock - $remaining);

        $row['expected']   = $expected;
        $row['residual']   = $residual;
        $row['suggested']  = max(0, $expected - $residual);
        $row['size_mix']   = sizeMix((string)$row['category'], (string)$row['family'], $year - 2);
    }

    usort($rows, static function (array $a, array $b): int {
        return [$a['category'], -$a['expected']] <=> [$b['category'], -$b['expected']];
    });

    return $rows;
}

/**
 * Éclate une quantité en tailles, au prorata du mix de ventes observé.
 *
 * Méthode du plus grand reste : les reliquats d'arrondi vont aux tailles dont
 * la part fractionnaire est la plus élevée, et non à la taille la plus vendue.
 * Donner systématiquement le reste au gros volume gonflerait la taille dominante
 * à chaque commande et affamerait les tailles rares — exactement le déséquilibre
 * qu'on cherche à corriger.
 *
 * @param  array<string, int> $mix
 * @return array<string, int>
 */
function splitBySize(int $qty, array $mix): array
{
    $total = array_sum($mix);
    if ($qty <= 0 || $total === 0) {
        return [];
    }

    $out        = [];
    $remainders = [];
    $allocated  = 0;

    foreach ($mix as $size => $n) {
        $exact            = $qty * $n / $total;
        $out[$size]       = (int)floor($exact);
        $remainders[$size] = $exact - floor($exact);
        $allocated       += $out[$size];
    }

    arsort($remainders);

    foreach (array_keys($remainders) as $size) {
        if ($allocated >= $qty) {
            break;
        }
        $out[$size]++;
        $allocated++;
    }

    return array_filter($out);
}

/** Formatage monétaire suisse : 12'999 CHF. */
function chf(?float $amount, bool $withUnit = true): string
{
    if ($amount === null) {
        return '—';
    }

    return number_format($amount, 0, '.', "'") . ($withUnit ? ' CHF' : '');
}

/** Âge en jours d'un vélo entré en stock, null si la date d'entrée est inconnue. */
function stockAgeDays(?string $enteredAt): ?int
{
    if (!$enteredAt) {
        return null;
    }

    $entered = strtotime($enteredAt);
    if (!$entered) {
        return null;
    }

    return (int)floor((time() - $entered) / 86400);
}
