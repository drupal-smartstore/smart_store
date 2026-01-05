<?php

declare(strict_types=1);

namespace Drupal\ss_dashboard\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a "Total Revenue" block for Commerce orders.
 *
 * @Block(
 *   id = "total_revenue_block",
 *   admin_label = @Translation("Total Revenue Block"),
 *   category = @Translation("Custom"),
 * )
 */
final class TotalRevenueBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a new TotalRevenueBlock instance.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
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
    $labels = [];
    $monthly_revenue = [];

    for ($i = 5; $i >= 0; $i--) {
      $labels[] = date('M', strtotime("-{$i} months"));

      $start = strtotime("first day of -{$i} months 00:00:00");
      $end = strtotime("last day of -{$i} months 23:59:59");

      $query = $storage->getQuery()
        ->condition('state', ['completed', 'fulfilled'], 'IN')
        ->condition('completed', $start, '>=')
        ->condition('completed', $end, '<=')
        ->accessCheck(FALSE);

      $orderIds = $query->execute();
      $orders = $storage->loadMultiple($orderIds);

      $total = 0;
      foreach ($orders as $order) {
        $total += (float) $order->getTotalPrice()->getNumber();
      }

      $monthly_revenue[] = round($total);
    }

    $total_revenue = array_sum($monthly_revenue);
    $percentage_change = $this->calculatePercentageChange($monthly_revenue);
    return [
      '#theme' => 'total_revenue_block',
      '#content' => [
        'total_revenue' => $total_revenue,
        'percentage_change' => $percentage_change['formatted'],
        'class' => $percentage_change['class'],
      ],
      '#attached' => [
        'library' => [
          'ss_dashboard/ss_dashboard.apexcharts',
          'ss_dashboard/ss_dashboard.total_revenue',
        ],
        'drupalSettings' => [
          'totalRevenueData' => [
            'monthlyRevenue' => $monthly_revenue,
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
   * Calculates the percentage change between the last two months.
   *
   * @param array<int, float> $monthly_revenue
   *   Monthly revenue values in chronological order.
   *
   * @return array
   *   Returns an array containing:
   *   - percentage: float
   *   - symbol: string
   *   - formatted: string
   *   - class: string
   */
  private function calculatePercentageChange(array $monthly_revenue): array {
    $count = count($monthly_revenue);

    // Not enough data to calculate a trend.
    if ($count < 2) {
      return [
        'percentage' => 0.0,
        'symbol' => '=',
        'formatted' => '= 0%',
        'class' => 'equal',
      ];
    }

    $previous = $monthly_revenue[$count - 2];
    $current = $monthly_revenue[$count - 1];

    // Prevent division by zero.
    if ($previous <= 0) {
      return [
        'percentage' => 0.0,
        'symbol' => '=',
        'formatted' => '= 0%',
        'class' => 'equal',
      ];
    }

    $percentage = round((($current - $previous) / $previous) * 100, 2);

    $symbol = match (TRUE) {
      $percentage > 0 => '▲',
      $percentage < 0 => '▼',
      default => '=',
    };

    return [
      'percentage' => $percentage,
      'symbol' => $symbol,
      'formatted' => "{$symbol} " . abs($percentage) . '%',
      'class' => $percentage > 0 ? 'increase' : ($percentage < 0 ? 'decrease' : 'equal'),
    ];
  }

}
