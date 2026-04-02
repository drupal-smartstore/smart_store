<?php

declare(strict_types=1);

namespace Drupal\standard_reports\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a form to filter data reports by date range and grouping.
 *
 * Users can select a start date, end date, and optionally a grouping
 * (day/week/month) depending on the current route. The form submits
 * by redirecting to the same route with updated query parameters.
 */
class ReportsFilterForm extends FormBase {

  /**
   * The request stack service.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The current route match service.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Constructs a new ReportFilterForm instance.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The route match service.
   */
  public function __construct(RequestStack $requestStack, RouteMatchInterface $routeMatch) {
    $this->requestStack = $requestStack;
    $this->routeMatch = $routeMatch;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('request_stack'),
      $container->get('current_route_match')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'report_filter_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $current_route = $this->routeMatch->getRouteName();
    $request = $this->requestStack->getCurrentRequest();

    // Retrieve existing query parameters to pre-fill defaults.
    $order_state = $request->query->get('order_state', '');
    $start_date = $request->query->get('start_date');
    $end_date = $request->query->get('end_date');
    $group_by = $request->query->get('group_by', 'day');

    // Convert timestamps back to date format if needed.
    if (is_numeric($start_date)) {
      $start_date = date('Y-m-d', (int) $start_date);
    }
    if (is_numeric($end_date)) {
      $end_date = date('Y-m-d', (int) $end_date);
    }

    $today = date('Y-m-d');

    if ($current_route === 'reports.product_performance') {
      $form['order_state'] = [
        '#type' => 'select',
        '#title' => $this->t('Order State'),
        '#options' => [
          '' => $this->t('- All -'),
          'completed' => $this->t('Completed'),
          'pending' => $this->t('Pending'),
          'canceled' => $this->t('Canceled'),
        ],
        '#default_value' => $order_state,
      ];
    }
    $form['start_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Start date'),
      '#required' => TRUE,
      '#default_value' => $start_date ?: $today,
    ];

    $form['end_date'] = [
      '#type' => 'date',
      '#title' => $this->t('End date'),
      '#required' => TRUE,
      '#default_value' => $end_date ?: $today,
    ];

    // Only show grouping selector for the report that supports grouping.
    if ($current_route === 'reports.sales_summary') {
      $form['group_by'] = [
        '#type' => 'select',
        '#title' => $this->t('Group by'),
        '#options' => [
          'day' => $this->t('Day'),
          'week' => $this->t('Week'),
          'month' => $this->t('Month'),
        ],
        '#default_value' => $group_by,
      ];
    }

    // Submit button.
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Generate report'),
      '#button_type' => 'primary',
    ];

    $form['actions']['reset'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reset Filters'),
      '#submit' => ['::resetFormSubmit'],
      '#limit_validation_errors' => [],
      '#button_type' => 'secondary',
    ];

    return $form;
  }

  /**
   * Custom submit handler for reset button.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function resetFormSubmit(array &$form, FormStateInterface $form_state): void {
    $form_state->setRedirect('<current>');
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $start_date = $form_state->getValue('start_date');
    $end_date = $form_state->getValue('end_date');

    if (!$start_date || !$end_date) {
      $form_state->setErrorByName('end_date', $this->t('Both start and end dates are required.'));
      return;
    }

    $start_timestamp = strtotime($start_date);
    $end_timestamp = strtotime($end_date);

    // Validate chronological order.
    if ($end_timestamp < $start_timestamp) {
      $form_state->setErrorByName('end_date', $this->t('The end date must be after the start date.'));
    }

    // Validate maximum range (6 months = 15552000 seconds).
    if (($end_timestamp - $start_timestamp) > 15552000) {
      $form_state->setErrorByName('end_date', $this->t('The report range cannot exceed 6 months.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $current_route = $this->routeMatch->getRouteName();
    $values = $form_state->getValues();

    // Convert dates to timestamps for URL query parameters.
    $query = [
      'start_date' => strtotime($values['start_date']),
      'end_date' => strtotime($values['end_date']),
    ];

    // Include grouping parameter only if applicable.
    if ($current_route === 'reports.sales_summary' && !empty($values['group_by'])) {
      $query['group_by'] = $values['group_by'];
    }

    // Include grouping parameter only if applicable.
    if ($current_route === 'reports.product_performance' && !empty($values['order_state'])) {
      $query['order_state'] = $values['order_state'];
    }

    // Redirect to the same route with updated query parameters.
    $form_state->setRedirect(
      '<current>',
      [],
      ['query' => $query]
    );
  }

}
