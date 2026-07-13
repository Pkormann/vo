# Changelog

Format : `MAJEUR.MINEUR.PATCH` — MAJEUR = incompatible · MINEUR = feature · PATCH = bug/CSS/texte.
La version fait foi dans `config/version.php` et s'affiche en bas de chaque page.

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
