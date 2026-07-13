# Specs — VO Cycles, outils internes

Application interne de **Version Originale Cycles** (Yverdon-les-Bains).
Objectif : analyser les données du magasin (Excel), et outiller le suivi stock / fournisseurs / clients.

- **Prod** : <https://www.mutatis.ch/vo/> — Infomaniak, `web/vo/`.
- **Déploiement** : GitHub Actions (`.github/workflows/deploy.yml`), rsync sur push `main`.
- **Stack** : PHP 8+, MySQLi, sessions natives, pas de framework. Chart.js + Font Awesome via CDN.
- **Version courante** : voir `config/version.php` — affichée en bas de chaque page.

## Arborescence

```
config/      version.php · install.php (token) · db.php · auth.php · secrets.php (serveur only) · .htaccess (deny all)
includes/    helpers.php · layout.php · bruteforce.php
assets/      css/{base,login,admin}.css · js/{login,modal,stats}.js
admin/       users.php · audit.php · stats.php          (rôle owner)
install/     setup.php · db.php · set_owner.php          (protégés par token)
analyse/     espace local, jamais versionné ni déployé
login.php · logout.php · index.php
```

## Conventions

- **CSS centralisé** dans `assets/css/`. Aucun `style=` inline, aucun `<style>` en page.
- **Aucun nom de table en dur** : toujours `tbl('users')`, qui applique `DB_PREFIX`.
- **Toute sortie dynamique** passe par `e()`. Tout POST passe par `requireCsrf()`.
- **Chrome commun** via `renderHeader()` / `renderFooter()` — c'est ce qui garantit
  la présence du numéro de version en pied de page.

## Base de données

La base MySQL est **partagée avec d'autres projets** : toutes les tables du projet sont
préfixées (`DB_PREFIX`, par défaut `vo_`), défini dans `config/secrets.php`.

### `vo_users`

| Colonne | Type | Note |
|---|---|---|
| `id` | INT AI PK | |
| `username` | VARCHAR(100) UNIQUE | |
| `role` | ENUM('owner','admin','user') | défaut `user` |
| `password` | VARCHAR(255) | `password_hash()`, bcrypt |
| `email` | VARCHAR(190) NULL | |
| `remember_token` | CHAR(64) NULL | 64 hex, stocké brut, indexé |
| `remember_expires` | DATETIME NULL | +30 jours |
| `created_at` | DATETIME | |
| `last_login` | DATETIME NULL | |

### `vo_login_attempts`

| Colonne | Type | Note |
|---|---|---|
| `id` | INT AI PK | |
| `ip` | VARCHAR(45) | IPv6 compatible |
| `username` | VARCHAR(100) | tel que saisi, même si inexistant |
| `success` | TINYINT(1) | |
| `user_agent` | VARCHAR(255) NULL | brut, tronqué |
| `device` | VARCHAR(20) NULL | Mobile / Tablette / Desktop |
| `os` | VARCHAR(50) NULL | Windows / macOS / iOS / Android / Linux / Inconnu |
| `created_at` | DATETIME | |

Index : `(ip, created_at)`, `(username, created_at)`, `(created_at)`.

## Installation (aucune connexion SSH requise)

Prérequis manuel unique : la base MySQL et son utilisateur existent (Manager Infomaniak).
Le `CREATE DATABASE` n'est pas réalisable depuis PHP en hébergement mutualisé.

1. `install/setup.php?token=<INSTALL_TOKEN>` — teste les identifiants, écrit `config/secrets.php`.
   Refuse d'écraser une config existante sans `&force=1`.
2. `install/db.php?token=<INSTALL_TOKEN>` — crée les tables (idempotent) et le premier compte `owner`
   (proposé uniquement si `vo_users` est vide).
3. Secours : `install/set_owner.php?token=<INSTALL_TOKEN>&username=Paul` — repasse un compte en `owner`
   si plus personne n'a ce rôle et que `admin/users.php` est donc inaccessible.

Le token est dans `config/install.php`. **Le dépôt GitHub doit rester privé.**
Les scripts d'`install/` étant versionnés, ils sont redéployés à chaque push : leur protection
repose sur le token et leur idempotence, pas sur une suppression.

## Persistance au déploiement

`rsync --delete` nettoie les fichiers retirés du dépôt, mais **rsync protège tout ce qui est
`--exclude`** : ces chemins survivent aux déploiements. Ne jamais ajouter `--delete-excluded`.

Préservés : `config/secrets.php`, `data/`, `uploads/`.
Jamais envoyés : `analyse/`, `.git/`, `.github/`, `.claude/`, `*.md`.

## Contrôle de la prod

Après un déploiement sensible (ou une migration de serveur), ces réponses doivent être vérifiées :

| URL | Attendu |
|---|---|
| `/login.php` | 200 |
| `/admin/*.php` non connecté | **302** vers `login.php` (jamais 200) |
| `/config/secrets.php` | 403 (`config/.htaccess`) |
| `/specs.md` | 403 (`.htaccess` racine) |
| `/install/setup.php` sans token | 403 |
| `/analyse/` | 404 (jamais déployé) |

```sh
base=https://www.mutatis.ch/vo
for p in /login.php /admin/audit.php /config/secrets.php /specs.md /install/setup.php; do
  printf '%-26s %s\n' "$p" "$(curl -s -o /dev/null -w '%{http_code}' "$base$p")"
done
```

Un `200` sur une page `admin/` signifierait que `checkAuth()` ne s'exécute plus : incident majeur.

<!-- ===================== BLOC PORTABLE — LOGIN / AUDIT / STATS ===================== -->

Ce bloc décrit un sous-système autonome, réutilisable tel quel dans un autre projet PHP/MySQL.
Fichiers concernés : `login.php`, `logout.php`, `config/auth.php`, `config/db.php`,
`includes/{helpers,bruteforce,layout}.php`, `admin/{users,audit,stats}.php`,
`install/{setup,db,set_owner}.php`, `assets/`.

### Rôles

| Rôle | Accès |
|---|---|
| `owner` | Tout, y compris audit, statistiques et gestion des utilisateurs |
| `admin` | Pages applicatives, pas l'administration |
| `user` | Accès restreint |

Le rôle est chargé en session au login **et** à la restauration par `remember_token`.
Contrôle : `checkAuth()` puis `checkRole(['owner'])` en tête de page.
La navigation (`navItems()`) filtre déjà les entrées selon le rôle — le contrôle serveur reste
la seule barrière de sécurité, le filtrage n'est que du confort.

### Session

- Durée : 7 jours (`SESSION_LIFETIME`), `session_regenerate_id(true)` après login.
- Cookies : `httponly`, `samesite=Strict`, `secure` dès que HTTPS est détecté
  (y compris derrière le reverse proxy Infomaniak, via `X-Forwarded-Proto`).
- « Rester connecté » : token de 64 hex, valable 30 jours, stocké brut en base et en cookie.
  Invalidé à la déconnexion **et** à tout changement de mot de passe.

### Protection brute force (`includes/bruteforce.php`)

Une seule table, deux compteurs sur une fenêtre glissante :

| Constante | Défaut | Rôle |
|---|---|---|
| `BF_MAX_ATTEMPTS_IP` | 10 | Bloque une IP qui insiste |
| `BF_MAX_ATTEMPTS_USER` | 20 | Bloque l'attaque d'un compte depuis plusieurs IP (botnet) |
| `BF_WINDOW_MINUTES` | 15 | Fenêtre glissante |
| `BF_PURGE_DAYS` | 90 | Rétention du journal, purge probabiliste (1 % des appels) |

Le blocage est évalué **avant** toute requête sur `users` : une IP bloquée ne fait pas travailler
la base. Les messages d'erreur ne révèlent jamais si c'est l'identifiant ou le mot de passe
qui est faux.

### `admin/audit.php`

KPIs du jour (succès / échecs / IP bloquées), alerte si des IP sont bloquées, journal filtrable
(dates, identifiant, IP, résultat), limité à 500 lignes, tri décroissant.

### `admin/stats.php`

Période 7 / 30 / 90 jours (`?period=`). 4 KPIs (total, succès, échecs, taux).
Barres empilées de l'activité quotidienne (jours creux inclus à zéro), doughnuts OS et appareil,
top 8 identifiants et top 10 IP — lignes signalées en rouge quand les échecs dominent.
Agrégation SQL côté PHP, données passées à Chart.js via un `<script type="application/json">`.

<!-- =================== FIN BLOC PORTABLE =================== -->

## À venir

- Import et analyse des fichiers Excel du magasin (modèle de données à définir sur pièces réelles).
- Visualisations stock / ventes.
- Liens fournisseurs et clients.
