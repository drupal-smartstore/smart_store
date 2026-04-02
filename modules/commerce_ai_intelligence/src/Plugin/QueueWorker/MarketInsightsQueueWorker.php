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
 * Processes queued market insight generation requests.
 *
 * @QueueWorker(
 *   id = "commerce_ai_intelligence.market_insights_generation",
 *   title = @Translation("Commerce AI Market Insights Generation"),
 *   cron = {"time" = 60}
 * )
 */
class MarketInsightsQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Market Analysis Service.
   *
   * @var \Drupal\commerce_ai_intelligence\Service\AiMarketAnalysisService
   */
  protected AiMarketAnalysisService $marketAnalysisService;

  /**
   * AI Intelligence Manager.
   *
   * @var \Drupal\commerce_ai_intelligence\Service\AiIntelligenceManager
   */
  protected AiIntelligenceManager $aiIntelligenceManager;

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * Logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * Time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected DatetimeTimeInterface $time;

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
      $result = $this->marketAnalysisService->generateInsights($payload);

      $status = $result['status'] ?? 'failed';
      $error = $result['error'] ?? NULL;
      $resultData = $result['data'] ?? [];

      if ($status !== 'completed') {
        $this->updateRequestStatus($requestId, 'failed', NULL, $error ?? 'Insight generation failed');
        return;
      }

      if (!is_array($resultData) || empty($resultData)) {
        $this->logger->error('Empty normalized result for request @id', ['@id' => $requestId]);
        $this->updateRequestStatus($requestId, 'failed', NULL, 'Empty insight result');
        return;
      }

      $this->updateRequestStatus($requestId, 'completed', $resultData, NULL);
    }
    catch (\Throwable $exception) {
      $this->updateRequestStatus($requestId, 'failed', NULL, $exception->getMessage());

      $this->logger->error(
        'Market insight queue failed for request @id: @message',
        [
          '@id' => $requestId,
          '@message' => $exception->getMessage(),
        ]
      );
    }
  }

  /**
   * Updates request status in database.
   */
  protected function updateRequestStatus(
    int $requestId,
    string $status,
    ?array $result = NULL,
    ?string $error = NULL,
  ): void {
    $fields = [
      'status' => $status,
      'completed' => $this->time->getCurrentTime(),
    ];

    if ($result !== NULL && $status === 'completed') {
      if (empty($result)) {
        $fields['status'] = 'failed';
        $fields['error_message'] = 'Invalid insight result';
        $fields['insights'] = NULL;
      }
      else {
        $products = $result['products'] ?? [];
        unset($result['products']);

        $fields['insights'] = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $this->insertProductInsights($requestId, $products);
        $fields['error_message'] = NULL;
      }
    }
    else {
      $fields['insights'] = NULL;
      $fields['error_message'] = $error ?? 'Unknown error';
    }

    $this->database->update('commerce_ai_intelligence_insight_details')
      ->fields($fields)
      ->condition('id', $requestId)
      ->execute();
  }

  /**
   * Inserts product-level insights.
   */
  protected function insertProductInsights(int $requestId, array $products): void {
    if (empty($products)) {
      return;
    }

    $query = $this->database->insert('commerce_ai_intelligence_insight_products')
      ->fields([
        'insight_request_id',
        'product_id',
        'product_name',
        'category',
        'category_reasoning',
        'demand_momentum',
        'trend_dynamics',
        'competition_landscape',
        'opportunity',
        'sustainability',
        'seasonality',
        'customer_segments',
        'strategic_insight',
        'decision_framing',
        'review_cadence',
        'confidence_overall',
        'metadata',
        'created',
        'updated',
      ]);

    $currentTime = $this->time->getCurrentTime();

    foreach ($products as $product) {
      $query->values([
        'insight_request_id' => $requestId,
        'product_id' => $product['id'] ?? '',
        'product_name' => $product['product_name'] ?? '',
        'category' => $product['category'] ?? '',
        'category_reasoning' => $product['category_reasoning'] ?? '',
        'demand_momentum' => !empty($product['demand_momentum']) ? $this->aiIntelligenceManager->toJson($product['demand_momentum']) : NULL,
        'trend_dynamics' => !empty($product['trend_dynamics']) ? $this->aiIntelligenceManager->toJson($product['trend_dynamics']) : NULL,
        'competition_landscape' => !empty($product['competition_landscape']) ? $this->aiIntelligenceManager->toJson($product['competition_landscape']) : NULL,
        'opportunity' => !empty($product['opportunity']) ? $this->aiIntelligenceManager->toJson($product['opportunity']) : NULL,
        'sustainability' => !empty($product['sustainability']) ? $this->aiIntelligenceManager->toJson($product['sustainability']) : NULL,
        'seasonality' => !empty($product['seasonality']) ? $this->aiIntelligenceManager->toJson($product['seasonality']) : NULL,
        'customer_segments' => !empty($product['customer_segments']) ? $this->aiIntelligenceManager->toJson($product['customer_segments']) : NULL,
        'strategic_insight' => !empty($product['strategic_insight']) ? $this->aiIntelligenceManager->toJson($product['strategic_insight']) : NULL,
        'decision_framing' => !empty($product['decision_framing']) ? $this->aiIntelligenceManager->toJson($product['decision_framing']) : NULL,
        'review_cadence' => !empty($product['review_cadence']) ? $this->aiIntelligenceManager->toJson($product['review_cadence']) : NULL,
        'confidence_overall' => $product['metadata']['confidence_overall'] ?? NULL,
        'metadata' => !empty($product['metadata']) ? $this->aiIntelligenceManager->toJson($product['metadata']) : NULL,
        'created' => $currentTime,
        'updated' => $currentTime,
      ]);
    }

    $query->execute();
  }

}
