(function (Drupal, drupalSettings, once) {
  Drupal.behaviors.totalUsersChart = {
    attach: function (context) {

      const charts = once('total-users-chart', '#total-users-chart', context);
      if (!charts.length) return;

      const data = drupalSettings.ss_invoice.totalUsers;

      const totalUsers = data.totalUsers || 0;
      const thisMonthUsers = data.thisMonthUsers || 0;
      const percentageIncrease = data.percentageIncrease || 0;
      const labels = data.labels || [];
      const values = data.values || [];

      // Update UI boxes (showing total users)
      document.getElementById('total-users-number').textContent = totalUsers;
      document.getElementById('total-users-percentage').textContent = percentageIncrease + "%";

      const options = {
        chart: {
          type: "area",
          height: 120,
          sparkline: { enabled: true }
        },

        series: [{
          name: "Users Created",
          data: values
        }],

        xaxis: {
          categories: labels,
          type: "category",
        },

        tooltip: {
          x: {
            formatter: (value) => value // Keep label as "YYYY-MM"
          }
        },

        stroke: {
          curve: "smooth",
          width: 3,
          colors: ['#2377FC']
        },

        fill: {
          type: 'gradient',
          gradient: {
            opacityFrom: 0.6,
            opacityTo: 0.1
          }
        }
      };

      new ApexCharts(charts[0], options).render();
    }
  };
})(Drupal, drupalSettings, once);
