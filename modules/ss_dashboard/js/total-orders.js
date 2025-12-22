(function (Drupal, drupalSettings, once) {
  Drupal.behaviors.totalOrdersApexChart = {
    attach: function (context, settings) {

      // Ensure chart renders only once.
      const chartEl = document.querySelector('#orders-chart');
      if (!chartEl || chartEl.dataset.rendered === "true") return;

      chartEl.dataset.rendered = "true";

      // Safely read settings
      const dashboardSettings = drupalSettings.totalOrders || {};
      const ordersData = dashboardSettings.ordersData || [];
      const labels = dashboardSettings.labels || [];

      if (!ordersData.length) return;

      const options = {
        chart: {
          type: "area",
          height: 150,
          sparkline: { enabled: false }  // Show x-axis
        },
        stroke: {
          curve: "smooth",
          width: 3,
          colors: ["#2341e9ff"]
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
            name: 'Orders',
            data: ordersData
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
