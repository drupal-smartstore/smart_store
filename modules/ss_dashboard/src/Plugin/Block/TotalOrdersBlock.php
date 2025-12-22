<?php

declare(strict_types=1);

namespace Drupal\ss_dashboard\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a "Total Orders Chart" block.
 *
 * Displays the total number of completed Commerce orders over the last
 * 6 months, along with percentage growth comparison.
 *
 * @Block(
 *   id = "total_orders_chart_block",
 *   admin_label = @Translation("Total Orders Chart Block")
 * )
 */
final class TotalOrdersBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
  ): self {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   *
   * Builds the chart block showing completed orders in the last 6 months.
   */
  public function build(): array {
    $storage = $this->entityTypeManager->getStorage('commerce_order');

    $labels = [];
    $ordersData = [];

    // Generate last 6 months (including current month).
    for ($i = 5; $i >= 0; $i--) {
      $labels[] = date('M', strtotime("-{$i} months"));

      $start = strtotime("first day of -{$i} months 00:00:00");
      $end = strtotime("last day of -{$i} months 23:59:59");

      $query = $storage->getQuery()
        ->condition('state', 'completed')
        ->condition('completed', $start, '>=')
        ->condition('completed', $end, '<=')
        ->accessCheck(FALSE);

      $orderIds = $query->execute();
      $ordersData[] = count($orderIds);
    }

    $totalOrders = array_sum($ordersData);

    // Calculate percentage growth (last month vs this month).
    $trend = $this->calculatePercentageTrend($ordersData);

    return [
      '#theme' => 'total_orders_block',
      '#content' => [
        'totalOrders' => $totalOrders,
        'percentageIncrease' => $trend['formatted'],
        'class' => $trend['class'],
      ],
      '#attached' => [
        'library' => [
          'ss_dashboard/ss_dashboard.apexcharts',
          'ss_dashboard/ss_dashboard.order_stats',
        ],
        'drupalSettings' => [
          'totalOrders' => [
            'ordersData' => $ordersData,
            'labels' => $labels,
          ],
        ],
      ],
      '#cache' => [
        'tags' => ['commerce_order_list'],
      ],
    ];
  }

  /**
   * Calculates the trend percentage between the last two months.
   *
   * @param array<int, int> $ordersData
   *   List of completed order counts.
   *
   * @return array
   *   Contains:
   *   - percentage (float)
   *   - arrow (string)
   *   - formatted (string) "▲ 12%" etc.
   */
  private function calculatePercentageTrend(array $ordersData): array {
    $count = count($ordersData);

    if ($count < 2) {
      return [
        'percentage' => 0.0,
        'arrow' => '=',
        'formatted' => '= 0%',
        'class' => 'equal',

      ];
    }

    $lastMonth = $ordersData[$count - 2];
    $thisMonth = $ordersData[$count - 1];

    if ($lastMonth <= 0) {
      return [
        'percentage' => 0.0,
        'arrow' => '=',
        'formatted' => '= 0%',
        'class' => 'equal',

      ];
    }

    $percentage = round((($thisMonth - $lastMonth) / $lastMonth) * 100, 2);

    $arrow = match (TRUE) {
      $percentage > 0 => '▲',
      $percentage < 0 => '▼',
      default => '=',
    };

    return [
      'percentage' => $percentage,
      'arrow' => $arrow,
      'formatted' => "{$arrow} " . abs($percentage) . "%",
      'class' => $percentage > 0 ? 'increase' : ($percentage < 0 ? 'decrease' : 'equal'),
    ];
  }

}
