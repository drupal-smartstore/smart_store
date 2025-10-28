<?php

namespace Drupal\ss_modify\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Provides a admin dashboard page controller.
 */
class AdminDashboardController extends ControllerBase {

  /**
   * Returns a render array for the admin page.
   */
  public function content() {
    return [
      '#theme' => 'admin_dashboard_page_template',
    ];
  }

}
