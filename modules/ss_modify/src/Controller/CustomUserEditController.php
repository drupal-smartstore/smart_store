<?php

namespace Drupal\ss_modify\Controller;

use Drupal\Core\Url;
use Drupal\Core\Controller\ControllerBase;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Provides a controller for custom user edit functionality.
 */
class CustomUserEditController extends ControllerBase {

  /**
   * Returns the user edit form for the currently logged-in user.
   */
  public function editUser() {
    // Get the currently logged-in user.
    $current_user = \Drupal::currentUser();
    $user_id = $current_user->id();

    // Load the user entity.
    $user = User::load($user_id);

    if ($user) {
      // Return the user edit form.
      return $this->entityFormBuilder()->getForm($user, 'default');
    }
    else {
      // Redirect to the homepage or another appropriate page if user not found.
      return new RedirectResponse(Url::fromRoute('<front>')->toString());
    }
  }

}
