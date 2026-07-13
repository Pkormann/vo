<?php
/**
 * Protection brute force, adossée à la seule table login_attempts.
 *
 * Deux compteurs sur une fenêtre glissante :
 *   - par IP       → freine un attaquant isolé
 *   - par username → freine un botnet distribué visant un compte précis
 *
 * Utilisé par login.php (enregistrement + blocage) et admin/audit.php (affichage).
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers.php';

define('BF_MAX_ATTEMPTS_IP',   10);
define('BF_MAX_ATTEMPTS_USER', 20);
define('BF_WINDOW_MINUTES',    15);
define('BF_PURGE_DAYS',        90);

/**
 * Purge probabiliste (1 % des appels) des tentatives trop anciennes.
 */
function bf_purge(mysqli $connect): void
{
    if (random_int(1, 100) !== 1) {
        return;
    }

    $stmt = $connect->prepare(
        'DELETE FROM ' . tbl('login_attempts') . ' WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)'
    );
    $days = BF_PURGE_DAYS;
    $stmt->bind_param('i', $days);
    $stmt->execute();
    $stmt->close();
}

/**
 * @return array{blocked: bool, remaining: int} remaining = essais restants avant blocage par IP
 */
function bf_get_status(mysqli $connect, string $ip, string $username): array
{
    $sql = 'SELECT
                SUM(ip = ?)                                    AS by_ip,
                SUM(username = ? AND username <> "")           AS by_user
            FROM ' . tbl('login_attempts') . '
            WHERE success = 0
              AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)';

    $stmt   = $connect->prepare($sql);
    $window = BF_WINDOW_MINUTES;
    $stmt->bind_param('ssi', $ip, $username, $window);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $byIp   = (int)($row['by_ip'] ?? 0);
    $byUser = (int)($row['by_user'] ?? 0);

    return [
        'blocked'   => $byIp >= BF_MAX_ATTEMPTS_IP || $byUser >= BF_MAX_ATTEMPTS_USER,
        'remaining' => max(0, BF_MAX_ATTEMPTS_IP - $byIp),
    ];
}

/**
 * @return array{device: string, os: string}
 */
function bf_parse_device_os(string $ua): array
{
    $device = 'Desktop';
    if (preg_match('/iPad|Tablet|Nexus (7|10)|Kindle|Silk/i', $ua)) {
        $device = 'Tablette';
    } elseif (preg_match('/Mobile|iPhone|iPod|Windows Phone/i', $ua)) {
        $device = 'Mobile';
    }

    $os = 'Inconnu';
    if (preg_match('/iPhone|iPad|iPod/i', $ua)) {
        $os = 'iOS';
    } elseif (preg_match('/Android/i', $ua)) {
        $os = 'Android';
    } elseif (preg_match('/Windows/i', $ua)) {
        $os = 'Windows';
    } elseif (preg_match('/Mac OS X|Macintosh/i', $ua)) {
        $os = 'macOS';
    } elseif (preg_match('/Linux|X11|Ubuntu/i', $ua)) {
        $os = 'Linux';
    }

    return ['device' => $device, 'os' => $os];
}

function bf_log(mysqli $connect, string $ip, string $username, bool $success): void
{
    $ua     = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $parsed = bf_parse_device_os($ua);
    $flag   = $success ? 1 : 0;

    $stmt = $connect->prepare(
        'INSERT INTO ' . tbl('login_attempts') . '
         (ip, username, success, user_agent, device, os) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('ssisss', $ip, $username, $flag, $ua, $parsed['device'], $parsed['os']);
    $stmt->execute();
    $stmt->close();
}

/**
 * IPs dont le seuil de blocage est atteint en ce moment.
 *
 * @return array<int, array{ip: string, fails: int}>
 */
function bf_blocked_ips(mysqli $connect): array
{
    $stmt = $connect->prepare(
        'SELECT ip, COUNT(*) AS fails
         FROM ' . tbl('login_attempts') . '
         WHERE success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
         GROUP BY ip
         HAVING fails >= ?
         ORDER BY fails DESC'
    );

    $window = BF_WINDOW_MINUTES;
    $max    = BF_MAX_ATTEMPTS_IP;
    $stmt->bind_param('ii', $window, $max);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}
