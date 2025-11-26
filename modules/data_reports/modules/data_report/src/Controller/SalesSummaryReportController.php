<?php

namespace Drupal\data_report\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\data_report\Service\ReportService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides the Sales Summary Report page controller.
 *
 * This controller handles the request for the sales summary report,
 * retrieves query parameters for filtering, and renders the
 * corresponding report form and data.
 */
class SalesSummaryReportController extends ControllerBase {

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The report service.
   *
   * @var \Drupal\data_report\Service\ReportService
   */
  protected $reportService;

  /**
   * Constructs a new SalesSummaryReportController.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\data_report\Service\ReportService $report_service
   *   The report service.
   */
  public function __construct(RequestStack $request_stack, ReportService $report_service) {
    $this->requestStack = $request_stack;
    $this->reportService = $report_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack'),
      $container->get('data_report.report_service')
    );
  }

  /**
   * Display the sales summary report.
   *
   * @return array
   *   A render array.
   */
  public function build(): array {

    // Fetch report data from your service.
    $report_data = $this->reportService->searchReportData();

    // Load the filter form using dependency injection.
    $form = $this->formBuilder()->getForm('Drupal\data_report\Form\ReportFilterForm');

    // Build the render array.
    $build = [];

    // Add form on top.
    $build['filter_form'] = $form;

    // Add report table (if form submitted).
    $build['report_table'] = [
      '#theme' => 'table',
      '#header' => ['Date', 'Orders', 'Revenue', 'AOV', 'Net Revenue'],
      '#rows' => $report_data,
      '#empty' => $this->t('No report data available.'),
    ];

    // Add pager.
    $build['pager'] = [
      '#type' => 'pager',
    ];

    return $build;

  }

}
