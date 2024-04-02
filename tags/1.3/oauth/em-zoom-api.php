<?php
namespace EM_OAuth;

class Zoom_API extends OAuth_API {

	public static $option_name = 'zoom';
	public static $service_name = 'Zoom';
	public static $service_url = 'https://marketplace.zoom.us/user/build';

	/*
	* @param int $user_id The User ID in WordPress
	* @param int $api_user_id The ID of the account in Google (i.e. the email)
	* @return EMIO_Meetup_API_Client|WP_Error
	*/
	public static function get_client( $user_id = 0, $api_user_id = 0 ) {
		$client = parent::get_client($user_id, $api_user_id); /* @var Zoom_API_Client $client */
		return $client;
	}
}
require_once('em-zoom-api-client.php');