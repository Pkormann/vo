<?php
/**
 * Session, authentification et contrôle de rôle.
 *
 * Toute page protégée commence par :
 *     require_once __DIR__ . '/../config/auth.php';
 *     checkAuth();                 // connecté ?
 *     checkRole(['owner']);        // rôle suffisant ? (optionnel)
 */

require_once __DIR__ . '/db.php';

const SESSION_LIFETIME  = 604800;   // 7 jours
const REMEMBER_LIFETIME = 2592000;  // 30 jours

/**
 * Démarre la session avec des cookies durcis. Idempotent.
 */
function startSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);
    ini_set('session.use_strict_mode', '1');

    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => isHttps(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    session_start();
}

function isHttps(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

/**
 * Racine du site (ex. /vo), quel que soit le sous-dossier de la page appelante.
 * Sert à construire les liens et redirections sans les coder en dur.
 */
function basePath(): string
{
    // config/ est toujours à un niveau sous la racine du site.
    $root = dirname(__DIR__);
    $doc  = rtrim(str_replace('\\', '/', (string)($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    $root = str_replace('\\', '/', $root);

    if ($doc !== '' && str_starts_with($root, $doc)) {
        return rtrim(substr($root, strlen($doc)), '/');
    }

    return ''; // site à la racine du domaine
}

function url(string $path = ''): string
{
    return basePath() . '/' . ltrim($path, '/');
}

/**
 * Rejoue une session "remember me" à partir du cookie, si la session PHP a expiré.
 */
function restoreFromRememberCookie(): bool
{
    $token = $_COOKIE['remember_token'] ?? '';
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return false;
    }

    $sql  = 'SELECT id, username, role FROM ' . tbl('users') . '
             WHERE remember_token = ? AND remember_expires > NOW() LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        clearRememberCookie();
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user_id']  = (int)$user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role']     = $user['role'] ?? 'user';

    return true;
}

function setRememberCookie(string $token): void
{
    setcookie('remember_token', $token, [
        'expires'  => time() + REMEMBER_LIFETIME,
        'path'     => '/',
        'secure'   => isHttps(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function clearRememberCookie(): void
{
    setcookie('remember_token', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => isHttps(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

/**
 * Exige une session authentifiée, sinon renvoie vers le login en mémorisant la cible.
 */
function checkAuth(): void
{
    startSession();

    if (isset($_SESSION['user_id'])) {
        return;
    }

    if (restoreFromRememberCookie()) {
        return;
    }

    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? url('index.php');
    header('Location: ' . url('login.php'));
    exit;
}

/**
 * Exige l'un des rôles listés, sinon renvoie à l'accueil.
 */
function checkRole(array $roles): void
{
    if (!in_array(currentRole(), $roles, true)) {
        header('Location: ' . url('index.php'));
        exit;
    }
}

function currentRole(): string
{
    return $_SESSION['role'] ?? 'user';
}

function currentUser(): string
{
    return $_SESSION['username'] ?? '';
}

function isOwner(): bool
{
    return currentRole() === 'owner';
}
