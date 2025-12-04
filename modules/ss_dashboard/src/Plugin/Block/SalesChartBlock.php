<?php

namespace Drupal\ss_dashboard\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'Sales Chart' block.
 *
 * @Block(
 *   id = "commerce_sales_chart_block",
 *   admin_label = @Translation("Commerce Sales Chart Block")
 * )
 */
class SalesChartBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a SalesChartBlock instance.
   *
   * @param array $configuration
   *   A configuration array containing plugin instance information.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $sales_data = [];
    $labels = [];

    // Generate last 6 months data including current month.
    for ($i = 5; $i >= 0; $i--) {
      $month_name = date('M', strtotime("-$i months"));
      $labels[] = $month_name;

      // Calculate first and last timestamps of the month.
      $start = strtotime("first day of -$i months 00:00:00");
      $end = strtotime("last day of -$i months 23:59:59");

      // Query completed orders within the month.
      $query = $this->entityTypeManager->getStorage('commerce_order')->getQuery();
      $query->condition('state', ['completed'], 'IN')
        ->condition('completed', $start, '>=')
        ->condition('completed', $end, '<=')
        ->accessCheck(FALSE);

      $order_ids = $query->execute();
      $orders = $this->entityTypeManager->getStorage('commerce_order')->loadMultiple($order_ids);

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

    // Last 2 months comparison.
    $last_month = $sales_data[count($sales_data) - 2] ?? 0;
    $this_month = $sales_data[count($sales_data) - 1] ?? 0;

    $percentage_change = "0%";
    $arrow = "";

    if ($last_month > 0) {
      $diff = $this_month - $last_month;
      $percent = ($diff / $last_month) * 100;
      $percent = number_format($percent, 2);

      if ($percent >= 0) {
        $arrow = "▲";
        $percentage_change = "$arrow $percent%";
      }
      else {
        $arrow = "▼";
        $percentage_change = "$arrow " . abs($percent) . "%";
      }
    }

    return [
      '#theme' => 'sales_chart_block',
      '#content' => [
        'total_sales' => $total_sales,
        'percentage_change' => $percentage_change,
      ],
      '#attached' => [
        'library' => [
          'ss_dashboard/ss_dashboard.sales_chart',
        ],
        'drupalSettings' => [
          'totalSalesData' => [
            'salesData' => $sales_data,
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
