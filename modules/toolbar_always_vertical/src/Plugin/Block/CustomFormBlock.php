<?php

namespace Drupal\toolbar_always_vertical\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a 'Custom Form Block' block.
 *
 * @Block(
 *   id = "custom_form_block",
 *   admin_label = @Translation("Custom Form Block")
 * )
 */
class CustomFormBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    \Drupal::service('plugin.manager.block')->clearCachedDefinitions();
    $build = [];
    $build['icon'] = [
      '#markup' => '<div class="close-wrapper"><div class="close-button"></div></div>',
    ];
    $build['form'] = \Drupal::formBuilder()->getForm('Drupal\toolbar_always_vertical\Form\CustomForm');
    return $build;
  }

}
