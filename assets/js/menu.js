/*
 * Navigation : menus déroulants en bureau, tiroir en mobile.
 *
 * Le markup est le même dans les deux cas — c'est le CSS qui décide. Ce script
 * ne gère donc que l'ouverture et la fermeture, jamais la mise en forme.
 */
(function () {
    'use strict';

    // --- Menus déroulants (bureau) ------------------------------------------
    const menus = Array.from(document.querySelectorAll('.menu'));

    function closeMenus(except) {
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
            closeMenus(menu);
            menu.classList.toggle('is-open', !open);
        });
    });

    // --- Tiroir (mobile) ----------------------------------------------------
    const nav     = document.querySelector('.js-nav');
    const toggle  = document.querySelector('.js-nav-toggle');
    const overlay = document.querySelector('.js-nav-close');

    function setDrawer(open) {
        if (!nav) {
            return;
        }

        nav.classList.toggle('is-open', open);
        document.body.classList.toggle('nav-open', open);

        if (overlay) {
            overlay.classList.toggle('is-open', open);
        }

        if (toggle) {
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    }

    if (toggle) {
        toggle.addEventListener('click', function (event) {
            event.stopPropagation();
            setDrawer(!nav.classList.contains('is-open'));
        });
    }

    if (overlay) {
        overlay.addEventListener('click', () => setDrawer(false));
    }

    // Suivre un lien ferme le tiroir : sinon il resterait ouvert par-dessus la
    // page d'arrivée, au retour du navigateur.
    if (nav) {
        nav.addEventListener('click', function (event) {
            if (event.target.closest('a')) {
                setDrawer(false);
            }
        });
    }

    document.addEventListener('click', function () {
        closeMenus(null);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeMenus(null);
            setDrawer(false);
        }
    });
})();
