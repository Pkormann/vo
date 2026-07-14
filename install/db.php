<?php
/**
 * Étape 2 — crée les tables du projet et le premier compte owner.
 *
 * Accès : install/db.php?token=<INSTALL_TOKEN>
 * Idempotent : relançable sans risque (CREATE TABLE IF NOT EXISTS).
 * Le compte owner n'est proposé que s'il n'existe encore aucun utilisateur.
 */

require_once __DIR__ . '/../config/install.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/layout.php';

requireInstallToken();

$connect = db();
$log     = [];
$error   = '';
$created = false;

/**
 * Schéma du projet. Toute évolution passe par un script de migration dédié,
 * jamais par une modification de ce tableau (les tables existent déjà en prod).
 */
$schema = [
    tbl('users') => 'CREATE TABLE IF NOT EXISTS ' . tbl('users') . ' (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        username         VARCHAR(100) NOT NULL UNIQUE,
        role             ENUM("owner","admin","user") NOT NULL DEFAULT "user",
        password         VARCHAR(255) NOT NULL,
        email            VARCHAR(190) NULL,
        remember_token   CHAR(64)     NULL,
        remember_expires DATETIME     NULL,
        created_at       DATETIME     DEFAULT CURRENT_TIMESTAMP,
        last_login       DATETIME     NULL,
        INDEX idx_remember (remember_token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

    tbl('login_attempts') => 'CREATE TABLE IF NOT EXISTS ' . tbl('login_attempts') . ' (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        ip         VARCHAR(45)  NOT NULL,
        username   VARCHAR(100) NOT NULL,
        success    TINYINT(1)   NOT NULL DEFAULT 0,
        user_agent VARCHAR(255) NULL,
        device     VARCHAR(20)  NULL,
        os         VARCHAR(50)  NULL,
        created_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ip_date       (ip, created_at),
        INDEX idx_username_date (username, created_at),
        INDEX idx_date          (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

    // --- Domaine magasin -------------------------------------------------
    // Le vélo est un exemplaire physique unique : il entre en stock, puis il
    // est vendu. Stock et ventes sont deux vues de la même table (vo_bikes),
    // filtrées sur le statut. C'est ce qui rend la rotation calculable.

    tbl('brands') => 'CREATE TABLE IF NOT EXISTS ' . tbl('brands') . ' (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        name          VARCHAR(80)   NOT NULL UNIQUE,
        discount_rate DECIMAL(5,2)  NULL COMMENT "rabais fournisseur en %, sert à estimer le prix d\'achat",
        active        TINYINT(1)    NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

    tbl('models') => 'CREATE TABLE IF NOT EXISTS ' . tbl('models') . ' (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        brand_id   INT          NOT NULL,
        category   VARCHAR(20)  NOT NULL COMMENT "Route, Gravel, VTT, E-bikes, Cargo, Urbain, Kids",
        family     VARCHAR(60)  NOT NULL COMMENT "SuperSix, Topstone, Addict RC… regroupe les déclinaisons",
        name       VARCHAR(140) NOT NULL,
        model_year SMALLINT     NULL,
        list_price DECIMAL(10,2) NULL,
        UNIQUE KEY uq_model (brand_id, name, model_year),
        INDEX idx_category (category),
        INDEX idx_family   (family),
        CONSTRAINT fk_model_brand FOREIGN KEY (brand_id) REFERENCES ' . tbl('brands') . ' (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

    tbl('customers') => 'CREATE TABLE IF NOT EXISTS ' . tbl('customers') . ' (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        name       VARCHAR(160) NOT NULL UNIQUE,
        email      VARCHAR(190) NULL,
        phone      VARCHAR(40)  NULL,
        notes      VARCHAR(255) NULL,
        created_at DATETIME     DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

    tbl('bikes') => 'CREATE TABLE IF NOT EXISTS ' . tbl('bikes') . ' (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        model_id       INT          NOT NULL,
        size           VARCHAR(10)  NULL,
        color          VARCHAR(60)  NULL,
        status         ENUM("stock","vendu","test","reserve") NOT NULL DEFAULT "stock",
        entered_at     DATE         NULL COMMENT "réception : NULL pour les ventes historiques importées",
        sold_at        DATE         NULL COMMENT "date de la vente, y compris pour un vélo réservé",
        delivery_at    DATE         NULL COMMENT "remise prévue au client, pour un vélo réservé",
        list_price     DECIMAL(10,2) NULL COMMENT "prix catalogue figé à la réception",
        purchase_price DECIMAL(10,2) NULL COMMENT "prix d\'achat réel ; sinon estimé via brands.discount_rate",
        sold_price     DECIMAL(10,2) NULL,
        customer_id    INT          NULL,
        notes          VARCHAR(255) NULL,
        import_key     CHAR(32)     NULL UNIQUE COMMENT "empreinte de la ligne source : rend l\'import rejouable",
        created_at     DATETIME     DEFAULT CURRENT_TIMESTAMP,
        updated_at     DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_status    (status),
        INDEX idx_sold_at   (sold_at),
        INDEX idx_entered   (entered_at),
        INDEX idx_model     (model_id),
        CONSTRAINT fk_bike_model    FOREIGN KEY (model_id)    REFERENCES ' . tbl('models') . ' (id),
        CONSTRAINT fk_bike_customer FOREIGN KEY (customer_id) REFERENCES ' . tbl('customers') . ' (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

    // Journal des actions métier. Le journal des *connexions* vit à part
    // (vo_login_attempts) : on ne mélange pas la sécurité et l'exploitation.
    tbl('activity') => 'CREATE TABLE IF NOT EXISTS ' . tbl('activity') . ' (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        username   VARCHAR(100) NULL COMMENT "auteur au moment de l\'action",
        action     VARCHAR(40)  NOT NULL COMMENT "vente, reservation, suppression, import…",
        entity     VARCHAR(40)  NULL COMMENT "objet touché : velo, prompt, precommande…",
        entity_id  INT          NULL,
        detail     VARCHAR(255) NULL COMMENT "de quoi relire la ligne sans jointure",
        ip         VARCHAR(45)  NULL,
        created_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_date        (created_at),
        INDEX idx_user_date   (username, created_at),
        INDEX idx_action_date (action, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

    // Inventaire de contrôle : on fige la liste des vélos que la base croit
    // présents, puis on pointe le rayon. L'écart entre les deux est le sujet.
    tbl('inventories') => 'CREATE TABLE IF NOT EXISTS ' . tbl('inventories') . ' (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        label      VARCHAR(120) NULL,
        author     VARCHAR(100) NULL,
        started_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
        closed_at  DATETIME     NULL COMMENT "NULL = inventaire en cours",
        INDEX idx_closed (closed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

    // Une ligne par vélo attendu au moment où l\'inventaire est ouvert. Le
    // snapshot est figé : un vélo vendu pendant le pointage reste dans la liste,
    // sinon on ne saurait plus ce qu\'on a compté.
    tbl('inventory_items') => 'CREATE TABLE IF NOT EXISTS ' . tbl('inventory_items') . ' (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        inventory_id INT          NOT NULL,
        bike_id      INT          NOT NULL,
        seen         TINYINT(1)   NULL COMMENT "NULL = pas encore pointé · 1 = vu · 0 = introuvable",
        seen_at      DATETIME     NULL,
        note         VARCHAR(255) NULL,
        UNIQUE KEY uq_item (inventory_id, bike_id),
        INDEX idx_inventory (inventory_id),
        CONSTRAINT fk_item_inventory FOREIGN KEY (inventory_id)
            REFERENCES ' . tbl('inventories') . ' (id) ON DELETE CASCADE,
        CONSTRAINT fk_item_bike FOREIGN KEY (bike_id)
            REFERENCES ' . tbl('bikes') . ' (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

    // Réglages éditables depuis l'application : aujourd'hui le prompt d'analyse.
    // Une valeur absente signifie « le défaut du code fait foi » — on ne recopie
    // donc pas le défaut en base à l'installation.
    tbl('settings') => 'CREATE TABLE IF NOT EXISTS ' . tbl('settings') . ' (
        name       VARCHAR(60) PRIMARY KEY,
        value      MEDIUMTEXT  NOT NULL,
        updated_at DATETIME    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

    // Chaque enregistrement d'un réglage archive la version précédente : on peut
    // donc toujours revenir en arrière, y compris longtemps après avoir écrasé
    // un texte par mégarde. Le défaut du code, lui, reste restaurable par nature.
    tbl('settings_history') => 'CREATE TABLE IF NOT EXISTS ' . tbl('settings_history') . ' (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        name       VARCHAR(60)  NOT NULL,
        value      MEDIUMTEXT   NOT NULL,
        author     VARCHAR(100) NULL,
        created_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_name_date (name, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

    // La pré-commande se décide par famille (« combien de Topstone en 2027 ? »),
    // pas par référence catalogue : les modèles MY27 n'existent pas encore quand
    // la décision se prend. Pas de FK vers models, donc, c'est volontaire.
    tbl('preorders') => 'CREATE TABLE IF NOT EXISTS ' . tbl('preorders') . ' (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        season     SMALLINT     NOT NULL COMMENT "millésime visé, ex. 2027",
        category   VARCHAR(20)  NOT NULL,
        family     VARCHAR(60)  NOT NULL,
        size       VARCHAR(10)  NOT NULL DEFAULT "" COMMENT "vide = quantité totale de la famille",
        qty        INT          NOT NULL DEFAULT 0,
        suggested  INT          NULL COMMENT "ce que l\'outil proposait : garde la trace de l\'écart de jugement",
        note       VARCHAR(255) NULL,
        updated_at DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_preorder (season, category, family, size),
        INDEX idx_season (season)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
];

try {
    foreach ($schema as $table => $sql) {
        $existed = $connect->query('SHOW TABLES LIKE "' . $connect->real_escape_string($table) . '"')->num_rows > 0;
        $connect->query($sql);
        $log[] = ['table' => $table, 'state' => $existed ? 'déjà présente' : 'créée'];
    }
} catch (mysqli_sql_exception $e) {
    $error = 'Création des tables impossible : ' . $e->getMessage();
}

$userCount = $error === ''
    ? (int)$connect->query('SELECT COUNT(*) AS n FROM ' . tbl('users'))->fetch_assoc()['n']
    : 0;

// Premier owner : possible uniquement tant que la table users est vide.
if ($error === '' && $userCount === 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || strlen($password) < 8) {
        $error = 'Identifiant requis, mot de passe d\'au moins 8 caractères.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $connect->prepare(
            'INSERT INTO ' . tbl('users') . ' (username, role, password) VALUES (?, "owner", ?)'
        );
        $stmt->bind_param('ss', $username, $hash);
        $stmt->execute();
        $stmt->close();

        $created   = true;
        $userCount = 1;
    }
}

renderHeader('Installation — base de données', ['css' => ['login'], 'nav' => false, 'chrome' => false, 'bodyClass' => 'login-body']);
?>

<div class="login-card install-card">
    <div class="login-head">
        <span class="brand-mark">VO</span>
        <h1 class="login-title">Base de données</h1>
        <p class="login-sub">Étape 2 sur 2</p>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($log): ?>
        <ul class="install-log">
            <?php foreach ($log as $entry): ?>
                <li>
                    <span class="mono"><?= e($entry['table']) ?></span>
                    <span class="muted"><?= e($entry['state']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($created): ?>
        <div class="alert alert-success">
            <strong>Compte owner créé.</strong> Installation terminée.
        </div>
        <a class="btn btn-block" href="<?= e(url('login.php')) ?>">Aller à la connexion</a>

    <?php elseif ($userCount === 0 && $error === ''): ?>
        <p class="muted text-sm">Aucun utilisateur : crée le premier compte owner.</p>

        <form method="post" autocomplete="off">
            <div class="field">
                <label class="label" for="username">Identifiant</label>
                <input class="input" type="text" id="username" name="username" required
                       value="<?= e($_POST['username'] ?? 'Paul') ?>">
            </div>

            <div class="field">
                <label class="label" for="password">Mot de passe</label>
                <input class="input" type="password" id="password" name="password" required
                       minlength="8" autocomplete="new-password">
            </div>

            <button type="submit" class="btn btn-block">Créer le compte owner</button>
        </form>

    <?php elseif ($error === ''): ?>
        <div class="alert alert-success">
            Tables en place, <?= (int)$userCount ?> utilisateur(s) enregistré(s). Rien à faire.
        </div>
        <a class="btn btn-block" href="<?= e(url('login.php')) ?>">Aller à la connexion</a>
    <?php endif; ?>
</div>

<?php renderFooter(['chrome' => false]); ?>
