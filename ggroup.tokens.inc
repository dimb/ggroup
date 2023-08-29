<?php

/**
 * @file
 * Builds placeholder replacement tokens for group group-related data.
 */

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\group\Entity\GroupRelationship;

/**
 * Implements hook_token_info_alter().
 */
function ggroup_token_info_alter(&$info) {
  $info['tokens']['group']['url']['type'] = 'url';
}

/**
 * Implements hook_token_info().
 */
function ggroup_token_info() {
  $tokens = [];

  $tokens['group'] = [
    'name' => t('Group'),
    'description' => t('The parent group.'),
    'type' => 'group',
  ];

  if (\Drupal::moduleHandler()->moduleExists('token')) {
    $tokens['groups'] = [
      'name' => t('Groups'),
      'description' => t('An array of all the group parent groups.'),
      'type' => 'array',
    ];
  }

  return [
    'tokens' => ['group' => $tokens],
  ];
}

/**
 * Implements hook_tokens().
 */
function ggroup_tokens($type, $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata) {
  if ($type != 'group' || empty($data['group']) || empty($data['group']->id())) {
    return [];
  }

  $token_service = \Drupal::token();
  $replacements = [];

  $group_relationship_array = GroupRelationship::loadByEntity($data['group']);
  if (empty($group_relationship_array)) {
    return [];
  }

  $groups = [];
  /** @var \Drupal\group\Entity\GroupRelationshipInterface $group_relationship */
  foreach ($group_relationship_array as $group_relationship) {
    $group = $group_relationship->getGroup();
    $groups[$group->id()] = $group->label();
    $bubbleable_metadata->addCacheableDependency($group);
  };

  if (isset($tokens['groups'])) {
    $replacements[$tokens['groups']] = token_render_array($groups, $options);
  }

  // [group:groups:*] chained tokens.
  if ($parents_tokens = $token_service->findWithPrefix($tokens, 'groups')) {
    $replacements += $token_service->generate('array', $parents_tokens, ['array' => $groups], $options, $bubbleable_metadata);
  }

  /** @var \Drupal\group\Entity\GroupRelationshipInterface $group_relationship */
  $group_relationship = array_pop($group_relationship_array);
  $group = $group_relationship->getGroup();
  if (isset($tokens['group'])) {
    $replacements[$tokens['group']] = $group->label();
  }

  $langcode = $data['group']->language()->getId();
  if ($group->hasTranslation($langcode)) {
    $group = $group->getTranslation($langcode);
  }

  if ($group_tokens = $token_service->findWithPrefix($tokens, 'group')) {
    $replacements += $token_service->generate('group', $group_tokens, ['group' => $group], $options, $bubbleable_metadata);
  }

  return $replacements;
}
