<?php
/**
 * Script de secours — repasse un utilisateur en owner.
 *
 * À utiliser si plus personne n'a le rôle owner et que admin/users.php est donc inaccessible.
 * Accès : install/set_owner.php?token=<INSTALL_TOKEN>&username=Paul
 */

require_once __DIR__ . '/../config/install.php';
require_once __DIR__ . '/../config/db.php';

requireInstallToken();

header('Content-Type: text/plain; charset=utf-8');

$username = trim((string)($_GET['username'] ?? ''));

if ($username === '') {
    exit("Paramètre manquant : &username=…\n");
}

$connect = db();

$stmt = $connect->prepare('UPDATE ' . tbl('users') . ' SET role = "owner" WHERE username = ?');
$stmt->bind_param('s', $username);
$stmt->execute();
$changed = $stmt->affected_rows;
$stmt->close();

if ($changed === 0) {
    // affected_rows = 0 signifie « inconnu » ou « déjà owner » : on lève l'ambiguïté.
    $stmt = $connect->prepare('SELECT role FROM ' . tbl('users') . ' WHERE username = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    exit($row
        ? "« $username » est déjà owner. Rien à faire.\n"
        : "Utilisateur « $username » introuvable.\n");
}

echo "« $username » est désormais owner.\n";
