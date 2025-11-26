<?php

namespace Drupal\toolbar_always_vertical\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Toolbar Always Vertical settings for this site.
 */
class ToolbarAlwaysVerticalConfigForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['toolbar_always_vertical.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'toolbar_always_vertical_config_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('toolbar_always_vertical.settings');

    $form['toolbar_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Toolbar Title'),
      '#default_value' => $config->get('toolbar_title') ?: $this->t('Smart Store'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('toolbar_always_vertical.settings')
      ->set('toolbar_title', $form_state->getValue('toolbar_title'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
