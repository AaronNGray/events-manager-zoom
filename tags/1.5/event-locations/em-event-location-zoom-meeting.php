<?php
namespace EM_Event_Locations;
use EM_OAuth\Zoom_API, EM_OAuth\Zoom_API_Client;
use EM_Exception, EM_Gateways, EM_Attendees_Form, EM_Booking_Form, EM_Events;

/**
 * Adds a URL event location type by extending EM_Event_Location and registering itself with EM_Event_Locations
 *
 * @property string id                  The unique ID for this meeting.
 * @property string join_url            The url for participants to join the meeting
 * @property string registration_url    The url for participants to register for the meeting
 * @property string password            Password for joining the meeting
 * @property string last_hash           The laast md5 hash of values for meeting creation, used in comparison for event updates.
 * @property string last_questions_hash The laast md5 hash of values for meeting registrant questions, used in comparison for form updates.
 * @property string start_url           The URL a host can use to join the meeting. Generated ad-hoc and should only be displayed to event admins.
 * @property bool   ical_location       Flag for whether to display the join_url on the ical location field
 * @property array  settings            The settings array sent to create/modify a meeting via the API 'settings' variable.
 */
class Zoom_Meeting extends Event_Location {
	
	public static $type = 'zoom_meeting';
	public static $admin_template = '/forms/event/event-locations/url.php';
	
	public $properties = array('id', 'join_url', 'password', 'registration_url', 'last_hash', 'last_questions_hash', 'settings', 'ical_location');
	/**
	 * @var int Specific meeting type defined by Zoom API for creating things like webinars or meetings
	 */
	public static $zoom_api_type = 2;
	public static $zoom_api_base = 'meetings';
	public static $zoom_admin_url_base = 'meeting';
	public static $zoom_name_singular = 'Meeting'; // Meeting, set/translated on init()
	public static $zoom_name_plural = 'Meetings'; // Meeting, set/translated on init()
	
	/**
	 * @var bool If rate limit is hit for meetings or webinars, flag will be set to true during script run so that further action can be taken.
	 */
	public static $error_rate_limit = false;
	
	public function __construct($EM_Event) {
		parent::__construct($EM_Event);
	}
	
	public static function init(){
		parent::init();
		// add listeners for bookings
		$class = get_called_class();
		// duplicate events
		add_filter('em_event_duplicate_pre', $class.'::em_event_duplicate_pre', 10, 1);
		// recurring event support (unique meeting per recurrence)
		add_filter('em_event_save_events', $class.'::em_event_save_events', 10, 4);
		add_filter('em_event_delete_meta', $class.'::em_event_delete_meta', 10, 2);
		add_filter('em_event_save_events_exclude_update_meta_keys', $class.'::em_event_save_events_exclude_update_meta_keys', 10, 2);
		// booking hooks
		add_filter('em_booking_save', $class.'::em_booking_save', 10, 2);
		add_filter('em_booking_set_status', $class.'::em_booking_set_status', 10, 2);
		add_filter('em_booking_deleted', $class.'::em_booking_deleted', 10, 1);
		add_action('em_booking_output_placeholder', $class.'::em_booking_output_placeholder', 10, 3);
		// scripts/styles
		add_action('em_enqueue_admin_styles', $class.'::settings_page_scripts');
		add_action('em_enqueue_styles', $class.'::settings_page_scripts');
		// booking admin page
		add_action('em_bookings_single_metabox_footer', $class.'::booking_admin_single', 1, 1);
		if( !empty($_REQUEST['resync_zoom_booking']) ) add_action('init', $class.'::wp_init');
		// tranlsate words
		static::$zoom_name_singular = esc_html__('Meeting', 'events-manager-zoom');
		static::$zoom_name_plural = esc_html__('Meetings', 'events-manager-zoom');
	}
	
	public static function wp_init(){
		global $EM_Notices; /* @var \EM_Notices $EM_Notices */
		if( !empty($_REQUEST['resync_zoom_booking']) && $_REQUEST['zoom_type'] == static::$type && !empty($_REQUEST['booking_id']) ){
			if( wp_verify_nonce($_REQUEST['resync_zoom_booking'], 'resync_zoom_booking_'.$_REQUEST['booking_id']) ){
				$EM_Booking = em_get_booking($_REQUEST['booking_id']);
				if( static::em_booking_save( true, $EM_Booking ) ){
					$EM_Notices->add_confirm( esc_html(sprintf(__('Booking has been synchronized with Zoom %s', 'events-manager-zoom'), static::$zoom_name_singular)), true);
				}else{
					$error = sprintf(__('Could not syncronize booking with %s due to the following error(s) provided by Zoom:', 'events-manager-zoom'), static::$zoom_name_singular);
					if( !empty($EM_Booking->exception) ){
						$error .= '<code>'.$EM_Booking->exception->getMessage().'</code>';
					}
					$EM_Notices->add_error( $error, true );
				}
			}
			wp_safe_redirect(em_wp_get_referer());
		}
	}
	
	public static function settings_page_scripts() {
		$frontend_admin = get_option('dbem_edit_events_page');
		if( is_admin() || (!empty($frontend_admin) && is_page(get_option('dbem_edit_events_page'))) ){
			wp_enqueue_style( 'select2', EM_ZOOM_DIR_URI . '/select2/css/select2.min.css', array(), '4.1.0-beta.1' );
			wp_enqueue_script( 'select2', EM_ZOOM_DIR_URI . '/select2/js/select2.min.js', array(), '4.1.0-beta.1' );
		}
	}
	
	public function __get( $var ){
		if( $var == 'start_url' ){
			try{
				$zoom_client = Zoom_API::get_client();
				$meeting_data = $zoom_client->get('/'.static::$zoom_api_base.'/'.$this->id);
				if( !empty($meeting_data['body']) ){
					return $meeting_data['body']->start_url;
				}
			}catch( EM_Exception $ex ){
				return $ex->get_message();
			}
		}elseif( $var == 'password' && !empty($this->data['settings']['password']) && empty($this->data['password']) ){
			// backward compatibility
			$this->data['password'] = $this->data['settings']['password'];
			unset($this->data['settings']['password']);
			return $this->data['password'];
		}elseif( $var == 'ical_location' ){
			return !empty($this->data['ical_location']) || (!isset($this->data['ical_location']) && defined('EM_ZOOM_ICAL_LOC') && EM_ZOOM_ICAL_LOC);
		}
		return parent::__get($var);
	}
	
	public function __isset($name) {
		if( $name == 'password' ){
			// backward compatibility
			if( !empty($this->data['settings']['password']) && empty($this->data['password']) ){
				$this->data['password'] = $this->data['settings']['password'];
				unset($this->data['settings']['password']);
			}
		}elseif( $name == 'ical_location' && $this->data['ical_location'] === null && defined('EM_ZOOM_ICAL_LOC') && EM_ZOOM_ICAL_LOC ){
			return true;
		}
		return parent::__isset($name);
	}
	
	/**
	 * @param null $post deprecated, left to prevent warning due to removed $post arg from parent in 5.9.7.8
	 * @return bool
	 */
	public function get_post( $post = null ){
		// get settings
		if( !empty($_POST['event_location_'.static::$type.'_settings']) ){
			if( empty($this->data['settings']) ) $this->data['settings'] = array();
			$admin_class = static::load_admin_class(); /* @var Zoom_Meeting_Admin $admin_class */
			$field_settings = $admin_class::admin_fields_settings( $this->event );
			foreach( $field_settings['fields'] as $field_key => $field_props ){
				$post_value = isset($_POST['event_location_'.static::$type.'_settings'][$field_key]) ? sanitize_text_field($_POST['event_location_'.static::$type.'_settings'][$field_key]) : null;
				if( $field_key == 'password' ){
					// passwords are stored out of settings so they can be maintained for recurring events
					$this->data['password'] = !empty($post_value) ? trim($post_value) : null;
					continue;
				}
				switch( $field_props['type'] ){
					case 'text':
						$this->data['settings'][$field_key] = trim($post_value);
						break;
					case 'select':
						if( !empty($field_props['multiple']) ){
							if( !empty($_POST['event_location_'.static::$type.'_settings'][$field_key]) && is_array($_POST['event_location_'.static::$type.'_settings'][$field_key]) ){
								$this->data['settings'][$field_key] = array(); // reset array for post get
								foreach( $_POST['event_location_'.static::$type.'_settings'][$field_key] as $v ){
									if( !empty($field_props['values'][$v]) ){
										$this->data['settings'][$field_key][] = $v;
									}
								}
							}else{
								unset($this->data['settings'][$field_key]);
							}
						}elseif( !empty($field_props['values'][$post_value]) ){
							$this->data['settings'][$field_key] = $post_value;
						}
						break;
					case 'boolean':
						$this->data['settings'][$field_key] = !empty($post_value);
						break;
				}
			}
			if( empty($this->data['settings']['registrants_email_notification']) && !empty($this->data['settings']['registrants_confirmation_email']) ){
				// odd zoom nuance that if confirmations are enabled, but not general email notifications, it won't send the confirmation notification.
				$this->data['settings']['registrants_email_notification'] = true;
			}
		}
		$this->data['ical_location'] = !empty($_POST['event_location_'.static::$type.'_settings_general']['ical_location']);
		// check if a recreate flag was raised, if so we do it again
		if( !empty($_REQUEST['recreate_zoom_event']) && wp_verify_nonce($_REQUEST['recreate_zoom_event'], 'recreate_zoom_event_'.$this->event->event_id) ){
			$this->id = $this->registration_url = $this->last_hash = $this->last_questions_hash = $this->join_url = null;
		}
		return true;
	}
	
	public function validate(){
		$result = true;
		$admin_class = static::load_admin_class(); /* @var Zoom_Meeting_Admin $admin_class */
		$field_settings = $admin_class::admin_fields_settings( $this->event );
		foreach( $field_settings['fields'] as $field_key => $field ){
			switch( $field['type'] ){
				case 'text':
					if( !empty($this->settings[$field_key]) ){
						if( $field_key == 'alternative_hosts' ){
							$emails = explode(',', $this->settings[$field_key]);
							foreach( $emails as $email ){
								if( !is_email( trim($email) ) ){
									$error = sprintf( __('The Zoom settings field %s has an invalid email.', 'events-manager-zoom'), $field['label'] );
									$this->event->add_error($error);
									$result = false;
								}
							}
						}elseif( $field_key == 'contact_email' ){
							if( !is_email( trim($this->settings[$field_key]) ) ){
								$error = sprintf( __('The Zoom settings field %s has an invalid email.', 'events-manager-zoom'), $field['label'] );
								$this->event->add_error($error);
								$result = false;
							}
						}
					}
					break;
				case 'select':
					if( !empty($this->settings[$field_key]) && !empty($field['multiple']) ){
						if( !is_array($this->settings[$field_key]) ){
							$error = sprintf( __('The Zoom settings field %s does not have a valid value selected.', 'events-manager-zoom'), '<code>'.$field['label'].'</code>' );
							$this->event->add_error($error);
						}else{
							foreach( $this->settings[$field_key] as $v ){
								if( !isset($field['values'][$v]) ){
									$error = sprintf( __('The Zoom settings field %s does not have a valid value selected.', 'events-manager-zoom'), '<code>'.$field['label'].'</code>' );
									$this->event->add_error($error);
									$result = false;
								}
							}
						}
					}elseif( !empty($this->settings[$field_key]) && !isset($field['values'][$this->settings[$field_key]]) ){
						$error = sprintf( __('The Zoom settings field %s does not have a valid value selected.', 'events-manager-zoom'), '<code>'.$field['label'].'</code>' );
						$this->event->add_error($error);
						$result = false;
					}
					break;
			}
		}
		return $result;
	}
	
	public function save(){
		$result = true;
		// short circuit recurring events, only settings should be saved
		if( $this->event->is_recurring() ){
			parent::save();
			return true;
		}
		// validate first, and save settings regardless
		if( !$this->validate() ){
			parent::save();
			return false;
		}
		// check for a valid room ID, and if so populate the info about the room.
		$meeting = $this->get_meeting_request_settings();
		if( !empty($this->id) ){
			$skip_create = !empty($this->data['last_hash']) && md5( var_export($meeting, true) ) === $this->data['last_hash'];
		}
		// update either meeting or questions
		try{
			$zoom_client = null;
			if( empty($skip_create) ){
				$zoom_client = Zoom_API::get_client();
				if( empty($this->id) ){
					$zoom_response = $zoom_client->post('/users/me/'.static::$zoom_api_base, $meeting, array(), true);
					if( !empty($zoom_response['body']) ) {
						// save individual fields for reuse
						$zoom_meeting = $zoom_response['body'];
						$this->id = $zoom_meeting->id;
						$this->join_url = $zoom_meeting->join_url;
						if( !empty($zoom_meeting->registration_url) ){
							$this->registration_url = $zoom_meeting->registration_url;
						}
						$this->data['password'] = $zoom_meeting->password;
						// add bookings if they exist by calling the save hook on this class
						if( $this->event->event_rsvp && $this->event->get_bookings()->get_booked_spaces() > 0 ){
							foreach( $this->event->get_bookings()->get_bookings() as $EM_Booking ){
								if( !empty($EM_Booking->booking_meta[static::$type]) ) unset($EM_Booking->booking_meta[static::$type]);
								static::em_booking_save( true, $EM_Booking );
							}
						}
					}else{
						$error = __('Could not create Zoom Meeting due to the following error: %s', 'events-manager-zoom');
						$error = sprintf($error, $zoom_response['response']['message']);
						$this->event->add_error( $error );
						$result = false;
					}
				}else{
					$zoom_response = $zoom_client->patch('/'.static::$zoom_api_base.'/'.$this->id, $meeting, array(), true);
					if( $zoom_response['response']['code'] != 204 ){
						$error = __('Could not update Zoom Meeting due to the following error: %s', 'events-manager-zoom');
						$error = sprintf($error, $zoom_response['response']['message']);
						$this->event->add_error( $error );
						$result = false;
					}
				}
			}
			if( $result !== false ){
				// save hash so
				$this->data['last_hash'] = md5( var_export($meeting, true) );
				if( $this->event->event_rsvp ){
					// now update Zoom questions based on Pro custom forms, will throw exception of something goes wrong
					$this->update_questions( $zoom_client );
				}
			}
		}catch( EM_Exception $ex ){
			$error = __('Could not create or update Zoom Meeting due to the following error: %s', 'events-manager-zoom');
			$error = sprintf(esc_html($error), '<code>'.$ex->getMessage().'</code>');
			$this->event->add_error( $error );
			if( $ex->getCode() == 429 ){
				static::$error_rate_limit = true;
			}
			$result = false;
		}
		parent::save();
		return $result;
	}
	
	/**
	 * Catches a duplicated event and removes meeting id so it's created again upon publication.
	 * @param \EM_Event $EM_Event
	 */
	public static function em_event_duplicate_pre( $EM_Event ){
		if( $EM_Event->has_event_location(static::$type) ){
			$e = $EM_Event->get_event_location(); /* @var Zoom_Meeting $e */
			$e->id = $e->join_url = $e->last_questions_hash = $e->last_hash = $e->registration_url = null;
		}
	}
	
	/**
	 * Hooks into EM_Event::save_events() and creates corresponding meetings for each event.
	 * @param bool $result
	 * @param \EM_Event $EM_Event
	 * @param array $event_ids
	 * @return bool
	 */
	public static function em_event_save_events( $result, $EM_Event, $event_ids ){
		if( $result && $EM_Event->has_event_location(static::$type) ){
			foreach( $event_ids as $event_id ){
				$event = em_get_event($event_id);
				// ensure location type and data is fresh
				$event->event_location_type = $EM_Event->event_location_type; // in case data is obtained from the cache
				if( $event->get_event_location()->id == $EM_Event->get_event_location()->id ) $event->get_event_location()->id = null; // in case we have the same as a recurring event
				$event->get_event_location()->settings = $EM_Event->get_event_location()->settings;
				if( $EM_Event->get_event_location()->password ){
					$event->get_event_location()->password = $EM_Event->get_event_location()->password;
				}
				// save
				$event->get_event_location()->save();
				// check for API request
				if( count($event->errors) > 1 && !empty(static::$error_rate_limit) ){
					$msg = esc_html__('You have reached your limit of Zoom %s API requests, which is limited to creating/updating/deleting 100 %s per day. Please wait until after midnight UTC to try again and create the remaining recurrence events.', 'events-manager-zoom');
					$EM_Event->add_error( sprintf($msg, static::$zoom_name_singular, static::$zoom_name_plural) );
					return false;
				}
			}
		}
		return $result;
	}
	
	/**
	 * Add all properties for this location type to be excluded in recurrence updates, we'll do it ourselves.
	 * @param array $array
	 * @param \EM_Event $EM_Event
	 * @return array
	 */
	public static function em_event_save_events_exclude_update_meta_keys( $array, $EM_Event ){
		if( $EM_Event->has_event_location(static::$type) ){
			foreach( $EM_Event->get_event_location()->properties as $prop ){
				// we exclude all but the settings, as that's something we can update via recurrences
				if( $prop !== 'settings' ){
					$array[] = '_event_location_'.static::$type.'_'.$prop;
				}
			}
		}
		return $array;
	}
	
	/**
	 * @return bool|null
	 */
	public function delete(){
		$return = null;
		if( $this->event->is_recurring() ){
			// delete() is called on a recurrning save() function before save_events(), therefore any data retrieved for recurrences currently saved will retrieve old location data, which would be the current data being deleted.
			foreach( EM_Events::get( array('recurrence'=>$this->event->event_id, 'scope'=>'all', 'status'=>'everything') ) as $EM_Event ){
				if( $EM_Event->has_event_location($this->type) ){
					$EM_Event->get_event_location()->delete();
				}
			}
			$return = true;
		}elseif( !empty($this->data['id']) ){
			try{
				$zoom_client = Zoom_API::get_client();
				$zoom_response = $zoom_client->delete('/'.static::$zoom_api_base.'/'.$this->id);
				if( $zoom_response['response']['code'] != 204 ){
					throw new EM_Exception($zoom_response['response']['message'], $zoom_response['response']['code']);
				}
				$return = true;
			}catch( EM_Exception $ex ){
				global $EM_Notices; /* @var \EM_Notices $EM_Notices */
				$error = __('Could not delete Zoom Meeting/Webinar due to the following error: %s', 'events-manager-zoom');
				$error = sprintf(esc_html($error), '<code>'.$ex->getMessage().'</code>');
				$EM_Notices->add_alert( $error );
				$return = false;
			}
		}
		// reset data here, after item deleted on Zoom
		parent::delete();
		return $return;
	}
	
	/**
	 * @param boolean $result
	 * @param \EM_Event $EM_Event
	 * @return boolean
	 */
	public static function em_event_delete_meta( $result, $EM_Event ){
		if( $result && $EM_Event->has_event_location( static::$type ) ){
			// get meeting/webinar and delete it
			try{
				$zoom_client = Zoom_API::get_client();
				$zoom_client->delete('/'.static::$zoom_api_base.'/'.$EM_Event->get_event_location()->id);
			}catch( EM_Exception $ex ){
				$error = __('Could not delete or update Zoom Meeting due to the following error: %s', 'events-manager-zoom');
				$error = sprintf(esc_html($error), '<code>'.$ex->getMessage().'</code>');
				$EM_Event->add_error( $error );
				$result = false;
			}
		}
		return $result;
	}
	
	public function get_meeting_request_settings(){
		// create or update this zoom meeting
		$minutes_difference = ($this->event->end()->getTimestamp() - $this->event->start()->getTimestamp()) / 60;
		if( $minutes_difference == 0 ) $minutes_difference = 15; //we need an amount of time
		$meeting = array (
			'topic' => $this->event->name,
			'type' => static::$zoom_api_type, // scheduled meeting
			'start_time' => $this->event->start(true)->format('Y-m-d\TH:i:s').'Z', // we may not include a timezone because Zoom doesn't support all PHP timezones, see further down
			'duration' => $minutes_difference,
			//'schedule_for' => 'string', // we could add scheduling for users within a zoom account
			'agenda' => $this->event->output_excerpt() .' '. sprintf(esc_html__('More information at %s', 'events-manager-zoom'), $this->event->get_permalink()),
			'settings' => $this->settings,
		);
		if( !empty($this->password) ) $meeting['password'] = $this->password;
		$meeting['settings']['registration_type'] = 2; // registration type should lock users to the one meeting/webinar since we don't have occurrence/recurrences. Recurrences seem to be very limited for purposes of registration etc.
		// get timezone, and if no corresponding Zoom timezone exists, leave it blank so that UTC is used
		$zoom_timezones = array('Pacific/Midway','Pacific/Pago_Pago','Pacific/Honolulu','America/Anchorage','America/Vancouver','America/Los_Angeles','America/Tijuana','America/Edmonton','America/Denver','America/Phoenix','America/Mazatlan','America/Winnipeg','America/Regina','America/Chicago','America/Mexico_City','America/Guatemala','America/El_Salvador','America/Managua','America/Costa_Rica','America/Montreal','America/New_York','America/Indianapolis','America/Panama','America/Bogota','America/Lima','America/Halifax','America/Puerto_Rico','America/Caracas','America/Santiago','America/St_Johns','America/Montevideo','America/Araguaina','America/Argentina/Buenos_Aires','America/Godthab','America/Sao_Paulo','Atlantic/Azores','Canada/Atlantic','Atlantic/Cape_Verde','UTC','Etc/Greenwich','Europe/Belgrade','CET','Atlantic/Reykjavik','Europe/Dublin','Europe/London','Europe/Lisbon','Africa/Casablanca','Africa/Nouakchott','Europe/Oslo','Europe/Copenhagen','Europe/Brussels','Europe/Berlin','Europe/Helsinki','Europe/Amsterdam','Europe/Rome','Europe/Stockholm','Europe/Vienna','Europe/Luxembourg','Europe/Paris','Europe/Zurich','Europe/Madrid','Africa/Bangui','Africa/Algiers','Africa/Tunis','Africa/Harare','Africa/Nairobi','Europe/Warsaw','Europe/Prague','Europe/Budapest','Europe/Sofia','Europe/Istanbul','Europe/Athens','Europe/Bucharest','Asia/Nicosia','Asia/Beirut','Asia/Damascus','Asia/Jerusalem','Asia/Amman','Africa/Tripoli','Africa/Cairo','Africa/Johannesburg','Europe/Moscow','Asia/Baghdad','Asia/Kuwait','Asia/Riyadh','Asia/Bahrain','Asia/Qatar','Asia/Aden','Asia/Tehran','Africa/Khartoum','Africa/Djibouti','Africa/Mogadishu','Asia/Dubai','Asia/Muscat','Asia/Baku','Asia/Kabul','Asia/Yekaterinburg','Asia/Tashkent','Asia/Calcutta','Asia/Kathmandu','Asia/Novosibirsk','Asia/Almaty','Asia/Dacca','Asia/Krasnoyarsk','Asia/Dhaka','Asia/Bangkok','Asia/Saigon','Asia/Jakarta','Asia/Irkutsk','Asia/Shanghai','Asia/Hong_Kong','Asia/Taipei','Asia/Kuala_Lumpur','Asia/Singapore','Australia/Perth','Asia/Yakutsk','Asia/Seoul','Asia/Tokyo','Australia/Darwin','Australia/Adelaide','Asia/Vladivostok','Pacific/Port_Moresby','Australia/Brisbane','Australia/Sydney','Australia/Hobart','Asia/Magadan','SST','Pacific/Noumea','Asia/Kamchatka','Pacific/Fiji','Pacific/Auckland','Asia/Kolkata','Europe/Kiev','America/Tegucigalpa','Pacific/Apia');
		if( in_array( $this->event->get_timezone()->getName(), $zoom_timezones) ){
			$meeting['timezone'] = $this->event->get_timezone()->getName();
		}
		return apply_filters('em_event_location_'.static::$type.'_settings', $meeting, $this);
	}
	
	/**
	 * @see Event_Location::admin_delete_warning()
	 */
	public function admin_delete_warning(){
		$link = '<a href="https://zoom.us/'.static::$zoom_admin_url_base.'/trashcan/list" target="_blank">'.esc_html__('recently deleted', 'events-manager-zoom').'</a>';
		$message = esc_html__('The Zoom %s will also be deleted. You can still recover this from your %s items on Zoom, but a new %s will be recreated if you choose this location type again later.', 'events-manager-zoom');
		echo '<div class="warning-bold">'. sprintf($message, static::$zoom_name_singular, $link, static::$zoom_name_singular) .'</div>';
	}
	
	/**
	 * @param Zoom_API_Client $zoom_client
	 * @param bool $save
	 * @throws EM_Exception
	 */
	public function update_questions( $zoom_client = null ){
		//if( isset($this->settings['approval_type']) && $this->settings['approval_type'] == 2 ) return;
		if( $this->settings['approval_type'] == 2 ) return;
		if( !$zoom_client ) $zoom_client = Zoom_API::get_client();
		$registrant_questions = $this->get_registrant_questions();
		if( empty($registrant_questions['custom_questions']) ) unset( $registrant_questions['custom_questions'] );
		if( empty($registrant_questions['questions']) ) unset( $registrant_questions['questions'] );
		if( !empty($registrant_questions) ){
			$skip = !empty($this->last_questions_hash) && md5( var_export($registrant_questions, true) ) === $this->last_questions_hash;
			if( !$skip ){
				try{
					$zoom_response = $zoom_client->patch('/'.static::$zoom_api_base.'/'.$this->id.'/registrants/questions', $registrant_questions, array(), true);
					if( $zoom_response['response']['code'] != 204 ){
						throw new EM_Exception($zoom_response['response']['code'] . ' - ' . $zoom_response['response']['message']);
					}
				}catch( EM_Exception $ex ){
					throw new EM_Exception(__('Registration questions error: '. $ex->getMessage()) );
				}
				// save hash for update comparison in future if edits are made
				$this->last_questions_hash = md5( var_export($registrant_questions, true) );
				$meta_key = '_event_location_'.static::$type.'_last_questions_hash';
				update_post_meta( $this->event->post_id, $meta_key, $this->last_questions_hash );
			}
		}
	}
	
	/**
	 * Gets the questions for the meeting of a Zoom event, based on whether an attendee or custom form is used.
	 */
	public function get_registrant_questions(){
		$registrant_questions = array(
			'questions' => array(),
			'custom_questions' => array( array('title' => __('Ticket Name', 'events-manager'), 'type' => 'short', 'required' => false) ),
		);
		$form_type = $custom_form = null;
		if( $this->supports_attendee_form() ){
			// go through attendee form and add the relevant forms if they match
			$form_type = 'attendee';
			$custom_form = EM_Attendees_Form::get_form( $this->event );
		}elseif( class_exists('EM_Booking_Form') ){
			// deal with custom booking form and gateways
			$form_type = 'booking';
			$custom_form = EM_Booking_Form::get_form( $this->event );
		}
		if( !empty($custom_form) ){
			$questions = static::get_zoom_registrant_questions();
			$associated_fields = array_flip(get_option('emp_gateway_customer_fields'));
			foreach( $custom_form->form_fields as $field_id => $field ){
				if( $field['type'] == 'html' ) continue;
				$q_added = false;
				if( array_key_exists($field_id, $questions) ){
					// standard Zoom question which matches field ID, check if it accepts specific values or any string
					if( is_array($questions[$field_id]) ){
						// DDM - ensure this question will provide the same values as accepted by Zoom
						$field_values = explode("\r\n",$field['options_select_values']);
						if( $field_values === $questions[$field_id]){
							// if not met, this will be added as a custom question
							$q_added = true;
						}
					}else{
						// regular string answer
						$q_added = true;
					}
					// add this to the questions array
					if( $q_added ){
						$registrant_questions['questions'][] = array(
							'field_name' => $field_id,
							'required' => false, //$field['required'] == true,
						);
					}
				}elseif( $form_type == 'booking' && class_exists('EM_Gateways') && array_key_exists($field_id, $associated_fields) ){
					// for regular booking forms, check common address fields mapped by gateway settings in case they are with different IDs
					$true_field_id = $associated_fields[$field_id];
					if( array_key_exists($true_field_id, $questions) ){
						// matched common question field
						$registrant_questions['questions'][] = array('field_name' => $true_field_id, 'required' => false);
						$q_added = true;
					}elseif( $true_field_id == 'company' ){
						// company = org in Zoom
						$registrant_questions['questions'][] = array('field_name' => 'org', 'required' => false);
						$q_added = true;
					}elseif( $true_field_id == 'address_2' ){
						$q_added = true; // for this address line, we're assuming the first line is added too and then later one we merge these two during a booking
					}
				}
				if( !$q_added && !in_array( $field_id, array('email', 'user_email', 'first_name', 'last_name', 'user_password') )){
					// this is a custom question
					$registrant_questions['custom_questions'][] = array(
						'title' => $field['label'],
						'type' => 'short', // we don't need validation of values, this is done by our form and values are passed on during registration
						'required' => false, //$field['required'] == true, // won't forced required fields and let EM force requirement on this side of registration
					);
				}
			}
		}
		return $registrant_questions;
	}
	
	public function get_link( $new_target = true ){
		return '<a href="'.esc_url($this->room_id).'">'. esc_html($this->text).'</a>';
	}
	
	public function get_admin_column() {
		$return = '<strong>' . static::get_label() . '</strong>';
		if( $this->event->can_manage('edit_events') && is_admin() ){
			$zoom_admin_link = '<a href="https://zoom.us/meeting/'.$this->id.'" target="_blank">'. __('View/Edit on Zoom.us', 'events-manager-zoom').'</a>';
			$join_link = '<a href="'.esc_url($this->join_url).'" target="_blank">'.esc_html__('Join URL', 'events-manager-zoom').'</a>';
			$return .= ' - #'.$this->id.'<br>'. '<span class="row-actions">'.$zoom_admin_link.' | '. $join_link .'</span>';
		}
		return $return;
	}
	
	public static function get_label( $label_type = 'singular' ){
		switch( $label_type ){
			case 'plural':
				return esc_html__('Zoom Meetings', 'events-manager-zoom');
				break;
			case 'singular':
				return esc_html__('Zoom Meeting', 'events-manager-zoom');
				break;
		}
		return parent::get_label($label_type);
	}
	
	/**
	 * Loads admin template automatically if static $admin_template is set to a valid path in templates folder.
	 * Classes with custom forms outside of template folders can override this function and provide their own HTML that will go in the loop of event location type forms.
	 */
	public static function load_admin_template(){
		$class = static::load_admin_class();
		$class::load_admin_template();
	}
	
	/**
	 * Loads external admin class for admin-specific functions.
	 * @return Zoom_Meeting_Admin Representation of admin class for static calls e.g. Zoom_Meeting_Admin
	 */
	public static function load_admin_class(){
		require_once('em-event-location-zoom-meeting-admin.php');
		$class = 'EM_Event_Locations\Zoom_Meeting_Admin'; /* @var Zoom_Meeting_Admin $class */
		return $class;
	}
	
	/**
	 * Array of standard questions permitted by Zoom, organized by key, where a null value indicates a string and an array a list of possible values.
	 * @return array
	 */
	public static function get_zoom_registrant_questions(){
		return array(
			// 'first_name' => null, 'last_name' => null, 'email' => null, //Required, Zoom won't accept these as arguments
			'address' => null ,'city' => null ,'country' => null ,'zip' => null ,'state' => null ,'phone' => null ,'industry' => null ,'org' => null ,'job_title' => null, 'comments' => null,
			'purchasing_time_frame' => array( 'Within a month', '1-3 months', '4-6 months', 'More than 6 months', 'No timeframe'),
			'role_in_purchase_process' => array('Decision Maker','Evaluator/Recommender','Influencer','Not involved'),
			'no_of_employees' => array('1-20','21-50','51-100','101-500','500-1,000','1,001-5,000','5,001-10,000','More than 10,000'),
		);
	}
	
	/**
	 * @param bool $result
	 * @param \EM_Booking $EM_Booking
	 * @return bool
	 */
	public static function em_booking_save( $result, $EM_Booking ){
		if( $result === false ) return $result;
		if( $EM_Booking->get_event()->has_event_location(static::$type) ){
			try{
				$_this = $EM_Booking->get_event()->get_event_location(); /* @var Zoom_Meeting $_this */
				if( $_this->settings['approval_type'] == 2 ) return $result; // if no registration is required for Zoom meeting, we don't register bookings and send the users a join url
				$meeting_id = $_this->id;
				if( $EM_Booking->booking_status == 1 && empty($EM_Booking->booking_meta[static::$type]) ){
					// a newly approved booking (possibly approved manually or automatically) - create new registration
					$zoom_client = Zoom_API::get_client();
					$_this->update_questions( $zoom_client );
					// check for PRO attendee form functionality
					if( $_this->supports_attendee_form() ){
						$failed_registrations = 0;
						foreach( $EM_Booking->booking_meta['attendees'] as $ticket_id =>  $ticket_attendees ){ /* @var $EM_Ticket_Booking \EM_Ticket_Booking */
							foreach( $ticket_attendees as $attendee_key => $ticket_attendee ){
								if( !static::register_attendee( $ticket_attendee, $attendee_key, $ticket_id, $EM_Booking, $meeting_id, $zoom_client ) ){
									$failed_registrations++;
								}
							}
						}
						if( $failed_registrations > 0 ){
							throw new EM_Exception( __('Could not register all attendees to Zoom meeting.', 'events-manager-zoom'));
						}
						$EM_Booking->booking_meta[static::$type] = 'attendees';
					}else{
						// If we can't register individual attendees, register one person, the booking user
						$zoom_registrant = array (
							'email' => $EM_Booking->get_person()->user_email,
							'first_name' => $EM_Booking->get_person()->first_name,
							'last_name' => $EM_Booking->get_person()->last_name,
						);
						// get PRO custom booking form fields
						if( class_exists('EM_Gateways') ){ // pro exists
							$custom_questions = array();
							//  common address fields linked via gateway settings, if available
							$address = '';
							if( EM_Gateways::get_customer_field('address', $EM_Booking, $EM_Booking->person_id) != '' ) $address = EM_Gateways::get_customer_field('address', $EM_Booking);
							if( EM_Gateways::get_customer_field('address_2', $EM_Booking, $EM_Booking->person_id) != '' ) $address .= ', ' .EM_Gateways::get_customer_field('address_2', $EM_Booking);
							if( !empty($address) ) $zoom_registrant['address'] = $address;
							if( EM_Gateways::get_customer_field('city', $EM_Booking, $EM_Booking->person_id) != '' ) $zoom_registrant['city'] = EM_Gateways::get_customer_field('city', $EM_Booking);
							if( EM_Gateways::get_customer_field('state', $EM_Booking, $EM_Booking->person_id) != '' ) $zoom_registrant['state'] = EM_Gateways::get_customer_field('state', $EM_Booking);
							if( EM_Gateways::get_customer_field('zip', $EM_Booking, $EM_Booking->person_id) != '' ) $zoom_registrant['zip'] = EM_Gateways::get_customer_field('zip', $EM_Booking);
							if( EM_Gateways::get_customer_field('country', $EM_Booking, $EM_Booking->person_id) != '' ){
								$zoom_countries = array('AD','AE','AF','AG','AI','AL','AM','AN','AO','AQ','AR','AS','AT','AU','AW','AX','AZ','BA','BB','BD','BE','BF','BG','BH','BI','BJ','BM','BN','BO','BR','BS','BT','BV','BW','BY','BZ','CA','CD','CF','CG','CH','CI','CK','CL','CM','CN','CO','CR','CS','CU','CV','CY','CZ','DE','DJ','DK','DM','DO','DZ','EC','EE','EG','ER','ES','ET','FI','FJ','FK','FM','FO','FR','GA','GB','GD','GE','GF','GG','GH','GI','GL','GM','GN','GP','GQ','GR','GS','GT','GU','GW','GY','HK','HN','HR','HT','HU','ID','IE','IL','IM','IN','IO','IQ','IR','IS','IT','JE','JM','JO','JP','KE','KG','KH','KI','KM','KN','KP','KR','KW','KY','KZ','LA','LB','LC','LI','LK','LR','LS','LT','LU','LV','LY','MA','MC','MD','ME','MF','MG','MH','MK','ML','MM','MN','MO','MP','MQ','MR','MS','MT','MU','MV','MW','MX','MY','MZ','NA','NC','NE','NF','NG','NI','NL','NO','NP','NR','NU','NZ','OM','PA','PE','PF','PG','PH','PK','PL','PM','PR','PS','PT','PW','PY','QA','RE','RO','RS','RU','RW','SA','SB','SC','SD','SE','SG','SI','SK','SL','SM','SN','SO','SR','SS','ST','SV','SY','SZ','TC','TD','TF','TG','TH','TJ','TK','TL','TM','TN','TO','TR','TT','TV','TW','TZ','UA','UG','UK','UM','US','UY','UZ','VA','VC','VE','VG','VI','VN','VU','WF','WS','YE','YT','ZA','ZM','ZW');
								$registrant_country = EM_Gateways::get_customer_field('country', $EM_Booking);
								if( in_array($registrant_country, $zoom_countries) ){ // don't register country if it doesn't exist
									$zoom_registrant['country'] = EM_Gateways::get_customer_field('country', $EM_Booking);
								}
							}
							if( EM_Gateways::get_customer_field('phone', $EM_Booking, $EM_Booking->person_id) != '' ) $zoom_registrant['phone'] = EM_Gateways::get_customer_field('phone', $EM_Booking);
							if( EM_Gateways::get_customer_field('company', $EM_Booking, $EM_Booking->person_id) != '' ) $zoom_registrant['org'] = EM_Gateways::get_customer_field('company', $EM_Booking);
							if( EM_Gateways::get_customer_field('fax', $EM_Booking, $EM_Booking->person_id) != '' ){
								$custom_questions[] = array(
									'title' => __('Fax', 'events-manager-pro'),
									'value' => EM_Gateways::get_customer_field('fax', $EM_Booking),
								);
							}
							// now get regular fields and add as zoom or custom questions
							$registration_fields = !empty($EM_Booking->booking_meta['registration']) ? $EM_Booking->booking_meta['registration'] : array();
							$booking_fields = !empty($EM_Booking->booking_meta['booking']) ? $EM_Booking->booking_meta['booking'] : array();
							$booking_data_fields = array_merge( $registration_fields, $booking_fields );
							if( !empty($booking_data_fields)  ){
								$booking_form = EM_Booking_Form::get_form( $EM_Booking->get_event() );
								$associated_fields = get_option('emp_gateway_customer_fields');
								$questions = static::get_zoom_registrant_questions();
								foreach( $booking_data_fields as $field_id => $field_value ){
									// skip already used (name and common-mapped fields) or sensitive fields (passwords)
									if( !(in_array( $field_id, array('user_email', 'first_name', 'last_name', 'user_name', 'user_password') ) || in_array( $field_id, $associated_fields)) ){
										if( array_key_exists($field_id, $questions) && !empty($field_value) ){
											// add additional fields
											if( !is_array($questions[$field_id]) || in_array($field_value, $questions[$field_id]) ) {
												$zoom_registrant[$field_id] = $field_value;
											}
										}else{
											// add custom fields
											$field_formatted_value = $booking_form->get_formatted_value( $booking_form->form_fields[$field_id], $field_value );
											if( !empty($field_formatted_value) ){
												$custom_questions[] = array(
													'title' => $booking_form->form_fields[$field_id]['label'],
													'value' => $field_formatted_value,
												);
											}
										}
									}
								}
							}
							if( !empty($custom_questions) ) $zoom_registrant['custom_questions'] = $custom_questions;
						}
						$auto_approve = $EM_Booking->get_event()->get_event_location()->settings['approval_type'] == 1; // if approval type is set to manual approval, we approve meetings/webinars on successful bookings.
						$EM_Booking->booking_meta[static::$type] = $zoom_client->add_registrant( $zoom_registrant, $meeting_id, static::$zoom_api_base, $auto_approve );
					}
				}elseif( !empty($EM_Booking->booking_meta[static::$type]) && $EM_Booking->booking_status !== $EM_Booking->previous_status ){
					// previous booking that was saved
					$action_array = array(
						0 => 'cancel',
						1 => 'approve',
						2 => 'deny',
						3 => 'cancel',
					);
					$zoom_client = Zoom_API::get_client();
					$_this->update_questions( $zoom_client );
					$registrant_action = !empty($action_array[$EM_Booking->booking_status]) ? $action_array[$EM_Booking->booking_status] : 'cancel';
					// we now either go through all attendees to build array of registrants, or just add the one
					if( $EM_Booking->booking_meta[static::$type] == 'attendees' && $_this->supports_attendee_form() ){ // Pro feature
						$failed_registrations = 0;
						$registrant_modifications = array();
						foreach( $EM_Booking->booking_meta['attendees'] as $ticket_id =>  $ticket_attendees ){ /* @var $EM_Ticket_Booking \EM_Ticket_Booking */
							foreach( $ticket_attendees as $attendee_key => $ticket_attendee ){
								// go through each attendee and either prepare for modification or add them anew
								if( !empty($ticket_attendee[static::$type]['id']) ){
									$registrant_modifications[] = array(
										'id' => $ticket_attendee[static::$type]['id'],
										'email' => $ticket_attendee['email'],
									);
								}elseif( $registrant_action == 'approve'){
									if( !static::register_attendee( $ticket_attendee, $attendee_key, $ticket_id, $EM_Booking, $meeting_id, $zoom_client ) ){
										$failed_registrations++;
									}
								}
							}
						}
						// register all modifications at once
						if( !empty($registrant_modifications) ){
							try{
								$zoom_client->update_registrants_status( $registrant_action, $registrant_modifications, $meeting_id, static::$zoom_api_base );
							}catch( EM_Exception $ex ){
								// throw exception after attempting all modifications
								throw new EM_Exception( __('Could not modify all attendees to Zoom meeting.', 'events-manager-zoom'));
							}
						}
						// throw exception after attempting all modifications
						if( $failed_registrations > 0 ){
							throw new EM_Exception( __('Could not register all attendees to Zoom meeting.', 'events-manager-zoom'));
						}
					}elseif( !empty($EM_Booking->booking_meta[static::$type]['id']) ){
						// change status of booking, nothing more
						$zoom_client->update_registrant_status( $registrant_action, $EM_Booking->booking_meta[static::$type]['id'], $EM_Booking->get_person()->user_email, $meeting_id, static::$zoom_api_base );
					}
				}
			}catch( EM_Exception $ex ){
				if( class_exists('\EMP_Logs') ) \EMP_Logs::log( $ex->get_message() , 'zoom');
				if( !empty($EM_Booking->booking_meta[static::$type]) ){
					$error = __('Could not automatically modify the status of Zoom meeting registrants.', 'events-manager-zoom');
				}else{
					$error = __('Could not automatically enroll you into the Zoom meeting. Please get in touch with the event organizer.', 'events-manager-zoom');
				}
				$EM_Booking->add_error( $error );
				$EM_Booking->exception = $ex;
				$result = false;
			}
			// save booking meta field just in case anything was modified
			global $wpdb;
			$wpdb->update( EM_BOOKINGS_TABLE, array('booking_meta' => serialize($EM_Booking->booking_meta)), array('booking_id' => $EM_Booking->booking_id) );
		}
		return $result;
	}
	
	/**
	 * Proides a Join URL for user booking, and also broken-down join URLS for attendees if using Pro Attendee Forms.
	 * @param string $replace
	 * @param \EM_Booking $EM_Booking
	 * @param string $full_result
	 * @return string
	 */
	public static function em_booking_output_placeholder($replace, $EM_Booking, $full_result){
		if( $full_result == '#_BOOKINGZOOMJOINURL' || $full_result == '#_BOOKINGZOOMJOINLINK' ){
			if( $EM_Booking->get_event()->has_event_location(static::$type) ){
				$replace = '#';
				// get user join URL
				$_this = $EM_Booking->get_event()->get_event_location(); /* @var Zoom_Meeting $_this */
				if( $_this->settings['approval_type'] == 2 ){
					$replace = $_this->join_url;
					if ($full_result == '#_BOOKINGZOOMJOINLINK') {
						$replace = '<a href="' . $replace . '">' . sprintf(esc_html__('Join %s', 'events-manager-zoom'), static::$zoom_name_singular) . '</a>';
					}
				} else {
					if (!empty($EM_Booking->booking_meta[static::$type])) {
						if ($EM_Booking->booking_meta[static::$type] == 'attendees' && $_this->supports_attendee_form()) {
							foreach ($EM_Booking->booking_meta['attendees'] as $ticket_id => $ticket_attendees) {
								/* @var $EM_Ticket_Booking \EM_Ticket_Booking */
								foreach ($ticket_attendees as $attendee_key => $ticket_attendee) {
									// go through each attendee and add by name and join URL
									$name = $ticket_attendee['first_name'] . ' ' . $ticket_attendee['last_name'];
									$join_url = '#';
									if (!empty($EM_Booking->booking_meta['attendees'][$ticket_id][$attendee_key][static::$type]['join_url'])) {
										$join_url = esc_url($EM_Booking->booking_meta['attendees'][$ticket_id][$attendee_key][static::$type]['join_url']);
										if ($full_result == '#_BOOKINGZOOMJOINLINK') {
											$join_url = '<a href="' . $join_url . '">' . sprintf(esc_html__('Join %s', 'events-manager-zoom'), static::$zoom_name_singular) . '</a>';
										}
									}
									$join_urls[] = $name . ' - ' . $join_url;
								}
							}
							$replace = implode("\r\n", $join_urls);
						} else {
							if (!empty($EM_Booking->booking_meta[static::$type]['join_url'])) {
								$replace = esc_url($EM_Booking->booking_meta[static::$type]['join_url']);
								if ($full_result == '#_BOOKINGZOOMJOINLINK') {
									$replace = '<a href="' . $replace . '">' . sprintf(esc_html__('Join %s', 'events-manager-zoom'), static::$zoom_name_singular) . '</a>';
								}
							}
						}
					}
				}
			}
		}
		return $replace;
	}
	
	/**
	 * @param \EM_Booking $EM_Booking
	 */
	public static function booking_admin_single( $EM_Booking ){
		if( !$EM_Booking->get_event()->has_event_location(static::$type) ) return;
		$supports_attendees = !empty($EM_Booking->booking_meta[static::$type]) && $EM_Booking->booking_meta[static::$type] == 'attendees' && $EM_Booking->get_event()->get_event_location()->supports_attendee_form();
		$join_links_count = $supports_attendees && count($EM_Booking->booking_meta['attendees']) > 1 ? count($EM_Booking->booking_meta['attendees']) : 1;
		?>
		<div class="postbox">
			<h3><?php echo esc_html(_n( 'Zoom Join Link', 'Zoom Join Links', $join_links_count, 'events-manager-zoom')); ?></h3>
			<div class="inside">
				<?php
				if( current_action() == 'em_bookings_single_metabox_footer') echo '';
				if( $EM_Booking->booking_status == 1 && !empty($EM_Booking->booking_meta[static::$type]) ){
					if( $supports_attendees ){
						foreach ($EM_Booking->booking_meta['attendees'] as $ticket_id => $ticket_attendees) {
							/* @var $EM_Ticket_Booking \EM_Ticket_Booking */
							foreach ($ticket_attendees as $attendee_key => $ticket_attendee) {
								// go through each attendee and add by name and join URL
								$name = $ticket_attendee['first_name'] . ' ' . $ticket_attendee['last_name'];
								$join_url = '#';
								if (!empty($EM_Booking->booking_meta['attendees'][$ticket_id][$attendee_key][static::$type]['join_url'])) {
									$join_url = esc_url($EM_Booking->booking_meta['attendees'][$ticket_id][$attendee_key][static::$type]['join_url']);
									$join_url = '<a href="' . $join_url . '">' . sprintf(esc_html__('Join %s', 'events-manager-zoom'), static::$zoom_name_singular) . '</a>';
								}
								$join_urls[] = $name . ' - ' . $join_url;
							}
						}
						echo implode("<br>", $join_urls);
					} else {
						$join_url = $EM_Booking->output('#_BOOKINGZOOMJOINURL');
						echo '<a href="'. $join_url .'">'. $join_url .'</a>';
					}
				}elseif( $EM_Booking->booking_status == 1 ){
					// status is approved here, but not on Zoom, something went wrong so advise to resave booking
					$resync_link = add_query_arg( array(
						'resync_zoom_booking' => wp_create_nonce('resync_zoom_booking_'.$EM_Booking->booking_id),
						'zoom_type' => static::$type,
					));
					?>
					<div>
						<p><em>
								<?php esc_html_e('Something is wrong, a related Zoom registration cannot be found. To correct this, you can resync this booking with Zoom.', 'events-manager-zoom'); ?>
							</em></p>
						<a href="<?php echo esc_url($resync_link); ?>" class="button-secondary"><?php esc_html_e('Synchronize booking on zoom.com', 'events-manager-zoom'); ?></a>
					</div>
					<?php
				}else{
					// booking not approved yet, no info about registrant
					echo "<p><em>". esc_html(sprintf(__('Once this booking has been approved, a registration will be created on the Zoom %s.', 'events-manager-zoom'), static::$zoom_name_singular)) ."</em></p>";
				}
				?>
			</div>
		</div>
		<?php
	}
	
	/**
	 * @param array $ticket_attendee
	 * @param int $attendee_key
	 * @param int $ticket_id
	 * @param \EM_Booking $EM_Booking
	 * @param int $meeting_id
	 * @param Zoom_API_Client $zoom_client
	 * @return bool
	 */
	public static function register_attendee( $ticket_attendee, $attendee_key, $ticket_id, $EM_Booking, $meeting_id, $zoom_client ){
		$zoom_registrant = array (
			// custom ticket type
			'custom_questions' => array(
				array(
					'title' => __('Ticket Name', 'events-manager'),
					'value' => $EM_Booking->get_tickets()->tickets[$ticket_id]->ticket_name,
				),
			)
		);
		$attendee_form = EM_Attendees_Form::get_form( $EM_Booking->get_event() );
		$questions = static::get_zoom_registrant_questions();
		foreach( $ticket_attendee as $field_id => $field_value ){
			if( in_array( $field_id, array('email', 'first_name', 'last_name') )){
				// required fields
				$zoom_registrant[$field_id] = $field_value;
			}elseif( array_key_exists($field_id, $questions) ){
				// add additional fields
				if( !is_array($questions[$field_id]) || in_array($field_value, $questions[$field_id]) ) {
					$zoom_registrant[$field_id] = $field_value;
				}
			}else{
				// add custom fields
				$zoom_registrant['custom_questions'][] = array(
					'title' => $attendee_form->form_fields[$field_id]['label'],
					'value' => $attendee_form->get_formatted_value( $attendee_form->form_fields[$field_id], $field_value ),
				);
			}
		}
		try{
			$EM_Booking->booking_meta['attendees'][$ticket_id][$attendee_key][static::$type] = $zoom_client->add_registrant( $zoom_registrant, $meeting_id, static::$zoom_api_base );
			return true;
		}catch( EM_Exception $ex ){
			if( class_exists('\EMP_Logs') ) \EMP_Logs::log( $ex->get_message() , 'zoom');
			return false;
		}
	}
	
	/**
	 * @param bool $result
	 * @param \EM_Booking $EM_Booking
	 * @return bool
	 */
	public static function em_booking_set_status( $result, $EM_Booking ){
		return static::em_booking_save( $result, $EM_Booking );
	}
	
	/**
	 * @param \EM_Booking $EM_Booking
	 * @return bool
	 */
	public static function em_booking_deleted( $EM_Booking ){
		$EM_Booking->booking_status = 3; // force a booking cancellation on Zoom
		return static::em_booking_save( true, $EM_Booking );
	}
	
	public function supports_attendee_form(){
		if( class_exists('EM_Attendees_Form') ){ // Pro feature
			// check if attendee form has the required fields as a minimum
			$attendee_form = EM_Attendees_Form::get_form( $this->event );
			if( !empty($attendee_form->form_fields['email']) && !empty($attendee_form->form_fields['first_name']) && !empty($attendee_form->form_fields['last_name']) ){
				return true;
			}
		}
		return false;
	}
	
	public function output($what = null, $target = null) {
		if( $what == 'start_url' && !($this->event->can_manage('edit_events','edit_others_events') || $target == 'email') ){
			return '#'; // return empty link if this is a start_url without perms
		}
		return parent::output($what);
	}
	
	public function get_ical_location(){
		if( $this->ical_location === true ) {
			return $this->join_url;
		}
		return false;
	}
}
Zoom_Meeting::init();