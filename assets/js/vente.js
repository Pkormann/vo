/*
 * Saisie d'une vente : choisir le vélo suffit, le prix catalogue s'affiche tout
 * seul en repère. Le champ reste vide tant qu'on n'y touche pas — un prix vide
 * veut dire « au catalogue », et c'est ce qui distingue un prix négocié d'un
 * prix jamais saisi.
 */
(function () {
    'use strict';

    const select = document.querySelector('.js-bike-select');
    const price  = document.querySelector('.js-sale-price');

    if (!select || !price) {
        return;
    }

    select.addEventListener('change', function () {
        const option = select.options[select.selectedIndex];
        const listed = option ? option.dataset.price : '';

        price.placeholder = listed ? listed + ' (catalogue)' : 'prix catalogue';
    });
})();
