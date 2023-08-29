<?php

namespace Drupal\ggroup\Plugin\Group\Relation;

use Drupal\group\Plugin\Group\Relation\GroupRelationBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a group relation for subgroups.
 *
 * @GroupRelationType(
 *   id = "subgroup",
 *   label = @Translation("Subgroup"),
 *   description = @Translation("Adds groups to groups."),
 *   entity_type_id = "group",
 *   pretty_path_key = "group",
 *   deriver = "Drupal\ggroup\Plugin\Group\Relation\SubgroupDeriver",
 * )
 */
class Subgroup extends GroupRelationBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $config = parent::defaultConfiguration();
    $config['entity_cardinality'] = 1;
    return $config;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    // Disable the entity cardinality field as the functionality of this module
    // relies on a cardinality of 1. We don't just hide it, though, to keep a UI
    // that's consistent with other content enabler plugins.
    $info = $this->t("This field has been disabled by the plugin to guarantee the functionality that's expected of it.");
    $form['entity_cardinality']['#disabled'] = TRUE;
    $form['entity_cardinality']['#description'] .= '<br /><em>' . $info . '</em>';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    $dependencies = parent::calculateDependencies();
    $dependencies['config'] = ["group.type.{$this->getRelationType()->getEntityBundle()}"];
    return $dependencies;
  }

}
