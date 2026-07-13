# Changelog

Format : `MAJEUR.MINEUR.PATCH` — MAJEUR = incompatible · MINEUR = feature · PATCH = bug/CSS/texte.
La version fait foi dans `config/version.php` et s'affiche en bas de chaque page.

## 0.3.0 — 2026-07-13

Interrogation par plage de dates.

- `includes/period.php` : période partagée par toutes les pages d'analyse — depuis le
  1er janvier, 12 derniers mois, année précédente, saison (oct. → sept.), tout l'historique,
  ou dates au choix.
- Toute période porte sa **comparaison à durée égale**, décalée d'un an : comparer six mois
  de cette année à douze mois de la précédente ne veut rien dire.
- `rapport.php` et `ventes.php` : le sélecteur remplace le menu « année ». Les filtres
  (catégorie, marque, recherche) survivent au changement de période.
- La couverture de stock se calcule désormais sur la durée réelle de la période interrogée.
- Le stock reste une photo de l'instant : il ne suit pas la période, volontairement.

## 0.2.0 — 2026-07-13

Module magasin : stock, ventes, rapport, pré-commande.

- Modèle de données : le vélo est un **exemplaire physique** avec un cycle de vie
  (`stock` → `vendu`), et non deux lignes dans deux fichiers. Nouvelles tables
  `vo_brands`, `vo_models`, `vo_bikes`, `vo_customers`, `vo_preorders`.
- `stock.php` : ce qui est en rayon, filtrable ; vente en deux clics ; âge de chaque vélo.
- `velo.php` : réception d'un vélo, correction d'une fiche. Marque, modèle et client
  se créent à la volée — aucun catalogue à peupler avant de pouvoir travailler.
- `ventes.php` : historique interrogeable, cumul comparable à date, délai d'écoulement.
- `rapport.php` : rotation par famille, stock dormant, familles en tension, graphiques.
- `precommande.php` : proposition par famille (demande attendue − stock résiduel),
  corrigée de la saisonnalité réelle, avec répartition indicative des tailles.
- `marques.php` : rabais fournisseur par marque → argent réellement engagé en rayon.
- `admin/import.php` : import CSV rejouable (empreinte par ligne), pour la reprise des
  classeurs Excel sans jamais faire transiter les données par le dépôt.
- `includes/catalog.php` : toutes les mesures de décision (couverture, dormant, tension,
  saisonnalité, répartition des tailles) au même endroit.

## 0.1.0 — 2026-07-13

Socle initial.

- Authentification : login, logout, sessions durcies, « rester connecté » 30 jours.
- Protection brute force par IP et par identifiant, journalisée (`vo_login_attempts`).
- Rôles `owner` / `admin` / `user`, contrôle par `checkAuth()` + `checkRole()`.
- `admin/users.php` : création, changement de rôle, changement de mot de passe, suppression.
- `admin/audit.php` : journal filtrable des tentatives, KPIs du jour, IP bloquées.
- `admin/stats.php` : KPIs, activité quotidienne, répartitions OS / appareil, top identifiants et IP.
- Installation sans SSH : `install/setup.php` (config BDD) puis `install/db.php` (tables + owner).
- Déploiement rsync avec protection des fichiers serveur (`config/secrets.php`, `data/`, `uploads/`).
- CSS centralisé, chrome commun versionné en pied de page.
