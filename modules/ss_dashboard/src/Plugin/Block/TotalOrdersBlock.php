<?php

namespace Drupal\ss_dashboard\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a "Total Orders Chart" block.
 *
 * Displays last 8 months' completed orders data in a chart.
 *
 * @Block(
 *   id = "total_orders_chart_block",
 *   admin_label = @Translation("Total Orders Chart Block")
 * )
 */
class TotalOrdersBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a new TotalOrdersBlock instance.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * Builds a formatted chart block showing last 8 months of completed orders.
   */
  public function build(): array {
    $labels = [];
    $orders_data = [];

    // Loop through last 6 months.
    for ($i = 5; $i >= 0; $i--) {
      $month_name = date('M', strtotime("-$i months"));
      $labels[] = $month_name;

      // Calculate first and last timestamps of the month.
      $start = strtotime("first day of -$i months 00:00:00");
      $end = strtotime("last day of -$i months 23:59:59");

      // Query completed orders within the month.
      $query = $this->entityTypeManager->getStorage('commerce_order')->getQuery();
      $query->condition('state', 'completed')
        ->condition('completed', $start, '>=')
        ->condition('completed', $end, '<=')
        ->accessCheck(FALSE);

      $order_ids = $query->execute();
      $orders_count = count($order_ids);

      $orders_data[] = $orders_count;
    }

    $totalOrders = array_sum($orders_data);

    // Calculate month-to-month percentage increase.
    $last_month = $orders_data[6] ?? 0;
    $this_month = $orders_data[7] ?? 0;
    $percentage_increase = ($last_month > 0)
      ? round((($this_month - $last_month) / $last_month) * 100, 2)
      : 0;

    // dd($labels, $orders_data, $percentage_increase, $this_month);.
    return [
      '#theme' => 'total_orders_block',
      '#content' => [
        'totalOrders' => $totalOrders,
        'percentageIncrease' => $percentage_increase,
      ],
      '#attached' => [
        'library' => ['ss_dashboard/ss_dashboard.order_stats'],
        'drupalSettings' => [
          'totalOrders' => [
            'ordersData' => $orders_data,
            'labels' => $labels,
          ],
        ],
      ],
      '#cache' => [
        'tags' => ['commerce_order_list'],
      ],
    ];
  }

}
