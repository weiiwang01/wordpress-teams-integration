<?php

/*
Plugin Name: OpenID teams
Description: OpenID teams implementation for Wordpress
Version: 0.1
Author: Stuart Metcalfe
Author URI: http://launchpad.net/~stuartmetcalfe
*/

add_action('admin_menu', 'openid_teams_admin_panels');
add_filter('openid_auth_request_extensions',
           'openid_teams_add_extenstion', 10, 2);
add_action('openid_finish_auth', 'openid_teams_finish_auth');
add_action('wp_login', 'openid_teams_assign_on_login');
add_action('wp_logout', 'openid_teams_assign_on_logout');
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

function delete_server_from_trusted($id, $trusted_servers = null) {
  if (is_null($trusted_servers)) {
    $trusted_servers = openid_get_server_list();
  }
  unset($trusted_servers[$id]);
  openid_teams_update_trusted_servers($trusted_servers);
  $all_trust_maps = openid_teams_get_trust_list();
  foreach ($all_trust_maps as $map_id => $trust_map) {
    if ($trust_map->server == $id) {
      $all_trust_maps[$map_id]->server = -1;
    }
  }
  openid_teams_update_trust_list($all_trust_maps);
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

function in_trusted_servers($server) {
  foreach (openid_get_server_list() as $trusted) {
    if ($server == $trusted) {
      return true;
    }
  }
  return false;
}

function display_openid_teams_restricted_access_form() {
  openid_teams_teams_process_restricted_access_form();
  $enabled_allowed_team = openid_teams_is_restricted_access_enabled();
  ?>
  <table class="form-table">
    <tbody>
      <tr valign="top">
        <th scope="row"><label for=""><?php echo _e('Restricted team', 'openid-teams') ?></label></th>
        <td>
          <label for="enable_restricted_team">
            <input type="checkbox" name="enable_restricted_team" 
                   id="enable_restricted_team" 
                   <?php echo $enabled_allowed_team ? 'checked="checked"' : '' ?> /> 
            <?php echo _e('Limit access only to members of the team'); ?>
          </label>
          <br />
          <input type="text" name="restricted_team_name" 
                 id="restricted_team_name" size="30" 
                 value="<?php echo openid_teams_get_restricted_team_name(); ?>"
              <?php echo $enabled_allowed_team ? '' : 'disabled="disabled"'; ?>/>
          <p><?php echo _e('Name of team user must be member to be able to access this site.'); ?></p>
        </td>                    
      </tr>
    </tbody>
  </table>
  <script launguage="javascript">
    jQuery('#enable_restricted_team').click(function () {
        var c = jQuery(this); 
        jQuery('#restricted_team_name').attr('disabled', c.attr('checked') ? '' : 'disabled'); 
      });
  </script>
  <?php
}

function openid_teams_is_restricted_access_enabled() {
  return get_option('openid_teams_enable_allowed_team');
}

function openid_teams_get_restricted_team_name() {
  return get_option('openid_teams_allowed_team_name');
}

function openid_teams_teams_process_restricted_access_form() {
  if (isset($_POST['restricted_team_name'])) {
    $team_name = $_POST['restricted_team_name'];
    update_option('openid_teams_allowed_team_name', $team_name);
    update_option('openid_teams_enable_allowed_team', isset($_POST['enable_restricted_team']));
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
      <td><input type="checkbox" name="delete[<?php print $id ?>]" value="1" /></td>
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
  set_include_path(dirname(__FILE__).'/../openid/' . PATH_SEPARATOR .
                   get_include_path());
  require_once 'teams-extension.php';
  restore_include_path();
  $teams = get_teams_for_endpoint($auth_request->endpoint->server_url);

  if (openid_teams_is_restricted_access_enabled()) {
    $team = openid_teams_get_restricted_team_name();
    if (!in_array($team, $teams)) {
      $teams[] = $team;
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
function openid_teams_finish_auth($identity_url) {
  global $openid_teams;
  set_include_path(dirname(__FILE__).'/../openid/' . PATH_SEPARATOR .
                   get_include_path());
  require_once 'teams-extension.php';
  restore_include_path();

  $response = openid_response();
  if ($response->status == Auth_OpenID_SUCCESS) {
    $teams_resp   = new Auth_OpenID_TeamsResponse($response);
    $raw_teams    = $teams_resp->getTeams();
    $endpoint     = $response->endpoint;
    $openid_teams = get_approved_team_mappings($raw_teams, $endpoint->server_url);

    if (openid_teams_is_restricted_access_enabled()) {
      $team = openid_teams_get_restricted_team_name();
      if (!in_array($team, $raw_teams)) {
        $url = get_option('siteurl') . '/wp-login.php?openid_error=' . urlencode('Permission denied.');
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
  global $openid_teams;
  $user = restore_old_roles(new WP_User($username));
  if ($openid_teams) {
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

/**
 * Clear the user's roles assigned by openid teams on logout if possible
 *
 * It isn't guaranteed that users will use the logout button but this will
 * remove the roles from the admin interface if they do.
 */
function openid_teams_assign_on_logout() {
  restore_old_roles(wp_get_current_user());
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

?>
