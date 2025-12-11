<?php

declare(strict_types=1);

namespace Drupal\ss_dashboard\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a "Total Users Chart" block.
 *
 * Displays total active users and new user counts for the past
 * 6 months with percentage increase or decrease.
 *
 * @Block(
 *   id = "total_users_chart_block",
 *   admin_label = @Translation("Total Users Created Chart")
 * )
 */
final class TotalUsersBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a TotalUsersBlock instance.
   *
   * @param array $configuration
   *   Plugin configuration values.
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \Drupal\Core\Database\Connection $database
   *   Database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity Type Manager.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    Connection $database,
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->database = $database;
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
      $container->get('database'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $totalUsers = $this->getTotalUsers();
    $monthlyUsers = $this->getNewUsersPerMonth();

    $labels = array_column($monthlyUsers, 'month');
    $values = array_column($monthlyUsers, 'new_users');

    $percentageTrend = $this->calculatePercentageTrend($values);

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
            'labels' => $labels,
            'usersData' => $values,
          ],
        ],
      ],
      '#cache' => [
        'tags' => ['user_list'],
      ],
    ];
  }

  /**
   * Returns total active user count.
   *
   * @return int
   *   Active user count.
   */
  private function getTotalUsers(): int {
    return (int) $this->entityTypeManager
      ->getStorage('user')
      ->getQuery()
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

  /**
   * Calculates the percentage trend between last two months.
   *
   * @param array<int, int> $values
   *   Numeric user counts.
   *
   * @return array
   *   Contains:
   *   - percentage: float
   *   - arrow: string (▲, ▼, =)
   *   - formatted: string "▲ 25%"
   */
  private function calculatePercentageTrend(array $values): array {
    $count = count($values);

    if ($count < 2) {
      return [
        'percentage' => 0.0,
        'arrow' => '=',
        'formatted' => '= 0%',
        'class' => 'equal',
      ];
    }

    $previous = $values[$count - 2];
    $current = $values[$count - 1];

    if ($previous <= 0) {
      return [
        'percentage' => 0.0,
        'arrow' => '=',
        'formatted' => '= 0%',
        'class' => 'equal',
      ];
    }

    $percentage = round((($current - $previous) / $previous) * 100, 2);

    $arrow = match (TRUE) {
      $percentage > 0 => '▲',
      $percentage < 0 => '▼',
      default => '=',
    };

    return [
      'percentage' => $percentage,
      'arrow' => $arrow,
      'formatted' => "{$arrow} " . abs($percentage) . "%",
      'class' => $percentage > 0 ? 'increase' : ($percentage < 0 ? 'decrease' : 'equal'),
    ];
  }

  /**
   * Returns new active user count per month for the past 6 months.
   *
   * @return array<int, array<string, mixed>>
   *   Each row contains:
   *   - month: string (e.g., "Jan 2025")
   *   - new_users: int
   */
  private function getNewUsersPerMonth(): array {
    $startTimestamp = strtotime('-6 months');
    $startMonth = strtotime(date('Y-m-01', $startTimestamp));

    $query = $this->database->select('users_field_data', 'u');

    $query->addExpression("DATE_FORMAT(FROM_UNIXTIME(u.created), '%b %Y')", 'month');
    $query->addExpression('COUNT(u.uid)', 'new_users');

    $query->condition('u.status', 1);
    $query->condition('u.created', $startMonth, '>=');

    $query->groupBy('month');

    // Fix ONLY_FULL_GROUP_BY error: Order by grouped column.
    $query->orderBy('month', 'ASC');

    return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

}
