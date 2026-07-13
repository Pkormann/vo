/*
 * Graphiques du rapport. Les données sont agrégées côté PHP et injectées
 * dans #chart-data — aucun calcul métier ici, seulement du dessin.
 */
(function () {
    'use strict';

    const node = document.getElementById('chart-data');
    if (!node || typeof Chart === 'undefined') {
        return;
    }

    const data = JSON.parse(node.textContent);

    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.color = '#6b7280';

    const gridColor = '#e4e6ea';
    const ink       = '#111214';
    const inkLight  = '#c8cbd0';

    const MOIS = ['jan', 'fév', 'mar', 'avr', 'mai', 'jun', 'jul', 'aoû', 'sep', 'oct', 'nov', 'déc'];

    // Année en cours en noir, année précédente en gris : la comparaison doit se
    // lire sans légende.
    new Chart(document.getElementById('chart-categories'), {
        type: 'bar',
        data: {
            labels: data.categories,
            datasets: [
                { label: data.labelPrev, data: data.soldPrev, backgroundColor: inkLight },
                { label: data.labelThis, data: data.soldThis, backgroundColor: ink }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } },
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: gridColor } }
            }
        }
    });

    new Chart(document.getElementById('chart-months'), {
        type: 'line',
        data: {
            labels: MOIS,
            datasets: [
                {
                    label: String(data.yearPrev),
                    data: data.monthsPrev,
                    borderColor: inkLight,
                    backgroundColor: inkLight,
                    tension: 0.3
                },
                {
                    label: String(data.yearThis),
                    data: data.monthsThis,
                    borderColor: ink,
                    backgroundColor: ink,
                    tension: 0.3
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } },
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: gridColor } }
            }
        }
    });

    // Valeur du stock par catégorie : une seule série, donc un dégradé de gris
    // suffit — la couleur n'apporterait aucun sens supplémentaire.
    const greys = ['#111214', '#3d4148', '#6b7280', '#9aa0a8', '#c8cbd0', '#dfe1e5', '#eff0f2'];

    new Chart(document.getElementById('chart-stock'), {
        type: 'doughnut',
        data: {
            labels: data.categories,
            datasets: [{ data: data.stockValue, backgroundColor: greys, borderWidth: 0 }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '58%',
            plugins: {
                legend: { position: 'right' },
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            const value = ctx.parsed || 0;
                            return ctx.label + ' : ' + value.toLocaleString('fr-CH') + ' CHF';
                        }
                    }
                }
            }
        }
    });
})();
