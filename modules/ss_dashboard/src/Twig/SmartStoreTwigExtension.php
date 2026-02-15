<?php

declare(strict_types=1);

namespace Drupal\ss_dashboard\Twig;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Provides custom Twig filters for Smart Store dashboards.
 */
final class SmartStoreTwigExtension extends AbstractExtension {

  /**
   * Entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs the SmartStoreTwigExtension.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function getFilters(): array {
    return [
      new TwigFilter('number_formatter', [$this, 'numberFormatter']),
      new TwigFilter('currency_symbol', [$this, 'getCurrencySymbol']),
    ];
  }

  /**
   * Converts a string or numeric value into a formatted number.
   *
   * Examples:
   * - "1,000.000000" → 1,000
   * - "₹1,25,000.75" → 125,000
   *
   * @param int|string $number
   *   Raw numeric value.
   * @param int $decimals
   *   Number of decimal places.
   *
   * @return string
   *   The formatted number.
   */
  public function numberFormatter(int|string $number, int $decimals = 0): string {
    $number = (int) $number;
    return number_format((float) $number, $decimals);
  }

  /**
   * Returns the currency symbol for a given currency code.
   *
   * @param string $currency_code
   *   ISO 4217 currency code (e.g. INR, USD).
   *
   * @return string
   *   Currency symbol or currency code as fallback.
   */
  public function getCurrencySymbol(string $currency_code): string {
    $storage = $this->entityTypeManager->getStorage('commerce_currency');
    $currency = $storage->load($currency_code);

    return $currency ? $currency->getSymbol() : $currency_code;
  }

}
