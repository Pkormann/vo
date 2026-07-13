/* Bascule affichage/masquage du mot de passe. */
(function () {
    'use strict';

    const toggle = document.getElementById('togglePassword');
    const input  = document.getElementById('password');

    if (!toggle || !input) {
        return;
    }

    toggle.addEventListener('click', function () {
        const visible = input.type === 'text';

        input.type = visible ? 'password' : 'text';
        toggle.classList.toggle('is-visible', !visible);
        toggle.setAttribute(
            'aria-label',
            visible ? 'Afficher le mot de passe' : 'Masquer le mot de passe'
        );
        input.focus();
    });
})();
