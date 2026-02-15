<?php

namespace Drupal\toolbar_always_vertical\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;

/**
 *
 */
class CustomForm extends FormBase {


  /**
   * Array to hold default values.
   */
  protected $defaultValues;

  /**
   * {@inheritdoc}
   */
  public function __construct() {
    // Define default array key values.
    $this->defaultValues = [
      'mode' => 'light',
      'menu_style' => 'menu-click',
      'bg_image' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'custom_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    // Load the configuration.
    $config = $this->config('toolbar_always_vertical.settings');
    // kint($config->get());die;
    $form['mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Theme color mode:'),
      '#options' => [
        'light' => $this->t('Light'),
        'dark' => $this->t('Dark'),
      ],
      '#default_value' => $config->get('mode') ?: $this->defaultValues['mode'],
      '#attributes' => [
        'class' => ['radio-buttons'],
      ],
      '#ajax' => [
        'callback' => '::ajaxCallback',
        'event' => 'change',
        'wrapper' => 'ajax-wrapper',
        'progress' => ['type' => 'throbber'],
      ],
    ];
    $form['menu_style'] = [
      '#type' => 'radios',
      '#title' => $this->t('Vertical & Horizontal menu style'),
      '#options' => [
        'menu-click' => $this->t('Menu click'),
        'icon-hover' => $this->t('Icon hover'),
        'icon-default' => $this->t('Icon default'),
      ],
      '#default_value' => $config->get('menu_style') ?: $this->defaultValues['menu_style'],
      '#attributes' => [
        'class' => ['radio-buttons'],
      ],
      '#ajax' => [
        'callback' => '::ajaxCallback',
        'event' => 'change',
        'wrapper' => 'ajax-wrapper',
        'progress' => ['type' => 'throbber'],
      ],
    ];
    $form['menu_position'] = [
      '#type' => 'radios',
      '#title' => $this->t('Menu position'),
      '#options' => [
        'fixed' => $this->t('Fixed'),
        'scrollable' => $this->t('Scrollable'),
      ],
      '#default_value' => $config->get('menu_position') ?: 'fixed',
      '#attributes' => [
        'class' => ['radio-buttons'],
      ],
      '#ajax' => [
        'callback' => '::ajaxCallback',
        'event' => 'change',
        'wrapper' => 'ajax-wrapper',
        'progress' => ['type' => 'throbber'],
      ],
    ];
    $form['width_style'] = [
      '#type' => 'radios',
      '#title' => $this->t('Layout width style'),
      '#options' => [
        'full' => $this->t('Full width'),
        'boxed' => $this->t('Boxed'),
      ],
      '#default_value' => $config->get('width_style') ?: 'full',
      '#attributes' => [
        'class' => ['radio-buttons'],
      ],
      '#ajax' => [
        'callback' => '::ajaxCallback',
        'event' => 'change',
        'wrapper' => 'ajax-wrapper',
        'progress' => ['type' => 'throbber'],
      ],
    ];
    $mdoulePath = \Drupal::service('extension.path.resolver')->getPath('module', 'toolbar_always_vertical');
    $form['bg_image'] = [
      '#type' => 'radios',
      '#title' => $this->t('Menu with background image'),
      '#options' => [
        'option1' => '<img class="img-1 bg-1" src="/' . $mdoulePath . '/images/img-1.jpg" alt="' . $this->t('Option 1') . '">',
        'option2' => '<img class="img-1 bg-2" src="/' . $mdoulePath . '/images/img-2.jpg" alt="' . $this->t('Option 2') . '">',
        'option3' => '<img class="img-1" src="/' . $mdoulePath . '/images/img-3.jpg" alt="' . $this->t('Option 3') . '">',
        'option4' => '<img class="img-1" src="/' . $mdoulePath . '/images/img-4.jpg" alt="' . $this->t('Option 4') . '">',
        'option5' => '<img class="img-1" src="/' . $mdoulePath . '/images/img-5.jpg" alt="' . $this->t('Option 5') . '">',

      ],
      '#default_value' => $config->get('bg_image') ?: $this->defaultValues['bg_image'],
      '#attributes' => [
        'class' => ['bg-image'],
      ],
      '#ajax' => [
        'callback' => '::ajaxCallback',
        'event' => 'change',
        'wrapper' => 'ajax-wrapper',
        'progress' => ['type' => 'throbber'],
      ],
      '#attached' => [
        'drupalSettings' => [
          'toolbar_always_vertical' => [
            'ss_theme_setting' => ['settings' => $config->get(), 'default' => $this->defaultValues],
          ],
        ],
      ],
    ];
    $form['#prefix'] = '<div id="ajax-wrapper">';
    $form['#suffix'] = '</div>';

    $form['actions']['clear_all'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reset to Default'),
      '#attributes' => [
        'class' => ['tf-button', 'cursor-pointer', 'w-full', 'ss-setting-reset'],
      ],
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => '::ajaxResetDefault',
        'event' => 'click',
        'wrapper' => 'ajax-wrapper',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Processing...'),
        ],
      ],
    ];

    return $form;
  }

  /**
   * AJAX callback function.
   */
  public function ajaxResetDefault(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#ajax-wrapper', $form));
    $this->configFactory->getEditable('toolbar_always_vertical.settings')
      ->set('mode', $this->defaultValues['mode'])
      ->set('width_style', $this->defaultValues['width_style'])
      ->set('menu_style', $this->defaultValues['menu_style'])
      ->set('menu_position', $this->defaultValues['menu_position'])
      ->set('bg_image', $this->defaultValues['bg_image'])
      ->save();
    return $response;
  }

  /**
   * AJAX callback function.
   */
  public function ajaxCallback(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#ajax-wrapper', $form));
    $this->configFactory->getEditable('toolbar_always_vertical.settings')
      ->set('mode', $form_state->getValue('mode'))
      ->set('width_style', $form_state->getValue('width_style'))
      ->set('menu_style', $form_state->getValue('menu_style'))
      ->set('menu_position', $form_state->getValue('menu_position'))
      ->set('bg_image', $form_state->getValue('bg_image'))
      ->save();
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
  }

}
