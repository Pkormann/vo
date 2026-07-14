/*
 * Menus déroulants de la barre de navigation.
 *
 * Un seul ouvert à la fois. Se ferment au clic ailleurs, à Échap, et après avoir
 * suivi un lien — sinon le menu resterait ouvert par-dessus la page d'arrivée.
 */
(function () {
    'use strict';

    const menus = Array.from(document.querySelectorAll('.menu'));
    if (!menus.length) {
        return;
    }

    function closeAll(except) {
        menus.forEach(function (menu) {
            if (menu !== except) {
                menu.classList.remove('is-open');
            }
        });
    }

    menus.forEach(function (menu) {
        const toggle = menu.querySelector('.js-menu');

        toggle.addEventListener('click', function (event) {
            event.stopPropagation();
            const open = menu.classList.contains('is-open');
            closeAll(menu);
            menu.classList.toggle('is-open', !open);
        });
    });

    document.addEventListener('click', function () {
        closeAll(null);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeAll(null);
        }
    });
})();
