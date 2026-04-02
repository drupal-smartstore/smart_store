<?php

namespace Drupal\commerce_ai_intelligence\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Url;
use Drupal\commerce_ai_intelligence\Service\AiIntelligenceManager;
use Drupal\commerce_ai_intelligence\Service\AiMarketAnalysisService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides Market Insight request form.
 */
class MarketInsightRequestForm extends FormBase {

  /**
   * AJAX result wrapper ID.
   */
  private const RESULT_WRAPPER_ID = 'ai-intelligence-insight-result';

  /**
   * Config name.
   */
  private const CONFIG_NAME = 'commerce_ai_intelligence.settings';

  /**
   * Config keys.
   */
  private const KEY_LOOKBACK = 'market_insights.lookback_start_days';
  private const KEY_LIMIT = 'market_insights.product_limit';
  private const KEY_TEMPLATE = 'market_insights.template';
  private const KEY_RULES = 'market_insights.rules';

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
    AiMarketAnalysisService $market_analysis_service,
    AiIntelligenceManager $ai_manager,
    Connection $database,
    QueueFactory $queue_factory,
  ) {
    $this->marketAnalysisService = $market_analysis_service;
    $this->aiManager = $ai_manager;
    $this->database = $database;
    $this->queueFactory = $queue_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('commerce_ai_intelligence.market_analysis'),
      $container->get('commerce_ai_intelligence.manager'),
      $container->get('database'),
      $container->get('queue'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_ai_intelligence_market_insight_request_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $product_types = $this->aiManager->getProductTypeOptions();
    $stores = $this->aiManager->getStoreOptions();

    $form['#prefix'] = '<div class="market-insight-request-form">';
    $form['#suffix'] = '</div>';

    $form['store'] = [
      '#type' => 'select',
      '#title' => $this->t('Select Store'),
      '#options' => $stores,
      '#empty_option' => $this->t('- Select Store -'),
    ];

    $form['from_date'] = [
      '#type' => 'date',
      '#title' => $this->t('From Date'),
      '#default_value' => date('Y-m-d'),
      '#max' => date('Y-m-d'),
    ];

    $form['to_date'] = [
      '#type' => 'date',
      '#title' => $this->t('To Date'),
      '#default_value' => date('Y-m-d', strtotime('-30 day')),
      '#max' => date('Y-m-d', strtotime('-30 day')),
    ];

    $form['product_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Product Type'),
      '#options' => $product_types,
      '#multiple' => TRUE,
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Generate Insight'),
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
   * AJAX submit handler.
   */
  public function ajaxSubmit(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    $wrapper = '#' . self::RESULT_WRAPPER_ID;

    $config = $this->config(self::CONFIG_NAME);

    try {
      $store_id = (string) $form_state->getValue('store');
      $product_types = (array) $form_state->getValue('product_type');

      $from_date = $form_state->getValue('from_date');
      $to_date = $form_state->getValue('to_date');

      if ($store_id === '' || empty($product_types)) {
        return $this->buildErrorResponse($response, $wrapper, 'Please select both store and product type.');
      }

      $from_date = $form_state->getValue('from_date');
      $to_date = $form_state->getValue('to_date');

      $lookback_days = $config->get(self::KEY_LOOKBACK);

      $limit = (int) $config->get(self::KEY_LIMIT);

      $country_code = $this->aiManager->getStoreCountryCode($store_id);
      $country_name = $this->aiManager->getCountryName($country_code);

      if (!$country_name) {
        throw new \RuntimeException('Unable to resolve country.');
      }

      $request_id = $this->insertInsightRequest([
        'store_id' => $store_id,
        'product_type' => $product_types,
        'lookback_days' => $lookback_days,
        'from_date' => $from_date,
        'to_date' => $to_date,
        'limit' => $limit,
        'status' => 'in_progress',
      ]);

      $this->queueFactory
        ->get('commerce_ai_intelligence.market_insights_generation')
        ->createItem([
          'request_id' => $request_id,
          'payload' => [
            'store_id' => (int) $store_id,
            'country' => $country_name,
            'product_type' => json_encode($product_types),
            'lookback_days' => $lookback_days,
            'from_date' => $from_date,
            'to_date' => $to_date,
            'limit' => $limit,
            'template' => $config->get(self::KEY_TEMPLATE),
            'rules' => $config->get(self::KEY_RULES),
          ],
        ]);

      $response->addCommand(new RedirectCommand(
        Url::fromRoute('commerce_ai_intelligence.market_insights_list')->toString()
      ));
    }
    catch (\Throwable $e) {
      $this->logger('commerce_ai_intelligence')->error($e->getMessage());
      return $this->buildErrorResponse($response, $wrapper, 'Failed to generate insights.');
    }

    return $response;
  }

  /**
   * Inserts insight request record.
   */
  private function insertInsightRequest(array $data): int {
    return (int) $this->database->insert('commerce_ai_intelligence_insight_details')
      ->fields([
        'store_id' => $data['store_id'],
        'product_type' => implode(', ', $data['product_type']),
        'lookback_days' => $data['lookback_days'],
        'from_date' => strtotime($data['from_date']),
        'to_date' => strtotime($data['to_date']),
        'limit' => $data['limit'],
        'status' => $data['status'],
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
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

}
