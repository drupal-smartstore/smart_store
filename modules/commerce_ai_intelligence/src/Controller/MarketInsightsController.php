<?php

namespace Drupal\commerce_ai_intelligence\Controller;

use Drupal\commerce_ai_intelligence\Service\AiIntelligenceManager;
use Drupal\commerce_ai_intelligence\Service\AiMarketAnalysisService;
use Drupal\Core\Database\Connection;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Render\Markup;

/**
 * Controller for rendering AI Intelligence Market Insights listing and detail.
 */
class MarketInsightsController extends ControllerBase {

  /**
   * AI market analysis service.
   */
  protected AiMarketAnalysisService $marketAnalysisService;

  /**
   * AI intelligence manager service.
   */
  protected AiIntelligenceManager $aiManager;

  /**
   * Database connection.
   */
  protected Connection $database;

  /**
   * Constructs the controller.
   */
  public function __construct(
    AiMarketAnalysisService $market_analysis_service,
    AiIntelligenceManager $ai_manager,
    Connection $database,
  ) {
    $this->marketAnalysisService = $market_analysis_service;
    $this->aiManager = $ai_manager;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('commerce_ai_intelligence.market_analysis'),
      $container->get('commerce_ai_intelligence.manager'),
      $container->get('database'),
    );
  }

  /**
   * Builds the Market Insights listing page.
   *
   * @return array
   *   Render array.
   */
  public function listing(): array {
    $insights = $this->aiManager->loadInsightDetails();

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-intelligence-insights']],
      '#attached' => [
        'library' => [
          'core/drupal.dialog.ajax',
        ],
      ],
      'open_modal' => $this->buildRequestModalLink(),
      'table' => $this->buildInsightsTable($insights),
    ];
  }

  /**
   * Displays a single insight detail page.
   */
  public function view(int $id): array {
    return [
      '#theme' => 'ai_intelligence_insights_controller',
      '#data' => [],
      '#attached' => [
        'library' => [],
      ],
    ];
  }

  /**
   * Builds modal trigger link for generating insights.
   */
  private function buildRequestModalLink(): array {
    return [
      '#type' => 'link',
      '#title' => $this->t('Generate Market Insight'),
      '#url' => Url::fromRoute('commerce_ai_intelligence.market_insight_request'),
      '#attributes' => [
        'class' => ['use-ajax', 'button', 'button--primary'],
        'data-dialog-type' => 'modal',
        'data-dialog-options' => json_encode(['width' => 700]),
      ],
    ];
  }

  /**
   * Builds insights table.
   */
  private function buildInsightsTable(array $insights): array {
    $rows = [];

    foreach ($insights as $insight) {
      $status = (string) ($insight['status'] ?? 'in_progress');
      $is_failed = $status === 'failed';

      $error_message = (string) ($insight['error_message'] ?? '');

      $rows[] = [
        'id' => (int) $insight['id'],
        'store_id' => $is_failed ? Markup::create('<span class="text-danger">—</span>') : $this->aiManager->getStoreName((string) $insight['store_id']),
        'product_type' => $is_failed
          ? Markup::create('<span class="text-danger">—</span>')
          : Markup::create(
            '<span class="badge-category">' .
            implode(', ', array_map('ucfirst', explode(', ', $insight['product_type']))) .
            '</span>'
        ),
        'from_date' => $is_failed ? Markup::create('<span class="text-danger">—</span>') : date('F j, Y', (int) $insight['from_date']),
        'to_date' => $is_failed ? Markup::create('<span class="text-danger">—</span>') : date('F j, Y', (int) $insight['to_date']),
        'limit' => $is_failed ? Markup::create('<span class="text-danger">—</span>') : (string) $insight['limit'],
        'status' => Markup::create('<span class="status-badge status-' . $status . '" ' . ($is_failed && $error_message ? 'title="' . htmlspecialchars($error_message) . '"' : '') . '>' . ucfirst(str_replace('_', ' ', $status)) . '</span>'),
        'created' => date('F j, Y', (int) $insight['created']),
        'actions' => [
          'data' => $this->buildActionLinks((int) $insight['id'], $status),
        ],
      ];
    }

    return [
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('ID'),
          $this->t('Store'),
          $this->t('Product Type'),
          $this->t('From Date'),
          $this->t('To Date'),
          $this->t('Limit'),
          $this->t('Status'),
          $this->t('Created'),
          $this->t('Actions'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No market insight requests available.'),
        '#attributes' => ['class' => ['list-table']],
      ],
      'pager' => ['#type' => 'pager'],
    ];
  }

  /**
   * Builds action links based on request status.
   */
  private function buildActionLinks(int $id, string $status): array {
    if ($status !== 'completed') {
      return [
        '#markup' => '<span class="text-disabled">View</span>',
      ];
    }

    return [
      '#type' => 'container',
      'view' => Link::fromTextAndUrl(
        $this->t('View'),
        Url::fromRoute('commerce_ai_intelligence.market_insight_view', ['id' => $id])
      )->toRenderable(),
    ];
  }

}
