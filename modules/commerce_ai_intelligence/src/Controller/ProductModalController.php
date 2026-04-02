<?php

namespace Drupal\commerce_ai_intelligence\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for rendering the product insight modal.
 */
class ProductModalController extends ControllerBase {

  /**
   * Summary of __construct.
   *
   * @param \Drupal\Core\Database\Connection $connection
   */
  protected Connection $connection;

  public function __construct(Connection $connection) {
    $this->connection = $connection;

  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('database'),
    );
  }

  /**
   * Modal callback to display detailed market insight for a product.
   */
  public function insightModal($id) {
    $product = $this->connection
      ->select('commerce_ai_intelligence_insight_products', 'p')
      ->fields('p')
      ->condition('id', $id)
      ->execute()
      ->fetchObject();

    if (!$product) {
      return [
        '#markup' => $this->t('No data found'),
      ];
    }

    // Helper for safe decoding.
    $decode = static function ($value) {
      return $value ? json_decode($value, TRUE) : [];
    };

    // Decode ALL JSON fields (aligned with DB)
    $product->category_reasoning = $decode($product->category_reasoning);
    $product->demand_momentum = $decode($product->demand_momentum);
    $product->trend_dynamics = $decode($product->trend_dynamics);
    $product->competition_landscape = $decode($product->competition_landscape);
    $product->opportunity = $decode($product->opportunity);
    $product->sustainability = $decode($product->sustainability);
    $product->seasonality = $decode($product->seasonality);
    $product->customer_segments = $decode($product->customer_segments);
    $product->strategic_insight = $decode($product->strategic_insight);
    $product->decision_framing = $decode($product->decision_framing);
    $product->review_cadence = $decode($product->review_cadence);
    $product->metadata = $decode($product->metadata);

    // Normalize confidence (avoid duplication issue)
    $product->confidence_overall = $product->metadata['confidence_overall']
    ?? $product->confidence_overall
    ?? 'unknown';

    return [
      '#theme' => 'ai_intelligence_insight_product_modal',
      '#product' => $product,
    ];
  }

  /**
   * Modal callback to display detailed forecast for a product.
   *
   * @param int $id
   *   The ID of the forecast product to display.
   *
   * @return array
   *   A render array for the product forecast modal.
   */
  public function forecastModal($id) {
    $product = $this->connection
      ->select('commerce_ai_intelligence_forecast_products', 'p')
      ->fields('p')
      ->condition('id', $id)
      ->execute()
      ->fetchObject();

    if (!$product) {
      return [
        '#markup' => $this->t('No data found'),
      ];
    }

    $decode = static function ($value) {
      return $value ? json_decode($value, TRUE) : [];
    };

    // Decode all JSON fields.
    $product->opportunity_score = $decode($product->opportunity_score);
    $product->demand_forecast = $decode($product->demand_forecast);
    $product->price_forecast = $decode($product->price_forecast);
    $product->profit_cost = $decode($product->profit_cost);
    $product->benchmarks = $decode($product->benchmarks);
    $product->operational_forecast = $decode($product->operational_forecast);
    $product->seasonality = $decode($product->seasonality);
    $product->decision_framing = $decode($product->decision_framing);
    $product->review_cadence = $decode($product->review_cadence);
    $product->historical_performance = $decode($product->historical_performance);
    $product->competition_metrics = $decode($product->competition_metrics);
    $product->confidence_score = $decode($product->confidence_score);
    $product->metadata = $decode($product->metadata);

    if (!empty($product->demand_forecast['key_drivers']) && is_array($product->demand_forecast['key_drivers'])) {
      $product->demand_forecast['key_drivers'] = array_map(function ($item) {
        if (strpos($item, ':') !== FALSE) {
          [$name, $impact] = explode(':', $item, 2);
          return [
            'name' => trim($name),
            'impact' => trim($impact),
          ];
        }

        return [
          'name' => '',
          'impact' => $item,
        ];
      }, $product->demand_forecast['key_drivers']);
    }

    return [
      '#theme' => 'ai_intelligence_forecast_product_modal',
      '#product' => $product,
      '#attributes' => ['class' => ['forecast-modal']],
    ];
  }

}
