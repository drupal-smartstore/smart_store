<?php

namespace Drupal\commerce_ai_intelligence\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Locale\CountryManagerInterface;

/**
 * Manager for AI intelligence operations.
 */
class AiIntelligenceManager {
  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * AI provider plugin manager.
   *
   * @var \Drupal\ai\AiProviderPluginManager
   */
  protected AiProviderPluginManager $aiProviderManager;

  /**
   * Config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $time;

  /**
   * Logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Country manager.
   *
   * @var \Drupal\Core\Locale\CountryManagerInterface
   */
  protected $countryManager;

  /**
   * AI prompts service.
   *
   * @var \Drupal\commerce_ai_intelligence\Service\AiPromptsService
   */
  protected $aiPrompts;

  /**
   * Constructs service.
   */
  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    AiProviderPluginManager $ai_provider_manager,
    ConfigFactoryInterface $config_factory,
    TimeInterface $time,
    LoggerChannelFactoryInterface $logger_factory,
    CountryManagerInterface $countryManager,
    AiPromptsService $ai_prompts,
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->aiProviderManager = $ai_provider_manager;
    $this->configFactory = $config_factory;
    $this->time = $time;
    $this->logger = $logger_factory->get('commerce_ai_intelligence.manager');
    $this->countryManager = $countryManager;
    $this->aiPrompts = $ai_prompts;
  }

  /**
   * Gets all available Commerce product types as options.
   *
   * @return array
   *   An associative array of product type IDs and labels.
   */
  public function getProductTypeOptions(): array {
    $type_storage = $this->entityTypeManager->getStorage('commerce_product_type');
    $types = $type_storage->loadMultiple();

    $options = [];
    foreach ($types as $type_id => $type) {
      $options[$type_id] = $type->label();
    }

    asort($options);
    return $options;
  }

  /**
   * Gets all Commerce stores as selectable options.
   *
   * @return array
   *   An associative array of store IDs and store names.
   *   Returns an empty array if no stores are available.
   */
  public function getStoreOptions(): array {
    $stores = $this->entityTypeManager->getStorage('commerce_store')->loadMultiple();

    if (empty($stores)) {
      return [];
    }

    $options = [];
    foreach ($stores as $store) {
      if ($store instanceof StoreInterface) {
        $options[$store->id()] = $store->getName();
      }
    }

    $options = array_unique($options);
    asort($options);

    return $options;
  }

  /**
   * Gets the country code for a given Commerce store ID.
   *
   * @param string $store_id
   *   The store entity ID.
   *
   * @return string|null
   *   The country code (e.g., "IN", "US"), or NULL if not available.
   */
  public function getStoreCountryCode(string $store_id): ?string {
    $store = $this->entityTypeManager
      ->getStorage('commerce_store')
      ->load($store_id);

    if ($store instanceof StoreInterface) {
      $address = $store->get('address')->first();
      return $address?->country_code ?? NULL;
    }

    return NULL;
  }

  /**
   * Gets the country name from a country code.
   *
   * @param string $country_code
   *   The ISO country code (e.g., "IN", "US").
   *
   * @return string|null
   *   The country name, or NULL if not found.
   */
  public function getCountryName(string $country_code): ?string {
    $countries = $this->countryManager->getList();
    return $countries[$country_code] ?? NULL;
  }

  /**
   * Gets the store name for a given Commerce store ID.
   *
   * @param string $store_id
   *   The store entity ID.
   *
   * @return string|null
   *   The store name, or NULL if not found.
   */
  public function getStoreName(string $store_id): ?string {
    $store = $this->entityTypeManager
      ->getStorage('commerce_store')
      ->load($store_id);

    if ($store instanceof StoreInterface) {
      return $store->getName();
    }

    return NULL;
  }

  /**
   * Calculates lookback days from a configured date string.
   */
  public function calculateLookbackDays(string $configured_date): int {
    try {
      $base_date = $configured_date !== ''
        ? new \DateTimeImmutable($configured_date)
        : new \DateTimeImmutable('-30 days');
    }
    catch (\Throwable) {
      $base_date = new \DateTimeImmutable('-30 days');
    }

    $today = new \DateTimeImmutable('today');
    $days = $base_date->diff($today)->days;

    return max(1, (int) $days);
  }

  /**
   * Gets the ai intelligence configuration.
   */
  public function getAiIntelligenceConfig() {
    return $this->configFactory->get('commerce_ai_intelligence.settings');
  }

  /**
   * Gets the AI provider plugin instance.
   */
  public function toJson($data): ?string {
    return !empty($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : NULL;
  }

  /**
   * Converts data to array if not empty, otherwise returns NULL.
   */
  public function toArray($data): ?array {
    return !empty($data) ? json_decode(json_encode($data, JSON_UNESCAPED_UNICODE), TRUE) : NULL;
  }

  /**
   * Loads insight details with pagination.
   */
  public function loadInsightDetails(): array {
    return $this->database
      ->select('commerce_ai_intelligence_insight_details', 'd')
      ->fields('d')
      ->orderBy('created', 'DESC')
      ->extend(PagerSelectExtender::class)
      ->limit(20)
      ->execute()
      ->fetchAllAssoc('id', \PDO::FETCH_ASSOC) ?: [];
  }

  /**
   * Loads a single insight request by ID.
   *
   * @param int $id
   *   The insight request ID.
   *
   * @return array|null
   *   The insight record or NULL if not found.
   */
  public function loadInsightRequest(int $id): ?array {
    $result = $this->database
      ->select('commerce_ai_intelligence_insight_details', 'd')
      ->fields('d')
      ->condition('id', $id)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    return $result ?: NULL;
  }

  /**
   * Loads a single forecast request by ID.
   *
   * @param int $id
   *   The forecast request ID.
   *
   * @return array|null
   *   The forecast record or NULL if not found.
   */
  public function loadForecastRequest(int $id): ?array {
    $result = $this->database
      ->select('commerce_ai_intelligence_forecast_details', 'd')
      ->fields('d')
      ->condition('id', $id)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    return $result ?: NULL;
  }

  /**
   * Loads paginated insight products for a given request ID.
   *
   * @param int $request_id
   *   The insight request ID.
   * @param bool $pager
   *   Whether to apply pagination.
   * @param int $limit
   *   Number of items per page if pagination is enabled.
   *
   * @return array
   *   List of product insight objects.
   */
  public function loadInsightProducts(int $request_id, bool $pager = TRUE, int $limit = 5): array {
    $query = $this->database->select('commerce_ai_intelligence_insight_products', 'p')
      ->fields('p')
      ->condition('insight_request_id', $request_id)
      ->orderBy('id', 'DESC');

    if ($pager) {
      $query = $query->extend(PagerSelectExtender::class)->limit($limit);
    }

    $results = $query->execute()->fetchAllAssoc('id', \PDO::FETCH_ASSOC);

    foreach ($results as &$item) {
      $this->decodeInsightFields($item);
    }

    return $results;
  }

  /**
   * Loads paginated forecast products for a given request ID.
   *
   * @param int $request_id
   *   The insight request ID.
   * @param bool $pager
   *   Whether to apply pagination.
   * @param int $limit
   *   Number of items per page if pagination is enabled.
   *
   * @return array
   *   List of product forecast objects.
   */
  public function loadForecastProducts(int $request_id, bool $pager = TRUE, int $limit = 5): array {
    $query = $this->database->select('commerce_ai_intelligence_forecast_products', 'p')
      ->fields('p')
      ->condition('forecast_request_id', $request_id)
      ->orderBy('id', 'DESC');

    if ($pager) {
      $query = $query->extend(PagerSelectExtender::class)->limit($limit);
    }

    $results = $query->execute()->fetchAllAssoc('id', \PDO::FETCH_ASSOC);

    foreach ($results as &$item) {
      $this->decodeForecastFields($item);
    }

    return $results;
  }

  /**
   * Decodes JSON fields for forecast product.
   */
  private function decodeForecastFields(array &$item): void {
    $fields = [
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
    ];

    foreach ($fields as $field) {
      $item[$field] = !empty($item[$field])
        ? json_decode($item[$field], TRUE)
        : [];
    }
  }

  /**
   * Decodes JSON fields for insight product.
   */
  private function decodeInsightFields(array &$item): void {

    $fields = [
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
      'metadata',
    ];

    foreach ($fields as $field) {
      $item[$field] = !empty($item[$field])
      ? json_decode($item[$field], TRUE)
      : [];
    }
  }

  /**
   * Builds the row data for the forecast table.
   */
  public function loadForecastDetails(): array {
    $results = $this->database
      ->select('commerce_ai_intelligence_forecast_details', 'fd')
      ->fields('fd', [
        'id',
        'store_id',
        'product_type',
        'limit',
        'market_insight_id',
        'error_message',
        'status',
        'created',
      ])
      ->orderBy('created', 'DESC')
      ->extend(PagerSelectExtender::class)
      ->limit(20)
      ->execute()
      ->fetchAllAssoc('id', \PDO::FETCH_ASSOC) ?: [];

    return $results;
  }

  /**
   * Sends prompt to AI provider and processes the response.
   */
  public function getInsightOptions(): array {
    $options = [];
    $details = $this->loadInsightDetails();

    foreach ($details as $id => $detail) {
      if ($detail['status'] === 'failed' || $detail['status'] === 'in_progress') {
        continue;
      }

      $store_name = $this->getStoreName((string) $detail['store_id']) ?? 'Unknown Store';
      $product_types = !empty($detail['product_type'])
        ? implode(', ', array_map('ucfirst', explode(', ', $detail['product_type'])))
        : 'All Products';
      $created_date = date('F j, Y', (int) $detail['created']);

      $options[$id] = "$store_name - $product_types - $created_date";
    }

    return $options;
  }

  /**
   * Builds forecast prompt.
   *
   * @param array $data
   *   Input data for prompt generation.
   *
   * @return string
   *   Final AI prompt string.
   */
  public function buildForecastPrompt(array $data): string {
    $config = $this->configFactory->get('commerce_ai_intelligence.settings');

    $template = $config->get('forecast.forecast_template')
    ?? $this->aiPrompts->getForecastTemplate();

    $rules = $config->get('forecast.forecast_rules')
    ?? $this->aiPrompts->getForecastRules();

    $prompt = $rules . "\n\n" . $template;

    return $this->replaceTokens($prompt, $this->preparePromptTokens($data, $config));
  }

  /**
   * Builds market insight prompt.
   *
   * @param array $data
   *   Input data for prompt generation.
   *
   * @return string
   *   Final AI prompt string.
   */
  public function buildInsightPrompt(array $data): string {
    $config = $this->configFactory->get('commerce_ai_intelligence.settings');

    $template = $config->get('market_insights.market_insights_template')
    ?? $this->aiPrompts->getMarketInsightTemplate();

    $rules = $config->get('market_insights.market_insights_rules')
    ?? $this->aiPrompts->getMarketInsightRules();

    $prompt = $rules . "\n\n" . $template;

    return $this->replaceTokens($prompt, $this->preparePromptTokens($data, $config));
  }

  /**
   * Prepares token values for prompt replacement.
   *
   * @param array $data
   *   Input data.
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   Configuration object.
   *
   * @return array
   *   Token key-value pairs.
   */
  private function preparePromptTokens(array $data, $config): array {
    $today = new \DateTimeImmutable();

    $start_date = $config->get('general.lookback_start_date');
    $lookback_days = $this->calculateLookbackDays($start_date);

    return [
      'country' => $data['country'] ?? 'India',
      'today' => $today->format('Y-m-d'),
      'lookback_days' => $lookback_days,
      'store_data' => json_encode($data['store_data'] ?? [], JSON_UNESCAPED_UNICODE),
      'market_insight_data' => json_encode($data['market_insight_data'] ?? [], JSON_UNESCAPED_UNICODE),
      'product_types' => json_encode($data['product_types'] ?? [], JSON_UNESCAPED_UNICODE),
      'limit' => (int) ($config->get('general.product_limit') ?? 5),
    ];
  }

  /**
   * Replaces tokens in the given text.
   *
   * @param string $text
   *   The text containing tokens.
   * @param array $tokens
   *   Token values.
   *
   * @return string
   *   Processed text.
   */
  private function replaceTokens(string $text, array $tokens): string {
    foreach ($tokens as $key => $value) {
      $text = str_replace("{{$key}}", (string) $value, $text);
    }
    return $text;
  }

  /**
   * Sanitizes JSON string.
   */
  public function sanitizeJson(string $json): string {
    // Remove markdown fences.
    $json = preg_replace('/^```json|```$/m', '', $json);

    // Decode HTML entities.
    $json = html_entity_decode($json, ENT_QUOTES | ENT_HTML5);

    // Remove control characters (VERY important)
    $json = preg_replace('/[\x00-\x1F\x80-\xFF]/u', '', $json);

    // Remove trailing commas.
    $json = preg_replace('/,\s*([}\]])/', '$1', $json);

    return trim($json);
  }

  /**
   * Extracts the first valid JSON object from text.
   */
  public function extractJson(string $text): string {
    // Try extracting from markdown ```json block first.
    // Greedy capture avoids truncation on nested JSON braces.
    if (preg_match('/```json\s*([\s\S]*?)\s*```/i', $text, $matches)) {
      $candidate = trim($matches[1]);
      if ($candidate !== '') {
        return $candidate;
      }
    }

    // Also support generic fenced blocks when model omits the json tag.
    if (preg_match('/```\s*([\s\S]*?)\s*```/', $text, $matches)) {
      $candidate = trim($matches[1]);
      if ($candidate !== '') {
        return $candidate;
      }
    }

    // Fallback: direct object-only extraction for plain text responses.
    if (preg_match('/(\{[\s\S]*\})/', $text, $matches)) {
      return $matches[1];
    }

    // Fallback: find first balanced JSON object.
    $length = strlen($text);
    $depth = 0;
    $start = NULL;

    for ($i = 0; $i < $length; $i++) {
      if ($text[$i] === '{') {
        if ($depth === 0) {
          $start = $i;
        }
        $depth++;
      }

      if ($text[$i] === '}') {
        $depth--;
        if ($depth === 0 && $start !== NULL) {
          return substr($text, $start, $i - $start + 1);
        }
      }
    }

    return $text;
  }

}
