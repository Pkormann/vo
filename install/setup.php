<?php
/**
 * Étape 1 — écrit config/secrets.php sur le serveur, sans connexion SSH/SFTP.
 *
 * Accès : install/setup.php?token=<INSTALL_TOKEN>
 * Les identifiants saisis sont testés avant écriture : pas de config invalide sur le disque.
 * Refuse d'écraser une configuration existante sans ?force=1.
 */

require_once __DIR__ . '/../config/install.php';
require_once __DIR__ . '/../includes/layout.php';

requireInstallToken();

const SECRETS_PATH = __DIR__ . '/../config/secrets.php';

$exists = is_file(SECRETS_PATH);
$force  = isset($_GET['force']);
$error  = '';
$done   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host   = trim((string)($_POST['host'] ?? ''));
    $user   = trim((string)($_POST['user'] ?? ''));
    $pass   = (string)($_POST['pass'] ?? '');
    $name   = trim((string)($_POST['name'] ?? ''));
    $prefix = trim((string)($_POST['prefix'] ?? 'vo_'));

    try {
        if ($exists && !$force) {
            throw new RuntimeException('Une configuration existe déjà. Ajoute &force=1 à l\'URL pour l\'écraser.');
        }

        if (!preg_match('/^[a-z0-9_]*$/i', $prefix)) {
            throw new RuntimeException('Préfixe invalide : lettres, chiffres et « _ » uniquement.');
        }

        // On ne persiste que des identifiants qui fonctionnent réellement.
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $test = new mysqli($host, $user, $pass, $name);
        $test->set_charset('utf8mb4');
        $test->close();

        $content = "<?php\n"
            . "/**\n"
            . " * Généré par install/setup.php le " . date('Y-m-d H:i:s') . ".\n"
            . " * Ce fichier n'est ni versionné, ni écrasé par le déploiement.\n"
            . " */\n\n"
            . "define('DB_HOST',   " . var_export($host, true) . ");\n"
            . "define('DB_USER',   " . var_export($user, true) . ");\n"
            . "define('DB_PASS',   " . var_export($pass, true) . ");\n"
            . "define('DB_NAME',   " . var_export($name, true) . ");\n"
            . "define('DB_PREFIX', " . var_export($prefix, true) . ");\n";

        if (file_put_contents(SECRETS_PATH, $content, LOCK_EX) === false) {
            throw new RuntimeException('Écriture impossible dans config/. Vérifie les droits du dossier.');
        }

        @chmod(SECRETS_PATH, 0600);
        $done = true;
    } catch (mysqli_sql_exception $e) {
        $error = 'Connexion refusée : ' . $e->getMessage();
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

renderHeader('Installation — configuration BDD', ['css' => ['login'], 'nav' => false, 'chrome' => false, 'bodyClass' => 'login-body']);
?>

<div class="login-card install-card">
    <div class="login-head">
        <span class="brand-mark">VO</span>
        <h1 class="login-title">Configuration de la base</h1>
        <p class="login-sub">Étape 1 sur 2</p>
    </div>

    <?php if ($done): ?>
        <div class="alert alert-success">
            <strong>config/secrets.php écrit.</strong> La connexion à la base fonctionne.
        </div>
        <a class="btn btn-block" href="<?= e(url('install/db.php')) ?>?token=<?= e(INSTALL_TOKEN) ?>">
            Étape 2 — créer les tables
        </a>
    <?php else: ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($exists && !$force): ?>
            <div class="alert alert-warn">
                Une configuration existe déjà. Recharge avec <span class="mono">&amp;force=1</span> pour l'écraser.
            </div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <div class="field">
                <label class="label" for="host">Hôte MySQL</label>
                <input class="input" type="text" id="host" name="host" required
                       value="<?= e($_POST['host'] ?? 'localhost') ?>">
            </div>

            <div class="field">
                <label class="label" for="name">Nom de la base</label>
                <input class="input" type="text" id="name" name="name" required value="<?= e($_POST['name'] ?? '') ?>">
            </div>

            <div class="field">
                <label class="label" for="user">Utilisateur</label>
                <input class="input" type="text" id="user" name="user" required value="<?= e($_POST['user'] ?? '') ?>">
            </div>

            <div class="field">
                <label class="label" for="pass">Mot de passe</label>
                <input class="input" type="password" id="pass" name="pass" autocomplete="new-password">
            </div>

            <div class="field">
                <label class="label" for="prefix">Préfixe des tables</label>
                <input class="input" type="text" id="prefix" name="prefix"
                       value="<?= e($_POST['prefix'] ?? 'vo_') ?>">
                <p class="muted text-sm">La base est partagée : le préfixe isole nos tables des autres projets.</p>
            </div>

            <button type="submit" class="btn btn-block">Tester et enregistrer</button>
        </form>
    <?php endif; ?>
</div>

<?php renderFooter(['chrome' => false]); ?>
