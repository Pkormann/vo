# Changelog

Format : `MAJEUR.MINEUR.PATCH` — MAJEUR = incompatible · MINEUR = feature · PATCH = bug/CSS/texte.
La version fait foi dans `config/version.php` et s'affiche en bas de chaque page.

## 0.7.0 — 2026-07-13

Le prompt devient versionné : on peut l'éditer sans risque.

- **Historique des versions** (`vo_settings_history`) : chaque enregistrement archive le texte
  qu'il remplace, daté et signé. On peut relire n'importe quelle version et la restaurer d'un clic.
- **L'archivage précède l'écriture.** Le scénario qui fait peur — tout sélectionner, tout effacer,
  enregistrer — laisse malgré tout la version d'avant derrière lui.
- **« Restaurer l'original » est lui-même réversible** : la version en cours est archivée avant
  d'être écartée. Aucune action de cette page n'est destructrice.
- Le texte d'origine vit dans le dépôt (`config/prompt.php`) : il ne peut pas être perdu, même si
  la base est vidée.
- L'historique est plafonné aux 30 dernières versions — au-delà, il n'aide plus personne.

## 0.6.0 — 2026-07-13

Le prompt d'analyse devient un actif, pas un consommable.

- **Plus aucun chiffre dans le prompt.** Un prompt qui embarque les données périme à chaque
  vente ; un prompt qui porte la méthode se retravaille saison après saison. Les données
  arrivent par le CSV joint.
- **`config/prompt.php`** : prompt fondé sur les mesures usuelles du commerce de détail —
  sell-through, couverture, GMROI, classement ABC, courbe de tailles, open-to-buy.
- Il insiste sur le **biais de rupture** : une baisse de ventes ne prouve pas une baisse de
  demande, on ne vend pas ce qu'on n'a pas en rayon. Le modèle doit recouper avec le stock
  avant de conclure à un déclin — et le dire quand il ne peut pas trancher.
- Sa dernière section demande **quelles données nous manquent** pour décider correctement,
  ce que chacune permettrait, et par quoi commencer.
- **Export complet** : une ligne par vélo, tout l'historique, toutes les colonnes, sans filtre
  de période. C'est le fichier à donner à l'IA. Les agrégats restent, en appoint.
- **Prompt éditable et persistant** (table `vo_settings`) : modifiable depuis la page, avec
  restauration du texte d'origine. Le défaut du code reste la référence.

## 0.5.0 — 2026-07-13

Export, analyse assistée, filtres instantanés, mobile.

- **`export.php`** : cinq jeux de données en CSV (ventes détaillées, stock, rotation par famille,
  ventes par taille, ventes par mois) — des tableaux déjà agrégés, pas un dump brut. Et surtout
  **un prompt prêt à coller** dans Claude ou ChatGPT, déjà rempli avec les chiffres de la période :
  il demande un diagnostic par catégorie et par taille, une projection de fin de saison, une
  recommandation de pré-commande chiffrée, et interdit d'inventer des chiffres.
- **Filtres instantanés** : le champ de recherche filtre le tableau à la frappe (accents ignorés),
  les listes déroulantes se soumettent toutes seules. **Le bouton « Filtrer » disparaît.**
- **Ventes et stock par taille** : nouveau tableau dans le rapport. Une famille peut être saine en
  volume et malade en tailles.
- **Unités et tooltips** : « 32 quoi ? » ne se pose plus — les axes portent leur unité (« vélos
  vendus »), et les en-têtes de colonne obscures ont une infobulle.
- **« Saisonnalité » devient « Ventes mois par mois »**, avec une phrase expliquant à quoi ça sert
  (projeter la fin de saison sans multiplier bêtement par deux).
- **Responsive iPhone** : les colonnes secondaires s'effacent au lieu de forcer un défilement
  horizontal, les tuiles passent en deux colonnes, la navigation défile au doigt sur une ligne.

## 0.4.1 — 2026-07-13

Vocabulaire et geste de vente.

- **« Famille en tension » disparaît.** Personne ne sait ce que ça veut dire. On lit désormais
  « bientôt épuisée », la colonne « Couverture » devient « Mois de stock », et le verdict
  « tendu » devient « bientôt épuisé » / « rupture » devient « épuisé ».
- Une légende sous le tableau de rotation explique en une phrase ce que « mois de stock »
  mesure, et ce que valent les deux seuils.
- `stock.php` : le bouton de vente porte enfin son nom — **« Vendu »** sur la ligne du vélo,
  au lieu d'une icône muette que personne ne remarquait.

## 0.4.0 — 2026-07-13

Enregistrer une vente depuis la page Ventes.

- `ventes.php` : bouton « Enregistrer une vente » en tête de page — on choisit le vélo
  parmi ceux en rayon (groupés par catégorie), la **date du jour est déjà remplie**, le
  client s'autocomplète. Deux clics, sans passer par l'écran du stock.
- `sellBike()` : la vente devient une opération unique dans `includes/catalog.php`.
  `stock.php` et `ventes.php` l'appellent tous les deux au lieu d'en avoir chacun sa copie.
- Un vélo déjà vendu ne peut plus l'être une seconde fois (`WHERE status <> "vendu"`),
  et une date de vente dans le futur est ramenée au jour même.
- Un prix de vente laissé vide continue de signifier « au prix catalogue » : on ne recopie
  pas le catalogue dans `sold_price`, sinon un prix négocié ne se distinguerait plus d'un
  prix jamais saisi. Le catalogue s'affiche en repère dans le champ.

## 0.3.1 — 2026-07-13

Mise au propre du CSS, maintenant qu'il y a du contenu à habiller.

- **Chevauchements corrigés** : rien n'espaçait les blocs de premier niveau. Seul
  `.card + .card` portait une marge, donc deux `.grid` consécutives — ou une `.grid`
  suivie d'une `.card` — se touchaient. `.page > * + *` donne le rythme vertical.
- **Graphiques** : les canvas n'avaient pas de conteneur dimensionné. Chart.js tourne en
  `maintainAspectRatio: false` et ignore l'attribut `height` : sans parent à hauteur fixe,
  le graphique s'effondre ou grandit sans fin.
- **Cohérence** : `.chart` était défini deux fois, dans `admin.css` (240px) et `app.css`
  (260px), tous deux chargés ensemble. Une seule définition, dans `base.css`.
- Cellules à deux niveaux (`.cell-main` / `.cell-sub`) : le détail passe sous le libellé
  au lieu de s'y coller.
- Tuiles KPI alignées (hauteur de libellé minimale, note poussée en bas).
- Plus aucune couleur en dur dans `app.css` : tout passe par les variables du socle.
- Passage responsive sur les filtres et les zones d'action.

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
