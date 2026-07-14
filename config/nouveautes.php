<?php
/**
 * Notes de version — ce qui a changé, écrit pour Raoul, pas pour un développeur.
 *
 * Volontairement séparé de CHANGELOG.md, qui est technique (et d'ailleurs jamais
 * déployé : le rsync exclut les *.md). Un titre de commit comme « bind_param
 * cohérents » ne dit rien à un vendeur de vélos. Ici on écrit ce que ça change
 * pour lui.
 *
 * La plus récente en premier. Une entrée = une version livrée.
 */

const NOUVEAUTES = [
    [
        'version' => '0.11.0',
        'date'    => '2026-07-14',
        'titre'   => 'Journal des actions et notes de version',
        'texte'   => 'Le propriétaire peut désormais consulter qui a fait quoi dans l\'application : '
                   . 'ventes, réservations, suppressions, imports, exports. Et cette page, qui liste '
                   . 'les nouveautés au fil des livraisons.',
    ],
    [
        'version' => '0.10.0',
        'date'    => '2026-07-14',
        'titre'   => 'Mise en place de l\'inventaire de contrôle',
        'texte'   => 'On fige la liste des vélos que l\'application croit avoir en magasin, puis on '
                   . 'pointe le rayon depuis son téléphone : « Là » ou « Absent ». Chaque clic est '
                   . 'enregistré aussitôt, on peut s\'interrompre et reprendre plus tard. Un vélo '
                   . 'introuvable est presque toujours une vente qu\'on a oublié de saisir.',
    ],
    [
        'version' => '0.9.0',
        'date'    => '2026-07-13',
        'titre'   => 'Bouton « Réserver » sur le stock',
        'texte'   => 'Pour un vélo vendu dont la remise au client est prévue plus tard. Il sort '
                   . 'immédiatement du stock disponible et compte dans les ventes : l\'outil de '
                   . 'pré-commande ne croit donc plus avoir en rayon des vélos déjà promis.',
    ],
    [
        'version' => '0.8.0',
        'date'    => '2026-07-13',
        'titre'   => 'Nettoyage des doublons',
        'texte'   => 'Repère les vélos encore « en rayon » alors qu\'un vélo identique figure déjà '
                   . 'comme vendu — l\'héritage des anciens fichiers Excel. Suppression en un lot, '
                   . 'sans jamais toucher à l\'historique des ventes.',
    ],
    [
        'version' => '0.7.0',
        'date'    => '2026-07-13',
        'titre'   => 'Le prompt d\'analyse devient modifiable, et versionné',
        'texte'   => 'Le texte à coller dans ChatGPT ou Claude peut être retravaillé librement : '
                   . 'chaque version est archivée et restaurable. Rien ne peut être perdu.',
    ],
    [
        'version' => '0.6.0',
        'date'    => '2026-07-13',
        'titre'   => 'Export des données et analyse assistée',
        'texte'   => 'Télécharge tes données en un clic, joins-les à ChatGPT ou Claude avec le texte '
                   . 'fourni, et obtiens une analyse des ventes par catégorie et par taille, une '
                   . 'projection de fin de saison, et une proposition de pré-commande.',
    ],
    [
        'version' => '0.5.0',
        'date'    => '2026-07-13',
        'titre'   => 'Filtres instantanés et affichage mobile',
        'texte'   => 'Les listes se filtrent à mesure que tu tapes, sans bouton à valider. '
                   . 'Et l\'application est enfin lisible sur iPhone.',
    ],
    [
        'version' => '0.2.0',
        'date'    => '2026-07-13',
        'titre'   => 'Stock, ventes, rapport et pré-commande',
        'texte'   => 'La première version utile : un vélo devient un exemplaire physique qu\'on suit '
                   . 'de son arrivée à sa vente. Le rapport dit ce qui tourne, ce qui dort et ce qui '
                   . 'manque ; la pré-commande propose des quantités, famille par famille.',
    ],
];
