<?php

namespace Drupal\commerce_ai_intelligence\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Exception\AiResponseErrorException;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for generating AI-driven market insights.
 *
 * Responsibilities:
 * - Builds AI prompts
 * - Fetches commerce data
 * - Calls AI provider
 * - Parses and validates responses.
 */
class AiMarketAnalysisService {

  /**
   * Config name.
   */
  private const CONFIG_NAME = 'commerce_ai_intelligence.settings';

  /**
   * Config keys.
   */
  private const INSIGHT_TEMPLATE_KEY = 'market_insights.template';
  private const INSIGHT_RULES_KEY = 'market_insights.rules';
  private const FORECAST_TEMPLATE_KEY = 'forecast.template';
  private const FORECAST_RULES_KEY = 'forecast.rules';
  /**
   * Logger instance.
   */
  protected LoggerInterface $logger;

  /**
   * Constructs the service.
   */
  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AiProviderPluginManager $aiProviderManager,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
    LoggerChannelFactoryInterface $loggerFactory,
    protected CommerceDataService $commerceDataService,
    protected AiIntelligenceManager $aiManager,
  ) {
    $this->logger = $loggerFactory->get('commerce_ai_intelligence.ai_analysis');
  }

  /**
   * Generates market insights using AI.
   *
   * @param array $input
   *   Input parameters (store_id, country, etc.).
   *
   * @return array
   *   Structured insights response.
   */
  public function generateInsights(array $input): array {
    $defaults = $this->aiProviderManager
      ->getDefaultProviderForOperationType('chat');

    if (empty($defaults['provider_id']) || empty($defaults['model_id'])) {
      $this->logger->error('No default AI chat provider configured.');
      return [
        'status' => 'failed',
        'error' => 'AI provider not configured',
        'data' => [],
      ];
    }

    $config = $this->configFactory->get(self::CONFIG_NAME);

    $template = trim((string) (
    $input['template']
    ?? $config->get(self::INSIGHT_TEMPLATE_KEY)
    ?? ''
    ));

    $rules = trim((string) (
    $input['rules']
    ?? $config->get(self::INSIGHT_RULES_KEY)
    ?? ''
    ));

    $prompt_template = trim(implode("\n\n", array_filter([$template, $rules])));

    if ($prompt_template === '') {
      $this->logger->warning('AI prompt template is empty.');
      return [
        'status' => 'failed',
        'error' => 'Prompt template missing',
        'data' => [],
      ];
    }

    // Validate date range (important)
    if (!empty($input['from_date']) && !empty($input['to_date'])) {
      if ((int) $input['from_date'] > (int) $input['to_date']) {
        return [
          'status' => 'failed',
          'error' => 'Invalid date range',
          'data' => [],
        ];
      }
    }

    // Fetch commerce data.
    $top_items = $this->commerceDataService->getTopItems([
      'store_id' => (int) ($input['store_id'] ?? 0),
      'lookback_days' => (int) ($input['lookback_days'] ?? 30),
      'limit' => (int) ($input['limit'] ?? 10),
      'product_type' => (string) ($input['product_type'] ?? ''),
    ]);

    $prompt = $this->buildPrompt($prompt_template, $input, $top_items);

    $this->logger->debug('Prompt length: @length', [
      '@length' => strlen($prompt),
    ]);

    try {
      $provider = $this->aiProviderManager
        ->createInstance($defaults['provider_id']);

      $messages = new ChatInput([
        new ChatMessage('user', $prompt),
      ]);

      $response = $provider->chat(
        $messages,
        $defaults['model_id'],
        ['commerce_ai_intelligence']
      );

      $response_text = $response->getNormalized()->getText();
      $parsed = $this->parseAiResponse($response_text);

      return [
        'status' => 'completed',
        'error' => NULL,
        'data' => $parsed,
      ];
    }
    catch (AiResponseErrorException $e) {
      $this->logger->error('AI provider error: @error', [
        '@error' => $e->getMessage(),
      ]);

      return [
        'status' => 'failed',
        'error' => 'AI provider error',
        'data' => [],
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('Unexpected error: @error', [
        '@error' => $e->getMessage(),
      ]);

      return [
        'status' => 'failed',
        'error' => 'Unexpected error',
        'data' => [],
      ];
    }
  }

  /**
   * Builds AI prompt with dynamic replacements.
   */
  protected function buildPrompt(string $template, array $input, array $top_items): string {
    $top_items_json = json_encode($top_items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '[]';

    return strtr($template, [
      '{country}' => (string) ($input['country'] ?? 'Unknown'),
      '{today}' => date('Y-m-d', $this->time->getCurrentTime()),
      '{lookback_days}' => (string) ($input['lookback_days'] ?? 30),
      '{from_date}' => $input['from_date'],
      '{to_date}' => $input['to_date'],
      '{product_type}' => (string) ($input['product_type'] ?? 'All'),
      '{limit}' => (string) ($input['limit'] ?? 100),
      '{top_items_json}' => $top_items_json,
    ]);
  }

  /**
   * Generates a forecast based on the input parameters.
   *
   * @param array $input
   *   Input parameters (store_id, country, etc.).
   *
   * @return array
   *   Structured forecast response.
   */
  public function generateForecast(array $input): array {
    $defaults = $this->aiProviderManager
      ->getDefaultProviderForOperationType('chat');

    if (empty($defaults['provider_id']) || empty($defaults['model_id'])) {
      $this->logger->error('No default AI chat provider configured.');
      return [
        'status' => 'failed',
        'error' => 'AI provider not configured',
        'data' => [],
      ];
    }

    $config = $this->configFactory->get(self::CONFIG_NAME);

    $template = trim((string) ($input['template'] ?? $config->get(self::FORECAST_TEMPLATE_KEY) ?? ''));

    $rules = trim((string) ($input['rules'] ?? $config->get(self::FORECAST_RULES_KEY) ?? ''));

    $prompt_template = trim(implode("\n\n", array_filter([$template, $rules])));

    if ($prompt_template === '') {
      $this->logger->warning('AI prompt template is empty.');
      return [
        'status' => 'failed',
        'error' => 'Prompt template missing',
        'data' => [],
      ];
    }

    // Fetch commerce data.
    $stored_data = $this->commerceDataService->getTopItems([
      'store_id' => (int) ($input['store_id'] ?? 0),
      'lookback_days' => (int) ($input['lookback_days'] ?? 30),
      'limit' => (int) ($input['limit'] ?? 10),
      'product_type' => (string) ($input['product_type'] ?? ''),
    ]);

    $prompt = $this->buildForecastPrompt($prompt_template, $input, $stored_data);

    $this->logger->debug('Prompt length: @length', [
      '@length' => strlen($prompt),
    ]);

    try {
      $provider = $this->aiProviderManager
        ->createInstance($defaults['provider_id']);

      $messages = new ChatInput([
        new ChatMessage('user', $prompt),
      ]);

      $response = $provider->chat(
        $messages,
        $defaults['model_id'],
        ['commerce_ai_intelligence']
      );

      $response_text = $response->getNormalized()->getText();
      $parsed = $this->parseAiResponse($response_text);

      return [
        'status' => 'completed',
        'error' => NULL,
        'data' => $parsed,
      ];
    }
    catch (AiResponseErrorException $e) {
      $this->logger->error('AI provider error: @error', ['@error' => $e->getMessage()]);

      return [
        'status' => 'failed',
        'error' => 'AI provider error',
        'data' => [],
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('Unexpected error: @error', ['@error' => $e->getMessage()]);

      return [
        'status' => 'failed',
        'error' => 'Unexpected error',
        'data' => [],
      ];
    }
  }

  /**
   * Builds AI prompt with dynamic replacements for forecast generation.
   */
  protected function buildForecastPrompt(string $template, array $input, array $stored_data): string {
    $stored_data_json = json_encode($stored_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '[]';

    return strtr($template, [
      '{country}' => (string) ($input['country'] ?? 'Unknown'),
      '{today}' => date('Y-m-d', $this->time->getCurrentTime()),
      '{store_data}' => $stored_data_json,
      '{market_insight_data}' => $input['market_insight_data'],
      '{limit}' => (string) ($input['limit'] ?? 100),
      '{product_types}' => (string) ($input['product_types'] ?? 'All'),
    ]);
  }

  /**
   * Parses and validates AI response.
   */
  protected function parseAiResponse(string $response): array {
    $this->logger->info('AI response received.');

    $json = $this->aiManager->sanitizeJson($this->aiManager->extractJson($response));
    $data = json_decode($json, TRUE);

    if (json_last_error() !== JSON_ERROR_NONE) {
      // Fallback: attempt to decode from the full response body.
      $fallbackJson = $this->aiManager->sanitizeJson($response);
      $data = json_decode($fallbackJson, TRUE);

      if (json_last_error() === JSON_ERROR_NONE) {
        return $data;
      }

      $this->logger->error('JSON decode error: @error', [
        '@error' => json_last_error_msg(),
      ]);

      return [
        'status' => 'failed',
        'error' => 'Invalid AI response format',
        'data' => [],
      ];
    }

    return $data;
  }

}
