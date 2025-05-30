<?php
/*
 *  Wordpress Teams plugin
 *  Copyright (C) 2009-2010 Canonical Ltd.
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */


/*
Plugin Name: OpenID teams
Plugin URI: http://launchpad.net/wordpress-teams-integration
Description: OpenID teams implementation for Wordpress
Version: 1.0
Author: Canonical ISD
Author URI: http://launchpad.net/~canonical-isd-hackers
*/

add_action('admin_menu', 'openid_teams_admin_panels');
add_filter('openid_auth_request_extensions',
           'openid_teams_add_extenstion', 10, 2);
add_action('openid_finish_auth', 'openid_teams_finish_auth', 9, 2);
add_action('wp_login', 'openid_teams_assign_on_login');
/**
 * Add the teams admin page to the main admin menu
 */
function openid_teams_admin_panels() {
  // trusted servers page
  $hookname = add_options_page(__('OpenID teams', 'openid-teams'),
                               __('OpenID Teams', 'openid-teams'), 8,
                                'openid-teams', 'openid_teams_page' );
}

/**
 * Add a trusted role->server map to the list
 *
 * @param string $team The team from the remote server
 * @param string $role The local role to map to the team
 * @param string $server The server url to match against the openid provider's endpoint
 */
function openid_add_trust_map($team, $role, $server) {
  $list = openid_teams_get_trust_list();
  $new_index = (sizeof($list) > 0) ? max(array_keys($list)) + 1 : 1;
  $new_item = null;
  $new_item->id = $new_index;
  $new_item->team = $team;
  $new_item->role = $role;
  $new_item->server = $server;
  $list[$new_index] = $new_item;
  openid_teams_update_trust_list($list);
}

/**
 * Save an amended array of trusted role->server maps
 *
 * See openid_teams_get_trust_list() for format
 *
 * @param array $list
 */
function openid_teams_update_trust_list($list) {
  if (is_array($list)) {
    update_option('openid_teams_trust_list', $list);
  }
}

/**
 * Get the list of trusted role->server maps
 *
 * Format is:
 * array(
 *   [1] => stdObject(
 *     -> id       - The index of the server (same as array index)
 *     -> team     - The remote team
 *     -> role     - The local role name
 *     -> server   - The server url to match against the openid provider's endpoint
 *   ),
 *   [2] => stdObject(
 *     ... etc ...
 * )
 *
 * @return array
 */
function openid_teams_get_trust_list() {
  $list = get_option('openid_teams_trust_list');
  if ($list === false) {
    $list = array();
    openid_teams_update_trust_list($list);
  }
  # trust_map can be an array if the option was updated using the wp-cli and the option was passed as a json
  # In that case, convert to a stdObject.
  foreach ($list as $map_id => $trust_map) {
    if (is_array($trust_map)) {
      $list[$map_id] = (object) $trust_map;
    }
  }
  return $list;
}

/**
 *
 */
function openid_teams_page() {
  ?>
  <div class="wrap">
    <form method="post">
      <h2><?php _e('OpenID Teams', 'openid-teams') ?></h2>
      <p>
        <a href="?page=openid-teams&amp;form=roles"><?php _e('Roles', 'openid-teams') ?></a> |
        <a href="?page=openid-teams&amp;form=servers"><?php _e('Servers', 'openid-teams') ?></a> |
        <a href="?page=openid-teams&amp;form=restricted"><?php _e('Restrict access', 'openid-teams') ?></a>
      </p>
  <?php

  $form = (isset($_REQUEST['form'])) ? $_REQUEST['form'] : '';
  switch ($form) {
  case 'restricted':
    display_openid_teams_restricted_access_form();
    break;
  case 'servers':
    display_openid_teams_servers_form();
    break;
  case 'roles':
  default:
    $form = 'roles';
    display_openid_teams_roles_form();
  }

  ?>
      <?php wp_nonce_field('openid-teams_update'); ?>
      <p class="submit">
        <input type="submit" name="teams_submit" value="<?php _e('Save changes') ?>" />
      </p>
    </form>
  </div>
  <?php
}

/**
 * Pass message to openid error display, it will be displayed on top of login form
 *
 * @param string $error
 */
function openid_teams_add_error($error) {
        @session_start();
        $_SESSION['openid_error'] = $error;;
        session_commit();
}

/**
 *
 */
function openid_teams_process_servers_form() {
  if (isset($_POST['teams_submit'])) {
    $trusted_servers = openid_get_server_list();
    $deletions = filter_input(INPUT_POST, 'delete');
    if (isset($_POST['delete']) && is_array($_POST['delete']) && !empty($_POST['delete'])) {
      foreach (array_keys($_POST['delete']) as $id) {
        delete_server_from_trusted($id, $trusted_servers);
      }
      $trusted_servers = openid_get_server_list();
    }

    $new_server = filter_input(INPUT_POST, 'new_server');
    if ($new_server && !in_trusted_servers($new_server)) {
      $all_keys = array_keys($trusted_servers);
      $all_keys[] = 0;
      $next_id = max($all_keys)+1;
      $trusted_servers[$next_id] = $new_server;
      openid_teams_update_trusted_servers($trusted_servers);
    }
  }
}

/**
 * Check if given server is bound to a role
 *
 * @param int $id Internal ID of the server to be queried
 *
 * @return boolean
 */
function is_server_bound($id) {
  $all_trust_maps = openid_teams_get_trust_list();
  $role_found = false;
  foreach ($all_trust_maps as $map_id => $trust_map) {
    if ($trust_map->server == $id) {
      $role_found = true;
      break;
    }
  }
  return $role_found;
}

/**
 * Delete given server from the list of trusted servers
 *
 * @param int $id
 * @param array $trusted_servers
 */
function delete_server_from_trusted($id, $trusted_servers = null) {
  if (is_null($trusted_servers)) {
    $trusted_servers = openid_get_server_list();
  }
  if (is_server_bound($id)) {
    print '<div id="message" class="error"><p>'.__("You cannot remove a server that has roles associated with it.").'</p></div>';
  } else {
    $all_trust_maps = openid_teams_get_trust_list();
    unset($trusted_servers[$id]);
    openid_teams_update_trusted_servers($trusted_servers);
    openid_teams_update_trust_list($all_trust_maps);
  }
}

/**
 * Get the list of trusted servers
 *
 * Format:
 * array(
 *   $id => $server,
 * )
 *
 * @return array An empty array to prevent breakage
 */
function openid_get_server_list() {
  $all_servers = get_option('openid_teams_trusted_servers');
  if ($all_servers === false) {
    $all_servers = array();
    openid_teams_update_trusted_servers($all_servers);
  }
  return $all_servers;
}

/**
 * Save an amended array of trusted servers
 *
 * @param array $all_servers
 */
function openid_teams_update_trusted_servers($all_servers) {
  if (is_array($all_servers)) {
    update_option('openid_teams_trusted_servers', $all_servers);
  }
}

/**
 * Check if given server is on trusted server list
 *
 * @param string $server
 *
 * @return bool
 */
function in_trusted_servers($server) {
  foreach (openid_get_server_list() as $trusted) {
    if ($server == $trusted) {
      return true;
    }
  }
  return false;
}

/**
 * Process and display form for setting up restricted access to blog
 */
function display_openid_teams_restricted_access_form() {
  openid_teams_teams_process_restricted_access_form();
  $enabled_allowed_team = openid_teams_is_restricted_access_enabled();
  ?>
  <table class="form-table">
    <tbody>
      <tr valign="top">
        <th scope="row"><label for=""><?php echo _e('Restricted team', 'openid-teams') ?></label></th>
        <td>
          <label for="enable_restricted_teams">
            <input type="checkbox" name="enable_restricted_teams"
                   id="enable_restricted_teams"
                   <?php echo $enabled_allowed_team ? 'checked="checked"' : '' ?> />
            <?php _e('Limit access to members of known teams'); ?>
          </label>
          <br />
          <small>
            Currently known teams:
            <?php echo implode(', ', get_all_local_teams()); ?>
          </small>
          <br />
          <p><?php _e('Comma-separated list of additional teams to allow access'); ?></p>
          <input type="text" name="restricted_teams"
                 id="restricted_teams" size="30"
                 value="<?php echo implode(', ', openid_teams_get_restricted_teams()); ?>" />
        </td>
      </tr>
    </tbody>
  </table>
  <script launguage="javascript">
    <?php if (!$enabled_allowed_team) { ?>
      jQuery('#restricted_teams').attr('disabled', 'disabled');
    <?php } ?>
    jQuery('#enable_restricted_teams').click(function () {
        var c = jQuery(this);
        jQuery('#restricted_teams').attr('disabled', c.attr('checked') ? '' : 'disabled');
      });
  </script>
  <?php
}

/**
 * Check if option for restricting access based on openid teams is turned on
 *
 * @return boolean
 */
function openid_teams_is_restricted_access_enabled() {
  return get_option('openid_teams_enable_allowed_team');
}

/**
 * Retrieve list of teams which are eligible to access this blog
 *
 * @return array
 */
function openid_teams_get_restricted_teams() {
  $result = array();
  foreach (explode(',', get_option('openid_teams_allowed_teams')) as $team) {
    $result[] = trim($team);
  }
  return $result;
}

/**
 * Process form for changing restriction setting
 */
function openid_teams_teams_process_restricted_access_form() {
  if (isset($_GET['form']) && $_GET['form'] == 'restricted' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['restricted_teams'])) {
      $teams = $_POST['restricted_teams'];
      update_option('openid_teams_allowed_teams', $teams);
    }
    update_option('openid_teams_enable_allowed_team', isset($_POST['enable_restricted_teams']));
  }
}

/**
 *
 */
function display_openid_teams_servers_form() {
  openid_teams_process_servers_form();
  $trusted_servers = openid_get_server_list();
  ?>
  <table width="100%">
    <tr>
      <th>Server</th>
      <th>Delete</th>
    </tr>
  <?php
  if (sizeof($trusted_servers) > 0) {
    foreach ($trusted_servers as $id => $server) {
    ?>
    <tr>
      <td><?php print htmlentities($server); ?></td>
      <td style="text-align:center;"><?php if (!is_server_bound($id)): ?><input type="checkbox" name="delete[<?php print $id ?>]" value="1" /><?php else: print __("(associated with a role)"); endif; ?></td>
    </tr>
    <?php
    }
  } else {
    print '<tr><td colspan="2" style="padding:1em; text-align:center;">No servers have been '.
          'defined</td></tr>';
  }
  ?>
    <tr>
      <td colspan="2">
        New server: <input type="text" name="new_server" value="" />
      </td>
    </tr>
  </table>
  <?php
}

/**
 *
 */
function openid_teams_process_roles_form() {
  if (isset($_POST['teams_submit'])) {
    check_admin_referer('openid-teams_update');
    // Update the existing team->server maps first
    $list = openid_teams_get_trust_list();
    $max = (int) $_POST['item_count'];
    for ($i = 0; $i < $max; $i ++) {
      $id    = (isset($_POST['tid_'.$i])) ? $_POST['tid_'.$i] : false;
      $team  = (isset($_POST['team_'.$i])) ? $_POST['team_'.$i] : '';
      $role  = (isset($_POST['role_'.$i])) ? $_POST['role_'.$i] : '';
      $trust = (isset($_POST['trust_'.$i])) ? $_POST['trust_'.$i] : '';
      if ($id && isset($list[$id])) {
        if (isset($_POST['delete_'.$i])) {
          unset($list[$id]);
        } elseif (!empty($team) && !empty($role) && is_numeric($trust)) {
          $list[$id]->team = $team;
          $list[$id]->role = $role;
          $list[$id]->server = $trust;
        }
      } elseif ($i+1 == $max) { // New items should always be last
        openid_teams_update_trust_list($list);
        if (!empty($team) && !empty($role) && is_numeric($trust)) {
          openid_add_trust_map($team, $role, $trust);
        }
      }
    }
  }
}

function display_openid_teams_roles_form() {
  openid_teams_process_roles_form();
  $all_trusted = openid_teams_get_trust_list();
  $servers = openid_get_server_list();
  $servers[0] = __('Any server', 'openid-teams');
  sort($servers);
  $roles = new WP_Roles();
  $i = 0;
  ?>
  <table width="100%">
    <tr>
      <th style="text-align:left;"><?php _e('Team', 'openid-teams') ?></th>
      <th style="text-align:left;"><?php _e('Role', 'openid-teams') ?></th>
      <th style="text-align:left;"><?php _e('Trust', 'openid-teams') ?></th>
      <th style="text-align:left;"><?php _e('Delete', 'openid-teams') ?></th>
    </tr>
    <?php foreach($all_trusted as $trusted) { ?>
    <tr>
      <td>
        <input type="hidden" name="tid_<?php echo $i ?>" value="<?php echo $trusted->id ?>"  />
        <input type="text" maxlength="128" name="team_<?php echo $i ?>"  size="20" value="<?php echo htmlentities($trusted->team) ?>" />
      </td>
      <td>
        <select name="role_<?php echo $i ?>">
          <?php foreach ($roles->get_names() as $key => $val) {
            list($val, ) = explode('|', $val, 2);
            $selected = ($trusted->role == $key) ? ' selected="selected"' : '';
            printf('<option value="%s"%s>%s</option>', $key, $selected, $val);
          } ?>
        </select>
      </td>
      <td>
        <select name="trust_<?php echo $i ?>">
          <?php foreach ($servers as $id => $server) {
            $selected = ($trusted->server == $id) ? ' selected="selected"' : '';
            printf('<option value="%d"%s>%s</option>', $id, $selected, $server);
          } ?>
        </select>
      </td>
      <td>
        <input type="checkbox" name="delete_<?php echo $i ?>" value="1" />
      </td>
    </tr>
    <?php $i++; } ?>
    <tr>
      <td>
        <input type="text" maxlength="128" name="team_<?php echo $i ?>"  size="20" value="" />
      </td>
      <td>
        <select name="role_<?php echo $i ?>">
          <?php foreach ($roles->get_names() as $key => $val) {
            list($val, ) = explode('|', $val, 2);
            $selected = '';
            printf('<option value="%s"%s>%s</option>', $key, $selected, $val);
          } ?>
        </select>
      </td>
      <td>
        <select name="trust_<?php echo $i ?>">
          <?php foreach ($servers as $id => $server) {
            printf('<option value="%d">%s</option>', $id, $server);
          } ?>
        </select>
      </td>
      <td><br /></td>
    </tr>
  </table>
  <input type="hidden" name="item_count" value="<?php echo $i+1 ?>"  />
  <?php
}

/**
 * Add the teams request to the openid request
 *
 * @param array $extensions The existing extensions to add to
 * @param object $auth_request The openid request object
 * @return array The amended extensions array to pass on
 */
function openid_teams_add_extenstion($extensions, $auth_request) {
  $old_include_path = get_include_path();
  set_include_path(dirname(__FILE__).'/../openid/' . PATH_SEPARATOR .
                   $old_include_path);
  require_once 'teams-extension.php';
  set_include_path($old_include_path);

  $teams = get_teams_for_endpoint($auth_request->endpoint->server_url);

  if (openid_teams_is_restricted_access_enabled()) {
    $restricted_teams = openid_teams_get_restricted_teams();
    foreach ($restricted_teams as $team) {
      if (!in_array($team, $teams)) {
        $teams[] = $team;
      }
    }
  }

  $extensions[] = new Auth_OpenID_TeamsRequest($teams);
  return $extensions;
}

/**
 * Get a list of all teams the site should ask about for a give endpoint
 *
 * @param string $endpoint The URL of the OpenID endpoint
 * @return array - 1-dimensional array of team names (strings)
 */
function get_teams_for_endpoint($endpoint) {
  $all_teams = get_all_local_teams();
  $relevant_team_ids = get_approved_team_mappings($all_teams, $endpoint);
  $relevant_teams = array();
  $all_teams_raw = openid_teams_get_trust_list();
  foreach ($all_teams_raw as $team) {
    if (in_array($team->id, $relevant_team_ids)) {
      $relevant_teams[] = $team->team;
    }
  }
  return array_unique($relevant_teams);
}

/**
 * Get an array of all teams this site is interested in
 *
 * @return array - 1-dimensional array of team names (strings)
 */
function get_all_local_teams() {
  $all_teams_raw = openid_teams_get_trust_list();
  $all_teams = array();
  foreach ($all_teams_raw as $team) {
    $all_teams[] = $team->team;
  }
  return array_unique($all_teams);
}

/**
 * On a successful openid response, get the teams data and generate a list of
 * approved team mappings
 *
 * @param string $identity_url
 */
function openid_teams_finish_auth($identity_url, $action) {
  $old_include_path = get_include_path();
  set_include_path(dirname(__FILE__).'/../openid/' . PATH_SEPARATOR .
                   $old_include_path);
  require_once 'teams-extension.php';
  set_include_path($old_include_path);

  $response = openid_response();
  if ($response->status == Auth_OpenID_SUCCESS) {
    $teams_resp   = new Auth_OpenID_TeamsResponse($response);
    $raw_teams    = $teams_resp->getTeams();
    $endpoint     = $response->endpoint;
    $openid_teams = get_approved_team_mappings($raw_teams, $endpoint->server_url);
    $_SESSION['openid_teams'] = $openid_teams;
    $_SESSION['openid_identity_url'] = $identity_url;

    # If restricted teams is enabled, check the list against allowed teams
    if (openid_teams_is_restricted_access_enabled()) {
      $teams = openid_teams_get_restricted_teams();
      $teams = array_merge($teams, get_all_local_teams());
      $intersection = array_intersect($teams, $raw_teams);
      if (count($intersection) == 0) {
        $url = get_option('siteurl') . '/wp-login.php';
        openid_teams_add_error(__('Permission denied.', 'openid-teams'));
        wp_safe_redirect($url);
        exit;
      }
    }
  }
}

/**
 * Once the user has been created, assign the actual mapped team roles
 *
 * @param string $username
 * @param string $password (Default '')
 */
function openid_teams_assign_on_login($username, $password='') {
  session_start();
  $identity_url = $_SESSION['openid_identity_url'];
  if (is_numeric($identity_url)) {
    $user_id = $identity_url;
  } else {
    $user_id = get_user_by_openid($identity_url);
  }
  $openid_teams = $_SESSION['openid_teams'];
  if ($user_id) {
    $user = new WP_User($user_id);
    $user = restore_old_roles($user);
      if ($user && $openid_teams) {
        $existing_roles = array_keys($user->caps);
        $openid_assigned_roles = array();
        $all_teams = openid_teams_get_trust_list();
        foreach ($openid_teams as $id) {
          $role = $all_teams[$id]->role;
          if (!in_array($role, $existing_roles) && !isset($user->caps[$role])) {
            $user->add_role($role);
            $openid_assigned_roles[] = $role;
          }
        }
        update_usermeta($user->ID, 'openid_assigned_roles', $openid_assigned_roles);
      }
  }
}

/**
 * Remove roles from the user which were assigned on last login by openid teams
 *
 * @param object $user
 * @return object The amended $user object
 */
function restore_old_roles($user) {
  $old_roles = get_usermeta($user->ID, 'openid_assigned_roles');
  if ($old_roles) {
    foreach ($old_roles as $role) {
      $user->remove_cap($role);
    }
  }
  update_usermeta($user->ID, 'openid_assigned_roles', null);
  return $user;
}

/**
 * Given a list of teams and a server, returns the map ids which are approved
 *
 * @param array|string $teams A comma separated string or an array of teams
 * @param string $server The server making the request
 * @return array An array of approved ids from the openid_teams_roles table
 */
function get_approved_team_mappings($teams, $server) {
  if (!is_array($teams)) {
    $teams = explode(',', $teams);
  }
  $approved = array();
  foreach ($teams as $team) {
    $map_ids = get_team_role_ids($team, $server);
    foreach ($map_ids as $map_id) {
      $approved[] = $map_id;
    }
  }
  return $approved;
}

/**
 * Given a team and a server, returns the team/role map ids
 *
 * @param string $team
 * @param string $server
 * @return array an array of integers (map ids)
 */
function get_team_role_ids($team, $server) {
  static $mapped_roles;
  $map_ids = array();
  if (!is_array($mapped_roles)) {
    $mapped_roles = array();
    foreach (openid_teams_get_trust_list() as $map) {
      $mapped_roles[$map->team][] = $map;
    }
  }
  if (isset($mapped_roles[$team])) {
    $all_trusted_servers = openid_get_server_list();
    foreach ($mapped_roles[$team] as $map) {
      if (array_key_exists($map->server, $all_trusted_servers)) {
        $server_url = $all_trusted_servers[$map->server];
      }
      if ($map->server == 0 || true === fnmatch($server_url, $server)) {
        $map_ids[] = $map->id;
      }
    }
  }
  return $map_ids;
}

