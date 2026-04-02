<?php

namespace Drupal\commerce_ai_intelligence\Form;

use Drupal\commerce_ai_intelligence\Service\AiPromptsService;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for AI Forecast and Market Insights settings.
 */
class IntelligenceSettingsForm extends ConfigFormBase {

  const CONFIG_NAME = 'commerce_ai_intelligence.settings';

  private const MIN_LOOKBACK_DAYS = 30;
  private const MAX_PRODUCT_LIMIT = 100;

  private const REQUIRED_PLACEHOLDERS = [
    '{country}',
    '{today}',
    '{limit}',
  ];

  /**
   * AI Prompts service.
   *
   * @var \Drupal\commerce_ai_intelligence\Service\AiPromptsService
   */
  protected $aiPrompts;

  public function __construct(AiPromptsService $ai_prompts) {
    $this->aiPrompts = $ai_prompts;
  }

  /**
   * {@inheritdoc}
   */
  public static function create($container) {
    return new static(
      $container->get('commerce_ai_intelligence.prompts')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_ai_intelligence_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(self::CONFIG_NAME);

    $maxDate = (new DrupalDateTime('-' . self::MIN_LOOKBACK_DAYS . ' days'))->format('Y-m-d');

    // COMMERCE DATA CONFIG.
    $form['general'] = [
      '#type' => 'details',
      '#title' => $this->t('Commerce Data Configuration'),
      '#description' => $this->t('Controls how store data is selected before being used by AI features.'),
      '#open' => TRUE,
    ];

    $form['general']['lookback_start_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Historical Data Start Days Ago'),
      '#description' => $this->t('Select a date at least @days days in the past.', ['@days' => self::MIN_LOOKBACK_DAYS]),
      '#default_value' => $config->get('general.lookback_start_days') ?? self::MIN_LOOKBACK_DAYS,
      '#required' => TRUE,
      '#min' => self::MIN_LOOKBACK_DAYS,
      '#max' => 120,
      '#attributes' => ['max' => $maxDate],
    ];

    $form['general']['product_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of Top Products to Select'),
      '#description' => $this->t('Top-performing products selected from commerce data for AI processing.'),
      '#default_value' => $config->get('general.product_limit') ?? 10,
      '#min' => 1,
      '#max' => self::MAX_PRODUCT_LIMIT,
      '#required' => TRUE,
    ];

    // FORECAST.
    $form['forecast'] = [
      '#type' => 'details',
      '#title' => $this->t('Forecast Configuration'),
      '#open' => TRUE,
    ];

    $form['forecast']['forecast_product_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of Products for Forecasting'),
      '#description' => $this->t('Number of products to include in AI-generated forecasts. Must be equal to or more than the combined total of top products and market insights limits.'),
      '#default_value' => $config->get('forecast.product_limit') ?? 10,
      '#min' => 1,
      '#max' => self::MAX_PRODUCT_LIMIT,
      '#required' => TRUE,
    ];

    $form['forecast']['forecast_template'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Forecast Prompt Template'),
      '#default_value' => $config->get('forecast.template') ?? $this->aiPrompts->getForecastTemplate(),
    ];

    $form['forecast']['forecast_rules'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Forecast Rules'),
      '#default_value' => $config->get('forecast.rules') ?? $this->aiPrompts->getForecastRules(),
    ];

    // MARKET INSIGHTS.
    $form['market_insights'] = [
      '#type' => 'details',
      '#title' => $this->t('Market Insights Configuration'),
      '#open' => TRUE,
    ];

    $form['market_insights']['market_insights_lookback_start_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Market Insights Lookback Start Days Ago'),
      '#default_value' => $config->get('market_insights.lookback_start_days') ?? self::MIN_LOOKBACK_DAYS,
      '#required' => TRUE,
      '#min' => self::MIN_LOOKBACK_DAYS,
      '#max' => 120,
    ];

    $form['market_insights']['market_insights_product_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of Products for Market Analysis'),
      '#default_value' => $config->get('market_insights.product_limit') ?? 10,
      '#min' => 1,
      '#max' => self::MAX_PRODUCT_LIMIT,
      '#required' => TRUE,
    ];

    $form['market_insights']['market_insights_template'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Market Insights Prompt Template'),
      '#default_value' => $config->get('market_insights.template') ?? $this->aiPrompts->getMarketInsightTemplate(),
    ];

    $form['market_insights']['market_insights_rules'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Market Insights Rules'),
      '#default_value' => $config->get('market_insights.rules') ?? $this->aiPrompts->getMarketInsightRules(),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {

    $productLimit = $this->validateProductLimit($form_state, 'product_limit', 'Top products');

    $this->validateLookbackDays($form_state, 'lookback_start_days', 'Historical data start days');

    $forecastLimit = $this->validateProductLimit($form_state, 'forecast_product_limit', 'Forecast products');
    $this->validateTemplate($form_state, 'forecast_template', 'Forecast template');
    $this->validateRules($form_state, 'forecast_rules', 'Forecast rules');

    $insightLimit = $this->validateProductLimit($form_state, 'market_insights_product_limit', 'Market insights products');
    $this->validateLookbackDays($form_state, 'market_insights_lookback_start_days', 'Market insights lookback days');
    $this->validateTemplate($form_state, 'market_insights_template', 'Market insights template');
    $this->validateRules($form_state, 'market_insights_rules', 'Market insights rules');

    if ($forecastLimit < ($productLimit + $insightLimit)) {
      $form_state->setErrorByName('forecast_product_limit', $this->t('Forecast limit cannot be less than the combined total of top products and market insights limits.'));
    }

    if ($insightLimit > $productLimit) {
      $form_state->setErrorByName('market_insights_product_limit', $this->t('Insights limit cannot exceed top products.'));
    }
  }

  /**
   * Validates that the lookback days is at least a certain number of days.
   *
   * Ensures the provided lookback value falls within the required
   * historical range.
   */
  private function validateLookbackDays(FormStateInterface $form_state, string $field, string $label): void {
    $value = $form_state->getValue($field);

    if (empty($value)) {
      $form_state->setErrorByName($field, $this->t('@label is required.', ['@label' => $label]));
      return;
    }

    if (!is_numeric($value) || $value < self::MIN_LOOKBACK_DAYS) {
      $form_state->setErrorByName($field, $this->t('@label must be a number greater than or equal to @days.', [
        '@label' => $label,
        '@days' => self::MIN_LOOKBACK_DAYS,
      ]));
    }
    if ($value > 120) {
      $form_state->setErrorByName($field, $this->t('@label cannot be greater than 120.', [
        '@label' => $label,
      ]));
    }
  }

  /**
   * Validates product limit fields to ensure they are within acceptable ranges.
   */
  private function validateProductLimit(FormStateInterface $form_state, string $field, string $label): int {
    $value = (int) $form_state->getValue($field);

    if ($value <= 0) {
      $form_state->setErrorByName($field, $this->t('@label must be greater than 0.', ['@label' => $label]));
    }

    if ($value > self::MAX_PRODUCT_LIMIT) {
      $form_state->setErrorByName($field, $this->t('@label cannot exceed @max.', [
        '@label' => $label,
        '@max' => self::MAX_PRODUCT_LIMIT,
      ]));
    }

    return $value;
  }

  /**
   * Validates that the prompt template includes required placeholders.
   */
  private function validateTemplate(FormStateInterface $form_state, string $field, string $label): void {
    $template = trim((string) $form_state->getValue($field));

    if (empty($template)) {
      $form_state->setErrorByName($field, $this->t('@label cannot be empty.', ['@label' => $label]));
      return;
    }

    foreach (self::REQUIRED_PLACEHOLDERS as $placeholder) {
      if (!str_contains($template, $placeholder)) {
        $form_state->setErrorByName($field, $this->t('@label must include @placeholder.', [
          '@label' => $label,
          '@placeholder' => $placeholder,
        ]));
      }
    }
  }

  /**
   * Validates that the rules field is not empty.
   */
  private function validateRules(FormStateInterface $form_state, string $field, string $label): void {
    if (empty(trim((string) $form_state->getValue($field)))) {
      $form_state->setErrorByName($field, $this->t('@label cannot be empty.', ['@label' => $label]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->configFactory->getEditable(self::CONFIG_NAME)
      ->set('general.lookback_start_days', $form_state->getValue('lookback_start_days'))
      ->set('general.product_limit', $form_state->getValue('product_limit'))
      ->set('forecast.product_limit', $form_state->getValue('forecast_product_limit'))
      ->set('forecast.template', trim($form_state->getValue('forecast_template')))
      ->set('forecast.rules', trim($form_state->getValue('forecast_rules')))
      ->set('market_insights.lookback_start_days', $form_state->getValue('market_insights_lookback_start_days'))
      ->set('market_insights.product_limit', $form_state->getValue('market_insights_product_limit'))
      ->set('market_insights.template', trim($form_state->getValue('market_insights_template')))
      ->set('market_insights.rules', trim($form_state->getValue('market_insights_rules')))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
