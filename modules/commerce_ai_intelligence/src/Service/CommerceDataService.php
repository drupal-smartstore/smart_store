<?php

namespace Drupal\commerce_ai_intelligence\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Provides commerce data aggregation for AI insights.
 *
 * Responsibilities:
 * - Fetch top-selling products
 * - Perform YoY comparisons
 * - Apply fallback strategies
 * - Normalize commerce data for AI consumption.
 */
class CommerceDataService {

  /**
   * Logger instance.
   */
  protected LoggerInterface $logger;

  /**
   * Constructs the CommerceDataService.
   */
  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected CacheBackendInterface $cache,
    LoggerChannelFactoryInterface $loggerFactory,
    protected TimeInterface $time,
    protected AiIntelligenceManager $aiManager,
  ) {
    $this->logger = $loggerFactory->get('commerce_ai_intelligence.commerce_data');
  }

  /**
   * Retrieves top-selling products with fallback logic.
   *
   * @param array $context
   *   Context options:
   *   - store_id
   *   - lookback_days
   *   - limit
   *   - product_type.
   *
   * @return array
   *   List of product performance data.
   */
  public function getTopItems(array $context = []): array {
    $config = $this->aiManager->getAiIntelligenceConfig();
    if (!$config) {
      return [];
    }

    $configured_date = (string) ($config->get('general.lookback_start_date') ?? '');

    $lookback_days = $this->aiManager->calculateLookbackDays($configured_date);

    $limit = max(1, (int) ($config->get('general.product_limit') ?? 10));
    $product_type = trim((string) ($context['product_type'] ?? ''));
    $store_id = (int) ($context['store_id'] ?? 0);

    $cache_id = "commerce_ai_intelligence:top_items:$lookback_days:$limit:$product_type:$store_id";

    if ($cache = $this->cache->get($cache_id)) {
      return $cache->data;
    }

    $end = $this->time->getCurrentTime();
    $start = $end - ($lookback_days * 86400);

    $this->logger->debug('Top items lookup: @days days', ['@days' => $lookback_days]);

    $current_items = $this->collectTopItemsWithFallback($start, $end, $limit, $product_type, $store_id);

    if (empty($current_items)) {
      $this->logger->warning('No top items found.');
      return [];
    }

    $product_ids = array_keys($current_items);

    // Year-over-year comparison.
    $last_year_items = $this->queryItemsByProductIds(
      strtotime('-1 year', $start),
      strtotime('-1 year', $end),
      $product_ids,
      $product_type,
      $store_id
    );

    $output = [];

    foreach ($current_items as $product_id => $item) {
      $last_year_units = $last_year_items[$product_id]['sales_volume'] ?? 0;

      $output[] = [
        'product_name' => $item['product_name'],
        'product_type' => $item['product_type'],
        'sales_volume' => $item['sales_volume'],
        'revenue' => $item['revenue'],
        'margin' => $item['margin'],
        'units_sold_last_year' => $last_year_units,
        'units_sold_this_year' => $item['sales_volume'],
        'target_units_this_year' => $this->calculateTargetUnits($item['sales_volume']),
      ];
    }

    $this->cache->set($cache_id, $output, $this->time->getCurrentTime() + 3600);

    return $output;
  }

  /**
   * Queries top-selling items from database.
   */
  protected function queryItems(int $start, int $end, int $limit, string $product_type = '', int $store_id = 0): array {
    $query = $this->database->select('commerce_order', 'o');
    $query->join('commerce_order_item', 'oi', 'oi.order_id = o.order_id');
    $query->join('commerce_product_variation_field_data', 'pvfd', 'pvfd.variation_id = oi.purchased_entity');
    $query->join('commerce_product_field_data', 'p', 'p.product_id = pvfd.product_id');

    $query->addField('p', 'product_id');
    $query->addField('p', 'title', 'product_name');
    $query->addField('p', 'type', 'product_type');

    $query->addExpression('SUM(oi.quantity)', 'sales_volume');
    $query->addExpression('SUM(oi.total_price__number)', 'revenue');

    $query->condition('o.state', 'completed');

    if ($start > 0 && $end > 0) {
      $query->condition('o.completed', [$start, $end], 'BETWEEN');
    }

    if ($product_type !== '') {
      $query->condition('p.type', $product_type);
    }

    if ($store_id > 0) {
      $query->condition('o.store_id', $store_id);
    }

    $query->groupBy('p.product_id');
    $query->groupBy('p.title');
    $query->groupBy('p.type');

    $query->orderBy('revenue', 'DESC');
    $query->range(0, $limit);

    return $this->formatQueryResults($query->execute()->fetchAll());
  }

  /**
   * Queries historical data for specific products.
   */
  protected function queryItemsByProductIds(int $start, int $end, array $product_ids, string $product_type = '', int $store_id = 0): array {
    if (empty($product_ids)) {
      return [];
    }

    $query = $this->database->select('commerce_order', 'o');
    $query->join('commerce_order_item', 'oi', 'oi.order_id = o.order_id');
    $query->join('commerce_product_variation_field_data', 'pvfd', 'pvfd.variation_id = oi.purchased_entity');
    $query->join('commerce_product_field_data', 'p', 'p.product_id = pvfd.product_id');

    $query->addField('p', 'product_id');
    $query->addExpression('SUM(oi.quantity)', 'sales_volume');

    $query->condition('o.state', 'completed');
    $query->condition('p.product_id', $product_ids, 'IN');

    if ($start > 0 && $end > 0) {
      $query->condition('o.completed', [$start, $end], 'BETWEEN');
    }

    return $this->mapProductSales($query->execute()->fetchAll());
  }

  /**
   * Maps query results to structured product sales data.
   */
  protected function mapProductSales(array $results): array {
    $data = [];

    foreach ($results as $row) {
      $data[$row->product_id] = [
        'sales_volume' => (int) $row->sales_volume,
      ];
    }

    return $data;
  }

  /**
   * Applies fallback strategies to ensure data availability.
   */
  protected function collectTopItemsWithFallback(int $start, int $end, int $limit, string $product_type, int $store_id): array {
    $fallback_start = $end - (365 * 86400);

    $attempts = [
      [$start, $end, $product_type, $store_id],
      [$start, $end, '', $store_id],
      [$fallback_start, $end, $product_type, $store_id],
      [$fallback_start, $end, '', $store_id],
      [$fallback_start, $end, '', 0],
    ];

    foreach ($attempts as [$from, $to, $type, $store]) {
      $items = $this->queryItems($from, $to, $limit, $type, $store);

      if (!empty($items)) {
        return $items;
      }
    }

    return [];
  }

  /**
   * Formats database query results.
   */
  protected function formatQueryResults(array $results): array {
    $items = [];

    foreach ($results as $row) {
      $items[$row->product_id] = [
        'product_name' => $row->product_name,
        'product_type' => $row->product_type,
        'sales_volume' => (int) $row->sales_volume,
        'revenue' => (float) $row->revenue,
        'margin' => NULL,
      ];
    }

    return $items;
  }

  /**
   * Calculates projected target units.
   */
  protected function calculateTargetUnits(int $current_units): int {
    return (int) ceil($current_units * 1.2);
  }

}
