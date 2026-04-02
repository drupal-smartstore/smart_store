<?php

declare(strict_types=1);

namespace Drupal\commerce_ai_intelligence\Plugin\Block;

use Drupal\Core\Url;
use Drupal\commerce_ai_intelligence\Service\AiIntelligenceManager;
use Drupal\Component\Utility\Html;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Markup;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a Market Insight Products block.
 *
 * @Block(
 *   id = "commerce_ai_intelligence_insight_products",
 *   admin_label = @Translation("AI Intelligence: Market Insight Products"),
 *   category = @Translation("Commerce AI Intelligence"),
 * )
 */
final class MarketInsightProductsBlock extends BlockBase implements ContainerFactoryPluginInterface {

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

    $products = $this->aiManager->loadInsightProducts((int) $id);

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-intelligence-table']],
      'header_top' => [
        '#markup' => Markup::create('
          <div class="table-header-top">
            <h3 class="table-title">Product Insights</h3>
          </div>
        '),
      ],
      'table' => [
        '#type' => 'table',
        '#attributes' => [
          'id' => 'productTable',
          'class' => ['product-table'],
        ],
        '#header' => [
          $this->t('Product'),
          $this->t('Category'),
          $this->t('Demand'),
          $this->t('Trend'),
          $this->t('Competition'),
          $this->t('Opportunity'),
          $this->t('Seasonality'),
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
   * Builds the table rows for the product insights.
   *
   * @param array $products
   *   The array of product insights.
   *
   * @return array
   *   The table rows.
   */
  private function buildRows(array $products): array {
    $rows = [];

    foreach ($products as $item) {
      $seasonality = !empty($item['seasonality']['peak_months'])
        ? implode(', ', $item['seasonality']['peak_months'])
        : '—';

      $rows[] = [
        'data' => [
          Markup::create('<span class="product-name">' . Html::escape($item['product_name']) . '</span>'),
          Markup::create('<span class="badge-category">' . Html::escape($item['category']) . '</span>'),
          Markup::create($this->buildScoreCell($item['demand_momentum'] ?? [])),
          Markup::create($this->buildScoreCell($item['trend_dynamics'] ?? [])),
          Markup::create($this->buildScoreCell($item['competition_landscape'] ?? [])),
          Markup::create($this->buildScoreCell($item['opportunity'] ?? [])),
          Markup::create('<span class="seasonality">' . Html::escape($seasonality) . '</span>'),

          [
            'data' => [
              '#type' => 'link',
              '#title' => Markup::create('<i class="fa-solid fa-arrow-right"></i>'),
              '#url' => Url::fromRoute('commerce_ai_intelligence.product_insight_modal', [
                'id' => $item['id'],
              ]),
              '#attributes' => [
                'class' => ['use-ajax'],
                'data-dialog-type' => 'dialog',
                'data-dialog-options' => json_encode(['width' => 700]),
              ],
            ],
          ],
        ],
      ];
    }

    return $rows;
  }

  /**
   * Builds a score cell with visual indicators based on the provided data.
   */
  private function buildScoreCell(array $data): string {
    $value = $data['momentum'] ?? $data['intensity'] ?? $data['potential'] ?? 'unknown';
    $value = strtolower((string) $value);

    $scoreMap = [
      'high' => 3,
      'rising' => 3,
      'accelerating' => 3,
      'medium' => 2,
      'medium-high' => 2,
      'stable' => 2,
      'low' => 1,
      'declining' => 1,
      'slowing' => 1,
    ];

    $score = $scoreMap[$value] ?? 0;

    $labelMap = [
      'rising' => 'High',
      'accelerating' => 'High',
      'high' => 'High',
      'medium-high' => 'Medium',
      'stable' => 'Medium',
      'medium' => 'Medium',
      'declining' => 'Low',
      'slowing' => 'Low',
      'low' => 'Low',
    ];

    $label = $labelMap[$value] ?? ucfirst($value);
    $tooltip = $data['confidence_note'] ?? $data['evidence'] ?? '';

    $html = '<div class="score-cell" title="' . Html::escape($tooltip) . '">';
    $html .= '<div class="score" data-score="' . $score . '">';
    $html .= '<div class="score-meter">
              <span class="meter-segment"></span>
              <span class="meter-segment"></span>
              <span class="meter-segment"></span>
            </div>';
    $html .= '<div class="score-text">' . Html::escape($label) . '</div>';
    $html .= '</div></div>';

    return $html;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return 0;
  }

}
