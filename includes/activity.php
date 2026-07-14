<?php
/**
 * Journal des actions métier.
 *
 * À ne pas confondre avec `vo_login_attempts`, qui journalise les *connexions* :
 * là c'est de la sécurité, ici c'est de l'exploitation. « Qui a supprimé ce
 * vélo ? » et « qui essaie de forcer le mot de passe ? » ne se lisent pas dans
 * le même écran.
 *
 * Le journal n'est jamais bloquant : si l'écriture échoue, l'action métier a
 * quand même eu lieu et doit aboutir. On ne perd pas une vente parce qu'une
 * ligne de log n'est pas passée.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers.php';

/** Libellés des actions, pour l'affichage. La clé est ce qui est stocké. */
const ACTIONS = [
    'login'         => 'Connexion',
    'vente'         => 'Vente enregistrée',
    'reservation'   => 'Vélo réservé',
    'velo_ajout'    => 'Vélo ajouté',
    'velo_edition'  => 'Fiche modifiée',
    'velo_suppr'    => 'Vélo supprimé',
    'doublons'      => 'Doublons supprimés',
    'import'        => 'Import CSV',
    'export'        => 'Export CSV',
    'prompt'        => 'Prompt modifié',
    'precommande'   => 'Pré-commande enregistrée',
    'rabais'        => 'Rabais fournisseurs modifiés',
    'inventaire'    => 'Inventaire',
    'utilisateur'   => 'Utilisateur modifié',
];

/**
 * Écrit une ligne de journal. Silencieux en cas d'échec, volontairement.
 */
function logAction(string $action, ?string $entity = null, ?int $entityId = null, ?string $detail = null): void
{
    try {
        $username = $_SESSION['username'] ?? null;
        $ip       = clientIp();

        $stmt = db()->prepare(
            'INSERT INTO ' . tbl('activity') . ' (username, action, entity, entity_id, detail, ip)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('sssiss', $username, $action, $entity, $entityId, $detail, $ip);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // Le journal ne doit jamais faire échouer l'action qu'il journalise.
        error_log('[VO] Journal indisponible : ' . $e->getMessage());
    }
}

/**
 * Lignes du journal, filtrables.
 *
 * @return array<int, array<string, mixed>>
 */
function activityLog(string $user = '', string $action = '', int $days = 30, int $limit = 500): array
{
    $where  = ['created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)'];
    $params = [$days];
    $types  = 'i';

    if ($user !== '') {
        $where[]  = 'username = ?';
        $params[] = $user;
        $types   .= 's';
    }

    if ($action !== '' && isset(ACTIONS[$action])) {
        $where[]  = 'action = ?';
        $params[] = $action;
        $types   .= 's';
    }

    $sql = 'SELECT * FROM ' . tbl('activity') . '
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY created_at DESC
            LIMIT ' . (int)$limit;

    $stmt = db()->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/** Purge du journal. Appelée au fil de l'eau, sans tâche planifiée. */
function purgeActivity(int $days = 730): void
{
    // 1 % des appels : suffisant pour contenir la table, sans peser sur chaque page.
    if (random_int(1, 100) !== 1) {
        return;
    }

    $stmt = db()->prepare(
        'DELETE FROM ' . tbl('activity') . ' WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)'
    );
    $stmt->bind_param('i', $days);
    $stmt->execute();
    $stmt->close();
}
