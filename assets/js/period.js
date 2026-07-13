/*
 * Sélecteur de période : les deux champs de dates n'apparaissent que pour
 * « Dates au choix ». Sans JS, ils restent visibles — le formulaire fonctionne
 * quand même, il est juste moins net.
 */
(function () {
    'use strict';

    document.querySelectorAll('.period-filter').forEach(function (form) {
        const select = form.querySelector('.js-range');
        const dates  = form.querySelectorAll('.js-custom-dates');

        if (!select) {
            return;
        }

        function sync() {
            const custom = select.value === 'custom';
            dates.forEach(function (field) {
                field.hidden = !custom;
            });
        }

        select.addEventListener('change', function () {
            sync();
            // Un préréglage n'a pas besoin d'être confirmé : il s'applique tout seul.
            if (select.value !== 'custom') {
                form.submit();
            }
        });

        sync();
    });
})();
