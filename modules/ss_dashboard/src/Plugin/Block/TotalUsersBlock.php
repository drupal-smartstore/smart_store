<?php

namespace Drupal\ss_dashboard\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'Total Users Created ApexChart' block.
 *
 * @Block(
 *   id = "total_users_chart_block",
 *   admin_label = @Translation("Total Users Created Chart")
 * )
 */
class TotalUsersBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
   * Constructs a TotalUsersBlock object.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
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
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('database'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $total_users = $this->getTotalUsers();
    $monthly_new = $this->getNewUsersPerMonth();

    $labels = array_column($monthly_new, 'month');
    $values = array_column($monthly_new, 'new_users');

    $percentage_increase = $this->getPercentageIncrease(
      end($values),
      count($values) >= 2 ? $values[count($values) - 2] : 0
    );

    return [
      '#theme' => 'total_users_chart',
      '#content' => [
        'totalUsers' => $total_users,
        'percentageIncrease' => $percentage_increase,
      ],
      '#attached' => [
        'library' => ['ss_dashboard/ss_dashboard.total_users'],
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
   * Returns the total number of active users.
   *
   * @return int
   *   Total active user count.
   */
  public function getTotalUsers(): int {
    $query = $this->entityTypeManager->getStorage('user')->getQuery();
    $query->condition('status', 1)
      ->accessCheck(FALSE);

    return (int) $query->count()->execute();
  }

  /**
   * Calculates the percentage increase or decrease.
   *
   * @param int|float $current
   *   The current month value.
   * @param int|float $previous
   *   The previous month value.
   *
   * @return float
   *   Percentage change. Returns 0 if previous is zero.
   */
  public function getPercentageIncrease($current, $previous): float {
    if ($previous == 0) {
      // Avoid division-by-zero. Treat as no growth.
      return 0.0;
    }

    return round((($current - $previous) / $previous) * 100, 2);
  }

  /**
   * Fetches new users count month-wise for past 6 months.
   *
   * @return array
   *   Array of objects with:
   *   - month: "Jan 2025"
   *   - new_users: numeric count
   */
  public function getNewUsersPerMonth(): array {
    $six_months_timestamp = strtotime('-6 months');
    $start_of_month = strtotime(date('Y-m-01', $six_months_timestamp));

    $query = $this->database->select('users_field_data', 'u');
    $query->addExpression("DATE_FORMAT(FROM_UNIXTIME(u.created), '%b %Y')", 'month');
    $query->addExpression("COUNT(u.uid)", 'new_users');

    $query->condition('u.status', 1);
    $query->condition('u.created', $start_of_month, '>=');

    $query->groupBy('month');
    $query->orderBy('month', 'ASC');

    return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

}
