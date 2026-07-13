<?php
/**
 * Tableau de bord. Point d'entrée après connexion.
 *
 * Les tuiles mènent aux outils ; les chiffres de tête servent à décider s'il y a
 * quelque chose à faire aujourd'hui.
 */

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/catalog.php';
require_once __DIR__ . '/includes/layout.php';

checkAuth();

$canManage = in_array(currentRole(), ['owner', 'admin'], true);
$kpis      = $canManage ? stockKpis() : null;
$soldYtd   = $canManage ? salesTotals(date('Y') . '-01-01', date('Y-m-d'))['n'] : 0;

renderHeader('Tableau de bord', ['css' => ['admin', 'app'], 'icons' => true]);
?>

<div class="card">
    <div class="card-header"><h2>Bienvenue, <?= e(currentUser()) ?></h2></div>
    <p class="muted">
        Outils internes de Version Originale Cycles : suivi du stock, des ventes et
        préparation des pré-commandes.
    </p>
</div>

<?php if ($canManage): ?>
    <div class="grid grid-4">
        <div class="kpi">
            <span class="kpi-label">Vélos en magasin</span>
            <span class="kpi-value"><?= (int)$kpis['units'] ?></span>
        </div>
        <div class="kpi">
            <span class="kpi-label">Valeur catalogue</span>
            <span class="kpi-value"><?= e(chf($kpis['value'], false)) ?></span>
        </div>
        <div class="kpi">
            <span class="kpi-label">Vendus en <?= e(date('Y')) ?></span>
            <span class="kpi-value"><?= (int)$soldYtd ?></span>
        </div>
        <div class="kpi <?= $kpis['old_units'] > 0 ? 'kpi-alert' : '' ?>">
            <span class="kpi-label">Millésimes anciens</span>
            <span class="kpi-value"><?= (int)$kpis['old_units'] ?></span>
            <span class="kpi-note">en rayon</span>
        </div>
    </div>

    <div class="grid grid-2">
        <a class="card tool" href="<?= e(url('stock.php')) ?>">
            <i class="fa-solid fa-warehouse tool-icon" aria-hidden="true"></i>
            <span class="tool-name">Stock</span>
            <span class="tool-desc muted">Ce qui est en rayon. Marquer un vélo vendu.</span>
        </a>

        <a class="card tool" href="<?= e(url('ventes.php')) ?>">
            <i class="fa-solid fa-tag tool-icon" aria-hidden="true"></i>
            <span class="tool-name">Ventes</span>
            <span class="tool-desc muted">L'historique, par année, catégorie et client.</span>
        </a>

        <a class="card tool" href="<?= e(url('rapport.php')) ?>">
            <i class="fa-solid fa-chart-line tool-icon" aria-hidden="true"></i>
            <span class="tool-name">Rapport</span>
            <span class="tool-desc muted">Ce qui tourne, ce qui dort, ce qui manque.</span>
        </a>

        <a class="card tool" href="<?= e(url('precommande.php')) ?>">
            <i class="fa-solid fa-clipboard-check tool-icon" aria-hidden="true"></i>
            <span class="tool-name">Pré-commande</span>
            <span class="tool-desc muted">Combien commander l'an prochain, famille par famille.</span>
        </a>

        <a class="card tool" href="<?= e(url('marques.php')) ?>">
            <i class="fa-solid fa-percent tool-icon" aria-hidden="true"></i>
            <span class="tool-name">Marques et achats</span>
            <span class="tool-desc muted">Rabais fournisseurs, argent réellement engagé.</span>
        </a>

        <a class="card tool" href="<?= e(url('velo.php')) ?>">
            <i class="fa-solid fa-plus tool-icon" aria-hidden="true"></i>
            <span class="tool-name">Ajouter un vélo</span>
            <span class="tool-desc muted">À la réception d'une livraison.</span>
        </a>

        <a class="card tool" href="<?= e(url('export.php')) ?>">
            <i class="fa-solid fa-robot tool-icon" aria-hidden="true"></i>
            <span class="tool-name">Export et analyse</span>
            <span class="tool-desc muted">Les données en CSV, et le prompt pour les faire analyser.</span>
        </a>
    </div>
<?php endif; ?>

<?php if (isOwner()): ?>
    <div class="grid grid-2">
        <a class="card tool" href="<?= e(url('admin/import.php')) ?>">
            <i class="fa-solid fa-file-import tool-icon" aria-hidden="true"></i>
            <span class="tool-name">Import CSV</span>
            <span class="tool-desc muted">Reprise des classeurs Excel du magasin.</span>
        </a>

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
