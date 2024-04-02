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
 * @property string last_questions_hash The laast md5 hash of values for meeting registrant questions, used in comparison for form updates.
 * @property string start_url           The URL a host can use to join the meeting. Generated ad-hoc and should only be displayed to event admins.
 * @property array settings             The settings array sent to create/modify a meeting via the API 'settings' variable.
 */
class Zoom_Meeting extends Event_Location {
	
	public static $type = 'zoom_meeting';
	public static $admin_template = '/forms/event/event-locations/url.php';
	
	public $properties = array('id', 'join_url', 'password', 'registration_url', 'last_hash', 'last_questions_hash', 'settings');
	/**
	 * @var int Specific meeting type defined by Zoom API for creating things like webinars or meetings
	 */
	public static $zoom_api_type = 2;
	public static $zoom_api_base = 'meetings';
	public static $zoom_admin_url_base = 'meeting';
	
	public function __construct($EM_Event) {
		parent::__construct($EM_Event);
	}
	
	public static function init(){
		parent::init();
		// add listeners for bookings
		$class = get_called_class();
		add_filter('em_event_delete_meta', $class.'::em_event_delete_meta', 10, 2);
		add_filter('em_booking_save', $class.'::em_booking_save', 10, 2);
		add_filter('em_booking_set_status', $class.'::em_booking_set_status', 10, 2);
		add_filter('em_booking_deleted', $class.'::em_booking_deleted', 10, 1);
		add_action('em_enqueue_admin_styles', $class.'::settings_page_scripts');
		add_action('em_enqueue_styles', $class.'::settings_page_scripts');
		add_action('em_booking_output_placeholder', $class.'::em_booking_output_placeholder', 10, 3);
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
		}elseif( $var == 'password' ){
			return !empty($this->settings['password']) ? $this->settings['password'] : null;
		}
		return parent::__get($var);
	}
	
	public function __set( $var, $value ){
		if( $var == 'password' ){
			$this->event->event_location_data['settings']['password'] = $value;
		}else{
			parent::__set( $var, $value );
		}
	}
	
	/**
	 * @param null $post deprecated, left to prevent warning due to removed $post arg from parent in 5.9.7.8
	 * @return bool
	 */
	public function get_post( $post = null ){
		// if current event already has a meeting, reset settings
		if( !$this->event->has_event_location(static::$type) ){
			parent::get_post();
		}
		// get settings
		if( !empty($_POST['event_location_zoom_meeting_settings']) ){
			if( empty($this->event->event_location_data['settings']) ) $this->event->event_location_data['settings'] = array();
			$admin_class = static::load_admin_class(); /* @var Zoom_Meeting_Admin $admin_class */
			$field_settings = $admin_class::admin_fields_settings( $this->event );
			foreach( $field_settings['fields'] as $field_key => $field_props ){
				$post_value = isset($_POST['event_location_zoom_meeting_settings'][$field_key]) ? sanitize_text_field($_POST['event_location_zoom_meeting_settings'][$field_key]) : null;
				switch( $field_props['type'] ){
					case 'text':
						$this->event->event_location_data['settings'][$field_key] = trim($post_value);
						break;
					case 'select':
						if( !empty($field_props['values'][$post_value]) ){
							$this->event->event_location_data['settings'][$field_key] = $post_value;
						}
						break;
					case 'boolean':
						$this->event->event_location_data['settings'][$field_key] = !empty($post_value);
						break;
				}
			}
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
					if( !(empty($this->settings[$field_key]) && !empty($field['multiple'])) ){
						if( !isset($field['values'][$this->settings[$field_key]]) ){
							$error = sprintf( __('The Zoom settings field %s does not have a valid value selected.', 'events-manager-zoom'), '<code>'.$field['label'].'</code>' );
							$this->event->add_error($error);
							$result = false;
						}
					}
					break;
			}
		}
		return $result;
	}
	
	public function save(){
		$result = true;
		// validate first, and save settings regardless
		if( !$this->validate() ){
			parent::save();
			return false;
		}
		// check for a valid room ID, and if so populate the info about the room.
		$meeting = $this->get_meeting_request_settings();
		if( !empty($this->id) ){
			$skip_create = !empty($this->event->event_location_data['last_hash']) && md5( var_export($meeting, true) ) === $this->event->event_location_data['last_hash'];
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
						$this->password = $this->event->event_location_data['settings']['password'] = $zoom_meeting->password;
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
				$this->event->event_location_data['last_hash'] = md5( var_export($meeting, true) );
				if( $this->event->event_rsvp ){
					// now update Zoom questions based on Pro custom forms, will throw exception of something goes wrong
					$this->update_questions( $zoom_client );
				}
			}
		}catch( EM_Exception $ex ){
			$error = __('Could not create or update Zoom Meeting due to the following error: %s', 'events-manager-zoom');
			$error = sprintf(esc_html($error), '<code>'.$ex->getMessage().'</code>');
			$this->event->add_error( $error );
			$result = false;
		}
		parent::save();
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
		$meeting['settings']['registration_type'] = 2; // registration type should lock users to the one meeting/webinar since we don't have occurrence/recurrences (yet?)
		// get timezone, and if no corresponding Zoom timezone exists, leave it blank so that UTC is used
		$zoom_timezones = array('Pacific/Midway','Pacific/Pago_Pago','Pacific/Honolulu','America/Anchorage','America/Vancouver','America/Los_Angeles','America/Tijuana','America/Edmonton','America/Denver','America/Phoenix','America/Mazatlan','America/Winnipeg','America/Regina','America/Chicago','America/Mexico_City','America/Guatemala','America/El_Salvador','America/Managua','America/Costa_Rica','America/Montreal','America/New_York','America/Indianapolis','America/Panama','America/Bogota','America/Lima','America/Halifax','America/Puerto_Rico','America/Caracas','America/Santiago','America/St_Johns','America/Montevideo','America/Araguaina','America/Argentina/Buenos_Aires','America/Godthab','America/Sao_Paulo','Atlantic/Azores','Canada/Atlantic','Atlantic/Cape_Verde','UTC','Etc/Greenwich','Europe/Belgrade','CET','Atlantic/Reykjavik','Europe/Dublin','Europe/London','Europe/Lisbon','Africa/Casablanca','Africa/Nouakchott','Europe/Oslo','Europe/Copenhagen','Europe/Brussels','Europe/Berlin','Europe/Helsinki','Europe/Amsterdam','Europe/Rome','Europe/Stockholm','Europe/Vienna','Europe/Luxembourg','Europe/Paris','Europe/Zurich','Europe/Madrid','Africa/Bangui','Africa/Algiers','Africa/Tunis','Africa/Harare','Africa/Nairobi','Europe/Warsaw','Europe/Prague','Europe/Budapest','Europe/Sofia','Europe/Istanbul','Europe/Athens','Europe/Bucharest','Asia/Nicosia','Asia/Beirut','Asia/Damascus','Asia/Jerusalem','Asia/Amman','Africa/Tripoli','Africa/Cairo','Africa/Johannesburg','Europe/Moscow','Asia/Baghdad','Asia/Kuwait','Asia/Riyadh','Asia/Bahrain','Asia/Qatar','Asia/Aden','Asia/Tehran','Africa/Khartoum','Africa/Djibouti','Africa/Mogadishu','Asia/Dubai','Asia/Muscat','Asia/Baku','Asia/Kabul','Asia/Yekaterinburg','Asia/Tashkent','Asia/Calcutta','Asia/Kathmandu','Asia/Novosibirsk','Asia/Almaty','Asia/Dacca','Asia/Krasnoyarsk','Asia/Dhaka','Asia/Bangkok','Asia/Saigon','Asia/Jakarta','Asia/Irkutsk','Asia/Shanghai','Asia/Hong_Kong','Asia/Taipei','Asia/Kuala_Lumpur','Asia/Singapore','Australia/Perth','Asia/Yakutsk','Asia/Seoul','Asia/Tokyo','Australia/Darwin','Australia/Adelaide','Asia/Vladivostok','Pacific/Port_Moresby','Australia/Brisbane','Australia/Sydney','Australia/Hobart','Asia/Magadan','SST','Pacific/Noumea','Asia/Kamchatka','Pacific/Fiji','Pacific/Auckland','Asia/Kolkata','Europe/Kiev','America/Tegucigalpa','Pacific/Apia');
		if( in_array( $this->event->get_timezone()->getName(), $zoom_timezones) ){
			$meeting['timezone'] = $this->event->get_timezone()->getName();
		}
		return apply_filters('em_event_location_zoom_meeting_settings', $meeting, $this);
	}
	
	/**
	 * @param Zoom_API_Client $zoom_client
	 * @param bool $save
	 * @throws EM_Exception
	 */
	public function update_questions( $zoom_client = null ){
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
								$countries = em_get_countries();
								$zoom_registrant['country'] = $countries[EM_Gateways::get_customer_field('country', $EM_Booking)];
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
						$EM_Booking->booking_meta[static::$type] = $zoom_client->add_registrant( $zoom_registrant, $meeting_id, static::$zoom_api_base );
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
				if( !empty($EM_Booking->booking_meta[static::$type]) ){
					if( $EM_Booking->booking_meta[static::$type] == 'attendees' && $_this->supports_attendee_form() ){
						foreach( $EM_Booking->booking_meta['attendees'] as $ticket_id =>  $ticket_attendees ){ /* @var $EM_Ticket_Booking \EM_Ticket_Booking */
							foreach( $ticket_attendees as $attendee_key => $ticket_attendee ){
								// go through each attendee and add by name and join URL
								$name = $ticket_attendee['first_name'] . ' ' . $ticket_attendee['last_name'];
								$join_url = '#';
								if( !empty($EM_Booking->booking_meta['attendees'][$ticket_id][$attendee_key][static::$type]['join_url']) ){
									$join_url = esc_url($EM_Booking->booking_meta['attendees'][$ticket_id][$attendee_key][static::$type]['join_url']);
									if( $full_result == '#_BOOKINGZOOMJOINLINK' ){
										$join_url = '<a href="'.$join_url.'">'. esc_html__('Join Meeting', 'events-manager-zoom'). '</a>';
									}
								}
								$join_urls[] = $name .' - '. $join_url;
							}
						}
						$replace = implode("\r\n", $join_urls);
					}else{
						if( !empty($EM_Booking->booking_meta[static::$type]['join_url']) ){
							$replace = esc_url($EM_Booking->booking_meta[static::$type]['join_url']);
							if( $full_result == '#_BOOKINGZOOMJOINLINK' ){
								$replace = '<a href="'.$replace.'">'. esc_html__('Join Meeting', 'events-manager-zoom'). '</a>';
							}
						}
					}
				}
			}
		}
		return $replace;
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
}
Zoom_Meeting::init();