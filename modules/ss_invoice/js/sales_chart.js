(function (Drupal, drupalSettings) {
  Drupal.behaviors.salesChart = {
    attach: function (context, settings) {
      const data = drupalSettings.ss_invoice.salesData || [];
      const labels = drupalSettings.ss_invoice.labels || [];

      if (!data.length) return;

      // Prevent multiple renders
      const chartEl = document.querySelector("#sales-chart");
      if (!chartEl || chartEl.dataset.rendered === "true") {
        return;
      }
      chartEl.dataset.rendered = "true";

      // Total sales = sum of all 8 months
      const totalSales = data.reduce((a, b) => a + b, 0);

      // Show total sales
      document.querySelector(".total-sales span").innerHTML =
        totalSales.toLocaleString("en-IN");

      // Month-to-month percentage
      const lastMonth = data[data.length - 2] || 0;
      const thisMonth = data[data.length - 1] || 0;

      let percentageText = "";
      if (lastMonth > 0) {
        const diff = thisMonth - lastMonth;
        const percent = ((diff / lastMonth) * 100).toFixed(2);
        percentageText = percent >= 0 ? `▲ ${percent}%` : `▼ -${Math.abs(percent)}%`;
      } else if (thisMonth > 0) {
        percentageText = "▲ New!";
      } else {
        percentageText = "0%";
      }

      document.querySelector(".percentage-change").innerHTML = percentageText;

      // Render chart only once
      const options = {
        chart: { type: "area", height: 150, sparkline: { enabled: true } },
        stroke: { curve: "smooth", width: 3, colors: ["#5fc490"] },
        fill: {
          type: "gradient",
          gradient: {
            shadeIntensity: 1,
            opacityFrom: 0.5,
            opacityTo: 0.2,
            colorStops: [
              { offset: 0, color: "#5fc490", opacity: 0.7 },
              { offset: 100, color: "#f0f9f5", opacity: 0.2 }
            ]
          }
        },
        series: [{ data }],
        xaxis: { categories: labels }
      };

      new ApexCharts(chartEl, options).render();
    }
  };
})(Drupal, drupalSettings);
