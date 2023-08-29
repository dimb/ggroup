<?php

namespace Drupal\ggroup\Plugin\Group\RelationHandler;

use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupType;
use Drupal\group\Plugin\Group\RelationHandler\OperationProviderInterface;
use Drupal\group\Plugin\Group\RelationHandler\OperationProviderTrait;

/**
 * Provides operations for the subgroup relation plugin.
 */
class SubgroupOperationProvider implements OperationProviderInterface {

  use OperationProviderTrait;

  /**
   * Constructs a new GroupMembershipRequestOperationProvider.
   *
   * @param \Drupal\group\Plugin\Group\RelationHandler\OperationProviderInterface $parent
   *   The default operation provider.
   */
  public function __construct(OperationProviderInterface $parent) {
    $this->parent = $parent;
  }

  /**
   * {@inheritdoc}
   */
  public function getGroupOperations(GroupInterface $group) {
    $operations = $this->parent->getGroupOperations($group);
    $type = $this->groupRelationType->getEntityBundle();

    $url = URL::fromRoute('entity.group_content.subgroup_add_form', [
      'group' => $group->id(),
      'group_type' => $type
    ]);

    if ($url->access($this->currentUser())) {
      $operations["ggroup_create-$type"] = [
        'title' => $this->t('Create @type', ['@type' => $this->getSubgroupType()->label()]),
        'url' => $url,
        'weight' => 35,
      ];
    }

    return $operations;
  }

  /**
   * Retrieves the group type this plugin supports.
   *
   * @return \Drupal\group\Entity\GroupTypeInterface
   *   The group type this plugin supports.
   */
  protected function getSubgroupType() {
    return GroupType::load($this->groupRelationType->getEntityBundle());
  }

}
