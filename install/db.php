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
