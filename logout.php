<?php
/**
 * Déconnexion : invalide la session, le cookie et le token remember_me en base.
 */

require_once __DIR__ . '/config/auth.php';

startSession();

if (isset($_SESSION['user_id'])) {
    $stmt = db()->prepare(
        'UPDATE ' . tbl('users') . ' SET remember_token = NULL, remember_expires = NULL WHERE id = ?'
    );
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $stmt->close();
}

clearRememberCookie();

$_SESSION = [];
session_destroy();

header('Location: ' . url('login.php'));
exit;
