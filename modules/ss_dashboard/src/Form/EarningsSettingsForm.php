<?php

namespace Drupal\ss_dashboard\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure earnings settings for this site.
 *
 * @see \Drupal\ss_dashboard\Plugin\Block\EarningsWidgetBlock
 */
class EarningsSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ss_dashboard.earnings_settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'earnings_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ss_dashboard.earnings_settings');

    $form['profit_margin'] = [
      '#type' => 'number',
      '#title' => $this->t('Profit Margin Percentage'),
      '#description' => $this->t('This percentage will be used to calculate earnings from revenue.'),
      '#default_value' => $config->get('profit_margin') ?? 10,
      '#min' => 0,
      '#max' => 100,
      '#step' => 0.01,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('ss_dashboard.earnings_settings')
      ->set('profit_margin', $form_state->getValue('profit_margin'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
