<?php

declare(strict_types=1);

namespace Drupal\commerce_ai_intelligence\Plugin\Block;

use Drupal\commerce_ai_intelligence\Service\AiIntelligenceManager;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a Forecast Detail block.
 *
 * @Block(
 *   id = "commerce_ai_intelligence_forecast_detail",
 *   admin_label = @Translation("AI Intelligence: Forecast Detail"),
 *   category = @Translation("Commerce AI Intelligence"),
 * )
 */
final class ForecastDetailBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the plugin instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
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
      $container->get('request_stack'),
      $container->get('commerce_ai_intelligence.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $request = $this->requestStack->getCurrentRequest();
    $id = $request->attributes->get('id');

    if (!$id) {
      return [
        '#markup' => $this->t('No forecast ID provided.'),
      ];
    }

    $forecast = $this->aiManager->loadForecastRequest((int) $id);

    return [
      '#theme' => 'ai_intelligence_forecast_detail',
      '#data' => [
        'forecast' => $forecast ? Json::decode($forecast['summary']) : [],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return 0;
  }

}
