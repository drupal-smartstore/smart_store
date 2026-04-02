<?php

declare(strict_types=1);

namespace Drupal\commerce_ai_intelligence\Plugin\Block;

use Drupal\commerce_ai_intelligence\Service\AiIntelligenceManager;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a Forecast Products block.
 *
 * @Block(
 *   id = "commerce_ai_intelligence_forecast_products",
 *   admin_label = @Translation("AI Intelligence: Forecast Products"),
 *   category = @Translation("Commerce AI Intelligence"),
 * )
 */
final class ForecastProductsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly Connection $connection,
    private readonly RequestStack $requestStack,
    private readonly AiIntelligenceManager $aiManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('database'),
      $container->get('request_stack'),
      $container->get('commerce_ai_intelligence.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $id = $this->requestStack->getCurrentRequest()->attributes->get('id');

    if (!$id) {
      return ['#markup' => $this->t('No insight ID provided.')];
    }

    $products = $this->aiManager->loadForecastProducts((int) $id);

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-intelligence-table']],
      'header_top' => [
        '#markup' => Markup::create('
          <div class="table-header-top">
            <h3 class="table-title">Forecast Products</h3>
          </div>
        '),
      ],
      'table' => [
        '#type' => 'table',
        '#attributes' => [
          'id' => 'productTable',
          'class' => ['product-table', 'forecast-table'],
        ],
        '#header' => [
          $this->t('Product Details'),
          $this->t('Category'),
          $this->t('Opportunity Score'),
          $this->t('Price Trend'),
          $this->t('Confidence'),
          $this->t('Actions'),
        ],
        '#rows' => $this->buildRows($products),
        '#empty' => $this->t('No product suggestions available.'),
      ],
      'pager' => ['#type' => 'pager'],
      '#attached' => [
        'library' => [
          'core/drupal.dialog.ajax',
        ],
      ],
    ];
  }

  /**
   * Builds forecast product rows.
   */
  private function buildRows(array $products): array {
    $rows = [];

    // dd($products);
    foreach ($products as $item) {
      $priceForecast = is_array($item['price_forecast'] ?? NULL) ? $item['price_forecast'] : [];
      $trajectory = (string) ($priceForecast['price_trajectory'] ?? 'stable');
      $estimatedChange = (string) ($priceForecast['estimated_change'] ?? 'N/A');
      $trajectoryMeta = $this->getPriceTrajectoryMeta($trajectory);

      $confidenceLevel = $this->getConfidenceLevel($item['opportunity_score']['total'] ?? NULL);
      $rows[] = [
        'data' => [
          Markup::create('<span class="product-name">' . $item['product_name'] . '</span><span class="product-id">ID: ' . $item['product_id'] . ' </span>'),
          Markup::create('<span class="badge-category">' . $item['category'] . '</span>'),
          Markup::create('<div class="score-bar-bg"><div class="score-bar-fill" style="width: ' . ($item['opportunity_score']['total'] * 10) . '%"></div></div><span style="font-weight: 700;">' . $item['opportunity_score']['total'] . '/10</span>'),
          Markup::create('<div style="display: flex; flex-direction: column; gap: 2px;">
                            <span class="trajectory-pill" style="color: ' . htmlspecialchars($trajectoryMeta['color']) . ';">
                              <i class="fa-solid ' . htmlspecialchars($trajectoryMeta['icon']) . '"></i>' . htmlspecialchars($trajectoryMeta['label']) . '
                            </span>
                            <span style="font-size: 11px; color: ' . htmlspecialchars($trajectoryMeta['color']) . '; font-weight: 600; padding-left: 20px;">' . htmlspecialchars($estimatedChange) . '</span>
                         </div>'),
          Markup::create('<span class="status-badge status-' . $confidenceLevel . '">' . ucfirst($confidenceLevel) . '</span>'),
          [
            'data' => [
              '#type' => 'link',
              '#title' => Markup::create('<i class="fa-solid fa-arrow-right"></i>'),
              '#url' => Url::fromRoute('commerce_ai_intelligence.product_forecast_modal', [
                'id' => $item['id'],
              ]),
              '#attributes' => [
                'class' => ['use-ajax'],
                'data-dialog-type' => 'dialog',
                'data-dialog-options' => json_encode(['width' => 700, 'dialogClass' => 'forecast-modal']),
              ],
            ],
          ],
        ],
      ];
    }

    return $rows;
  }

  /**
   * Maps trajectory text to icon/color for UI display.
   */
  private function getPriceTrajectoryMeta(string $trajectory): array {
    $normalized = strtolower(trim($trajectory));

    if (in_array($normalized, ['rising', 'up', 'upward', 'increasing', 'increase'], TRUE)) {
      return [
        'icon' => 'fa-arrow-trend-up',
        'color' => 'var(--success-main)',
        'label' => 'Rising',
      ];
    }

    if (in_array($normalized, ['falling', 'declining', 'down', 'downward', 'decreasing', 'decrease'], TRUE)) {
      return [
        'icon' => 'fa-arrow-trend-down',
        'color' => 'var(--error-main)',
        'label' => ucfirst($normalized === 'declining' ? 'declining' : 'falling'),
      ];
    }

    return [
      'icon' => 'fa-minus',
      'color' => 'var(--text-muted)',
      'label' => 'Stable',
    ];
  }

  /**
   *
   */
  public function getConfidenceLevel($score): string {
    if ($score === NULL || !is_numeric($score)) {
      return 'unknown';
    }

    $score = (float) $score;

    if ($score >= 7) {
      return 'high';
    }

    if ($score >= 5) {
      return 'medium';
    }

    if ($score >= 1) {
      return 'low';
    }

    return 'unknown';
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return 0;
  }

}
