<?php

namespace Drupal\rules\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\rules\Context\ContextConfig;
// TODO Question: should we do this change?
use Drupal\rules\Context\ContextDefinitionInterface;
use Drupal\rules\Context\DataProcessorManagerTrait;
use Drupal\typed_data\Widget\FormWidgetManagerTrait;

/**
 * Provides form logic for handling contexts when configuring an expression.
 */
trait ContextFormTrait {

  use DataProcessorManagerTrait;
  use FormWidgetManagerTrait;

  /**
   * Provides the form part for a context parameter.
   */
  public function buildContextForm(array $form, FormStateInterface $form_state, $context_name, ContextDefinitionInterface $context_definition, array $configuration) {
    $form['context'][$context_name] = [
      '#type' => 'fieldset',
      '#title' => $context_definition->getLabel(),
    ];
    $form['context'][$context_name]['description'] = [
      '#markup' => $context_definition->getDescription(),
    ];

    // If the form has been submitted already take the mode from the submitted
    // values, otherwise default to existing configuration. And if that does not
    // exist default to the "input" mode.
    $mode = $form_state->get('context_' . $context_name);
    if (!$mode) {
      if (isset($configuration['context_mapping']) && isset($configuration['context_mapping'][$context_name])) {
        $mode = 'selector';
      }
      else {
        $mode = 'input';
      }
      $form_state->set('context_' . $context_name, $mode);
    }

    $title = $mode == 'selector' ? $this->t('Data selector') : $this->t('Value');

    if (isset($configuration['context_values'][$context_name])) {
      $default_value = $configuration['context_values'][$context_name];
    }
    elseif (isset($configuration['context_mapping']) && isset($configuration['context_mapping'][$context_name])) {
      $default_value = $configuration['context_mapping'][$context_name];
    }
    else {
      $default_value = $context_definition->getDefaultValue();
    }

    $form['context'][$context_name]['setting'] = [
      '#type' => 'textfield',
      '#title' => $title,
      '#required' => $context_definition->isRequired(),
      '#default_value' => $default_value,
    ];

    // Use suitable widgets for data entry of values.
    if ($context_name == 'value') {
      $dataManager = $context_definition->getTypedDataManager();
      $dataDefinition = $context_definition->getDataDefinition();
      $typed_data = $dataManager->create($dataDefinition);

      if (isset($configuration['context_mapping']) && $property_path = $configuration['context_mapping']['data']) {
        $sub_paths = explode('.', $property_path);
        $dataType = \Drupal::entityManager()->getStorage('field_storage_config')->load($sub_paths[0] . '.' . $sub_paths[1])->getType();

        if ($widget_id = $context_definition->getWidgetId($dataType)) {
          $widget = $this->getFormWidgetManager()->createInstance($widget_id);
          $sub_form = [];
          $sub_form_state = SubformState::createForSubform($sub_form, $form, $form_state);

          $form['context'][$context_name]['setting'] = $widget->form($typed_data, $sub_form_state);
        }
      }
    }

    $element = &$form['context'][$context_name]['setting'];

    if ($mode == 'selector') {
      $element['#description'] = $this->t("The data selector helps you drill down into the data available to Rules. <em>To make entity fields appear in the data selector, you may have to use the condition 'entity has field' (or 'content is of type').</em> More useful tips about data selection is available in <a href=':url'>the online documentation</a>.", [
        ':url' => 'https://www.drupal.org/node/1300042',
      ]);

      $url = $this->getRulesUiHandler()->getUrlFromRoute('autocomplete', []);
      $element['#attributes']['class'][] = 'rules-autocomplete';
      $element['#attributes']['data-autocomplete-path'] = $url->toString();
      $element['#attached']['library'][] = 'rules/rules.autocomplete';
    }
    elseif ($context_definition->isMultiple()) {
      $element['#type'] = 'textarea';
      // @todo get a description for possible values that can be filled in.
      $element['#description'] = $this->t('Enter one value per line for this multi-valued context.');

      // Glue the list of values together as one item per line in the text area.
      if (is_array($default_value)) {
        $element['#default_value'] = implode("\n", $default_value);
      }
    }

    $value = $mode == 'selector' ? $this->t('Switch to the direct input mode') : $this->t('Switch to data selection');
    $form['context'][$context_name]['switch_button'] = [
      '#type' => 'submit',
      '#name' => 'context_' . $context_name,
      '#attributes' => ['class' => ['rules-switch-button']],
      '#parameter' => $context_name,
      '#value' => $value,
      '#submit' => [static::class . '::switchContextMode'],
      // Do not validate!
      '#limit_validation_errors' => [],
    ];
    return $form;
  }

  /**
   * Creates a context config object from the submitted form values.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state containing the submitted values.
   * @param \Drupal\Core\Plugin\Context\ContextDefinitionInterface[] $context_definitions
   *   The context definitions of the plugin.
   *
   * @return \Drupal\rules\Context\ContextConfig
   *   The context config object populated with context mappings/values.
   */
  protected function getContextConfigFromFormValues(FormStateInterface $form_state, array $context_definitions) {
    $context_config = ContextConfig::create();
    foreach ($form_state->getValue('context') as $context_name => $value) {
      if ($form_state->get("context_$context_name") == 'selector') {
        $context_config->map($context_name, $value['setting']);
      }
      else {
        // Each line of the textarea is one value for multiple contexts.
        if ($context_definitions[$context_name]->isMultiple()) {
          $values = explode("\n", $value['setting']);
          $context_config->setValue($context_name, $values);
        }
        else {
          $context_config->setValue($context_name, $value['setting']);
        }
        // For now, always add in the token context processor - if it's present.
        // @todo: Improve this in https://www.drupal.org/node/2804035.
        if ($this->getDataProcessorManager()->getDefinition('rules_tokens')) {
          $context_config->process($context_name, 'rules_tokens');
        }
      }
    }

    return $context_config;
  }

  /**
   * Submit callback: switch a context to data selecor or direct input mode.
   */
  public static function switchContextMode(array &$form, FormStateInterface $form_state) {
    $element_name = $form_state->getTriggeringElement()['#name'];
    $mode = $form_state->get($element_name);
    $switched_mode = $mode == 'selector' ? 'input' : 'selector';
    $form_state->set($element_name, $switched_mode);

    $form_state->setRebuild();
  }

}
