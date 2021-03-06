<?php

namespace Drupal\features_ui\Form;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\features\FeaturesManagerInterface;
use Drupal\features\FeaturesAssignerInterface;
use Drupal\features\FeaturesBundleInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures the configuration assignment methods for this site.
 */
class AssignmentConfigureForm extends FormBase {

  /**
   * Bundle select value that should trigger a new bundle to be created.
   */
  const NEW_BUNDLE_SELECT_VALUE = 'new';

  /**
   * The features manager.
   *
   * @var \Drupal\features\FeaturesManagerInterface
   */
  protected $featuresManager;

  /**
   * The package assigner.
   *
   * @var \Drupal\features\FeaturesAssignerInterface
   */
  protected $assigner;

  /**
   * Constructs a AssignmentConfigureForm object.
   *
   * @param \Drupal\features\FeaturesManagerInterface $features_manager
   *   The features manager.
   * @param \Drupal\features\FeaturesAssignerInterface $assigner
   *   The configuration assignment methods manager.
   */
  public function __construct(FeaturesManagerInterface $features_manager, FeaturesAssignerInterface $assigner) {
    $this->featuresManager = $features_manager;
    $this->assigner = $assigner;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('features.manager'),
      $container->get('features_assigner')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'features_assignment_configure_form';
  }

  /**
   * Load the values from the bundle into the user input.
   * Used during Ajax callback since updating #default_values is ignored.
   *
   * @param mixed $bundle_name
   *   The bundle name.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form values.
   */
  protected function loadBundleValues($bundle_name, FormStateInterface &$form_state, $current_bundle, $enabled_methods, $methods_weight) {
    $input = $form_state->getUserInput();
    if ($bundle_name == self::NEW_BUNDLE_SELECT_VALUE) {
      $input['bundle']['name'] = '';
      $input['bundle']['machine_name'] = '';
      $input['bundle']['description'] = '';
      $input['bundle']['is_profile'] = NULL;
      $input['bundle']['profile_name'] = '';
    }
    else {
      $input['bundle']['name'] = $current_bundle->getName();
      $input['bundle']['machine_name'] = $current_bundle->getMachineName();
      $input['bundle']['description'] = $current_bundle->getDescription();
      $input['bundle']['is_profile'] = $current_bundle->isProfile() ? 1 : NULL;
      $input['bundle']['profile_name'] = $current_bundle->isProfile() ? $current_bundle->getProfileName() : '';
    }

    foreach ($methods_weight as $method_id => $weight) {
      $enabled = isset($enabled_methods[$method_id]);
      $input['weight'][$method_id] = $weight;
      $input['enabled'][$method_id] = $enabled ? 1 : NULL;
    }

    $form_state->setUserInput($input);
  }

  /**
   * Detects if an element triggered the form submission via Ajax.
   * TODO: SHOULDN'T NEED THIS!  BUT DRUPAL IS CALLING buildForm AFTER THE
   * BUNDLE AJAX IS SELECTED AND DOESN'T HAVE getTriggeringElement() SET YET.
   */
  protected function elementTriggeredScriptedSubmission(FormStateInterface &$form_state) {
    $input = $form_state->getUserInput();
    if (!empty($input['_triggering_element_name'])) {
      return $input['_triggering_element_name'];
    }
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $bundle_name = NULL) {
    $load_values = FALSE;
    $trigger = $form_state->getTriggeringElement();
    // TODO: See if there is a Drupal Core issue for this.
    // Sometimes the first ajax call on the page causes buildForm to be called
    // twice!  First time form_state->getTriggeringElement is NOT SET, but
    // the form_state['input'] shows the _triggering_element_name.  Then the
    // SECOND time it is called the getTriggeringElement is fine.
    $real_trigger = $this->elementTriggeredScriptedSubmission($form_state);
    if (!isset($trigger) && ($real_trigger == 'bundle[bundle_select]')) {
      $input = $form_state->getUserInput();
      $bundle_name = $input['bundle']['bundle_select'];
      if ($bundle_name != self::NEW_BUNDLE_SELECT_VALUE) {
        $this->assigner->setCurrent($this->assigner->getBundle($bundle_name));
      }
      $load_values = TRUE;
    }
    elseif (isset($trigger['#name']) && $trigger['#name'] == 'bundle[bundle_select]') {
      $bundle_name = $form_state->getValue(['bundle', 'bundle_select']);
      if ($bundle_name != self::NEW_BUNDLE_SELECT_VALUE) {
        $this->assigner->setCurrent($this->assigner->getBundle($bundle_name));
      }
      $load_values = TRUE;
    }
    elseif (isset($trigger['#name']) && $trigger['#name'] == 'removebundle') {
      $current_bundle = $this->assigner->loadBundle($bundle_name);
      $bundle_name = $current_bundle->getMachineName();
      $this->assigner->removeBundle($bundle_name);
      return $this->redirect('features.assignment', ['']);
    }
    if (!isset($current_bundle)) {
      switch ($bundle_name) {
        // If no bundle is selected, use the current one.
        case NULL:
          $current_bundle = $this->assigner->loadBundle();
          $bundle_name = $current_bundle->getMachineName();
          break;

        case self::NEW_BUNDLE_SELECT_VALUE:
          $current_bundle = $this->assigner->loadBundle(FeaturesBundleInterface::DEFAULT_BUNDLE);
          break;

        default:
          $current_bundle = $this->assigner->loadBundle($bundle_name);
          break;
      }
    }

    $enabled_methods = $current_bundle->getEnabledAssignments();
    $methods_weight = $current_bundle->getAssignmentWeights();

    // Add missing data to the methods lists.
    $assignment_info = $this->assigner->getAssignmentMethods();
    foreach ($assignment_info as $method_id => $method) {
      if (!isset($methods_weight[$method_id])) {
        $methods_weight[$method_id] = isset($method['weight']) ? $method['weight'] : 0;
      }
    }
    // Order methods list by weight.
    asort($methods_weight);

    if ($load_values) {
      $this->loadBundleValues($bundle_name, $form_state, $current_bundle, $enabled_methods, $methods_weight);
    }

    $form = [
      '#attached' => [
        'library' => [
          // Provides the copyFieldValue behavior invoked below.
          'system/drupal.system',
          'features_ui/drupal.features_ui.admin',
        ],
      ],
      // '#attributes' => array('class' => 'edit-bundles-wrapper'),
      '#tree' => TRUE,
      '#show_operations' => FALSE,
      'weight' => ['#tree' => TRUE],
      '#prefix' => '<div id="edit-bundles-wrapper">',
      '#suffix' => '</div>',
    ];

    $form['bundle'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Bundle'),
      '#tree' => TRUE,
      '#weight' => -9,
    ];

    if ($bundle_name == self::NEW_BUNDLE_SELECT_VALUE) {
      $default_values = [
        'bundle_select' => self::NEW_BUNDLE_SELECT_VALUE,
        'name' => '',
        'machine_name' => '',
        'description' => '',
        'is_profile' => FALSE,
        'profile_name' => '',
      ];
    }
    else {
      $default_values = [
        'bundle_select' => $current_bundle->getMachineName(),
        'name' => $current_bundle->getName(),
        'machine_name' => $current_bundle->getMachineName(),
        'description' => $current_bundle->getDescription(),
        'is_profile' => $current_bundle->isProfile(),
        'profile_name' => $current_bundle->getProfileName(),
      ];
    }
    $form['bundle']['bundle_select'] = [
      '#title' => $this->t('Bundle'),
      '#title_display' => 'invisible',
      '#type' => 'select',
      '#options' => [self::NEW_BUNDLE_SELECT_VALUE => $this->t('--New--')] + $this->assigner->getBundleOptions(),
      '#default_value' => $default_values['bundle_select'],
      '#ajax' => [
        'callback' => '::updateForm',
        'wrapper' => 'edit-bundles-wrapper',
      ],
    ];

    // Don't show the remove button for the default bundle or when adding a new
    // bundle.
    if ($bundle_name != self::NEW_BUNDLE_SELECT_VALUE && !$current_bundle->isDefault()) {
      $form['bundle']['remove'] = [
        '#type' => 'button',
        '#name' => 'removebundle',
        '#value' => $this->t('Remove bundle'),
      ];
    }

    $form['bundle']['name'] = [
      '#title' => $this->t('Bundle name'),
      '#type' => 'textfield',
      '#description' => $this->t('A unique human-readable name of this bundle.'),
      '#default_value' => $default_values['name'],
      '#required' => TRUE,
      '#disabled' => $bundle_name == FeaturesBundleInterface::DEFAULT_BUNDLE,
    ];

    // Don't allow changing the default bundle machine name.
    if ($bundle_name == FeaturesBundleInterface::DEFAULT_BUNDLE) {
      $form['bundle']['machine_name'] = [
        '#type' => 'value',
        '#value' => $default_values['machine_name'],
      ];
    }
    else {
      $form['bundle']['machine_name'] = [
        '#title' => $this->t('Machine name'),
        '#type' => 'machine_name',
        '#required' => TRUE,
        '#default_value' => $default_values['machine_name'],
        '#description' => $this->t('A unique machine-readable name of this bundle.  Used to prefix exported packages. It must only contain lowercase letters, numbers, and underscores.'),
        '#machine_name' => [
          'source' => ['bundle', 'name'],
          'exists' => [$this, 'bundleExists'],
        ],
      ];
    }

    $form['bundle']['description'] = [
      '#title' => $this->t('Distribution description'),
      '#type' => 'textfield',
      '#default_value' => $default_values['description'],
      '#description' => $this->t('A description of the bundle.'),
      '#size' => 80,
    ];

    $form['bundle']['is_profile'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include install profile'),
      '#default_value' => $default_values['is_profile'],
      '#description' => $this->t('Select this option to have your features packaged into an install profile.'),
      '#attributes' => [
        'data-add-profile' => 'status',
      ],
    ];

    $show_and_require_if_profile_checked = [
      'visible' => [
        ':input[data-add-profile="status"]' => ['checked' => TRUE],
      ],
      'required' => [
        ':input[data-add-profile="status"]' => ['checked' => TRUE],
      ],
    ];

    $form['bundle']['profile_name'] = [
      '#title' => $this->t('Profile name'),
      '#type' => 'textfield',
      '#default_value' => $default_values['profile_name'],
      '#description' => $this->t('The machine name (directory name) of your profile.'),
      '#size' => 30,
      // Show and require only if the profile.add option is selected.
      '#states' => $show_and_require_if_profile_checked,
    ];

    // Attach the copyFieldValue behavior to the profile_name field. In
    // practice this only works if a user tabs through the bundle machine name
    // field or manually edits it.
    $form['#attached']['drupalSettings']['copyFieldValue']['edit-bundle-machine-name'] = ['edit-bundle-profile-name'];

    foreach ($methods_weight as $method_id => $weight) {

      // A packaging method might no longer be available if the defining module
      // has been uninstalled after the last configuration saving.
      if (!isset($assignment_info[$method_id])) {
        continue;
      }

      $enabled = isset($enabled_methods[$method_id]);
      $method = $assignment_info[$method_id];

      $method_name = Html::escape($method['name']);

      $form['weight'][$method_id] = [
        '#type' => 'weight',
        '#title' => $this->t('Weight for @title package assignment method', ['@title' => mb_strtolower($method_name)]),
        '#title_display' => 'invisible',
        '#default_value' => $weight,
        '#attributes' => ['class' => ['assignment-method-weight']],
        '#delta' => 20,
      ];

      $form['title'][$method_id] = ['#markup' => $method_name];

      $form['enabled'][$method_id] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable @title package assignment method', ['@title' => mb_strtolower($method_name)]),
        '#title_display' => 'invisible',
        '#default_value' => $enabled,
      ];

      $form['description'][$method_id] = ['#markup' => $method['description']];

      $config_op = [];
      if (isset($method['config_route_name'])) {
        $config_op['configure'] = [
          'title' => $this->t('Configure'),
          'url' => Url::fromRoute($method['config_route_name'], ['bundle_name' => $current_bundle->getMachineName()]),
        ];
        // If there is at least one operation enabled, show the operation
        // column.
        $form['#show_operations'] = TRUE;
      }
      $form['operation'][$method_id] = [
        '#type' => 'operations',
        '#links' => $config_op,
      ];
    }

    $form['actions'] = ['#type' => 'actions', '#weight' => 9];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#button_type' => 'primary',
      '#value' => $this->t('Save settings'),
    ];

    return $form;
  }

  /**
   * Ajax callback for handling switching the bundle selector.
   */
  public function updateForm($form, FormStateInterface $form_state) {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->getValue(['bundle', 'is_profile']) && empty($form_state->getValue(['bundle', 'profile_name']))) {
      $form_state->setErrorByName('bundle][profile_name', $this->t('To create a profile, please enter a profile name.'));
    }

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $enabled_methods = array_filter($form_state->getValue('enabled'));
    ksort($enabled_methods);
    $method_weights = $form_state->getValue('weight');
    ksort($method_weights);

    $machine_name = $form_state->getValue(['bundle', 'machine_name']);

    // If this is a new bundle, create it.
    if ($form_state->getValue(['bundle', 'bundle_select']) == self::NEW_BUNDLE_SELECT_VALUE) {
      $bundle = $this->assigner->createBundleFromDefault($machine_name);
    }
    // Otherwise, load the current bundle and rename if needed.
    else {
      $bundle = $this->assigner->loadBundle();
      $old_name = $bundle->getMachineName();
      $new_name = $form_state->getValue(['bundle', 'machine_name']);
      if ($old_name != $new_name) {
        $bundle = $this->assigner->renameBundle($old_name, $new_name);
      }
    }

    $bundle->setName($form_state->getValue(['bundle', 'name']));
    $bundle->setDescription($form_state->getValue(['bundle', 'description']));
    $bundle->setEnabledAssignments(array_keys($enabled_methods));
    $bundle->setAssignmentWeights($method_weights);
    $bundle->setIsProfile($form_state->getValue(['bundle', 'is_profile']));
    $bundle->setProfileName($form_state->getValue(['bundle', 'profile_name']));
    $bundle->save();
    $this->assigner->setBundle($bundle);
    $this->assigner->setCurrent($bundle);

    $form_state->setRedirect('features.assignment');
    $this->messenger()->addStatus($this->t('Package assignment configuration saved.'));
  }

  /**
   * Callback for machine_name exists()
   *
   * @param $value
   * @param $element
   * @param $form_state
   *
   * @return bool
   */
  public function bundleExists($value, $element, $form_state) {
    $bundle = $this->assigner->getBundle($value);
    return isset($bundle);
  }

}
