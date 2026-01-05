<?php

declare(strict_types=1);

namespace Drupal\ss_dashboard\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a "Total Users Chart" dashboard block.
 *
 * Displays:
 * - Total active users
 * - Monthly new user registrations (last 6 months)
 * - Net user base growth percentage (MoM)
 *
 * @Block(
 *   id = "total_users_chart_block",
 *   admin_label = @Translation("Total Users Created Chart")
 * )
 */
final class TotalUsersBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs the TotalUsersBlock.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager service.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): self {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    // Current total active users.
    $totalUsers = $this->getTotalActiveUsers();

    // Monthly new users (chart data).
    $newUsersData = $this->getMonthlyNewUsers();

    // Cumulative active users per month (growth calculation).
    $totalUsersByMonth = $this->getTotalActiveUsersByMonth();

    // Percentage trend based on total active user growth.
    $percentageTrend = $this->calculatePercentageTrend(
      $totalUsersByMonth['totals']
    );

    return [
      '#theme' => 'total_users_chart',
      '#content' => [
        'totalUsers' => $totalUsers,
        'percentageIncrease' => $percentageTrend['formatted'],
        'class' => $percentageTrend['class'],
      ],
      '#attached' => [
        'library' => [
          'ss_dashboard/ss_dashboard.apexcharts',
          'ss_dashboard/ss_dashboard.total_users',
        ],
        'drupalSettings' => [
          'totalUsersData' => [
            'labels' => $newUsersData['labels'],
            'usersData' => $newUsersData['users'],
            'totalUsers' => $totalUsersByMonth['totals'],
          ],
        ],
      ],
      '#cache' => [
        'tags' => ['user_list'],
        'contexts' => ['user.permissions'],
      ],
    ];
  }

  /**
   * Returns the current total number of active users.
   *
   * @return int
   *   Active user count.
   */
  private function getTotalActiveUsers(): int {
    return (int) $this->entityTypeManager
      ->getStorage('user')
      ->getQuery()
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

  /**
   * Returns monthly new user registrations for the last 6 months.
   *
   * Used for chart visualization.
   *
   * @return array
   *   Array containing:
   *   - labels: Month labels
   *   - users: New user counts
   */
  private function getMonthlyNewUsers(): array {
    $labels = [];
    $users = [];
    $storage = $this->entityTypeManager->getStorage('user');

    for ($i = 5; $i >= 0; $i--) {
      $labels[] = date('M', strtotime("-{$i} months"));

      $start = strtotime("first day of -{$i} months 00:00:00");
      $end = strtotime("last day of -{$i} months 23:59:59");

      $count = $storage->getQuery()
        ->condition('created', $start, '>=')
        ->condition('created', $end, '<=')
        ->accessCheck(FALSE)
        ->count()
        ->execute();

      $users[] = (int) $count;
    }

    return [
      'labels' => $labels,
      'users' => $users,
    ];
  }

  /**
   * Returns cumulative total active users at the end of each month.
   *
   * Used for net user base growth calculation.
   *
   * @return array
   *   Array containing:
   *   - labels: Month labels
   *   - totals: Cumulative active user totals
   */
  private function getTotalActiveUsersByMonth(): array {
    $labels = [];
    $totals = [];
    $storage = $this->entityTypeManager->getStorage('user');

    for ($i = 5; $i >= 0; $i--) {
      $labels[] = date('M', strtotime("-{$i} months"));
      $end = strtotime("last day of -{$i} months 23:59:59");

      $total = $storage->getQuery()
        ->condition('status', 1)
        ->condition('created', $end, '<=')
        ->accessCheck(FALSE)
        ->count()
        ->execute();

      $totals[] = (int) $total;
    }

    return [
      'labels' => $labels,
      'totals' => $totals,
    ];
  }

  /**
   * Calculates percentage growth trend between last two values.
   *
   * @param array<int, int> $values
   *   Numeric dataset (chronological).
   *
   * @return array
   *   Contains:
   *   - percentage: float
   *   - arrow: string
   *   - formatted: string
   *   - class: string
   */
  private function calculatePercentageTrend(array $values): array {
    $count = count($values);

    if ($count < 2 || $values[$count - 2] <= 0) {
      return [
        'percentage' => 0.0,
        'arrow' => '=',
        'formatted' => '= 0%',
        'class' => 'equal',
      ];
    }

    $previous = $values[$count - 2];
    $current = $values[$count - 1];

    $percentage = round((($current - $previous) / $previous) * 100, 2);

    $arrow = match (TRUE) {
      $percentage > 0 => '▲',
      $percentage < 0 => '▼',
      default => '=',
    };

    return [
      'percentage' => $percentage,
      'arrow' => $arrow,
      'formatted' => "{$arrow} " . abs($percentage) . '%',
      'class' => $percentage > 0 ? 'increase' : ($percentage < 0 ? 'decrease' : 'equal'),
    ];
  }

}
