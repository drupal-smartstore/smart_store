<?php

namespace Drupal\product_csv_tools\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Drupal\commerce_product\Entity\ProductInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Messenger\MessengerInterface;

/**
 * Controller for product CSV import and export.
 */
class ProductCsvController extends ControllerBase {

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a ProductCsvController object.
   */
  public function __construct(RequestStack $request_stack, $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, MessengerInterface $messenger) {
    $this->requestStack = $request_stack;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('messenger')
    );
  }

  /**
   * Export products with variations.
   */
  public function exportProducts() {
    $base_columns = [
      'product_title',
      'product_type',
      'variation_title',
      'variation_type',
      'sku',
      'price',
      'currency',
      'status',
      'store_id',
    ];

    $attribute_columns = [];
    $attribute_columns_by_bundle = [];
    $rows = [];

    /** @var \Drupal\commerce_product\Entity\ProductInterface[] $products */
    $products = $this->entityTypeManager
      ->getStorage('commerce_product')
      ->loadMultiple();

    foreach ($products as $product) {
      if (!$product instanceof ProductInterface) {
        continue;
      }

      foreach ($product->getVariations() as $variation) {
        $variation_bundle = $variation->bundle();
        if (!isset($attribute_columns_by_bundle[$variation_bundle])) {
          $field_definitions = $this->entityFieldManager->getFieldDefinitions('commerce_product_variation', $variation_bundle);
          $attribute_fields = [];
          foreach ($field_definitions as $field_name => $definition) {
            if (str_starts_with($field_name, 'attribute_')) {
              $attribute_fields[] = $field_name;
              $attribute_columns[$field_name] = $field_name;
            }
          }
          $attribute_columns_by_bundle[$variation_bundle] = $attribute_fields;
        }

        $price = $variation->getPrice();
        $stores = $product->getStores();
        $store_id = $stores ? $stores[0]->id() : '';

        $row = [
          'product_title' => $product->label(),
          'product_type' => $product->bundle(),
          'variation_title' => $variation->label(),
          'variation_type' => $variation->bundle(),
          'sku' => $variation->getSku(),
          'price' => $price ? $price->getNumber() : '',
          'currency' => $price ? $price->getCurrencyCode() : '',
          'status' => $product->isPublished() ? 1 : 0,
          'store_id' => $store_id,
        ];

        foreach ($attribute_columns_by_bundle[$variation_bundle] as $field_name) {
          $item = $variation->get($field_name)->first();
          $row[$field_name] = $item ? (string) $item->target_id : '';
        }

        $rows[] = $row;
      }
    }

    $header = array_merge($base_columns, array_values($attribute_columns));

    $output = fopen('php://temp', 'r+');

    fputcsv($output, $header);

    foreach ($rows as $row) {
      $line = [];
      foreach ($header as $column) {
        $line[] = $row[$column] ?? '';
      }
      fputcsv($output, $line);
    }

    rewind($output);
    $csv = stream_get_contents($output);

    fclose($output);

    $response = new Response($csv);
    $response->headers->set('Content-Type', 'text/csv');
    $response->headers->set('Content-Disposition', 'attachment; filename="products_export.csv"');

    return $response;
  }

}
