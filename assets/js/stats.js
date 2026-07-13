/*
 * Graphiques de la page statistiques.
 * Les données sont agrégées côté PHP et injectées dans #stats-data.
 */
(function () {
    'use strict';

    const node = document.getElementById('stats-data');
    if (!node || typeof Chart === 'undefined') {
        return;
    }

    const data = JSON.parse(node.textContent);

    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.color = '#6b7280';

    const gridColor = '#e4e6ea';

    new Chart(document.getElementById('chartDaily'), {
        type: 'bar',
        data: {
            labels: data.daily.labels,
            datasets: [
                { label: 'Réussies', data: data.daily.ok, backgroundColor: '#2f855a' },
                { label: 'Échouées', data: data.daily.ko, backgroundColor: '#c53030' }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } },
            scales: {
                x: { stacked: true, grid: { display: false } },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    ticks: { precision: 0 },
                    grid: { color: gridColor }
                }
            }
        }
    });

    function doughnut(canvasId, set) {
        if (!set.values.length) {
            return;
        }

        new Chart(document.getElementById(canvasId), {
            type: 'doughnut',
            data: {
                labels: set.labels,
                datasets: [{
                    data: set.values,
                    backgroundColor: set.colors,
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '58%',
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }

    doughnut('chartOs', data.os);
    doughnut('chartDevice', data.device);
})();
