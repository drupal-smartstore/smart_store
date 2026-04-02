<?php

namespace Drupal\commerce_ai_intelligence\Form;

use Drupal\commerce_ai_intelligence\Service\AiIntelligenceManager;
use Drupal\commerce_ai_intelligence\Service\AiMarketAnalysisService;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides Forecast request form.
 */
class ForecastRequestForm extends FormBase {
  /**
   * AJAX result wrapper ID.
   */
  private const RESULT_WRAPPER_ID = 'ai-intelligence-forecast-result';

  /**
   * Config name.
   */
  private const CONFIG_NAME = 'commerce_ai_intelligence.settings';

  /**
   * Config keys.
   */
  private const KEY_LIMIT = 'forecast.product_limit';
  private const KEY_TEMPLATE = 'forecast.template';
  private const KEY_RULES = 'forecast.rules';

  /**
   * Market analysis service.
   */
  protected AiMarketAnalysisService $marketAnalysisService;

  /**
   * AI intelligence manager.
   */
  protected AiIntelligenceManager $aiManager;

  /**
   * Database connection.
   */
  protected Connection $database;

  /**
   * Queue factory.
   */
  protected QueueFactory $queueFactory;

  /**
   * Constructs the form.
   */
  public function __construct(
    Connection $database,
    QueueFactory $queue_factory,
    AiMarketAnalysisService $market_analysis_service,
    AiIntelligenceManager $ai_manager,
  ) {
    $this->database = $database;
    $this->queueFactory = $queue_factory;
    $this->marketAnalysisService = $market_analysis_service;
    $this->aiManager = $ai_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('queue'),
      $container->get('commerce_ai_intelligence.market_analysis'),
      $container->get('commerce_ai_intelligence.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'commerce_ai_intelligence_forecast_request_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $insight_options = $this->aiManager->loadInsightDetails();

    if (empty($insight_options)) {
      $form['#markup'] = $this->t('No market insights available. Please generate insights first. <a href=":link">Generate Insights</a>', [
        ':link' => Url::fromRoute('commerce_ai_intelligence.market_insights_list')->toString(),
      ]);
      return $form;
    }

    $form['#prefix'] = '<div class="forecast-form">';
    $form['#suffix'] = '</div>';

    $form['market_insight'] = [
      '#type' => 'select',
      '#title' => $this->t('Select Market Insight'),
      '#options' => $this->aiManager->getInsightOptions(),
      '#empty_option' => $this->t('- Select Insight -'),
      '#empty_value' => 0,
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Generate Forecast'),
      '#ajax' => [
        'callback' => '::ajaxSubmit',
        'wrapper' => self::RESULT_WRAPPER_ID,
      ],
    ];

    $form['result'] = [
      '#type' => 'container',
      '#attributes' => ['id' => self::RESULT_WRAPPER_ID],
    ];
    return $form;
  }

  /**
   * AJAX callback for form submission.
   */
  public function ajaxSubmit(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    $wrapper = '#' . self::RESULT_WRAPPER_ID;

    $config = $this->config(self::CONFIG_NAME);

    try {
      $market_insight_id = (int) $form_state->getValue('market_insight');

      if (!$market_insight_id || $market_insight_id == 0) {
        return $this->buildErrorResponse($response, $wrapper, 'Please select a market insight.');
      }

      $market_insight_data = $this->aiManager->loadInsightRequest($market_insight_id);

      if (!$market_insight_data) {
        return $this->buildErrorResponse($response, $wrapper, 'Selected market insight not found.');
      }

      $store_id = $market_insight_data['store_id'];
      $product_types = $market_insight_data['product_type'];

      $country_code = $this->aiManager->getStoreCountryCode($store_id);
      $country_name = $this->aiManager->getCountryName($country_code);

      if (!$country_name) {
        throw new \RuntimeException('Unable to resolve country for the selected insight.');
      }

      $insight = $this->buildInsightPayload($market_insight_data);

      $limit = (int) $config->get(self::KEY_LIMIT);

      $request_id = $this->insertForecastDetails([
        'store_id' => $store_id,
        'product_type' => $product_types,
        'market_insight_id' => $market_insight_id,
        'limit' => $limit,
        'status' => 'in_progress',
      ]);

      $this->queueFactory
        ->get('commerce_ai_intelligence.forecast_generation')
        ->createItem([
          'request_id' => $request_id,
          'payload' => [
            'store_id' => (int) $store_id,
            'country' => $country_name,
            'lookback_days' => $market_insight_data['lookback_days'],
            'market_insight_data' => $insight,
            'product_type' => $product_types,
            'limit' => $limit,
            'template' => $config->get(self::KEY_TEMPLATE),
            'rules' => $config->get(self::KEY_RULES),
          ],
        ]);

      $response->addCommand(new RedirectCommand(
        Url::fromRoute('commerce_ai_intelligence.forecast_list')->toString()
      ));
    }
    catch (\Throwable $e) {
      $this->logger('commerce_ai_intelligence')->error($e->getMessage());
      return $this->buildErrorResponse($response, $wrapper, 'Failed to generate insights.');
    }
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // No-op since we're handling submission via AJAX.
  }

  /**
   * Inserts forecast details into the database.
   */
  public function insertForecastDetails(array $data): int {
    return $this->database->insert('commerce_ai_intelligence_forecast_details')
      ->fields([
        'store_id' => $data['store_id'],
        'product_type' => $data['product_type'],
        'market_insight_id' => $data['market_insight_id'],
        'limit' => $data['limit'] ?? 0,
        'status' => $data['status'] ?? 'in_progress',
        'created' => time(),
      ])
      ->execute();
  }

  /**
   * Builds AJAX error response.
   */
  private function buildErrorResponse(AjaxResponse $response, string $wrapper, string $message): AjaxResponse {
    $response->addCommand(new HtmlCommand($wrapper, [
      '#markup' => '<div class="messages messages--error">' . $message . '</div>',
    ]));
    return $response;
  }

  /**
   * Builds final insight payload with products merged.
   *
   * @param array $market_insight_data
   *   Insight request data.
   *
   * @return string
   *   Sanitized JSON string.
   *
   * @throws \RuntimeException
   *   When JSON is invalid.
   */
  public function buildInsightPayload(array $market_insight_data): string {
    if (empty($market_insight_data['insights'])) {
      throw new \RuntimeException('Insight data is missing.');
    }

    $data = json_decode($market_insight_data['insights'], TRUE);

    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new \RuntimeException('Invalid insight JSON: ' . json_last_error_msg());
    }
    $products = $this->aiManager->loadInsightProducts($market_insight_data['id']);

    $data['products'] = !empty($products) ? array_values($products) : [];

    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    if ($json === FALSE) {
      throw new \RuntimeException('JSON encoding failed: ' . json_last_error_msg());
    }

    return $this->aiManager->sanitizeJson($json);
  }

}
