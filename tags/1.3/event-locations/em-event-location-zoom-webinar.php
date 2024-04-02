<?php
namespace EM_Event_Locations;
use EM_OAuth\Zoom_API, EM_OAuth\Zoom_API_Client;
use EM_Exception, EM_Gateways, EM_Attendees_Form, EM_Booking_Form;

/**
 * Adds a URL event location type by extending EM_Event_Location and registering itself with EM_Event_Locations
 *
 * @property string id                  The unique ID for this meeting.
 * @property string join_url            The url for participants to join the meeting
 * @property string registration_url    The url for participants to register for the meeting
 * @property string password            Password for joining the meeting
 * @property string last_hash           The laast md5 hash of values for meeting creation, used in comparison for event updates.
 * @property string start_url           The URL a host can use to join the meeting. Generated ad-hoc and should only be displayed to event admins.
 * @property array settings             The settings array sent to create/modify a meeting via the API 'settings' variable.
 */
class Zoom_Webinar extends Zoom_Meeting {
	
	public static $type = 'zoom_webinar';
	/**
	 * @var int Specific meeting type defined by Zoom API for creating things like webinars or meetings
	 */
	public static $zoom_api_type = 5;
	public static $zoom_api_base = 'webinars';
	public static $zoom_admin_url_base = 'webinar';
	public static $zoom_name_singular = 'Webinar';
	public static $zoom_name_plural = 'Webinars';
	
	public static function init(){
		parent::init();
		static::$zoom_name_singular = esc_html__('Webinar', 'events-manager-zoom');
		static::$zoom_name_plural = esc_html__('Webinars', 'events-manager-zoom');
	}
	
	public function get_meeting_request_settings(){
		$meeting = parent::get_meeting_request_settings();
		return apply_filters('em_event_location_zoom_webinar_settings', $meeting, $this);
	}
	
	/**
	 * @param null $post deprecated, left to prevent warning due to removed $post arg from parent in 5.9.7.8
	 * @return bool
	 */
	public function get_post( $post = null ){
		$return = parent::get_post();
		if( !empty($_POST['event_location_zoom_meeting_settings']['survey_url']) ){
			$this->data['settings']['survey_url'] = esc_url_raw($_POST['event_location_zoom_meeting_settings']['survey_url']);
		}
		return $return;
	}
	
	public function validate(){
		$result = parent::validate();
		if( !empty($this->settings['post_webinar_survey']) && empty($this->settings['survey_url']) ){
			$error = __('The Zoom Webinar "Survey URL" field requires a valid URL when Post Webinar Surveys are enabled.', 'events-manager-zoom');
			$this->event->add_error($error);
			$result = false;
		}
		return $result;
	}
	
	public static function get_label( $label_type = 'singular' ){
		switch( $label_type ){
			case 'plural':
				return esc_html__('Zoom Webinars', 'events-manager-zoom');
				break;
			case 'singular':
				return esc_html__('Zoom Webinar', 'events-manager-zoom');
				break;
		}
		return parent::get_label($label_type);
	}
	/**
	 * @return Zoom_Webinar_Admin Representation of admin class for static calls e.g. Zoom_Meeting_Admin
	 */
	public static function load_admin_class(){
		require_once('em-event-location-zoom-webinar-admin.php');
		$class = 'EM_Event_Locations\Zoom_Webinar_Admin'; /* @var Zoom_Webinar_Admin $class */
		return $class;
	}
	
}
Zoom_Webinar::init();