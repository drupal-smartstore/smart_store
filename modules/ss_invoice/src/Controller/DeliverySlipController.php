<?php

namespace Drupal\ss_invoice\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\ss_invoice\Service\InvoiceService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides controller methods for generating and displaying delivery slips.
 */
class DeliverySlipController extends ControllerBase {
  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The invoice service.
   *
   * @var \Drupal\ss_invoice\Service\InvoiceService
   */
  protected $invoiceService;

  /**
   * Constructs a new InvoiceController object.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\ss_invoice\Service\InvoiceService $invoice_service
   *   The invoice service.
   */
  public function __construct(
    AccountProxyInterface $current_user,
    RendererInterface $renderer,
    InvoiceService $invoice_service,
  ) {
    $this->currentUser = $current_user;
    $this->renderer = $renderer;
    $this->invoiceService = $invoice_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('current_user'),
      $container->get('renderer'),
      $container->get('ss_invoice.invoice_service')
    );
  }

  /**
   * Displays the delivery index page.
   *
   * @param int $order_id
   *   The order ID.
   *
   * @return array
   *   A render array for the invoice index theme.
   */
  public function index($order_id): array {
    $logo = \Drupal::config('system.site')->get('logo.url');
    if (empty($logo)) {
      $logo = "/sites/default/files/inline-images/TTN-logo.svg__2.jpg";
    }
    return [
      '#theme' => 'delivery_slip_index',
      '#data' => [
        'data' => $this->invoiceService->getUserDetails((int) $order_id),
        'logo' => $logo,
      ],
      '#attached' => [
        'library' => [
          'ss_invoice/print_invoice',
        ],
      ],
    ];
  }

}
