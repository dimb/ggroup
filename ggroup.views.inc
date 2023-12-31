<?php

/**
 * @file
 * Views alterations for group_graph.
 */

/**
 * Implements hook_views_data().
 */
function ggroup_views_data() {
  $data = [];

  $data['group_graph'] = [];
  $data['group_graph']['table'] = [];
  $data['group_graph']['table']['group'] = t('Group Graph table');
  $data['group_graph']['table']['provider'] = 'ggroup';

  return $data;
}

/**
 * Implements hook_views_data_alter().
 */
function ggroup_views_data_alter(array &$data) {
  $data['group_relationship_field_data']['group_id_depth'] = [
    'help' => t('Group id with depth implemented by subgroups'),
    'argument' => [
      'title' => t('Has parent group ID (with depth)'),
      'id' => 'group_id_depth',
    ],
  ];
}
