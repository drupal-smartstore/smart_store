<?php

namespace Drupal\ss_invoice\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Database\Database;

/**
 * Provides a 'Total Users' ApexChart Block.
 *
 * @Block(
 *   id = "total_users_chart_block",
 *   admin_label = @Translation("Total Users Created Chart")
 * )
 */
class TotalUsersBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {

    $connection = Database::getConnection();

    /**
     *
     * 1️⃣ Get last 8 months user creation counts
     * ----------------------------------------- */
    $query = $connection->select('users_field_data', 'u');
    $query->addExpression("DATE_FORMAT(FROM_UNIXTIME(created), '%Y-%m')", 'month');
    $query->addExpression('COUNT(uid)', 'total_users');
    $query->condition('u.uid', 0, '<>');
    $query->groupBy('month');
    $query->orderBy('month', 'DESC');
    $query->range(0, 8);

    $result = array_reverse($query->execute()->fetchAll());

    $labels = [];
    $values = [];

    foreach ($result as $row) {
      $labels[] = $row->month;
      $values[] = (int) $row->total_users;
    }

    /** -----------------------------------------
     * 2️⃣ Total users overall
     * ----------------------------------------- */
    $total_users = $connection->select('users_field_data', 'u')
      ->condition('u.uid', 0, '<>')
      ->countQuery()
      ->execute()
      ->fetchField();

    /** -----------------------------------------
     * 3️⃣ Calculate percentage increase
     * ----------------------------------------- */
    if (count($values) >= 2) {
      $prev = $values[count($values) - 2];
      $current = end($values);

      if ($prev > 0) {
        $percentage = round((($current - $prev) / $prev) * 100, 2);
      }
      else {
        $percentage = 100;
      }
    }
    else {
      $percentage = 0;
    }

    /** -----------------------------------------
     * 4️⃣ Final Drupal Settings (Correct, Not Overwritten)
     * ----------------------------------------- */
    $settings['ss_invoice']['totalUsers'] = [
      'labels'             => $labels,
      'values'             => $values,
      'thisMonthUsers'     => end($values),
    // ⭐ All time total users
      'totalUsers'         => (int) $total_users,
      'percentageIncrease' => $percentage,
    ];

    return [
      '#theme' => 'total_users_chart',
      '#attached' => [
        'library' => ['ss_invoice/total_users_chart'],
        'drupalSettings' => $settings,
      ],
    ];
  }

}
