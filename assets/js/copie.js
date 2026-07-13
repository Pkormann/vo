/*
 * Bouton « Copier » : recopie le contenu d'une cible dans le presse-papier et
 * le confirme, brièvement. Sans confirmation visible, l'utilisateur reclique.
 */
(function () {
    'use strict';

    document.querySelectorAll('.js-copy').forEach(function (button) {
        const target = document.querySelector(button.dataset.copy);
        if (!target) {
            return;
        }

        button.addEventListener('click', async function () {
            const text  = target.value !== undefined ? target.value : target.textContent;
            const label = button.innerHTML;

            try {
                await navigator.clipboard.writeText(text);
            } catch (error) {
                // Navigateur ancien, ou page non sécurisée : on retombe sur la
                // sélection, que l'utilisateur copie lui-même.
                target.select();
                return;
            }

            button.innerHTML = '<i class="fa-solid fa-check"></i> Copié';
            setTimeout(function () {
                button.innerHTML = label;
            }, 1600);
        });
    });
})();
