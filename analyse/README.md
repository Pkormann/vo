# Espace de travail local

Ce dossier **n'est jamais versionné ni déployé** (`.gitignore` + `--exclude` du workflow rsync).
Seul ce README est suivi par git, pour que le dossier existe après un clone.

## Ce qu'on y met

- Les fichiers Excel bruts du magasin (données commerciales — ne doivent pas quitter la machine).
- Les rapports d'analyse en `.md` générés au fil des explorations.
- Les scripts jetables, exports intermédiaires, notes de travail.

## Ce qu'on n'y met pas

Rien qui doive tourner en production. Dès qu'un script devient un outil, il sort d'ici
et rejoint l'arborescence du site (avec specs et bump de version).
