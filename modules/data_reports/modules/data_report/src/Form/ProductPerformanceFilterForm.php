<?php

declare(strict_types=1);

namespace Drupal\data_report\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a filter form for product performance reports.
 *
 * This form allows filtering based on order state, date ranges,
 * product type, and minimum quantity sold. The form submits by
 * redirecting to the same route with query parameters applied.
 *
 * @package Drupal\data_report\Form
 */
class ProductPerformanceFilterForm extends FormBase {

  /**
   * The request stack service.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new ProductPerformanceFilterForm instance.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   */
  public function __construct(
    RequestStack $requestStack,
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    $this->requestStack = $requestStack;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('request_stack'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'product_performance_filter_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {

    $request = $this->requestStack->getCurrentRequest();

    // Retrieve existing query parameters to prefill defaults.
    $order_state = $request->query->get('order_state', '');
    $start_date = $request->query->get('start_date');
    $end_date = $request->query->get('end_date');

    // Convert timestamps back to date format if needed.
    if (is_numeric($start_date)) {
      $start_date = date('Y-m-d', (int) $start_date);
    }
    if (is_numeric($end_date)) {
      $end_date = date('Y-m-d', (int) $end_date);
    }

    $today = date('Y-m-d');

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

    $form['start_date'] = [
      '#type' => 'date',
      '#title' => $this->t('From date'),
      '#required' => TRUE,
      '#default_value' => $start_date ?: $today,
    ];

    $form['end_date'] = [
      '#type' => 'date',
      '#title' => $this->t('End date'),
      '#required' => TRUE,
      '#default_value' => $end_date ?: $today,
    ];

    // Form actions container.
    $form['actions'] = [
      '#type' => 'actions',
    ];

    // Submit button.
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply Filters'),
      '#button_type' => 'primary',
    ];

    // Reset Filters button that clears query params.
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

    $values = $form_state->getValues();

    // Set query parameters for redirect.
    $query = [
      'start_date' => strtotime($values['start_date']),
      'end_date' => strtotime($values['end_date']),
    ];

    if (!empty($values['order_state'])) {
      $query['order_state'] = $values['order_state'];
    }

    $form_state->setRedirect('<current>', [], ['query' => $query]);
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

}
