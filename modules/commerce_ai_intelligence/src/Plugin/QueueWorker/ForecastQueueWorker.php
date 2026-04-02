<?php

namespace Drupal\commerce_ai_intelligence\Plugin\QueueWorker;

use Drupal\commerce_ai_intelligence\Service\AiIntelligenceManager;
use Drupal\commerce_ai_intelligence\Service\AiMarketAnalysisService;
use Drupal\Component\Datetime\TimeInterface as DatetimeTimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes queued forecast generation requests.
 *
 * @QueueWorker(
 *   id = "commerce_ai_intelligence.forecast_generation",
 *   title = @Translation("Commerce AI Forecast Generation"),
 *   cron = {"time" = 60}
 * )
 */
class ForecastQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Market analysis service.
   */
  protected AiMarketAnalysisService $marketAnalysisService;

  /**
   * AI intelligence manager service.
   */
  protected AiIntelligenceManager $aiIntelligenceManager;

  /**
   * Database connection.
   */
  protected Connection $database;

  /**
   * Logger channel.
   */
  protected LoggerChannelInterface $logger;

  /**
   * Time service.
   */
  protected DatetimeTimeInterface $time;

  /**
   * Constructor.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    AiMarketAnalysisService $market_analysis_service,
    AiIntelligenceManager $ai_intelligence_manager,
    Connection $database,
    LoggerChannelFactoryInterface $logger_factory,
    DatetimeTimeInterface $time,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->marketAnalysisService = $market_analysis_service;
    $this->aiIntelligenceManager = $ai_intelligence_manager;
    $this->database = $database;
    $this->logger = $logger_factory->get('commerce_ai_intelligence.queue');
    $this->time = $time;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('commerce_ai_intelligence.market_analysis'),
      $container->get('commerce_ai_intelligence.manager'),
      $container->get('database'),
      $container->get('logger.factory'),
      $container->get('datetime.time')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $requestId = (int) ($data['request_id'] ?? 0);
    $payload = $data['payload'] ?? NULL;

    if ($requestId <= 0 || !is_array($payload) || empty($payload)) {
      $this->logger->warning('Invalid queue payload received.');
      return;
    }

    try {
      $result = $this->marketAnalysisService->generateForecast($payload);

      $status = $result['status'] ?? 'failed';
      $error = $result['error'] ?? NULL;
      $data = $result['data'] ?? [];

      if ($status !== 'completed') {
        $this->updateRequestStatus($requestId, 'failed', NULL, $error ?? 'Forecast generation failed');
        return;
      }

      // Normalize valid data.
      $resultText = $this->normalizeResult($data);

      if ($resultText === '') {
        $this->logger->error('Empty normalized result for request @id', ['@id' => $requestId]);
        $this->updateRequestStatus($requestId, 'failed', NULL, 'Empty forecast result');
        return;
      }

      $this->updateRequestStatus($requestId, 'completed', $resultText, NULL);
    }
    catch (\Throwable $exception) {
      $this->updateRequestStatus($requestId, 'failed', NULL, $exception->getMessage());

      $this->logger->error(
        'Market forecast queue failed for request @id: @message',
        [
          '@id' => $requestId,
          '@message' => $exception->getMessage(),
        ]
      );
    }
  }

  /**
   * Normalize forecast result into string.
   */
  protected function normalizeResult($forecast): string {
    if (is_array($forecast)) {
      $encoded = json_encode($forecast, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
      return $encoded !== FALSE ? $encoded : '';
    }

    return trim((string) $forecast);
  }

  /**
   * Updates request status in database.
   */
  protected function updateRequestStatus(
    int $requestId,
    string $status,
    ?string $result = NULL,
    ?string $error = NULL,
  ): void {
    $fields = [
      'status' => $status,
      'completed' => $this->time->getCurrentTime(),
    ];

    if ($result !== NULL && $status === 'completed') {
      $data = json_decode($result, TRUE);

      if (json_last_error() !== JSON_ERROR_NONE || empty($data)) {
        $fields['status'] = 'failed';
        $fields['error_message'] = 'Invalid JSON result';
        $fields['summary'] = NULL;
      }
      else {
        $products = $data['products'] ?? [];
        unset($data['products']);

        $fields['summary'] = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $this->insertProductForecast($requestId, $products);
        $fields['error_message'] = NULL;
      }
    }
    else {
      $fields['summary'] = NULL;
      $fields['error_message'] = $error ?? 'Unknown error';
    }

    $this->database->update('commerce_ai_intelligence_forecast_details')
      ->fields($fields)
      ->condition('id', $requestId)
      ->execute();
  }

  /**
   * Inserts product-level forecast.
   */
  protected function insertProductForecast(int $requestId, array $products): void {
    if (empty($products)) {
      return;
    }

    $query = $this->database->insert('commerce_ai_intelligence_forecast_products')
      ->fields([
        'forecast_request_id',
        'product_id',
        'product_name',
        'category',
        'opportunity_score',
        'demand_forecast',
        'price_forecast',
        'profit_cost',
        'benchmarks',
        'operational_forecast',
        'seasonality',
        'decision_framing',
        'review_cadence',
        'historical_performance',
        'competition_metrics',
        'confidence_score',
        'metadata',
        'created',
        'updated',
      ]);

    $currentTime = $this->time->getCurrentTime();

    foreach ($products as $product) {
      $query->values([
        'forecast_request_id' => $requestId,
        'product_id' => $product['product_id'] ?? '',
        'product_name' => $product['product_name'] ?? '',
        'category' => $product['category'] ?? '',
        'opportunity_score' => !empty($product['opportunity_score']) ? $this->aiIntelligenceManager->toJson($product['opportunity_score']) : '',
        'demand_forecast' => !empty($product['demand_forecast']) ? $this->aiIntelligenceManager->toJson($product['demand_forecast']) : '',
        'price_forecast' => !empty($product['price_forecast']) ? $this->aiIntelligenceManager->toJson($product['price_forecast']) : '',
        'profit_cost' => !empty($product['profit_cost']) ? $this->aiIntelligenceManager->toJson($product['profit_cost']) : '',
        'benchmarks' => !empty($product['benchmarks']) ? $this->aiIntelligenceManager->toJson($product['benchmarks']) : '',
        'operational_forecast' => !empty($product['operational_forecast']) ? $this->aiIntelligenceManager->toJson($product['operational_forecast']) : '',
        'seasonality' => !empty($product['seasonality']) ? $this->aiIntelligenceManager->toJson($product['seasonality']) : '',
        'decision_framing' => !empty($product['decision_framing']) ? $this->aiIntelligenceManager->toJson($product['decision_framing']) : '',
        'review_cadence' => !empty($product['review_cadence']) ? $this->aiIntelligenceManager->toJson($product['review_cadence']) : '',
        'historical_performance' => !empty($product['historical_performance']) ? $this->aiIntelligenceManager->toJson($product['historical_performance']) : '',
        'competition_metrics' => !empty($product['competition_metrics']) ? $this->aiIntelligenceManager->toJson($product['competition_metrics']) : '',
        'confidence_score' => !empty($product['confidence_score']) ? $this->aiIntelligenceManager->toJson($product['confidence_score']) : '',
        'metadata' => !empty($product['metadata']) ? $this->aiIntelligenceManager->toJson($product['metadata']) : NULL,
        'created' => $currentTime,
        'updated' => $currentTime,
      ]);
    }

    $query->execute();
  }

}
