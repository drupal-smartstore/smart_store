<?php

namespace Drupal\smart_store\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Process\Process;

/**
 * Provides the storefront theme selection form during installation.
 *
 * Shown after profile install, before Configure site. Only the chosen theme
 * is installed via Composer; others are not pulled.
 *
 * @internal
 */
class StorefrontThemeInstallForm extends FormBase {

  /**
   * Registry of available Smartstore storefront themes (id => package, machine_name, label).
   *
   * @var array<string, array{package: string, machine_name: string, label: string, version?: string}>
   */
  protected const THEME_REGISTRY = [
    'kart' => [
      'package' => 'drupal/kart:^1.0',
      'machine_name' => 'kart',
      'label' => 'Kart',
    ],
    'vitoria' => [
      'package' => 'smartstore/vitoria:^1.0',
      'machine_name' => 'vitoria',
      'label' => 'Vitoria',
    ],
    'estore' => [
      'package' => 'drupal/estore:^2.2',
      'machine_name' => 'estore',
      'label' => 'eStore',
    ],
  ];

  /**
   * The theme installer.
   *
   * @var \Drupal\Core\Extension\ThemeInstallerInterface
   */
  protected ThemeInstallerInterface $themeInstaller;

  /**
   * The theme handler.
   *
   * @var \Drupal\Core\Extension\ThemeHandlerInterface
   */
  protected ThemeHandlerInterface $themeHandler;

  /**
   * Constructs the form.
   */
  public function __construct(
    ThemeInstallerInterface $theme_installer,
    ThemeHandlerInterface $theme_handler,
  ) {
    $this->themeInstaller = $theme_installer;
    $this->themeHandler = $theme_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('theme_installer'),
      $container->get('theme_handler'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'install_smartstore_storefront_theme';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#title'] = $this->t('Choose your storefront theme');

    $options = ['_skip' => $this->t('Skip for now (choose later from Appearance)')];
    foreach (self::THEME_REGISTRY as $id => $info) {
      $options[$id] = $this->t('@label', ['@label' => $info['label']]);
    }

    $form['theme'] = [
      '#type' => 'radios',
      '#title' => $this->t('Storefront theme'),
      '#description' => $this->t('Only the selected theme will be downloaded. You can change it later from Appearance.'),
      '#options' => $options,
      '#default_value' => '_skip',
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save and continue'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $theme_id = $form_state->getValue('theme');
    if ($theme_id === '_skip') {
      return;
    }

    if (!isset(self::THEME_REGISTRY[$theme_id])) {
      $this->messenger()->addError($this->t('Invalid theme selected.'));
      return;
    }

    $info = self::THEME_REGISTRY[$theme_id];
    $machine_name = $info['machine_name'];

    // Check if already installed (e.g. from a previous run or config).
    $this->themeHandler->refreshInfo();
    $list = $this->themeHandler->listInfo();
    if (isset($list[$machine_name])) {
      $this->setDefaultTheme($machine_name);
      return;
    }

    $project_root = $this->getProjectRoot();
    if ($project_root === NULL) {
      $this->messenger()->addError($this->t('Could not detect project root. Install the theme later from Appearance or run: composer require @package', [
        '@package' => explode(':', $info['package'])[0],
      ]));
      return;
    }

    if (!$this->runComposerRequire($project_root, $info['package'])) {
      $this->messenger()->addError($this->t('Theme could not be installed via Composer. You can install it later from Appearance or run: composer require @package', [
        '@package' => explode(':', $info['package'])[0],
      ]));
      return;
    }

    // Rescan so Drupal sees the new theme.
    $this->themeHandler->refreshInfo();
    $extension_list = \Drupal::service('extension.list.theme');
    $extension_list->reset();

    try {
      $this->themeInstaller->install([$machine_name], TRUE);
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Theme was downloaded but could not be enabled: @message', [
        '@message' => $e->getMessage(),
      ]));
      return;
    }

    $this->setDefaultTheme($machine_name);
    $this->messenger()->addStatus($this->t('@theme is now your default storefront theme.', [
      '@theme' => $info['label'],
    ]));
  }

  /**
   * Sets the given theme as the default and clears caches.
   *
   * @param string $machine_name
   *   The theme machine name.
   */
  protected function setDefaultTheme(string $machine_name): void {
    \Drupal::configFactory()
      ->getEditable('system.theme')
      ->set('default', $machine_name)
      ->save();
    drupal_flush_all_caches();
  }

  /**
   * Detects the Composer project root (parent of DRUPAL_ROOT).
   *
   * @return string|null
   *   The project root path, or NULL if not found or not a Smartstore project.
   */
  protected function getProjectRoot(): ?string {
    $root = \Drupal::root();
    if ($root === NULL || $root === '') {
      return NULL;
    }
    $project_root = dirname($root);
    $composer_json = $project_root . '/composer.json';
    if (!is_file($composer_json)) {
      return NULL;
    }
    $data = json_decode(file_get_contents($composer_json), TRUE);
    if (!is_array($data) || empty($data['require']['smartstore/smart_store'])) {
      return NULL;
    }
    return $project_root;
  }

  /**
   * Runs composer require for the given package at project root.
   *
   * @param string $project_root
   *   The Composer project root path.
   * @param string $package
   *   The package specification (e.g. "drupal/kart:^1.0").
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  protected function runComposerRequire(string $project_root, string $package): bool {
    if (!class_exists(Process::class)) {
      return FALSE;
    }

    $process = new Process(
      ['composer', 'require', $package, '--no-interaction', '--no-progress'],
      $project_root,
      NULL,
      NULL,
      300
    );

    try {
      $process->run();
      return $process->isSuccessful();
    }
    catch (\Throwable $e) {
      return FALSE;
    }
  }

}
