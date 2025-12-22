(function (Drupal, drupalSettings, once) {
  Drupal.behaviors.totalUsersChart = {
    attach: function (context, settings) {

      const chartEl = document.querySelector('#total-users');
      if (!chartEl || chartEl.dataset.rendered === "true") return;

      chartEl.dataset.rendered = "true";

      const data = drupalSettings.totalUsersData;

      const labels = data.labels || [];
      const values = data.usersData || [];

      const options = {
        chart: {
          type: "area",
          height: 150,
          sparkline: { enabled: false }
        },
        stroke: {
          curve: "smooth",
          width: 3,
          colors: ["#2377fc"]
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
            name: 'Users',
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
