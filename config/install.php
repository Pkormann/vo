<?php
/**
 * Token protégeant les scripts d'installation (install/*.php).
 *
 * Ces scripts s'auto-suppriment après un succès. Si tu régénères ce token,
 * régénère-le avec : php -r 'echo bin2hex(random_bytes(16));'
 *
 * Le dépôt GitHub doit rester PRIVÉ : ce token y est versionné.
 */

define('INSTALL_TOKEN', '60bb806857be7a66639d8ea6fabed97e');

/**
 * Refuse l'accès si le token fourni en GET ne correspond pas.
 * À appeler en toute première ligne de chaque script d'installation.
 */
function requireInstallToken(): void
{
    if (!hash_equals(INSTALL_TOKEN, (string)($_GET['token'] ?? ''))) {
        http_response_code(403);
        exit('403 — token invalide.');
    }
}

/**
 * Supprime le script d'installation appelant : il ne doit servir qu'une fois.
 */
function selfDestruct(): bool
{
    $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
    return $script !== '' && is_file($script) && @unlink($script);
}
