<?php

namespace Drupal\data_report\Controller;

use Drupal\Core\Controller\ControllerBase;


use Drupal\data_report\Service\ReportService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides the Customer summary page controller.
 *
 * This controller handles the request for the Customer summary,
 * retrieves query parameters for filtering, and renders the
 * corresponding report form and data.
 */
class ProductPerformanceReportController extends ControllerBase {

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
   * Constructs a new CustomerSummaryController.
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
   * Builds the product performance report page.
   *
   * @return array
   *   A render array representing the report page.
   */
  public function build(): array {
    // Fetch report data from your service.
    $report_data = $this->reportService->getProductPerformance();
    // dd($report_data);
    // Load the filter form using dependency injection.
    $form = $this->formBuilder()->getForm('Drupal\data_report\Form\ProductPerformanceFilterForm');
    // Build the render array.
    $build = [];

    // Add form on top.
    $build['filter_form'] = $form;

    // Add report table (if form submitted).
    $build['report_table'] = [
      '#theme' => 'table',
      '#header' => $this->getReportHeader(),
      '#rows' => $report_data,
      '#empty' => $this->t('No report data available.'),
    ];

    // Add pager.
    $build['pager'] = [
      '#type' => 'pager',
    ];

    return $build;
  }

  /**
   * Returns the header for the product performance report table.
   *
   * @return array
   *   An associative array representing the table header.
   */
  protected function getReportHeader(): array {
    return [
      'product_id'      => $this->t('Product ID'),
      'product_title'   => $this->t('Product Title'),
      'variation_id'    => $this->t('Variation ID'),
      'variation_title' => $this->t('Variation Title'),
      'sku'             => $this->t('SKU'),
      'qty_sold'        => $this->t('Qty Sold'),
      'unit_price'      => $this->t('Unit Price'),
      'revenue'         => $this->t('Revenue'),
      'stock_level'     => $this->t('Stock Level'),
    ];
  }

}
