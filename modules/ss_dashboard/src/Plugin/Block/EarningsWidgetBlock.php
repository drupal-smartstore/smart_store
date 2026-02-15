<?php

declare(strict_types=1);

namespace Drupal\ss_dashboard\Plugin\Block;

use Drupal\commerce_order\Entity\Order;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an earningswidget block.
 *
 * @Block(
 *   id = "earnings_widget_block",
 *   admin_label = @Translation("EarningsWidgetBlock"),
 *   category = @Translation("Custom"),
 * )
 */
final class EarningsWidgetBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The Config Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

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
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Config Factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;

  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition,): self {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Loads revenue, orders, and trend for CURRENT MONTH only.
   */
  public function build(): array {
    $profit_margin = $this->configFactory->get('my_dashboard.earnings_settings')->get('profit_margin') ?? 10;

    $data = $this->loadMonthlyData($profit_margin);

    return [
      '#theme' => 'earnings_widget_block',
      '#content' => [
        'total_revenue' => '$' . number_format($data['total_revenue'], 2),
        'total_profit' => '$' . number_format($data['total_profit'], 2),
      ],
      '#attached' => [
        'library' => [
          'ss_dashboard/ss_dashboard.apexcharts',
          'ss_dashboard/ss_dashboard.earnings_widget',
        ],
        'drupalSettings' => [
          'totalEarningData' => [
            'profitData' => $data['profit'],
            'labels' => $data['labels'],
            'revenueData' => $data['revenue'],
          ],
        ],
      ],
      '#cache' => [
        'tags' => ['commerce_order_list'],
      ],
    ];
  }

  /**
   * Loads revenue, orders, and trend for CURRENT MONTH only.
   */
  private function loadMonthlyData(float $profit_margin) {
    $monthLabels = [];
    $revenueData = [];
    $profitData = [];

    for ($i = 5; $i >= 0; $i--) {

      // Month label.
      $monthLabels[] = date('M', strtotime("-{$i} months"));

      // Month start and end timestamps.
      $start = strtotime("first day of -{$i} months 00:00:00");
      $end = strtotime("last day of -{$i} months 23:59:59");

      // Revenue for this month.
      $revenue = $this->calculateOrderStats($start, $end);

      // Profit calculation.
      $profit = $revenue * ($profit_margin / 100);

      $revenueData[] = $revenue;
      $profitData[] = $profit;
    }

    $total_revenue = array_sum($revenueData);
    $total_profit = array_sum($profitData);

    return [
      'total_revenue' => $total_revenue,
      'total_profit' => $total_profit,
      'labels' => $monthLabels,
      'revenue' => $revenueData,
      'profit' => $profitData,
    ];
  }

  /**
   * Query Commerce orders for revenue + count within a date range.
   */
  private function calculateOrderStats($start, $end) {
    $storage = $this->entityTypeManager->getStorage('commerce_order');
    $total_revenue = 0;
    $query = $storage->getQuery()
      ->condition('state', 'completed')
      ->condition('completed', $start, '>=')
      ->condition('completed', $end, '<=')
      ->accessCheck(FALSE);

    $order_ids = $query->execute();

    if (empty($order_ids)) {
      return $total_revenue;
    }

    $orders = Order::loadMultiple($order_ids);

    foreach ($orders as $order) {
      $total_revenue += (float) $order->getTotalPrice()->getNumber();
    }

    return $total_revenue;
  }

}
