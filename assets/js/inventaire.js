/*
 * Pointage de l'inventaire.
 *
 * Chaque clic part immédiatement au serveur : le pointage se fait dans l'atelier,
 * au téléphone, et perdre sa place au 60e vélo sur 90 serait le meilleur moyen de
 * ne jamais finir un inventaire.
 *
 * Recliquer sur un bouton déjà actif annule le pointage — on se trompe, et il
 * faut pouvoir revenir en arrière sans recharger.
 */
(function () {
    'use strict';

    const table = document.querySelector('.js-filterable[data-url]');
    if (!table) {
        return;
    }

    const url  = table.dataset.url;
    const csrf = table.dataset.csrf;

    function refreshStats(stats) {
        const map = {
            '.js-stat-seen':    stats.seen,
            '.js-stat-missing': stats.missing,
            '.js-stat-pending': stats.pending
        };

        Object.keys(map).forEach(function (selector) {
            const node = document.querySelector(selector);
            if (node) {
                node.textContent = map[selector];
            }
        });
    }

    async function mark(row, seen) {
        const body = new FormData();
        body.append('csrf_token', csrf);
        body.append('item_id', row.dataset.item);
        body.append('seen', seen === null ? '' : String(seen));

        row.classList.add('is-saving');

        try {
            const response = await fetch(url, { method: 'POST', body: body });
            const data     = await response.json();

            if (!data.ok) {
                throw new Error('refus du serveur');
            }

            row.classList.remove('is-seen', 'is-missing');
            if (seen === 1) {
                row.classList.add('is-seen');
            } else if (seen === 0) {
                row.classList.add('is-missing');
            }

            refreshStats(data.stats);
        } catch (error) {
            // Le pointage n'a pas été enregistré : le dire, plutôt que de laisser
            // croire à un comptage qui n'existe pas côté serveur.
            row.classList.add('is-error');
            window.setTimeout(() => row.classList.remove('is-error'), 2000);
        } finally {
            row.classList.remove('is-saving');
        }
    }

    table.addEventListener('click', function (event) {
        const button = event.target.closest('.js-seen, .js-missing');
        if (!button) {
            return;
        }

        const row     = button.closest('.js-item');
        const wantSeen = button.classList.contains('js-seen');
        const already = wantSeen ? row.classList.contains('is-seen')
                                 : row.classList.contains('is-missing');

        mark(row, already ? null : (wantSeen ? 1 : 0));
    });
})();
