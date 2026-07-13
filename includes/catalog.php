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

/**
 * Glossaire — le vocabulaire de l'application, défini une seule fois.
 *
 * Chaque terme obscur d'un en-tête de colonne s'explique ici, et nulle part
 * ailleurs : une définition recopiée dans trois pages finit par en dire trois
 * choses différentes. Les pages appellent hint().
 */
const GLOSSAIRE = [
    'famille' => "Regroupe toutes les déclinaisons d'un même vélo : Addict RC 10, 20, 30, Pro et Team "
               . "forment la famille « Addict RC ». C'est l'unité de décision : on ne pré-commande pas "
               . "une référence isolée, on décide d'une famille, puis on la répartit en tailles.",

    'millesime' => "L'année du modèle (MY), pas celle de l'achat. Un millésime en retard se décote dès "
                 . "que le suivant arrive en magasin.",

    'millesimes_anciens' => "Vélos en rayon dont le millésime a déjà un an ou plus : ce sont eux qui "
                          . "perdent de la valeur.",

    'mois_de_stock' => "Combien de temps le stock actuel tiendrait au rythme de vente observé. "
                     . "Au-delà de 6 mois la famille dort (ne pas recommander, écouler d'abord) ; "
                     . "en dessous de 2 mois elle sera bientôt épuisée.",

    'age' => "Nombre de jours passés en rayon depuis la réception du vélo.",

    'delai' => "Nombre de jours passés en rayon entre la réception et la vente.",

    'verdict' => "dort = ne pas recommander · bientôt épuisé = commander vite · sain = rien à faire.",

    'vendus' => "Nombre de vélos vendus pendant la période interrogée.",

    'en_stock' => "Nombre de vélos actuellement en rayon — aujourd'hui, indépendamment de la période.",

    'valeur_stock' => "Valeur du stock au prix catalogue, en francs.",

    'demande_attendue' => "Ce que la famille devrait vendre sur l'année entière, déduit du rythme "
                        . "observé et corrigé de la saisonnalité réelle (on ne vend pas autant en "
                        . "janvier qu'en mai).",

    'restera' => "Ce qu'il restera en rayon à l'ouverture de la prochaine saison, une fois absorbées "
               . "les ventes du reste de l'année. C'est ce report qu'il faut soustraire — l'oubli qui "
               . "a fait démarrer 2026 avec 90 vélos route pour 45 de demande annuelle.",

    'proposition' => "Ce que l'outil calcule : demande attendue moins ce qui restera en rayon. "
                   . "Une suggestion, pas un ordre.",

    'a_commander' => "Ce que tu retiens, toi. Modifie librement : l'écart avec la proposition est "
                   . "conservé, pour pouvoir juger la méthode l'an prochain.",

    'rabais' => "Le pourcentage que le fournisseur retranche au prix catalogue.",

    'a_coute' => "Prix catalogue moins le rabais : ce que ces vélos t'ont coûté. C'est la trésorerie "
               . "immobilisée dans le magasin.",

    'valeur_rayon' => "Ce que le stock vaut si tout part au prix affiché en magasin.",

    'marge_potentielle' => "Différence entre la valeur en rayon et ce que le stock a coûté : "
                         . "ce qu'il rapporterait s'il partait entièrement au prix affiché.",
];

/**
 * En-tête avec infobulle. Le libellé affiché peut différer de la clé du glossaire.
 *
 * Attention : l'infobulle native ne s'affiche pas au doigt sur mobile. Elle est
 * donc un confort, jamais le seul endroit où une information vitale est dite —
 * les légendes sous les tableaux restent nécessaires.
 */
function hint(string $key, ?string $label = null): string
{
    $label = $label ?? ucfirst(str_replace('_', ' ', $key));

    if (!isset(GLOSSAIRE[$key])) {
        return e($label);
    }

    return '<span class="hint" title="' . e(GLOSSAIRE[$key]) . '">' . e($label) . '</span>';
}

/**
 * Statuts d'un exemplaire.
 *
 * `reserve` est le statut le plus subtil : le vélo est **vendu** — un client l'a
 * pris, il ne se revendra pas — mais il n'a pas encore été remis, parce que la
 * livraison est dans quelques semaines. Il occupe donc physiquement le magasin
 * tout en étant commercialement parti.
 */
const STATUSES = [
    'stock'   => 'En stock',
    'reserve' => 'Réservé',
    'test'    => 'Test / démo',
    'vendu'   => 'Vendu',
];

/**
 * Statuts qui comptent comme une vente.
 *
 * Un vélo réservé compte dès la réservation, pas à la remise : sinon l'outil de
 * pré-commande croirait avoir en rayon un vélo déjà promis, et commanderait trop
 * peu. La date de remise (`delivery_at`) n'est qu'une information logistique.
 */
const STATUSES_SOLD = ['vendu', 'reserve'];

/** Statuts réellement disponibles à la vente. Un réservé n'en est plus. */
const STATUSES_AVAILABLE = ['stock'];

/** Statuts qui occupent physiquement le magasin (le réservé y est encore). */
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

/**
 * Réserve un exemplaire : le client l'a pris, la remise est plus tard.
 *
 * C'est une vente, datée d'aujourd'hui — pas une mise de côté. Le vélo sort donc
 * du stock disponible immédiatement. `delivery_at` porte la date de remise, qui
 * est une information logistique et n'entre dans aucun calcul commercial.
 *
 * @return bool false si le vélo n'existe pas ou n'est plus disponible.
 */
function reserveBike(int $bikeId, ?string $deliveryAt, ?float $price, ?string $customerName): bool
{
    if ($bikeId <= 0) {
        return false;
    }

    $customer = customerId($customerName);
    $soldAt   = date('Y-m-d');
    $delivery = ($deliveryAt !== null && $deliveryAt !== '' && strtotime($deliveryAt))
        ? date('Y-m-d', strtotime($deliveryAt))
        : null;

    $stmt = db()->prepare(
        'UPDATE ' . tbl('bikes') . '
         SET status = "reserve", sold_at = ?, delivery_at = ?, sold_price = ?, customer_id = ?
         WHERE id = ? AND status IN ("stock", "test")'
    );
    $stmt->bind_param('ssdii', $soldAt, $delivery, $price, $customer, $bikeId);
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

/**
 * Doublons probables : un vélo encore « en rayon » alors qu'un vélo identique
 * (même modèle, même taille) figure déjà comme vendu.
 *
 * Ce cas vient de la reprise des Excel : le vélo était vendu dans le fichier des
 * ventes, mais n'avait pas été retiré du fichier de stock. Il existe donc deux
 * fois en base. Le marquer vendu créerait une deuxième vente — il faut supprimer
 * la ligne de stock.
 *
 * C'est une suspicion, pas une certitude : le magasin peut légitimement avoir un
 * second exemplaire identique en rayon. La décision reste humaine, on ne supprime
 * jamais tout seul.
 *
 * @return array<int, array<string, mixed>>
 */
function suspectedDuplicates(): array
{
    $sql = 'SELECT b.id, b.size, b.color, b.status, b.entered_at,
                   COALESCE(b.list_price, m.list_price) AS list_price,
                   m.name AS model_name, m.category, m.model_year, br.name AS brand,
                   (SELECT COUNT(*) FROM ' . tbl('bikes') . ' v
                     WHERE v.status = "vendu" AND v.model_id = b.model_id
                       AND v.size <=> b.size)                       AS sold_count,
                   (SELECT MAX(v.sold_at) FROM ' . tbl('bikes') . ' v
                     WHERE v.status = "vendu" AND v.model_id = b.model_id
                       AND v.size <=> b.size)                       AS last_sold_at
            FROM ' . tbl('bikes') . ' b
            JOIN ' . tbl('models') . ' m  ON m.id = b.model_id
            JOIN ' . tbl('brands') . ' br ON br.id = m.brand_id
            WHERE b.status IN ("stock", "reserve", "test")
            HAVING sold_count > 0
            ORDER BY last_sold_at DESC';

    return db()->query($sql)->fetch_all(MYSQLI_ASSOC);
}

/** Supprime un exemplaire. Réservé aux erreurs de saisie et aux doublons d'import. */
function deleteBike(int $bikeId): bool
{
    if ($bikeId <= 0) {
        return false;
    }

    $stmt = db()->prepare('DELETE FROM ' . tbl('bikes') . ' WHERE id = ?');
    $stmt->bind_param('i', $bikeId);
    $stmt->execute();
    $deleted = $stmt->affected_rows;
    $stmt->close();

    return $deleted > 0;
}

/** Vélos encore en rayon, prêts à être vendus. Groupés par catégorie pour la saisie. */
function sellableBikes(): array
{
    $sql = bikeSelect() . ' WHERE b.status IN ("stock","test")
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
                   SUM(b.status IN ("vendu","reserve") AND b.sold_at BETWEEN ? AND ?) AS sold,
                   SUM(b.status = "stock")                                            AS in_stock,
                   SUM(CASE WHEN b.status = "stock" THEN COALESCE(b.list_price, m.list_price, 0) ELSE 0 END) AS stock_value,
                   SUM(b.status = "stock" AND m.model_year <= ?)                      AS old_stock
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
              SUM(b.status = "stock")                                      AS units,
              SUM(CASE WHEN b.status = "stock"
                       THEN COALESCE(b.list_price, m.list_price, 0) END)   AS value,
              SUM(b.status = "stock" AND m.model_year <= ?)                AS old_units,
              SUM(b.status = "test")                                       AS test_units,
              SUM(b.status = "reserve")                                    AS reserved,
              SUM(b.status = "reserve" AND b.sold_at IS NULL)              AS reserved_undated
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
        'reserved'   => (int)($row['reserved'] ?? 0),
        // Un réservé sans date de vente ne compte nulle part : il faut le corriger.
        'reserved_undated' => (int)($row['reserved_undated'] ?? 0),
    ];
}

/** Ventes d'une année, agrégées par mois (1..12), trous inclus à zéro. */
function salesByMonth(int $year): array
{
    $stmt = db()->prepare(
        'SELECT MONTH(sold_at) AS m, COUNT(*) AS n, SUM(COALESCE(sold_price, list_price, 0)) AS ca
         FROM ' . tbl('bikes') . '
         WHERE status IN ("vendu","reserve") AND YEAR(sold_at) = ?
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
         WHERE b.status IN ("vendu","reserve") AND b.sold_at BETWEEN ? AND ?
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
         WHERE status IN ("vendu","reserve") AND sold_at BETWEEN ? AND ?'
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
         WHERE status IN ("vendu","reserve") AND YEAR(sold_at) = ?'
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

/**
 * Ventes par taille et par catégorie sur une période, avec le stock actuel en
 * regard. Les tailles alphabétiques (S/M/L) et numériques (51/54) coexistent
 * selon les modèles : on ne les mélange donc pas, on les rend telles quelles.
 *
 * @return array<int, array<string, mixed>>
 */
function salesBySize(string $from, string $to): array
{
    $stmt = db()->prepare(
        'SELECT m.category,
                COALESCE(NULLIF(b.size, ""), "?") AS size,
                SUM(b.status IN ("vendu","reserve") AND b.sold_at BETWEEN ? AND ?) AS sold,
                SUM(b.status = "stock")                                            AS in_stock
         FROM ' . tbl('bikes') . ' b
         JOIN ' . tbl('models') . ' m ON m.id = b.model_id
         GROUP BY m.category, size
         HAVING sold > 0 OR in_stock > 0
         ORDER BY m.category, sold DESC'
    );
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/** Réglage éditable, ou son défaut si rien n'a été enregistré. */
function setting(string $name, string $default = ''): string
{
    $stmt = db()->prepare('SELECT value FROM ' . tbl('settings') . ' WHERE name = ? LIMIT 1');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row['value'] ?? $default;
}

/**
 * Enregistre un réglage, en archivant d'abord la version qu'il remplace.
 *
 * L'archivage précède l'écriture : même une bêtise (tout sélectionner, tout
 * effacer, enregistrer) laisse derrière elle la version d'avant. Une valeur vide
 * supprime le réglage — le défaut du code reprend alors la main, et lui n'est
 * jamais perdu puisqu'il vit dans le dépôt.
 *
 * @return bool true si quelque chose a changé (rien n'est archivé pour rien).
 */
function saveSetting(string $name, string $value, ?string $author = null): bool
{
    $current = null;

    $stmt = db()->prepare('SELECT value FROM ' . tbl('settings') . ' WHERE name = ? LIMIT 1');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $current = $row['value'];
    }

    // Réenregistrer un texte identique ne crée pas une version de plus.
    if ($current === $value) {
        return false;
    }

    if ($current !== null) {
        $stmt = db()->prepare(
            'INSERT INTO ' . tbl('settings_history') . ' (name, value, author) VALUES (?, ?, ?)'
        );
        $stmt->bind_param('sss', $name, $current, $author);
        $stmt->execute();
        $stmt->close();

        pruneSettingHistory($name);
    }

    if (trim($value) === '') {
        $stmt = db()->prepare('DELETE FROM ' . tbl('settings') . ' WHERE name = ?');
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $stmt->close();

        return true;
    }

    $stmt = db()->prepare(
        'INSERT INTO ' . tbl('settings') . ' (name, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)'
    );
    $stmt->bind_param('ss', $name, $value);
    $stmt->execute();
    $stmt->close();

    return true;
}

/** Ne garde que les versions récentes : un historique infini n'aide personne. */
function pruneSettingHistory(string $name, int $keep = 30): void
{
    $stmt = db()->prepare(
        'DELETE h FROM ' . tbl('settings_history') . ' h
         JOIN (
            SELECT id FROM ' . tbl('settings_history') . '
            WHERE name = ?
            ORDER BY created_at DESC, id DESC
            LIMIT 18446744073709551615 OFFSET ?
         ) old ON old.id = h.id'
    );
    $stmt->bind_param('si', $name, $keep);
    $stmt->execute();
    $stmt->close();
}

/**
 * Versions archivées d'un réglage, la plus récente d'abord.
 *
 * @return array<int, array<string, mixed>>
 */
function settingHistory(string $name): array
{
    $stmt = db()->prepare(
        'SELECT id, value, author, created_at
         FROM ' . tbl('settings_history') . '
         WHERE name = ?
         ORDER BY created_at DESC, id DESC'
    );
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/** Contenu d'une version archivée, ou null si elle n'existe pas (ou plus). */
function settingVersion(int $id): ?string
{
    $stmt = db()->prepare('SELECT value FROM ' . tbl('settings_history') . ' WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row['value'] ?? null;
}

/**
 * Jeux de données exportables.
 *
 * Le premier, `complet`, est l'export intégral : une ligne par exemplaire, tous
 * statuts et toutes dates confondus, sans filtre de période. C'est celui qu'on
 * donne à un modèle d'analyse — il contient tout, à lui de recouper.
 *
 * Les suivants sont des agrégats prêts à lire, pour l'humain pressé ou pour
 * recouper rapidement un chiffre.
 */
function exportDatasets(): array
{
    return [
        'complet' => [
            'label' => 'Export complet',
            'desc'  => 'Une ligne par vélo — stock et ventes, tout l\'historique, toutes les colonnes. '
                     . 'C\'est le fichier à donner à l\'IA : il contient tout, sans filtre de période.',
            'sql'   => 'SELECT b.id,
                               m.category   AS categorie,
                               m.family     AS famille,
                               br.name      AS marque,
                               m.name       AS modele,
                               m.model_year AS millesime,
                               b.size       AS taille,
                               b.color      AS couleur,
                               b.status     AS statut,
                               b.entered_at AS entre_le,
                               b.sold_at    AS vendu_le,
                               COALESCE(b.list_price, m.list_price) AS prix_catalogue,
                               b.purchase_price AS prix_achat,
                               b.sold_price     AS prix_vente,
                               br.discount_rate AS rabais_marque_pct,
                               DATEDIFF(COALESCE(b.sold_at, CURDATE()), b.entered_at) AS jours_en_rayon,
                               c.name       AS client,
                               b.notes      AS remarque
                        FROM ' . tbl('bikes') . ' b
                        JOIN ' . tbl('models') . ' m  ON m.id = b.model_id
                        JOIN ' . tbl('brands') . ' br ON br.id = m.brand_id
                        LEFT JOIN ' . tbl('customers') . ' c ON c.id = b.customer_id
                        ORDER BY b.sold_at IS NULL, b.sold_at, m.category, m.family',
            'dated' => false,
        ],

        'ventes' => [
            'label' => 'Ventes détaillées',
            'desc'  => 'Une ligne par vélo vendu sur la période : date, catégorie, famille, modèle, taille, prix, client.',
            'sql'   => 'SELECT b.sold_at AS date, m.category AS categorie, m.family AS famille,
                               br.name AS marque, m.name AS modele, b.size AS taille,
                               m.model_year AS millesime,
                               COALESCE(b.sold_price, b.list_price) AS prix,
                               c.name AS client
                        FROM ' . tbl('bikes') . ' b
                        JOIN ' . tbl('models') . ' m  ON m.id = b.model_id
                        JOIN ' . tbl('brands') . ' br ON br.id = m.brand_id
                        LEFT JOIN ' . tbl('customers') . ' c ON c.id = b.customer_id
                        WHERE b.status = "vendu" AND b.sold_at BETWEEN ? AND ?
                        ORDER BY b.sold_at',
            'dated' => true,
        ],

        'stock' => [
            'label' => 'Stock actuel',
            'desc'  => 'Une ligne par vélo en rayon : catégorie, famille, taille, millésime, prix, âge en jours.',
            'sql'   => 'SELECT m.category AS categorie, m.family AS famille, br.name AS marque,
                               m.name AS modele, b.size AS taille, m.model_year AS millesime,
                               COALESCE(b.list_price, m.list_price) AS prix_catalogue,
                               b.status AS statut, b.entered_at AS entre_le,
                               DATEDIFF(CURDATE(), b.entered_at) AS age_jours
                        FROM ' . tbl('bikes') . ' b
                        JOIN ' . tbl('models') . ' m  ON m.id = b.model_id
                        JOIN ' . tbl('brands') . ' br ON br.id = m.brand_id
                        WHERE b.status IN ("stock","reserve","test")
                        ORDER BY m.category, m.family, b.size',
            'dated' => false,
        ],

        'rotation' => [
            'label' => 'Rotation par famille',
            'desc'  => 'Le tableau de décision : vendus sur la période, stock, mois de stock, valeur immobilisée.',
            'sql'   => null,   // construit en PHP, à partir de familyRotation()
            'dated' => true,
        ],

        'tailles' => [
            'label' => 'Ventes par taille',
            'desc'  => 'Croisement catégorie × taille : ce qui se vend, ce qui reste. La clé des pré-commandes.',
            'sql'   => null,
            'dated' => true,
        ],

        'mensuel' => [
            'label' => 'Ventes par mois et catégorie',
            'desc'  => 'La saisonnalité, année par année : indispensable pour projeter la fin de saison.',
            'sql'   => 'SELECT YEAR(b.sold_at) AS annee, MONTH(b.sold_at) AS mois,
                               m.category AS categorie, COUNT(*) AS vendus,
                               SUM(COALESCE(b.sold_price, b.list_price, 0)) AS chiffre_affaires
                        FROM ' . tbl('bikes') . ' b
                        JOIN ' . tbl('models') . ' m ON m.id = b.model_id
                        WHERE b.status = "vendu" AND b.sold_at IS NOT NULL
                        GROUP BY annee, mois, categorie
                        ORDER BY annee, mois, categorie',
            'dated' => false,
        ],
    ];
}

/** Envoie un tableau de lignes en CSV et coupe court. */
function streamCsv(string $filename, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');

    // BOM : sans lui, Excel ouvre l'UTF-8 en latin-1 et massacre les accents.
    fwrite($out, "\xEF\xBB\xBF");

    if ($rows) {
        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
    }

    fclose($out);
    exit;
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
