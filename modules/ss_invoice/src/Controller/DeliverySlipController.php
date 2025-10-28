<?php

namespace Drupal\ss_invoice\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Provides controller methods for generating and displaying delivery slips.
 */
class DeliverySlipController extends ControllerBase {

  /**
   * Renders the delivery slip page.
   *
   * @return array
   *   A render array for the delivery slip page.
   */
  public function index(): array {
    return [
      '#theme' => 'delivery_slip_index',
      '#attached' => [
        'library' => [
          'ss_invoice/print_invoice',
        ],
      ],
    ];
  }

}
