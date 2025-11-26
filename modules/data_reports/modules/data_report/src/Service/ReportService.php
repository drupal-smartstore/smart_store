<?php

namespace Drupal\data_report\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Component\Datetime\TimeInterface as DatetimeTimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\commerce_product\Entity\ProductVariation;

/**
 * Provides business logic for generating sales and data reports.
 *
 * This service acts as a centralized layer for retrieving and processing
 * report data such as sales summaries, order insights, and analytics.
 *
 * @package Drupal\data_report\Service
 */
class ReportService {

  /**
   * The database connection service.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The logger factory service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The current user service.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The datetime time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected DatetimeTimeInterface $time;

  /**
   * The configuration factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The request stack service.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * The stock service manager.
   *
   * @var \Drupal\commerce_stock\ServiceManagerInterface|null
   */
  protected $stockService;

  /**
   * Constructs a new ReportService object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The datetime time service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack service.
   */
  public function __construct(
    Connection $database,
    LoggerChannelFactoryInterface $logger_factory,
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user,
    DatetimeTimeInterface $time,
    ConfigFactoryInterface $config_factory,
    RequestStack $request_stack,
    $stockService = NULL,
  ) {
    $this->database = $database;
    $this->loggerFactory = $logger_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->time = $time;
    $this->configFactory = $config_factory;
    $this->requestStack = $request_stack;
    $this->stockService = $stockService;
  }

  /**
   * Generates a sales summary report.
   *
   * This method retrieves total sales data grouped by a specified
   * period (e.g., day, week, or month) within the provided date range.
   *
   * @param array $dateRange
   *   An associative array containing 'start' and 'end' date values.
   *   @endcode
   * @param string $groupBy
   *   The time grouping for the report.
   *
   * @return array
   *   A structured array of sales data, where each record contains:
   *   - Date: The reporting period label.
   *   - Orders: Total number of completed orders for that period.
   *   - Revenue: Gross revenue before refunds.
   *   - AOV (Average Order Value): Average revenue per order.
   *   - Refunds: Total refunded amount for that period.
   *   - Net Revenue: Actual earnings after refunds.
   */
  public function getSalesSummary(array $dateRange, string $groupBy): array {
    $start_timestamp = strtotime($dateRange['start']);
    $end_timestamp = strtotime($dateRange['end']);

    /** @var \Drupal\commerce_order\OrderStorageInterface $order_storage */
    $order_storage = $this->entityTypeManager->getStorage('commerce_order');

    $query = $order_storage->getQuery()
      ->condition('created', $start_timestamp, '>=')
      ->condition('created', $end_timestamp, '<=')
      ->condition('state', 'completed')
      ->accessCheck(FALSE)
      // ->pager(2)
      ->sort('created', 'DESC');
    $order_ids = $query->execute();

    // Rest of your existing code remains the same...
    if (empty($order_ids)) {
      return [];
    }

    /** @var \Drupal\commerce_order\Entity\OrderInterface[] $orders */
    $orders = $order_storage->loadMultiple($order_ids);
    $grouped_data = [];

    // Group orders based on the selected interval (day/week/month).
    foreach ($orders as $order) {
      if (!$order instanceof OrderInterface) {
        continue;
      }

      $created = $order->getCreatedTime();
      $key = $this->getGroupingKey($created, $groupBy);
      $price = (float) $order->getTotalPrice()->getNumber();
      $currency = $order->getTotalPrice()->getCurrencyCode();

      if (!isset($grouped_data[$key])) {
        $grouped_data[$key] = [
          'Orders' => 0,
          'Revenue' => 0.0,
          'Refunds' => 0.0,
          'CurrencyCode' => $currency,
        ];
      }

      $grouped_data[$key]['Orders']++;
      $grouped_data[$key]['Revenue'] += $price;
    }

    return $this->prepareSummaryData($grouped_data);
  }

  /**
   * Returns the grouping key based on the provided interval.
   *
   * @param int $timestamp
   *   The order creation timestamp.
   * @param string $groupBy
   *   The grouping factor ('day', 'week', 'month').
   *
   * @return string
   *   The formatted key used for grouping orders.
   */
  protected function getGroupingKey(int $timestamp, string $groupBy): string {
    switch ($groupBy) {
      case 'month':
        return date('Y-m', $timestamp);

      case 'week':
        $date = (new \DateTime())->setTimestamp($timestamp);
        // 0 = Sunday
        $day_of_week = $date->format('w');
        $week_start = (clone $date)->modify('-' . $day_of_week . ' days');
        $week_end = (clone $week_start)->modify('+6 days');
        return $week_start->format('Y-m-d') . ' to ' . $week_end->format('Y-m-d');

      case 'day':
      default:
        return date('Y-m-d', $timestamp);
    }
  }

  /**
   * Prepares formatted summary output with AOV and Net Revenue.
   *
   * @param array $grouped_data
   *   The grouped order data.
   *
   * @return array
   *   The formatted sales summary output.
   */
  protected function prepareSummaryData(array $grouped_data): array {
    $prepared = [];

    foreach ($grouped_data as $period => $values) {
      $aov = $values['Orders'] > 0
        ? round($values['Revenue'] / $values['Orders'], 2)
        : 0.0;

      $net_revenue = $values['Revenue'] - $values['Refunds'];
      $currency = $values['CurrencyCode'];

      $prepared[] = [
        'Date' => $period,
        'Orders' => $values['Orders'],
        'Revenue' => sprintf('%s %.2f', $currency, $values['Revenue']),
        'AOV' => sprintf('%s %.2f', $currency, $aov),
        'Net Revenue' => sprintf('%s %.2f', $currency, $net_revenue),
      ];
    }

    return $prepared;
  }

  /**
   * Search report data based on query parameters.
   *
   * @return array
   *   The report data array.
   */
  public function searchReportData(): array {
    // Default empty data.
    $filterData = $this->getCustomerSummaryFilterData();
    $report_data = [];

    if ($filterData['start_date'] && $filterData['end_date']) {
      // Fetch report data from your service.
      $report_data = $this->getSalesSummary([
        'start' => date('Y-m-d 00:00:01', $filterData['start_date']),
        'end' => date('Y-m-d 23:59:59', $filterData['end_date']),
      ], $filterData['group_by']);
    }

    return $report_data;
  }

  /**
   * Retrived all the request param.
   *
   * @return array
   *   The param array.
   */
  public function getCustomerSummaryFilterData() {
    // Retrieve current request object.
    $request = $this->requestStack->getCurrentRequest();

    // Get filter parameters from query string.
    $start_date = $request->query->get('start_date');
    $end_date = $request->query->get('end_date');
    $group_by = $request->query->get('group_by', 'day');

    return ['start_date' => $start_date, 'end_date' => $end_date, 'group_by' => $group_by];
  }

  /**
   * Returns a customer summary report.
   *
   * @return array
   *   Associative array with summary metrics.
   */
  public function getCustomerSummary(): array {
    $filterData = $this->getCustomerSummaryFilterData();
    $start = date('Y-m-d 00:00:01', $filterData['start_date']);
    $end = date('Y-m-d 23:59:59', $filterData['end_date']);

    $start_timestamp = strtotime($start);
    $end_timestamp = strtotime($end);
    // dd($start_timestamp, $end_timestamp);.
    $order_storage = $this->entityTypeManager->getStorage('commerce_order');

    // Load all completed orders in date range.
    $order_ids = $order_storage->getQuery()
      ->condition('created', $start_timestamp, '>=')
      ->condition('created', $end_timestamp, '<=')
      ->condition('state', 'completed')
      ->accessCheck(FALSE)
      ->execute();

    if (empty($order_ids)) {
      return [
        'new_customers' => 0,
        'returning_customers' => 0,
        'orders_per_customer' => 0,
        'revenue_per_customer' => 0,
      ];
    }

    $orders = $order_storage->loadMultiple($order_ids);

    $customers_in_period = [];
    $total_revenue = 0;

    foreach ($orders as $order) {
      if (!$order instanceof OrderInterface) {
        continue;
      }

      $uid = $order->getCustomerId();
      if ($uid && $uid > 0) {
        $customers_in_period[$uid][] = $order;
      }

      // Sum total revenue (subtotal or total_price).
      $total_revenue += (float) $order->getTotalPrice()->getNumber();
    }

    // Separate new vs returning customers.
    $new_customers = 0;
    $returning_customers = 0;

    foreach (array_keys($customers_in_period) as $uid) {
      // Find user's first order date.
      $first_order_time = $this->getFirstOrderTime($uid);

      if ($first_order_time >= $start_timestamp && $first_order_time <= $end_timestamp) {
        $new_customers++;
      }
      else {
        $returning_customers++;
      }
    }

    // Orders per Customer.
    $total_orders = count($orders);
    $unique_customers = count($customers_in_period);
    $orders_per_customer = $unique_customers ? round($total_orders / $unique_customers, 2) : 0;

    // Revenue per Customer.
    $revenue_per_customer = $unique_customers ? round($total_revenue / $unique_customers, 2) : 0;

    return [
      'new_customers' => $new_customers,
      'returning_customers' => $returning_customers,
      'orders_per_customer' => $orders_per_customer,
      'revenue_per_customer' => $revenue_per_customer,
    ];
  }

  /**
   * Finds the timestamp of the customer's first order.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return int|null
   *   Timestamp of first order, or NULL if none.
   */
  protected function getFirstOrderTime(int $uid): ?int {
    $query = $this->entityTypeManager->getStorage('commerce_order')->getQuery()
      ->condition('uid', $uid)
      ->condition('state', 'completed')
      ->sort('created', 'ASC')
      ->range(0, 1)
      ->accessCheck(FALSE);

    $ids = $query->execute();

    if (empty($ids)) {
      return NULL;
    }

    $order = $this->entityTypeManager->getStorage('commerce_order')->load(reset($ids));

    if ($order instanceof OrderInterface) {
      return $order->getCreatedTime();
    }

    return NULL;
  }

  /**
   * Retrieves product performance filter data from the current request.
   *
   * @return array
   *   An associative array containing filter parameters.
   */
  public function getProductPerformanceFilter(): array {
    $request = $this->requestStack->getCurrentRequest();

    // Retrieve existing query parameters to prefill defaults.
    $order_state = $request->query->get('order_state', '');
    $start_date = $request->query->get('start_date');
    $end_date = $request->query->get('end_date');

    return [
      'order_state' => $order_state,
      'start_date' => $start_date,
      'end_date' => $end_date,
    ];
  }

  /**
   * Retrieves product performance data.
   *
   * @return array
   *   An array containing product performance metrics.
   */
  public function getProductPerformance(): array {
    $data = [];
    $filterData = $this->getProductPerformanceFilter();

    // Prepare date boundaries using DrupalDateTime.
    $start_date = $filterData['start_date'] ? new DrupalDateTime('@' . $filterData['start_date']) : NULL;
    $end_date = $filterData['end_date'] ? new DrupalDateTime('@' . $filterData['end_date']) : NULL;

    $start_timestamp = $start_date ? $start_date->getTimestamp() : NULL;
    $end_timestamp = $end_date ? $end_date->modify('23:59:59')->getTimestamp() : NULL;

    // Build order query.
    $order_storage = $this->entityTypeManager->getStorage('commerce_order');
    $query = $order_storage->getQuery();

    if (!empty($start_timestamp)) {
      $query->condition('created', $start_timestamp, '>=');
    }
    if (!empty($end_timestamp)) {
      $query->condition('created', $end_timestamp, '<=');
    }
    if (!empty($filterData['order_state'])) {
      $query->condition('state', $filterData['order_state']);
    }

    $query->pager(20);
    $order_ids = $query->accessCheck(FALSE)->execute();

    if (!$order_ids) {
      return [];
    }

    // Load order items for all orders.
    $order_item_storage = $this->entityTypeManager->getStorage('commerce_order_item');
    $order_items = $order_item_storage->loadByProperties([
      'order_id' => $order_ids,
    ]);

    // dd($order_items);
    foreach ($order_items as $order_item) {
      $variation = $order_item->getPurchasedEntity();
      if (!$variation instanceof ProductVariation) {
        continue;
      }

      $variation_id = $variation->id();
      $product = $variation->getProduct();
      $product_id = $product->id();

      // Lazy initialize row.
      if (!isset($data[$product_id])) {
        $unit_price = (float) $order_item->getUnitPrice()->getNumber();
        $data[$product_id] = [
          'product_id' => $product_id,
          'product_title' => $product->label(),
          'variation_id' => $variation_id,
          'variation_title' => $variation->label(),
          'sku' => $variation->getSku(),
          'qty_sold' => 0,
          'unit_price' => $unit_price,
          'revenue' => 0,
          'stock_level' => $this->getStockLevel($variation) ?? 'N/A',
        ];
      }

      // Update totals.
      $qty = (float) $order_item->getQuantity();
      $data[$product_id]['qty_sold'] += $qty;
      $data[$product_id]['revenue'] += ($data[$product_id]['unit_price'] * $qty);
    }

    return $data;
  }

  /**
   * Helper function: Get stock level of a product variation.
   */
  public function getStockLevel(ProductVariation $variation) {
    // Commerce Stock module service.
    if ($this->stockService) {
      return (float) $this->stockService->getStockLevel($variation);
    }

    if ($variation->hasField('field_stock') && !$variation->get('field_stock')->isEmpty()) {
      return (float) $variation->get('field_stock')->value;
    }

    return NULL;
  }

}
