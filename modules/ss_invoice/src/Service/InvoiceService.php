<?php

namespace Drupal\ss_invoice\Service;

use Drupal\Component\Datetime\TimeInterface as DatetimeTimeInterface;
use CommerceGuys\Addressing\Subdivision\SubdivisionRepositoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_store\CurrentStoreInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service to generate and fetch invoices for Commerce orders.
 */
class InvoiceService {

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The current store service.
   *
   * @var \Drupal\commerce_store\CurrentStoreInterface
   */
  protected $currentStore;

  /**
   * The subdivision repository service.
   *
   * @var \CommerceGuys\Addressing\Subdivision\SubdivisionRepositoryInterface
   */
  protected $subdivisionRepository;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * Logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructor.
   */
  public function __construct(
    Connection $database,
    AccountProxyInterface $current_user,
    CurrentStoreInterface $current_store,
    SubdivisionRepositoryInterface $subdivision_repository,
    EntityTypeManagerInterface $entity_type_manager,
    DatetimeTimeInterface $time,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->database = $database;
    $this->currentUser = $current_user;
    $this->currentStore = $current_store;
    $this->subdivisionRepository = $subdivision_repository;
    $this->entityTypeManager = $entity_type_manager;
    $this->time = $time;
    $this->logger = $logger_factory->get('ss_invoice');
  }

  /**
   * Generates or fetches an invoice for a given order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The commerce order entity.
   *
   * @return array
   *   Invoice data containing:
   *   - invoice_number: The invoice number string.
   *   - invoice_details: The invoice details string.
   */
  public function getOrCreateInvoice(OrderInterface $order): array {
    // Check if invoice already exists for this order.
    $existing = $this->database->select('order_invoice', 'oi')
      ->fields('oi')
      ->condition('order_id', $order->id())
      ->execute()
      ->fetchAssoc();

    if ($existing) {
      return [
        'invoice_number' => $existing['invoice_number'],
        'invoice_details' => $existing['invoice_details'],
      ];
    }

    // Generate invoice components.
    $state_code = $this->getStateCode($order);
    $store_code = $this->getStoreCode($order);
    $order_reference = $order->getOrderNumber();
    $slip_sequence = $this->getNextSlipSequence($store_code);

    // Build invoice identifiers.
    $invoice_number = sprintf('%s-%s-%d', $state_code, $store_code, $slip_sequence);
    $invoice_details = sprintf('%s-%s-%s-%d', $state_code, $store_code, $order_reference, $slip_sequence);

    // Save new invoice to DB.
    $this->database->insert('order_invoice')
      ->fields([
        'invoice_number' => $invoice_number,
        'invoice_details' => $invoice_details,
        'order_id' => $order->id(),
        'user_id' => $this->currentUser->id(),
        'store_code' => $store_code,
        'state_code' => $state_code,
        'order_reference' => $order_reference,
        'slip_sequence' => $slip_sequence,
        'created' => $this->time->getCurrentTime(),
      ])
      ->execute();

    return [
      'invoice_number' => $invoice_number,
      'invoice_details' => $invoice_details,
    ];
  }

  /**
   * Get store code from order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The commerce order entity.
   *
   * @return string
   *   Store code or 'NA' if unavailable.
   */
  protected function getStoreCode(OrderInterface $order): string {
    $store = $order->getStore();
    return $store ? $store->id() : 'NA';
  }

  /**
   * Get state code from order billing address.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The commerce order entity.
   *
   * @return string
   *   State code or 'NA' if unavailable.
   */
  protected function getStateCode(OrderInterface $order): string {
    if ($profile = $order->getBillingProfile()) {
      $address = $profile->get('address')->first();
      if ($address && $address->getAdministrativeArea()) {
        return $address->getAdministrativeArea();
      }
    }
    return 'NA';
  }

  /**
   * Get next slip sequence number for a store.
   *
   * @param string $store_code
   *   The store code.
   *
   * @return int
   *   Next slip sequence number, starting from 10001.
   */
  protected function getNextSlipSequence(string $store_code): int {
    $last = $this->database->select('order_invoice', 'oi')
      ->fields('oi', ['slip_sequence'])
      ->condition('store_code', $store_code)
      ->orderBy('slip_sequence', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    return $last ? ((int) $last + 1) : 10001;
  }

  /**
   * Converts an administrative area code to its full name.
   *
   * @param string $administrative_area
   *   The administrative area code (e.g., "CA" or "MH").
   * @param string $country_code
   *   The ISO country code (e.g., "US" or "IN").
   *
   * @return string|null
   *   The full name of the administrative area, or NULL if not found.
   */
  public function getAdministrativeAreaName(string $administrative_area, string $country_code): ?string {
    if (empty($administrative_area) || empty($country_code)) {
      return NULL;
    }

    // Defensive: some implementations expect (country, subdivision) and some the reverse.
    try {
      $subdivision = $this->subdivisionRepository->get($administrative_area, [$country_code]);
    }
    catch (\Throwable $e) {
      $subdivision = NULL;
    }

    return $subdivision ? (method_exists($subdivision, 'getName') ? $subdivision->getName() : NULL) : NULL;
  }

  /**
   * Returns details of the currently active store.
   *
   * @return array
   *   An associative array containing:
   *   - name: The store name.
   *   - address: The formatted store address.
   *   - email: The store email address.
   */
  public function getStoreDetails(): array {
    $store = $this->currentStore->getStore();
    $address_string = '';

    if ($store && $store->hasField('address') && !$store->get('address')->isEmpty()) {
      $address_item = $store->get('address')->first();
      $state_name = $this->getAdministrativeAreaName(
        $address_item->getAdministrativeArea() ?? '',
        $address_item->getCountryCode() ?? ''
      );

      $address_parts = [
        $address_item->getAddressLine1(),
        $address_item->getAddressLine2(),
        $address_item->getLocality(),
        $state_name,
        $address_item->getPostalCode(),
        $address_item->getCountryCode(),
      ];
      $address_string = implode(', ', array_filter($address_parts));
    }

    $email = '';
    if ($store && $store->hasField('mail') && !$store->get('mail')->isEmpty()) {
      $email = $store->get('mail')->value ?? '';
    }
    elseif ($store && method_exists($store, 'getEmail')) {
      $email = $store->getEmail();
    }

    return [
      'name' => $store?->getName() ?? '',
      'address' => $address_string,
      'email' => $email,
    ];
  }

  /**
   * Fetches order details for a given order ID.
   *
   * @param int $order_id
   *   The order ID.
   *
   * @return array
   *   Order data including items, customer, adjustments, and totals.
   */
  public function getUserOrder(int $order_id): array {
    $order = $this->entityTypeManager->getStorage('commerce_order')->load($order_id);
    if (!$order instanceof OrderInterface) {
      return [];
    }

    $total_price = $order->getTotalPrice();
    return [
      'completed_time' => $order->getCompletedTime(),
      'total_price' => $total_price ? $total_price->getNumber() : '0.00',
      'items' => $order->getItems(),
      'customer_id' => $order->getCustomerId(),
      'adjustments' => $this->getUserOrderAdjustments($order),
    ];
  }

  /**
   * Returns the adjustments for a given order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   *
   * @return array
   *   An array of adjustments.
   */
  public function getUserOrderAdjustments(OrderInterface $order): array {
    $adjustments = [];
    foreach ($order->getAdjustments() as $adjustment) {
      $amount = $adjustment->getAmount();
      $adjustments[] = [
        'label' => $adjustment->getLabel(),
        'number' => $amount->getNumber(),
      ];
    }
    return $adjustments;
  }

  /**
   * Returns the billing information for a given order.
   *
   * @param int $order_id
   *   The order ID.
   *
   * @return array
   *   Associative array with address and contact name.
   */
  public function getOrderBillingInfo(int $order_id): array {
    $order = $this->entityTypeManager->getStorage('commerce_order')->load($order_id);
    if (!$order instanceof OrderInterface) {
      return [];
    }

    $name = '';
    $address_string = '';

    $billing_profile = $order->getBillingProfile();
    if ($billing_profile && $billing_profile->hasField('address') && !$billing_profile->get('address')->isEmpty()) {
      $address = $billing_profile->get('address')->first();
      $name = trim(($address->getGivenName() ?? '') . ' ' . ($address->getFamilyName() ?? ''));

      $address_parts = [
        $address->getAddressLine1(),
        $address->getAddressLine2(),
        $address->getLocality(),
        $address->getPostalCode(),
        $address->getCountryCode(),
      ];
      $address_string = implode(', ', array_filter($address_parts));
    }

    return [
      'address' => $address_string,
      'name' => $name,
    ];
  }

  /**
   * Builds user details data for the invoice.
   *
   * @param int|null $order_id
   *   The order ID, or NULL.
   *
   * @return array
   *   Invoice + order + billing data, or empty array if unauthorized.
   */
  public function getUserDetails(?int $order_id = NULL): array {
    try {
      if (!$order_id) {
        return [];
      }

      $order_data = $this->getUserOrder($order_id);
      if (empty($order_data) || empty($order_data['completed_time'])) {
        return [];
      }

      // Access check: only administrators or the order's customer.
      $is_admin = in_array('administrator', $this->currentUser->getRoles());
      if (!$is_admin && (int) $this->currentUser->id() !== (int) $order_data['customer_id']) {
        return [];
      }

      $invoice_data = $this->generateInvoice($order_id);
      if (empty($invoice_data)) {
        return [];
      }

      return [
        'invoice' => $invoice_data,
        'order_id' => $order_id,
        'sender_info' => $this->getStoreDetails(),
        'billing_info' => $this->getOrderBillingInfo($order_id),
        'order' => $order_data['items'],
        'adjustments' => $order_data['adjustments'] ?? [],
        'total_price' => $order_data['total_price'],
        'completed_time' => $order_data['completed_time'],
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('Error in getUserDetails(): @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Generates or fetches the invoice for a given order ID.
   *
   * @param int $order_id
   *   The order ID.
   *
   * @return array
   *   Invoice data.
   */
  public function generateInvoice(int $order_id): array {
    $order = $this->entityTypeManager->getStorage('commerce_order')->load($order_id);
    if (!$order instanceof OrderInterface) {
      return [];
    }
    return $this->getOrCreateInvoice($order);
  }

}
