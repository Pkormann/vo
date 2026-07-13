/*
 * Case « tout sélectionner » de la liste des doublons.
 */
(function () {
    'use strict';

    const master = document.querySelector('.js-check-all');
    const boxes  = document.querySelectorAll('.js-check');

    if (!master || !boxes.length) {
        return;
    }

    master.checked = true;

    master.addEventListener('change', function () {
        boxes.forEach(function (box) {
            box.checked = master.checked;
        });
    });

    // Décocher une ligne décoche la case maîtresse : elle ne doit pas prétendre
    // que tout est sélectionné alors que ce n'est plus vrai.
    boxes.forEach(function (box) {
        box.addEventListener('change', function () {
            master.checked = Array.from(boxes).every(b => b.checked);
        });
    });
})();
