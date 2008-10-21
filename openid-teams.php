<?php

/*
Plugin Name: OpenID teams
Description: OpenID teams implementation for Wordpress
Version: 0.1
Author: Stuart Metcalfe
Author URI: http://launchpad.net/~stuartmetcalfe
*/

add_action('admin_menu', 'openid_teams_admin_panels');
add_filter('openid_auth_request_extensions', 'openid_teams_add_extenstion', 10, 2);
add_action('openid_finish_auth', 'openid_teams_finish_auth');

/**
 *
 */
function openid_teams_admin_panels() {
	// trusted servers page
	$hookname = add_options_page(__('OpenID teams', 'openid-teams'), __('OpenID Teams', 'openid-teams'), 8, 'openid-teams', 'openid_teams_page' );
}

/**
 *
 */
function openid_teams_add_extenstion($extensions, $auth_request) {
  set_include_path(dirname(__FILE__).'/../openid/' . PATH_SEPARATOR . get_include_path());
  require_once 'teams-extension.php';
  restore_include_path();
  $teams = array('canonical');
  $extensions[] = new Auth_OpenID_TeamsRequest($teams);
	return $extensions;
}

/**
 * On a successful openid response, get the teams data and assign it to local roles
 * 
 * @param string $identity_url
 * @todo Assign local roles
 */
function openid_teams_finish_auth($identity_url) {
  set_include_path(dirname(__FILE__).'/../openid/' . PATH_SEPARATOR . get_include_path());
  require_once 'teams-extension.php';
  restore_include_path();
  
  $response = openid_response();
  if ($response->status == Auth_OpenID_SUCCESS) {
    $teams_resp = new Auth_OpenID_TeamsResponse($response);
    $teams = $teams_resp->getTeams();
  }
}

?>