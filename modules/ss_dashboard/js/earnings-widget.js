(function (Drupal, drupalSettings) {
    Drupal.behaviors.earningWidget = {
        attach: function (context, settings) {

            const data = drupalSettings.totalEarningData || {};
            const profitData = data.profitData || [];
            const revenueData = data.revenueData || [];
            const labels = data.labels || [];
            
            if (!profitData.length) return;

            const chartEl = document.querySelector("#earnings-chart");
            if (!chartEl || chartEl.dataset.rendered === "true") return;

            chartEl.dataset.rendered = "true";

            const options = {
                series: [{
                    name: 'Profit',
                    data: profitData
                }, {
                    name: 'Revenue',
                    data: revenueData
                }],
                chart: {
                    type: 'bar',
                    height: 350
                },
                plotOptions: {
                    bar: {
                        horizontal: false,
                        columnWidth: '55%',
                        borderRadius: 5,
                        borderRadiusApplication: 'end'
                    },
                },
                stroke: {
                    show: true,
                    width: 2,
                    colors: ['transparent']
                },
                xaxis: {
                    categories: labels,
                },
                fill: {
                    opacity: 1
                },
                yaxis: { labels: { show: true } },
                dataLabels: { enabled: false },
                tooltip: { enabled: true }
            };

            new ApexCharts(chartEl, options).render();
        },
    };
})(Drupal, drupalSettings);
