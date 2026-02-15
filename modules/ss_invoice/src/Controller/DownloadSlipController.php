<?php

namespace Drupal\ss_invoice\Controller;

use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\ss_invoice\Service\InvoiceService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides controller methods for downloading invoices or delivery slips.
 */
class DownloadSlipController extends ControllerBase {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The invoice service.
   *
   * @var \Drupal\ss_invoice\Service\InvoiceService
   */
  protected $invoiceService;

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * Constructs a new DownloadSlipController object.
   */
  public function __construct(
    AccountProxyInterface $current_user,
    RendererInterface $renderer,
    InvoiceService $invoice_service,
    Request $request,
  ) {
    $this->currentUser = $current_user;
    $this->renderer = $renderer;
    $this->invoiceService = $invoice_service;
    $this->request = $request;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('current_user'),
      $container->get('renderer'),
      $container->get('ss_invoice.invoice_service'),
      $container->get('request_stack')->getCurrentRequest()
    );
  }

  /**
   * Generates and returns the invoice or delivery slip as a downloadable PDF.
   *
   * @param int $order_id
   *   The order ID.
   * @param string $type
   *   The document type ('invoice' or 'delivery_slip').
   */
  public function downloadDocument(int $order_id, string $type): Response {
    // Build template and get filename.
    [$build, $filename] = $this->callInvoiceTemplate($order_id, $type);

    // Render HTML.
    $html = $this->renderer->renderRoot($build);

    // Configure Dompdf.
    $options = new Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isRemoteEnabled', TRUE);
    $options->set('chroot', DRUPAL_ROOT);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $output = $dompdf->output();

    return new Response(
      $output,
      200,
      [
        'Content-Type' => 'application/pdf',
        'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        'Content-Length' => strlen($output),
      ]
    );
  }

  /**
   * Builds the render array for invoice or delivery slip and determines filename.
   *
   * @param int $order_id
   *   The order ID.
   * @param string $type
   *   The document type.
   *
   * @return array
   *   An array containing:
   *     - Render array for the theme.
   *     - Corresponding filename.
   */
  public function callInvoiceTemplate(int $order_id, string $type): array {
    $data = $this->invoiceService->getUserDetails($order_id);

    switch ($type) {
      case 'invoice':
        $template_name = 'invoice_template';
        $filename = 'invoice_' . $order_id . '.pdf';
        break;

      case 'delivery':
        $template_name = 'delivery_slip_template';
        $filename = 'delivery_slip_' . $order_id . '.pdf';
        break;

      default:
        throw new \InvalidArgumentException("Invalid document type: $type");
    }

    $build = [
      '#theme' => $template_name,
      '#data' => $data ?? [],
    ];

    return [$build, $filename];
  }

}
