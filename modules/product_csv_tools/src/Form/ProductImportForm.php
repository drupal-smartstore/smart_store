<?php

namespace Drupal\product_csv_tools\Form;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\Core\Url;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\FileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Imports products from a CSV upload.
 */
class ProductImportForm extends FormBase implements ContainerInjectionInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * Constructs a ProductImportForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, FileSystemInterface $file_system) {
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('file_system')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'product_csv_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Ensure the upload directory exists.
    $upload_directory = 'public://csv-import/';
    $this->fileSystem->prepareDirectory(
      $upload_directory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
    );

    $form['csv_file'] = [
      '#type' => 'file',
      '#title' => $this->t('Upload CSV file'),
      '#required' => TRUE,
    ];

    $form['export'] = [
      '#type' => 'link',
      '#title' => $this->t('Export Products CSV'),
      '#url' => Url::fromRoute('product_csv_tools.export_products'),
      '#attributes' => ['class' => ['button']],
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import Products'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $file = file_save_upload('csv_file', ['file_validate_extensions' => ['csv']], FALSE, 0);
    if (!$file) {
      $form_state->setErrorByName('csv_file', $this->t('Please upload a valid CSV file.'));
    }
    else {
      $form_state->setValue('csv_file_object', $file);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {

    $file = $form_state->getValue('csv_file_object');

    if (!$file instanceof FileInterface) {
      $this->messenger()->addError($this->t('File upload failed.'));
      return;
    }

    $file->setPermanent();
    $file->save();

    $batch = [
      'title' => $this->t('Importing products...'),
      'operations' => [
      [[static::class, 'processBatch'], [$file->getFileUri()]],
      ],
      'finished' => [static::class, 'batchFinished'],
    ];

    batch_set($batch);
  }

  /**
   *
   */
  public static function processBatch($uri, &$context) {

    $fs = \Drupal::service('file_system');
    $path = $fs->realpath($uri);
    $entity_type_manager = \Drupal::service('entity_type.manager');
    $field_manager = \Drupal::service('entity_field.manager');
    $store_storage = $entity_type_manager->getStorage('commerce_store');
    $variation_storage = $entity_type_manager->getStorage('commerce_product_variation');
    $product_storage = $entity_type_manager->getStorage('commerce_product');

    $limit = 20;

    if (!isset($context['sandbox']['pointer'])) {

      $stores = $store_storage->loadMultiple();
      $default_store = $stores ? reset($stores) : NULL;
      if (!$default_store) {
        throw new \Exception('No default store found.');
      }
      $context['sandbox']['store_id'] = $default_store->id();

      $handle = fopen($path, 'rb');

      $header = fgetcsv($handle);

      if ($header === FALSE) {
        fclose($handle);
        throw new \Exception('CSV header missing.');
      }

      if (isset($header[0]) && str_starts_with($header[0], "\xEF\xBB\xBF")) {
        $header[0] = substr($header[0], 3);
      }

      $context['sandbox']['header'] = $header;
      $context['sandbox']['pointer'] = ftell($handle);
      $context['sandbox']['processed'] = 0;
      $context['sandbox']['attribute_fields'] = [];

      fclose($handle);
    }

    $handle = fopen($path, 'rb');
    fseek($handle, $context['sandbox']['pointer']);

    $header = $context['sandbox']['header'];
    $default_store = $store_storage->load($context['sandbox']['store_id']);
    if (!$default_store) {
      fclose($handle);
      throw new \Exception('Default store could not be loaded.');
    }

    $count = 0;
    $attribute_fields_by_bundle = $context['sandbox']['attribute_fields'] ?? [];

    while (($row = fgetcsv($handle)) !== FALSE && $count < $limit) {

      if (count($row) !== count($header)) {
        continue;
      }

      $data = array_combine($header, $row);

      if (!$data) {
        continue;
      }

      try {

        if (empty($data['sku']) || empty($data['price'])) {
          continue;
        }

        $product_type_id = $data['product_type'] ?? '';
        if ($product_type_id === '') {
          throw new \Exception('Missing product_type.');
        }

        $product_type = $entity_type_manager->getStorage('commerce_product_type')->load($product_type_id);
        if (!$product_type) {
          throw new \Exception(sprintf('Invalid product_type: %s', $product_type_id));
        }

        try {
          $variation_type_id = $product_type->getVariationTypeId();
          $variation_type_ids = [$variation_type_id];
        }
        catch (\Throwable $exception) {
          $variation_type_ids = $product_type->getVariationTypeIds();
          $variation_type_id = $variation_type_ids ? reset($variation_type_ids) : NULL;
        }

        if (!$variation_type_id) {
          throw new \Exception(sprintf('No variation type for product_type: %s', $product_type_id));
        }

        $csv_variation_type = trim((string) ($data['variation_type'] ?? ''));
        if ($csv_variation_type !== '') {
          if (!in_array($csv_variation_type, $variation_type_ids, TRUE)) {
            throw new \Exception(sprintf('Invalid variation_type %s for product_type %s', $csv_variation_type, $product_type_id));
          }
          $variation_type_id = $csv_variation_type;
        }

        if (!isset($attribute_fields_by_bundle[$variation_type_id])) {
          $definitions = $field_manager->getFieldDefinitions('commerce_product_variation', $variation_type_id);
          $attribute_fields = [];
          foreach ($definitions as $field_name => $definition) {
            if (str_starts_with($field_name, 'attribute_')) {
              $attribute_fields[] = $field_name;
            }
          }
          $attribute_fields_by_bundle[$variation_type_id] = $attribute_fields;
        }

        $status = isset($data['status']) ? (int) $data['status'] : 1;
        $currency = $data['currency'] ?? '';
        if ($currency === '') {
          $currency = $default_store->getDefaultCurrencyCode();
        }

        $store_id = trim((string) ($data['store_id'] ?? ''));
        $store = $store_id !== '' ? $store_storage->load($store_id) : NULL;
        if ($store_id !== '' && !$store) {
          throw new \Exception(sprintf('Invalid store_id: %s', $store_id));
        }
        $store = $store ?: $default_store;

        $variation_ids = $variation_storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('sku', $data['sku'])
          ->range(0, 1)
          ->execute();

        if ($variation_ids) {
          $variation = $variation_storage->load(reset($variation_ids));
          if ($variation) {
            if ($variation->bundle() !== $variation_type_id) {
              $context['results']['skipped'] =
                ($context['results']['skipped'] ?? 0) + 1;

              \Drupal::logger('product_csv_import')
                ->warning('SKU @sku belongs to variation type @type, expected @expected. Skipping.', [
                  '@sku' => $data['sku'],
                  '@type' => $variation->bundle(),
                  '@expected' => $variation_type_id,
                ]);

              $count++;
              $context['sandbox']['processed']++;
              continue;
            }

            $variation->setTitle($data['variation_title'] ?: $data['product_title']);
            $variation->setPrice(new Price($data['price'], $currency));
            $variation->set('status', $status);

            foreach ($attribute_fields_by_bundle[$variation_type_id] as $field_name) {
              if (!array_key_exists($field_name, $data) || $data[$field_name] === '') {
                continue;
              }
              if ($variation->hasField($field_name)) {
                $variation->set($field_name, ['target_id' => $data[$field_name]]);
              }
            }

            $variation->save();

            $product = $variation->getProduct();
            if ($product) {
              if ($product->bundle() !== $product_type_id) {
                $context['results']['skipped'] =
                  ($context['results']['skipped'] ?? 0) + 1;

                \Drupal::logger('product_csv_import')
                  ->warning('SKU @sku is attached to product type @type, expected @expected. Skipping.', [
                    '@sku' => $data['sku'],
                    '@type' => $product->bundle(),
                    '@expected' => $product_type_id,
                  ]);

                $count++;
                $context['sandbox']['processed']++;
                continue;
              }

              if (!empty($data['product_title'])) {
                $product->setTitle($data['product_title']);
              }
              $product->set('status', $status);
              $product->set('stores', [$store]);
              $product->save();
            }

            $context['results']['updated'] =
              ($context['results']['updated'] ?? 0) + 1;

            $count++;
            $context['sandbox']['processed']++;
            continue;
          }
        }

        $variation = ProductVariation::create([
          'type' => $variation_type_id,
          'sku' => $data['sku'],
          'title' => $data['variation_title'] ?: $data['product_title'],
          'price' => new Price($data['price'], $currency),
          'status' => $status,
        ]);

        foreach ($attribute_fields_by_bundle[$variation_type_id] as $field_name) {
          if (!array_key_exists($field_name, $data) || $data[$field_name] === '') {
            continue;
          }
          if ($variation->hasField($field_name)) {
            $variation->set($field_name, ['target_id' => $data[$field_name]]);
          }
        }

        $variation->save();

        $product = Product::create([
          'type' => $product_type_id,
          'title' => $data['product_title'],
          'status' => $status,
          'stores' => [$store],
          'variations' => [$variation],
        ]);
        $product->save();

        $context['results']['imported'] =
          ($context['results']['imported'] ?? 0) + 1;

      }
      catch (\Exception $e) {

        $context['results']['skipped'] =
        ($context['results']['skipped'] ?? 0) + 1;

        \Drupal::logger('product_csv_import')
          ->error('Error importing SKU @sku: @msg', [
            '@sku' => $data['sku'] ?? 'unknown',
            '@msg' => $e->getMessage(),
          ]);
      }

      $count++;
      $context['sandbox']['processed']++;
    }

    $context['sandbox']['attribute_fields'] = $attribute_fields_by_bundle;

    $context['sandbox']['pointer'] = ftell($handle);

    if (feof($handle)) {
      $context['finished'] = 1;
    }
    else {
      $context['finished'] = 0;
    }

    fclose($handle);

    $context['message'] = t('Processed @count rows', [
      '@count' => $context['sandbox']['processed'],
    ]);
  }

  /**
   *
   */
  public static function batchFinished($success, $results, $operations) {
    if ($success) {
      $imported = $results['imported'] ?? 0;
      $updated = $results['updated'] ?? 0;
      $skipped = $results['skipped'] ?? 0;

      \Drupal::messenger()->addMessage(t('Imported @count products.', [
        '@count' => $imported,
      ]));

      if ($updated) {
        \Drupal::messenger()->addMessage(t('Updated @count products.', [
          '@count' => $updated,
        ]));
      }

      if ($skipped) {
        \Drupal::messenger()->addWarning(t('Skipped @count rows.', [
          '@count' => $skipped,
        ]));
      }
    }
    else {
      \Drupal::messenger()->addError(t('Batch import failed.'));
    }
  }

}
