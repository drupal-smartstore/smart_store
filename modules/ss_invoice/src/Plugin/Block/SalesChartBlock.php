<?php

namespace Drupal\ss_invoice\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a 'Sales Chart' block.
 *
 * @Block(
 *   id = "commerce_sales_chart_block",
 *   admin_label = @Translation("Commerce Sales Chart Block")
 * )
 */
class SalesChartBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {

    $sales_data = [];
    $labels = [];

    for ($i = 7; $i >= 0; $i--) {
      $month_name = date("M", strtotime("-$i months"));
      $labels[] = $month_name;

      $start = strtotime("first day of -$i months 00:00:00");
      $end = strtotime("last day of -$i months 23:59:59");

      $query = \Drupal::entityQuery('commerce_order')
        ->condition('state', ['completed'], 'IN')
        ->condition('completed', $start, '>=')
        ->condition('completed', $end, '<=')
        ->accessCheck(FALSE);

      $order_ids = $query->execute();
      $orders = \Drupal::entityTypeManager()
        ->getStorage('commerce_order')
        ->loadMultiple($order_ids);

      $total = 0;
      foreach ($orders as $order) {
        if ($order->getTotalPrice()) {
          $total += (float) $order->getTotalPrice()->getNumber();
        }
      }

      $sales_data[] = round($total);
    }

    // Total sales of 8 months.
    $total_sales = array_sum($sales_data);

    return [
      '#theme' => 'sales_chart_block',
      '#sales_data' => $sales_data,
      '#labels' => $labels,
      '#total_sales' => $total_sales,
      '#attached' => [

        'library' => ['ss_invoice/sales_chart'],
        'drupalSettings' => [
          'ss_invoice' => [
            'salesData' => $sales_data,
            'labels' => $labels,
            'totalSales' => $total_sales,
          ],
        ],
      ],
    ];
  }

}
