<?php

declare(strict_types=1);

namespace Drupal\ss_dashboard\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a "Commerce Sales Chart" block.
 *
 * @Block(
 *   id = "commerce_sales_chart_block",
 *   admin_label = @Translation("Commerce Sales Chart Block")
 * )
 */
final class SalesChartBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a SalesChartBlock instance.
   *
   * @param array $configuration
   *   Plugin configuration array.
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager.
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
   */
  public function build(): array {
    $storage = $this->entityTypeManager->getStorage('commerce_order');

    $salesData = [];
    $monthLabels = [];

    // Generate last 6 months including current month.
    for ($i = 5; $i >= 0; $i--) {
      $monthLabels[] = date('M', strtotime("-{$i} months"));

      $start = strtotime("first day of -{$i} months 00:00:00");
      $end = strtotime("last day of -{$i} months 23:59:59");

      $query = $storage->getQuery()
        ->condition('state', 'completed')
        ->condition('completed', $start, '>=')
        ->condition('completed', $end, '<=')
        ->accessCheck(FALSE);

      $orderIds = $query->execute();
      $orders = $storage->loadMultiple($orderIds);

      $total = 0;
      foreach ($orders as $order) {
        if ($order->getTotalPrice()) {
          $total += (float) $order->getTotalPrice()->getNumber();
        }
      }

      $salesData[] = round($total);
    }

    $totalSales = array_sum($salesData);
    $percentageData = $this->calculateTrend($salesData);

    return [
      '#theme' => 'sales_chart_block',
      '#content' => [
        'total_sales' => $totalSales,
        'percentage_change' => $percentageData['text'],
        'class' => $percentageData['class'],
      ],
      '#attached' => [
        'library' => [
          'ss_dashboard/ss_dashboard.apexcharts',
          'ss_dashboard/ss_dashboard.sales_chart',
        ],
        'drupalSettings' => [
          'totalSalesData' => [
            'salesData' => $salesData,
            'labels' => $monthLabels,
          ],
        ],
      ],
      '#cache' => [
        'tags' => ['commerce_order_list'],
      ],
    ];
  }

  /**
   * Calculates percentage trend and arrow indicator based on last 2 months.
   *
   * @param array<int, float> $salesData
   *   Sales amounts for last 6 months.
   *
   * @return array
   *   Contains:
   *   - percentage: float
   *   - arrow: string
   *   - text: string
   */
  private function calculateTrend(array $salesData): array {
    $count = count($salesData);

    if ($count < 2) {
      return [
        'percentage' => 0.0,
        'arrow' => '=',
        'text' => '= 0%',
        'class' => 'equal',
      ];
    }

    $lastMonth = $salesData[$count - 2] ?? 0;
    $thisMonth = $salesData[$count - 1] ?? 0;

    if ($lastMonth <= 0) {
      return [
        'percentage' => 0.0,
        'arrow' => '=',
        'text' => '= 0%',
        'class' => 'equal',
      ];
    }

    $diff = $thisMonth - $lastMonth;
    $percentage = round(($diff / $lastMonth) * 100, 2);

    // Determine arrow symbol.
    $arrow = match (TRUE) {
      $percentage > 0 => '▲',
      $percentage < 0 => '▼',
      default => '=',
    };

    $displayPercent = abs($percentage);

    return [
      'percentage' => $percentage,
      'arrow' => $arrow,
      'text' => "{$arrow} {$displayPercent}%",
      'class' => $percentage > 0 ? 'increase' : ($percentage < 0 ? 'decrease' : 'equal'),
    ];
  }

}
