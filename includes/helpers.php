<?php
/**
 * Utilitaires transverses : échappement, CSRF, formatage.
 */

require_once __DIR__ . '/../config/auth.php';

/** Échappement HTML. Toute sortie dynamique passe par là. */
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Jeton CSRF de la session, créé à la demande. */
function csrfToken(): string
{
    startSession();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/** Champ caché à insérer dans chaque formulaire POST. */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

/** Vérifie le jeton d'un POST, coupe court en cas d'échec. */
function requireCsrf(): void
{
    startSession();

    $sent = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $sent)) {
        http_response_code(419);
        exit('Session expirée. Recharge la page et recommence.');
    }
}

/** IP du client, en tenant compte du reverse proxy Infomaniak. */
function clientIp(): string
{
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwarded !== '') {
        $first = trim(explode(',', $forwarded)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }

    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** Date lisible, ou tiret si absente. */
function fmtDate(?string $sqlDate, string $format = 'd.m.Y H:i'): string
{
    if (!$sqlDate) {
        return '—';
    }

    $ts = strtotime($sqlDate);
    return $ts ? date($format, $ts) : '—';
}
