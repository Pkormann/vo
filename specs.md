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
includes/    helpers.php · layout.php · bruteforce.php · catalog.php (domaine) · period.php (plages de dates)
assets/      css/{base,login,admin,app}.css · js/{login,modal,stats,rapport,period,vente}.js
admin/       users.php · audit.php · stats.php · import.php   (rôle owner)
install/     setup.php · db.php · set_owner.php               (protégés par token)
analyse/     espace local, jamais versionné ni déployé
login.php · logout.php · index.php
stock.php · velo.php · ventes.php · rapport.php · precommande.php · marques.php   (owner + admin)
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

## Modèle de données du magasin

### Le principe

**Un vélo est un exemplaire physique unique**, pas une ligne de stock ou une ligne de vente.
Il entre en magasin (`entered_at`), puis il est vendu (`sold_at`). « Le stock » et « les ventes » ne sont pas
deux objets : ce sont deux filtres sur `vo_bikes`, selon le statut.

C'est ce qui rend calculables la rotation, l'âge du stock et le délai d'écoulement — trois choses
impossibles avec les classeurs Excel d'origine, où le même vélo était saisi deux fois, dans deux
fichiers, sous deux libellés différents (« S6 EVO 2 » au stock, « SuperSix Crb. 2 » en vente).

### `vo_brands`

| Colonne | Type | Note |
|---|---|---|
| `id` | INT AI PK | |
| `name` | VARCHAR(80) UNIQUE | |
| `discount_rate` | DECIMAL(5,2) NULL | rabais fournisseur en %, sert à estimer le prix d'achat |
| `active` | TINYINT(1) | |

### `vo_models`

Le référentiel produit. La clé est `(marque, libellé, millésime)` : un même vélo en MY26 et MY27
sont **deux modèles**, car le prix et les specs changent d'une année à l'autre.

| Colonne | Type | Note |
|---|---|---|
| `id` | INT AI PK | |
| `brand_id` | INT FK → `vo_brands` | |
| `category` | VARCHAR(20) | Route · Gravel · E-bikes · VTT · Cargo · Urbain · Kids |
| `family` | VARCHAR(60) | SuperSix, Topstone, Addict RC… — voir `familyOf()` |
| `name` | VARCHAR(140) | libellé commercial |
| `model_year` | SMALLINT NULL | millésime |
| `list_price` | DECIMAL(10,2) NULL | prix catalogue de référence |

La **famille** est l'unité de décision commerciale : on ne pré-commande pas « un Addict RC 30 en 52 »,
on décide « combien d'Addict RC en 2027 ». `familyOf()` (dans `includes/catalog.php`) replie les
libellés divergents des trois fichiers sources sur une famille unique. **Toute nouvelle famille se
déclare là, nulle part ailleurs.**

### `vo_bikes`

| Colonne | Type | Note |
|---|---|---|
| `id` | INT AI PK | |
| `model_id` | INT FK → `vo_models` | |
| `size` | VARCHAR(10) NULL | 54, M, XL… les deux systèmes coexistent selon les modèles |
| `color` | VARCHAR(60) NULL | |
| `status` | ENUM | `stock` · `reserve` · `test` · `vendu` |
| `entered_at` | DATE NULL | réception. NULL pour les ventes historiques importées |
| `sold_at` | DATE NULL | |
| `list_price` | DECIMAL(10,2) NULL | prix catalogue figé à la réception |
| `purchase_price` | DECIMAL(10,2) NULL | prix d'achat réel ; sinon estimé via `brands.discount_rate` |
| `sold_price` | DECIMAL(10,2) NULL | |
| `customer_id` | INT NULL FK → `vo_customers` | `ON DELETE SET NULL` |
| `notes` | VARCHAR(255) NULL | |
| `import_key` | CHAR(32) NULL UNIQUE | empreinte de la ligne source : rend l'import rejouable |
| `created_at` / `updated_at` | DATETIME | |

`stock`, `reserve` et `test` sont **physiquement présents** en magasin (`STATUSES_PRESENT`) ; seul
`stock` et `reserve` comptent dans la valeur du stock vendable.

### `vo_customers`

`id` · `name` (UNIQUE) · `email` · `phone` · `notes` · `created_at`. Créé à la volée à la saisie d'une vente.

### `vo_preorders`

| Colonne | Type | Note |
|---|---|---|
| `id` | INT AI PK | |
| `season` | SMALLINT | millésime visé, ex. 2027 |
| `category` + `family` | VARCHAR | l'identité de la ligne de décision |
| `size` | VARCHAR(10) | vide = quantité totale de la famille |
| `qty` | INT | ce qui est retenu |
| `suggested` | INT NULL | ce que l'outil proposait — garde la trace de l'écart de jugement |
| `note` | VARCHAR(255) NULL | |

Clé unique : `(season, category, family, size)`. **Pas de FK vers `vo_models`, volontairement** : la
pré-commande se décide avant que les modèles de la saison suivante n'existent au catalogue.

## Période d'interrogation (`includes/period.php`)

Toutes les pages d'analyse s'interrogent sur une **plage de dates**, jamais sur une année figée.
Le sélecteur est le même partout (`renderPeriodFilter()`), et circule dans l'URL :
`?range=ytd|last12|prev_year|season|all|custom` (+ `from` / `to` en mode `custom`).

`season` court d'octobre à septembre : c'est le rythme des millésimes, donc celui des pré-commandes.

Une période porte **toujours sa période de comparaison** : la même durée, décalée d'un an
(`prev_from` / `prev_to`). Comparer six mois de 2026 à douze mois de 2025 ne veut rien dire ;
ici la comparaison est à durée égale et à saison égale, par construction.

Entrées invalides : dates inversées → remises à l'endroit ; date illisible ou préréglage inconnu →
repli sur le défaut. Jamais de plage vide, qui se lirait à tort comme « aucune vente ».

**Le stock ne dépend pas de la période** : c'est une photo de l'instant. Interroger « mars à juin »
compare donc les ventes de ce trimestre au stock d'aujourd'hui — c'est bien ce qu'on veut pour
décider quoi commander.

## Calculs de décision (`includes/catalog.php`)

Ces formules ne vivent qu'ici. Aucune page ne les réimplémente.

| Mesure | Définition |
|---|---|
| **Couverture** | `stock ÷ (ventes ÷ mois de la période)` — nombre de mois de stock au rythme observé |
| **Dormant** | couverture > 6 mois, ou stock sans aucune vente sur la période |
| **Tension** | couverture < 2 mois : rupture probable avant la fin de saison |
| **Saisonnalité** | `seasonProgress()` — part des ventes de l'an dernier réalisées avant le même jour. Annualiser en juillet en multipliant par 12/7 supposerait qu'un vélo se vende autant en janvier qu'en mai : c'est faux |
| **Demande attendue** | ventes de l'année ÷ saisonnalité écoulée |
| **Stock résiduel** | `stock − (demande attendue − déjà vendu)` — ce qui restera en rayon à l'ouverture de la saison |
| **Pré-commande** | `demande attendue − stock résiduel`, jamais négative |

La dernière ligne est **la correction de l'erreur constatée dans `Proj.S&_MY27.xlsx`** : la saison 2026
avait démarré avec 90 vélos route disponibles (48 reportés + 42 pré-commandés) pour une demande
historique de 45 par an, parce que la pré-commande avait été calée sur la demande sans retrancher
le stock reporté.

`splitBySize()` éclate une quantité en tailles selon le mix de ventes observé, par la **méthode du plus
grand reste** : donner le reliquat d'arrondi à la taille la plus vendue gonflerait la taille dominante à
chaque commande et affamerait les tailles rares.

## Vendre un vélo

`sellBike()` (dans `includes/catalog.php`) est **le seul endroit** où une vente s'écrit.
`stock.php` (bouton sur la ligne du vélo) et `ventes.php` (bouton « Enregistrer une vente »,
avec la date du jour pré-remplie) l'appellent tous les deux.

- Un vélo déjà vendu ne peut pas l'être deux fois : la clause `WHERE status <> "vendu"` le garantit,
  et la fonction renvoie `false` plutôt que de mentir sur le succès.
- Une date de vente dans le futur est ramenée au jour même (`normalizeSaleDate()`).
- **Un `sold_price` vide signifie « au prix catalogue »**, et les lectures retombent sur `list_price`.
  On ne recopie jamais le catalogue dans `sold_price` : sinon un prix réellement négocié ne se
  distinguerait plus d'un prix jamais saisi.

## Import des données (`admin/import.php`, rôle owner)

Les classeurs Excel sont convertis en CSV **en local**, puis téléversés : les données commerciales ne
transitent jamais par le dépôt (`*.csv` et `analyse/` sont dans `.gitignore`).

Colonnes attendues, dans cet ordre :

```
marque, categorie, modele, millesime, taille, couleur, prix_catalogue,
statut, entre_le, vendu_le, prix_vente, client, remarque
```

Marques, modèles et clients sont créés à la volée. Chaque ligne porte une empreinte MD5 (`import_key`) :
**l'import est rejouable** — une ligne déjà connue est ignorée, jamais dupliquée. Contrepartie assumée :
deux vélos réellement identiques (même modèle, même taille, même date, même client) sont vus comme un
seul. Le double import est plus fréquent que le doublon exact.

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

- Fiches clients (historique d'achat par personne) — la table existe, la page reste à faire.
- Pré-commande à la maille taille (aujourd'hui : famille, avec répartition indicative des tailles).
- Dates d'entrée en stock : absentes des Excel, à saisir pour rendre la rotation exacte plutôt qu'estimée.
- Liens fournisseurs.
