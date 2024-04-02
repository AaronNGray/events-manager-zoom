<?php
/*
Plugin Name: Events Manager Zoom
Version: 1.1
Plugin URI: http://wp-events-plugin.com
Description: Adds Zoom integration for Events Manager
Author: Events Manager
Author URI: http://wp-events-plugin.com
*/

/*
Copyright (c) 2020, Pixelite SL

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
define('EM_ZOOM_VERSION', '1.1');
define('EM_ZOOM_DIR_URI', trailingslashit(plugins_url('',__FILE__))); //an absolute path to this directory


class EM_Zoom {
	
	public static function init(){
		if( EM_VERSION < 5.975 ){
			add_action('admin_notices', function(){
				$message = esc_html__('Events Manager Zoom integration requires the Events Manager Version 5.9.8 or later to work.', 'events-manager-zoom');
				$dev_url = admin_url('edit.php?post_type=event&page=events-manager-options#general+admin-tools');
				echo '<div class="notice notice-warning">';
				echo "<p>$message</p>";
				//provisional link until we release an update, not worth making translatable as will be removed soon
				echo "<p>Version 5.9.7.5 will also work, which is available as a beta version, upgrade now via <a href='$dev_url'>Events > Settings > Admin Tools</a> and click the 'Check Dev Versions' button to trigger an update check for the latest beta.</p>";
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
}
add_action('events_manager_loaded','EM_Zoom::init');