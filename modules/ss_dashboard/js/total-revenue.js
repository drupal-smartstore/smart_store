(function (Drupal, drupalSettings, once) {
  Drupal.behaviors.totalRevenueChart = {
    attach: function (context, settings) {

      if (typeof ApexCharts === "undefined") {
        console.error("ApexCharts not loaded");
        return;
      }

      const chartEl = document.querySelector('#revenue-chart');
      if (!chartEl || chartEl.dataset.rendered === "true") return;

      chartEl.dataset.rendered = "true";

      const data = drupalSettings.totalRevenueData;
      const labels = data.labels || [];
      const values = data.monthlyRevenue || [];

      const options = {
        chart: {
          type: "area",
          height: 150,
          sparkline: { enabled: false }
        },
        stroke: {
          curve: "smooth",
          width: 3,
          colors: ["#f00000"]
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
            name: 'Revenue',
            data: values
          }
        ],
        xaxis: {
          categories: labels,
          labels: { show: true }
        },
        yaxis: { labels: { show: true } },
        dataLabels: { enabled: false },
        tooltip: { enabled: true }
      };

      // Render chart
      new ApexCharts(chartEl, options).render();
    }
  };
})(Drupal, drupalSettings, once);
