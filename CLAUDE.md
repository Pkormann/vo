# VO — Outils Version Originale Cycles

Magasin/atelier de vélo à Yverdon-les-Bains (vo-cycles.ch). Client : Raoul (associé, vente).
Objectif : analyser les fichiers Excel du magasin, produire des outils d'analyse, de visualisation
et de liaison fournisseurs/clients. Une partie tourne en local, une partie est publiée en ligne.

## Stack

PHP 8+, MySQLi, sessions natives, pas de framework. Front : JS vanilla, Chart.js (CDN).
Prod : Infomaniak, `web/vo/`, déployé par GitHub Actions (rsync) sur push `main`.
BDD MySQL **partagée** avec d'autres projets → toutes nos tables sont préfixées `vo_` (`DB_PREFIX`).

## Règles non négociables

- **Versionnage** : bumper `APP_VERSION` + `APP_VERSION_DATE` dans `config/version.php` à *chaque*
  modification, et ajouter une ligne de changelog dans `CHANGELOG.md`.
  MAJEUR = incompatible · MINEUR = feature · PATCH = bug/CSS/texte.
- **specs.md** : mis à jour à chaque changement de schéma, d'endpoint ou de comportement.
  Pas de modif sans mise à jour des specs.
- **Version affichée** en bas de chaque page (via `includes/layout.php`).
- **CSS centralisé** dans `assets/css/`. Pas de `style="..."` inline, pas de `<style>` en page.
- **Pas de duplication** : tout ce qui sert deux fois va dans `includes/` ou `config/`.
- **Aucune intervention manuelle sur le serveur.** Toute opération BDD passe par un script
  `install/*.php` protégé par token, qui s'auto-supprime après succès.
- **`analyse/` n'est jamais poussé** (ni git, ni serveur). C'est l'espace de travail local :
  notes, explorations Excel, rapports .md.
- Les fichiers Excel du magasin ne sont **jamais** commités (données commerciales).

## Préférences

- Réponses en français, franches, sans complaisance. Pas de flatterie.
- Commits : sujet ≤ 50 car., corps détaillé si besoin. **Je ne commite jamais moi-même** —
  je propose le message à la fin.
- Icônes : Font Awesome (free) ou Lucide, en nuances de gris/noir. Pas de couleurs vives
  sauf sémantique (succès/erreur/badges de rôle).
- Markdown : pas de hard wrap, retours à la ligne sémantiques.
- Style visuel : épuré, noir/blanc/gris, dans l'esprit de vo-cycles.ch.

## Rôles

`owner` (accès total : audit, stats, users) · `admin` (pages applicatives) · `user` (restreint).
