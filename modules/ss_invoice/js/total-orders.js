(function (Drupal, drupalSettings) {
  Drupal.behaviors.totalOrdersApexChart = {
    attach: function (context, settings) {

      const chartContainer = once('total-orders-chart', '#orders-chart', context);

      if (chartContainer.length === 0) return;

      const ordersData = drupalSettings.ss_invoice.ordersData || [];
      const labels = drupalSettings.ss_invoice.labels || [];
      const percentageIncrease = drupalSettings.ss_invoice.percentageIncrease || 0;
      const thisMonthOrders = drupalSettings.ss_invoice.thisMonthOrders || 0;

      document.querySelector('.total-orders').innerHTML =
        `Orders Paid <span>${thisMonthOrders.toLocaleString()}</span>`;

      document.querySelector('.orders-percentage-change').textContent =
        `${percentageIncrease}%`;

      const options = {
        chart: {
          type: 'area',
          height: 150,
          sparkline: { enabled: true }
        },
        stroke: {
          curve: 'smooth',
          width: 3,
          colors: ['#CBD5E1']
        },
        fill: {
          type: 'gradient',
          gradient: {
            shadeIntensity: 1,
            opacityFrom: 0.5,
            opacityTo: 0.2
          }
        },
        series: [{
          name: 'Orders',
          data: ordersData
        }]
      };

      new ApexCharts(document.querySelector("#orders-chart"), options).render();
    }
  };
})(Drupal, drupalSettings);
