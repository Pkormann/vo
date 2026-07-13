/*
 * Filtrage des tableaux, sans rechargement.
 *
 * Deux mécanismes, et la distinction est volontaire :
 *
 *  - Les listes déroulantes (catégorie, marque, période) touchent aux totaux
 *    affichés en tête de page : elles doivent repartir au serveur. On les
 *    soumet donc automatiquement au changement — d'où la disparition du bouton
 *    « Filtrer », devenu inutile.
 *
 *  - Le champ de recherche filtre les lignes déjà à l'écran, à la frappe. C'est
 *    instantané, mais ça ne masque que des lignes : le compteur de la carte est
 *    donc mis à jour pour ne pas mentir sur ce qu'on voit.
 */
(function () {
    'use strict';

    // --- Listes déroulantes : soumission automatique ------------------------
    document.querySelectorAll('.js-autosubmit select').forEach(function (select) {
        select.addEventListener('change', function () {
            select.form.submit();
        });
    });

    // --- Recherche instantanée ---------------------------------------------
    const input = document.querySelector('.js-filter');
    if (!input) {
        return;
    }

    const table = document.querySelector(input.dataset.target || '.js-filterable');
    if (!table) {
        return;
    }

    const rows    = Array.from(table.querySelectorAll('tbody tr'));
    const counter = document.querySelector('.js-filter-count');
    const total   = rows.length;

    // La recherche ignore les accents et la casse : « supersix » doit trouver
    // « SuperSix », et « velo » doit trouver « vélo ».
    function normalize(text) {
        return text
            .toLowerCase()
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '');
    }

    const haystacks = rows.map(row => normalize(row.textContent));

    function apply() {
        const needle = normalize(input.value.trim());
        let shown = 0;

        rows.forEach(function (row, i) {
            const match = needle === '' || haystacks[i].includes(needle);
            row.hidden = !match;
            if (match) {
                shown++;
            }
        });

        if (counter) {
            counter.textContent = shown === total
                ? total
                : shown + ' sur ' + total;
        }

        table.classList.toggle('is-empty', shown === 0);
    }

    input.addEventListener('input', apply);

    // Entrée ne doit pas recharger la page : le filtrage est déjà fait.
    input.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
        }
        if (event.key === 'Escape') {
            input.value = '';
            apply();
        }
    });
})();
