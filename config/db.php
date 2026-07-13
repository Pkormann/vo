<?php
/**
 * Connexion MySQLi. Point d'entrée unique vers la base.
 *
 * La base est partagée : toutes les tables du projet passent par tbl() qui
 * applique le préfixe DB_PREFIX. Ne jamais écrire un nom de table en dur.
 */

if (!defined('APP_VERSION')) {
    require_once __DIR__ . '/version.php';
}

// L'inclusion ne doit jamais échouer : install/setup.php tourne justement
// pour créer ce fichier. C'est db() qui exige une configuration valide.
if (is_file(__DIR__ . '/secrets.php')) {
    require_once __DIR__ . '/secrets.php';
}

function isConfigured(): bool
{
    return defined('DB_HOST') && defined('DB_NAME');
}

/**
 * Nom complet d'une table du projet, préfixe appliqué.
 * L'argument vient toujours du code, jamais d'une entrée utilisateur.
 */
function tbl(string $name): string
{
    return (defined('DB_PREFIX') ? DB_PREFIX : '') . $name;
}

/**
 * Connexion partagée, ouverte à la première demande.
 */
function db(): mysqli
{
    static $connect = null;

    if ($connect instanceof mysqli) {
        return $connect;
    }

    if (!isConfigured()) {
        http_response_code(503);
        exit('Configuration absente. Lance install/setup.php?token=… pour créer config/secrets.php.');
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
        $connect = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $connect->set_charset('utf8mb4');
    } catch (mysqli_sql_exception $e) {
        error_log('[VO] Connexion BDD échouée : ' . $e->getMessage());
        http_response_code(503);
        exit('Service indisponible : la base de données est injoignable.');
    }

    return $connect;
}
