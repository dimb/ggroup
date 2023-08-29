<?php

namespace Drupal\ggroup_role_mapper\Access;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\ggroup\GroupHierarchyManager;
use Drupal\group\Access\GroupPermissionCalculatorBase;
use Drupal\group\Access\RefinableCalculatedGroupPermissions;
use Drupal\group\Access\CalculatedGroupPermissionsItem;
use Drupal\group\Access\CalculatedGroupPermissionsItemInterface;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupContentType;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\GroupMembership;
use Drupal\group\GroupMembershipLoader;
use Drupal\ggroup_role_mapper\GroupRoleInheritanceInterface;

/**
 * Calculates group permissions for an account.
 */
class InheritGroupPermissionCalculator extends GroupPermissionCalculatorBase {

  /**
   * The group hierarchy manager.
   *
   * @var \Drupal\ggroup\GroupHierarchyManager
   */
  protected $hierarchyManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The group membership loader.
   *
   * @var \Drupal\group\GroupMembershipLoader
   */
  protected $membershipLoader;

  /**
   * The group role inheritance manager.
   *
   * @var \Drupal\ggroup_role_mapper\GroupRoleInheritanceInterface
   */
  protected $groupRoleInheritanceManager;

  /**
   * Static cache for all group memberships per user.
   *
   * A nested array with all group memberships keyed by user ID.
   *
   * @var \Drupal\group\GroupMembership[][]
   */
  protected $userMemberships = [];

  /**
   * Static cache for all inherited group roles by user.
   *
   * A nested array with all inherited roles keyed by user ID and group ID.
   *
   * @var array
   */
  protected $mappedRoles = [];

  /**
   * Static cache for all outsider roles of group type.
   *
   * A nested array with all outsider roles keyed by group type ID and role ID.
   *
   * @var array
   */
  protected $groupTypeOutsiderRoles = [];

  /**
   * Constructs a InheritGroupPermissionCalculator object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\ggroup\GroupHierarchyManager $hierarchy_manager
   *   The group hierarchy manager.
   * @param \Drupal\group\GroupMembershipLoader $membership_loader
   *   The group membership loader.
   * @param \Drupal\ggroup_role_mapper\GroupRoleInheritanceInterface $group_role_inheritance_manager
   *   The group membership loader.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, GroupHierarchyManager $hierarchy_manager, GroupMembershipLoader $membership_loader, GroupRoleInheritanceInterface $group_role_inheritance_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->hierarchyManager = $hierarchy_manager;
    $this->membershipLoader = $membership_loader;
    $this->groupRoleInheritanceManager = $group_role_inheritance_manager;
  }

  /**
   * Getter for mapped roles.
   *
   * @param string $account_id
   *   Account id.
   * @param string|null $group_id
   *   Group id.
   *
   * @return array
   *   Mapped roles, defaults to empty array.
   */
  public function getMappedRoles($account_id, $group_id = NULL) {
    if (!empty($group_id)) {
      return $this->mappedRoles[$account_id][$group_id] ?? [];
    }
    return $this->mappedRoles[$account_id] ?? [];
  }

  /**
   * Checker for mapped roles.
   *
   * @param string $account_id
   *   Account id.
   * @param string|null $group_id
   *   Group id.
   *
   * @return bool
   *   TRUE if there are mapped roles
   *   for given account id (optionally group id).
   */
  public function hasMappedRoles($account_id, $group_id = NULL) {
    return !empty($this->getMappedRoles($account_id, $group_id));
  }

  /**
   * Get all (inherited) group roles a user account inherits for a group.
   *
   * Check if the account is a direct member of any subgroups/supergroups of
   * the group. For each subgroup/supergroup, we check which roles we are
   * allowed to map. The result contains a list of all roles the user has have
   * inherited from 1 or more subgroups or supergroups.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   An account to map only the roles for a specific user.
   *
   * @return \Drupal\group\Entity\GroupRoleInterface[]
   *   An array of group roles inherited for the given group.
   */
  public function calculateMemberPermissions(AccountInterface $account) {
    $calculated_permissions = new RefinableCalculatedGroupPermissions();
    $calculated_permissions->addCacheContexts(['user']);

    $user = $this->entityTypeManager->getStorage('user')->load($account->id());
    $calculated_permissions->addCacheableDependency($user);

    foreach ($this->membershipLoader->loadByUser($account) as $group_membership) {
      $group = $group_membership->getGroup();
      $group_role_array = $this->getInheritedGroupRoleIdsByMembership($group_membership, $account);
       foreach ($group_role_array as $group_id => $group_roles) {
         $permission_sets = [];
         foreach ($group_roles as $group_role) {
           $permission_sets[] = $group_role->getPermissions();
           $calculated_permissions->addCacheableDependency($group_role);
         }
         $permissions = $permission_sets ? array_merge(...$permission_sets) : [];
         $item = new CalculatedGroupPermissionsItem(
           CalculatedGroupPermissionsItemInterface::SCOPE_GROUP,
           (string) $group_id,
           $permissions
         );
         $calculated_permissions->addItem($item);
         $calculated_permissions->addCacheableDependency($group);
      }
    }
    return $calculated_permissions;
  }

  public function calculateAnonymousPermissions() {
    $calculated_permissions = new RefinableCalculatedGroupPermissions();
    $calculated_permissions->addCacheContexts(['user']);

    // We have to select all the groups, because we need the mapping in
    // both directions.
    $groups = $this->entityTypeManager->getStorage('group')->loadMultiple();

    foreach ($groups as $group) {
      $permission_sets = [];

      // Add group content types as a cache dependency.
      $plugins = $group->getGroupType()->getInstalledContentPlugins();
      foreach ($plugins as $plugin) {
        if ($plugin->getEntityTypeId() == 'group') {
          $group_content_types = GroupContentType::loadByContentPluginId($plugin->getPluginId());
          foreach ($group_content_types as $group_content_type) {
            $calculated_permissions->addCacheableDependency($group_content_type);
          }
        }
      }

      $group_roles = $this->getInheritedGroupAnonymousRoleIds($group, $groups);

      foreach ($group_roles as $group_role) {
        $permission_sets[] = $group_role->getPermissions();
        $calculated_permissions->addCacheableDependency($group_role);
      }

      $permissions = $permission_sets ? array_merge(...$permission_sets) : [];

      $item = new CalculatedGroupPermissionsItem(
        CalculatedGroupPermissionsItemInterface::SCOPE_GROUP,
        $group->id(),
        $permissions
      );

      $calculated_permissions->addItem($item);
      $calculated_permissions->addCacheableDependency($group);
    }

    return $calculated_permissions;
  }

  public function calculateOutsiderPermissions(AccountInterface $account) {
    $calculated_permissions = new RefinableCalculatedGroupPermissions();
    $calculated_permissions->addCacheContexts(['user']);

    $user = $this->entityTypeManager->getStorage('user')->load($account->id());
    $calculated_permissions->addCacheableDependency($user);

    // We have to select all the groups, because we need the mapping in
    // both directions.
    $groups = $this->entityTypeManager->getStorage('group')->loadMultiple();

    foreach ($groups as $group) {
      // We check only groups where the user is outsider.
      if ($group->getMember($user)) {
        continue;
      }

      // Add group content types as a cache dependency.
      $plugins = $group->getGroupType()->getInstalledContentPlugins();
      foreach ($plugins as $plugin) {
        if ($plugin->getEntityTypeId() == 'group') {
          $group_content_types = GroupContentType::loadByContentPluginId($plugin->getPluginId());
          foreach ($group_content_types as $group_content_type) {
            $calculated_permissions->addCacheableDependency($group_content_type);
          }
        }
      }

      $permission_sets = [];

      $group_roles = $this->getInheritedGroupOutsiderRoleIds($group, $user);

      foreach ($group_roles as $group_role) {
        $permission_sets[] = $group_role->getPermissions();
        $calculated_permissions->addCacheableDependency($group_role);
      }

      $permissions = $permission_sets ? array_merge(...$permission_sets) : [];

      $item = new CalculatedGroupPermissionsItem(
        CalculatedGroupPermissionsItemInterface::SCOPE_GROUP,
        $group->id(),
        $permissions
      );

      $calculated_permissions->addItem($item);
      $calculated_permissions->addCacheableDependency($group);
    }

    return $calculated_permissions;
  }

  /**
   * {@inheritdoc}
   */
  public function getInheritedGroupRoleIdsByMembership(GroupMembership $group_membership, AccountInterface $account) {
    $account_id = $account->id();
    $group = $group_membership->getGroup();
    $group_id = $group->id();
    $roles = array_keys($group_membership->getRoles());

    if ($this->hasMappedRoles($account_id, $group_id)) {
      return $this->getMappedRoles($account_id, $group_id);
    }

    // Statically cache the memberships of a user since this method could get
    // called a lot.
    if (empty($this->userMemberships[$account_id])) {
      $this->userMemberships[$account_id] = $this->membershipLoader->loadByUser($account);
    }

    $role_map = $this->groupRoleInheritanceManager->getAllInheritedGroupRoleIds($group);

    $mapped_role_ids = [[]];
    foreach ($this->userMemberships[$account_id] as $membership) {
      $membership_gid = $membership->getGroup()->id();

      $subgroup_ids = $this->hierarchyManager->getGroupSupergroupIds($membership_gid) + $this->hierarchyManager->getGroupSubgroupIds($membership_gid);;
      foreach ($subgroup_ids as $subgroup_id) {
        if (!empty($role_map[$subgroup_id][$group_id])) {
          $mapped_role_ids[$subgroup_id] = array_merge(isset($mapped_role_ids[$subgroup_id]) ? $mapped_role_ids[$subgroup_id] : [], array_intersect_key($role_map[$subgroup_id][$group_id], array_flip($roles)));
        }
      }
    }

    foreach ($mapped_role_ids as $group_id => $role_ids) {
      if (!empty(array_unique($role_ids))) {
        $this->mappedRoles[$account_id][$group_id] = array_merge($this->getMappedRoles($account_id, $group_id), $this->entityTypeManager->getStorage('group_role')->loadMultiple(array_unique($role_ids)));
      }
    }

    return $this->getMappedRoles($account_id);
  }

  /**
   * {@inheritdoc}
   */
  public function getInheritedGroupOutsiderRoleIds(GroupInterface $group, AccountInterface $account) {

    $account_id = $account->id();
    $group_id = $group->id();

    if ($this->hasMappedRoles($account_id, $group_id)) {
      return $this->getMappedRoles($account_id, $group_id);
    }

    if (empty($this->userMemberships[$account_id])) {
      $this->userMemberships[$account_id] = $this->membershipLoader->loadByUser($account);
    }

    $role_map = $this->groupRoleInheritanceManager->getAllInheritedGroupRoleIds($group);

    $mapped_role_ids = [[]];
    foreach ($this->userMemberships[$account_id] as $membership) {
      $membership_gid = $membership->getGroupContent()->gid->target_id;
      $role_mapping = [];

      // Get all outsider roles.
      $outsider_roles = $this->getOutsiderGroupRoles($membership->getGroupContent()->getGroup());
      if (!empty($role_map[$membership_gid][$group_id])) {
        $role_mapping = array_intersect_key($role_map[$membership_gid][$group_id], $outsider_roles);
      }
      else if (!empty($role_map[$group_id][$membership_gid])) {
        $role_mapping = array_intersect_key($role_map[$group_id][$membership_gid], $outsider_roles);
      }

      $mapped_role_ids[] = $role_mapping;
    }

    $mapped_role_ids = array_replace_recursive(...$mapped_role_ids);

    $this->mappedRoles[$account_id][$group_id] = $this->entityTypeManager->getStorage('group_role')->loadMultiple(array_unique($mapped_role_ids));

    return $this->getMappedRoles($account_id, $group_id);
  }

  /**
   * Get outsider group type roles.
   *
   * @param Group $group
   *   Group.
   * @return array
   *   Group type roles.
   */
  protected function getOutsiderGroupRoles(Group $group) {
    if (!isset($this->groupTypeOutsiderRoles[$group->getGroupType()->id()])) {
      $storage = $this->entityTypeManager->getStorage('group_role');
      $outsider_roles = $storage->loadSynchronizedByGroupTypes([$group->getGroupType()->id()]);
      $outsider_roles[$group->getGroupType()->getOutsiderRoleId()] = $group->getGroupType()->getOutsiderRole();
      $this->groupTypeOutsiderRoles[$group->getGroupType()->id()] = $outsider_roles;
    }
    return $this->groupTypeOutsiderRoles[$group->getGroupType()->id()];
  }

  /**
   * {@inheritdoc}
   */
  public function getInheritedGroupAnonymousRoleIds(GroupInterface $group, array $groups) {
    // Anonymous user doesn't have id, but we want to cache it.
    $account_id = 0;
    $group_id = $group->id();

    $role_map = $this->groupRoleInheritanceManager->getAllInheritedGroupRoleIds($group);
    $mapped_role_ids = [[]];
    foreach ($groups as $group_item) {
      $group_item_gid = $group_item->id();
      $role_mapping = [];

      $anonymous_role = [$group_item->getGroupType()->getAnonymousRoleId() => $group_item->getGroupType()->getAnonymousRole()];

      if (!empty($role_map[$group_item_gid][$group_id])) {
        $role_mapping = array_intersect_key($role_map[$group_item_gid][$group_id], $anonymous_role);
      }
      else if (!empty($role_map[$group_id][$group_item_gid])) {
        $role_mapping = array_intersect_key($role_map[$group_id][$group_item_gid], $anonymous_role);
      }

      $mapped_role_ids[] = $role_mapping;
    }

    $mapped_role_ids = array_replace_recursive(...$mapped_role_ids);

    $this->mappedRoles[$account_id][$group_id] = $this->entityTypeManager->getStorage('group_role')->loadMultiple(array_unique($mapped_role_ids));

    return $this->getMappedRoles($account_id, $group_id);
  }

}
