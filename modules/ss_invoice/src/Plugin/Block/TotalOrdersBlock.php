<?php

namespace Drupal\ss_invoice\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a 'Total Orders Chart' block.
 *
 * @Block(
 *   id = "total_orders_chart_block",
 *   admin_label = @Translation("Total Orders Chart Block")
 * )
 */
class TotalOrdersBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $labels = [];
    $orders_data = [];
    $current = new \DateTime();

    // Last 8 months.
    for ($i = 7; $i >= 0; $i--) {
      $month = (clone $current)->modify("-$i months");
      $start = strtotime($month->format('Y-m-01 00:00:00'));
      $end = strtotime($month->format('Y-m-t 23:59:59'));

      $query = \Drupal::entityQuery('commerce_order')
        ->condition('state', 'completed')
        ->condition('placed', $start, '>=')
        ->condition('placed', $end, '<=')
        ->accessCheck(FALSE);

      $order_ids = $query->execute();
      $orders_count = count($order_ids);

      $labels[] = $month->format('M Y');
      $orders_data[] = $orders_count;
    }

    $last_month = $orders_data[6] ?? 0;
    $this_month = $orders_data[7] ?? 0;
    $percentage_increase = $last_month ? round((($this_month - $last_month) / $last_month) * 100, 2) : 0;

    return [
      '#theme' => 'total_orders_block',
      '#attached' => [
        'library' => ['ss_invoice/total_orders_chart'],
        'drupalSettings' => [
          'ss_invoice' => [
            'ordersData' => $orders_data,
            'labels' => $labels,
            'percentageIncrease' => $percentage_increase,
            'thisMonthOrders' => $this_month,
          ],
        ],
      ],
    ];
  }

}
