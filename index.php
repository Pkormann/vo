<?php
/**
 * Tableau de bord. Point d'entrée après connexion.
 * Les outils d'analyse (Excel, fournisseurs, clients) viendront se brancher ici.
 */

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/layout.php';

checkAuth();

renderHeader('Tableau de bord', ['css' => ['admin'], 'icons' => true]);
?>

<div class="card">
    <div class="card-header"><h2>Bienvenue, <?= e(currentUser()) ?></h2></div>
    <p class="muted">
        Outils internes de Version Originale Cycles. Les modules d'analyse arrivent ici
        au fur et à mesure : import Excel, stock, fournisseurs, clients.
    </p>
</div>

<?php if (isOwner()): ?>
    <div class="grid grid-2">
        <a class="card tool" href="<?= e(url('admin/users.php')) ?>">
            <i class="fa-solid fa-users tool-icon" aria-hidden="true"></i>
            <span class="tool-name">Utilisateurs</span>
            <span class="tool-desc muted">Comptes, rôles et mots de passe.</span>
        </a>

        <a class="card tool" href="<?= e(url('admin/audit.php')) ?>">
            <i class="fa-solid fa-clipboard-list tool-icon" aria-hidden="true"></i>
            <span class="tool-name">Audit</span>
            <span class="tool-desc muted">Journal des tentatives de connexion.</span>
        </a>

        <a class="card tool" href="<?= e(url('admin/stats.php')) ?>">
            <i class="fa-solid fa-chart-column tool-icon" aria-hidden="true"></i>
            <span class="tool-name">Statistiques</span>
            <span class="tool-desc muted">Volumes, plateformes, adresses suspectes.</span>
        </a>
    </div>
<?php endif; ?>

<?php renderFooter(); ?>
