<?php

declare(strict_types=1);

namespace Drupal\ss_invoice;

use Drupal\Core\Session\AccountInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Provides Twig extensions for SS Invoice module.
 *
 * @package Drupal\ss_invoice
 */
final class SsInvoiceTwigExtension extends AbstractExtension {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructs a new SsInvoiceTwigExtension.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(AccountInterface $current_user) {
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public function getFunctions() {
    return [
      new TwigFunction('currentUserRole', [$this, 'currentUserRole']),
    ];
  }

  /**
   * Checks if current user has 'admin' role.
   *
   * @return array
   *   The roles of the current user.
   */
  public function currentUserRole() {
    $roles = $this->currentUser->getRoles();
    return array_intersect($roles, ['admin', 'administrator']);
  }

}
