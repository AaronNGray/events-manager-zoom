<?php
/*
Plugin Name: Events Manager - Zoom Integration
Version: 1.5
Plugin URI: http://wp-events-plugin.com
Description: Adds Zoom integration for Events Manager
Author: Pixelite
Author URI: https://pixelite.com
*/

/*
Copyright (c) 2022, Pixelite SL

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/
use EM_OAuth\Zoom_API;
define('EM_ZOOM_VERSION', '1.5');
define('EM_ZOOM_DIR_URI', trailingslashit(plugins_url('',__FILE__))); //an absolute path to this directory

class EM_Zoom {
	
	public static function init(){
		$zoom_min_version = 5.983;
		if( EM_VERSION < $zoom_min_version ){
			add_action('admin_notices', function(){
				$zoom_min_version_text = '5.9.9';
				$zoom_dev_version_text = '5.9.8.3';
				$message = esc_html__('Events Manager Zoom integration requires Events Manager %s or later to work, and has been disabled to prevent crashing your site.', 'events-manager-zoom');
				$dev_url = admin_url('edit.php?post_type=event&page=events-manager-options#general+admin-tools');
				echo '<div class="notice notice-warning">';
				echo "<p>". sprintf($message, $zoom_min_version_text). "</p>";
				//provisional link until we release an update, not worth making translatable as will be removed soon
				if( $zoom_dev_version_text != $zoom_min_version_text ){
					$dev_message = esc_html__("Version %s will also work, which is available as a dev (beta) version, upgrade now via the %s and click the 'Check Dev Versions' button to trigger an update check for the latest beta.", 'events-manager-zoom');
					$dev_message = sprintf($dev_message, $zoom_dev_version_text, "<a href='$dev_url'>".esc_html__('Events Manager Admin Tools', 'events-manager-zoom').'</a>');
					echo "<p>$dev_message</p>";
				}
				echo '</div>';
			});
			return;
		}
		// oauth stuff - this could be loaded proactively in the future
		EM_Loader::oauth();
		require_once('oauth/em-zoom-api.php');
		if( is_admin() ) require_once('oauth/em-zoom-admin-settings.php');
		// location types
		include('event-locations/em-event-location-zoom-room.php');
		include('event-locations/em-event-location-zoom-meeting.php');
		include('event-locations/em-event-location-zoom-webinar.php');
		// add callback action here to avoid loading APIs unecessarily in the future
		add_action('wp_ajax_em_oauth_zoom', 'EM_Zoom::callback');
		if( is_admin() && !empty($_GET['page']) && $_GET['page']=='events-manager-help' ){
			include('em-zoom-docs.php');
		}
	}
	
	/**
	 * Handles callbacks such as OAuth authorizations and disconnects
	 */
	public static function callback(){
		if( !empty($_REQUEST['callback']) ){
			if( $_REQUEST['callback'] == 'authorize' ) Zoom_API::oauth_authorize();
			if( $_REQUEST['callback'] == 'disconnect' ) Zoom_API::oauth_disconnect();
		}
	}
	
	public static function get_directory_url(){
		return trailingslashit(plugins_url('',__FILE__));
	}
	
	/**
	 * Checks to see if a dev version is available (when requested via EM settings)
	 * @param $transient
	 * @return mixed
	 */
	public static function dev_update_check( $transient ) {
		// Check if the transient contains the 'checked' information
		if( empty( $transient->checked ) )
			return $transient;
		
		//only bother if we're checking for dev versions
		if( get_option('em_check_dev_version') || get_option('dbem_pro_dev_updates') ){
			//check WP repo for trunk version
			$request = wp_remote_get('https://plugins.svn.wordpress.org/events-manager-zoom/trunk/events-manager-zoom.php');
			
			if( !is_wp_error($request) ){
				preg_match('/Version: ([0-9a-z\.]+)/', $request['body'], $matches);
				
				$slug = plugin_basename( __FILE__ );
				if( !empty($matches[1]) ){
					//we have a version number!
					if( !empty($transient->checked[$slug]) && version_compare($transient->checked[$slug], $matches[1]) < 0) {
						$response = new stdClass();
						$response->slug = $slug;
						$response->new_version = $matches[1] ;
						$response->url = 'http://wordpress.org/extend/plugins/events-manager-zoom/';
						$response->package = 'http://downloads.wordpress.org/plugin/events-manager-zoom.zip';
						$transient->response[$slug] = $response;
					}
				}
			}
		}
		
		return $transient;
	}
	
}
add_action('events_manager_loaded','EM_Zoom::init');
// dev updates checker
add_filter('pre_set_site_transient_update_plugins', 'EM_Zoom::dev_update_check');

// Add this plugin to EM's dev updates check (EM 5.9.9.2 and later)
add_filter('em_org_dev_versions', function( $plugin_slugs ){
	$plugin_slugs['events-manager-zoom'] = array(
		'slug' => plugin_basename( __FILE__ ),
		'version' => EM_ZOOM_VERSION,
	);
	return $plugin_slugs;
});