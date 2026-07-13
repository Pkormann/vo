/*
 * Modales génériques.
 *
 * Un bouton `.js-modal` ouvre la modale `#<data-modal>` et recopie ses
 * attributs data-* dans les cibles `.js-<nom>` de la modale :
 *   data-user-id  → .js-user-id  (input : value)
 *   data-username → .js-username (élément : texte)
 *   data-role     → <select name="role"> : option sélectionnée
 */
(function () {
    'use strict';

    function fill(modal, dataset) {
        Object.keys(dataset).forEach(function (key) {
            const value  = dataset[key];
            const kebab  = key.replace(/[A-Z]/g, m => '-' + m.toLowerCase());
            const target = modal.querySelector('.js-' + kebab);

            if (!target) {
                return;
            }

            if ('value' in target && target.tagName === 'INPUT') {
                target.value = value;
            } else {
                target.textContent = value;
            }
        });

        const select = modal.querySelector('select[name="role"]');
        if (select && dataset.role) {
            select.value = dataset.role;
        }
    }

    function close(modal) {
        modal.classList.remove('is-open');
    }

    document.querySelectorAll('.js-modal').forEach(function (trigger) {
        trigger.addEventListener('click', function () {
            const modal = document.getElementById(trigger.dataset.modal);
            if (!modal) {
                return;
            }

            fill(modal, trigger.dataset);
            modal.classList.add('is-open');

            const firstInput = modal.querySelector('input:not([type="hidden"]), select');
            if (firstInput) {
                firstInput.focus();
            }
        });
    });

    document.querySelectorAll('.modal').forEach(function (modal) {
        modal.querySelectorAll('.js-close').forEach(function (btn) {
            btn.addEventListener('click', () => close(modal));
        });

        // Clic sur le fond (hors de la boîte) : ferme.
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                close(modal);
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            document.querySelectorAll('.modal.is-open').forEach(close);
        }
    });
})();
