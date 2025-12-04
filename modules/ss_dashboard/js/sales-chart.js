(function (Drupal, drupalSettings) {
    Drupal.behaviors.salesChart = {
        attach: function (context) {

            const settings = drupalSettings.totalSalesData || {};

            const data = settings.salesData || [];
            const labels = settings.labels || [];

            if (!data.length) return;

            const chartEl = document.querySelector("#sales-chart");
            if (!chartEl || chartEl.dataset.rendered === "true") return;

            chartEl.dataset.rendered = "true";

            const options = {
                chart: {
                    type: "area",
                    height: 150,
                    sparkline: { enabled: false }  // Show x-axis
                },
                stroke: {
                    curve: "smooth",
                    width: 3,
                    colors: ["#5fc490"]
                },
                fill: {
                    type: "gradient",
                    gradient: {
                        shadeIntensity: 1,
                        opacityFrom: 0.5,
                        opacityTo: 0.2
                    }
                },
                series: [
                    {
                        name: "Monthly Sales",
                        data: data   // Monthly sales numbers
                    }
                ],
                xaxis: {
                    categories: labels,  // Month labels
                    labels: { show: true }
                },
                yaxis: { labels: { show: true } },
                dataLabels: { enabled: false },
                tooltip: { enabled: true }
            };

            new ApexCharts(chartEl, options).render();
        },
    };
})(Drupal, drupalSettings);
