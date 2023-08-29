<?php

namespace Drupal\ggroup\Plugin\Group\Relation;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\group\Entity\GroupType;
use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\group\Plugin\Group\Relation\GroupRelationTypeInterface;
use Drupal\node\Entity\NodeType;

/**
 * Derives subgroup plugin definitions based on group types.
 *
 * @see \Drupal\ggroup\Plugin\Group\Relation\Subgroup;
 */
class SubgroupDeriver extends DeriverBase {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}.
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    assert($base_plugin_definition instanceof GroupRelationTypeInterface);
    $this->derivatives = [];

    foreach (GroupType::loadMultiple() as $name => $group_type) {
      $label = $group_type->label();

      $this->derivatives[$name] = clone $base_plugin_definition;
      $this->derivatives[$name]->set('entity_bundle', $name);
      $this->derivatives[$name]->set('label', $this->t('Subgroup (@type)', ['@type' => $label]));
      $this->derivatives[$name]->set('description', $this->t('Adds %type groups to groups both publicly and privately.', ['%type' => $label]));
    }

    return $this->derivatives;
  }

}
