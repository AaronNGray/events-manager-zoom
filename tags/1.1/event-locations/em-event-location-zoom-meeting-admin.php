<?php
namespace EM_Event_Locations;
use EM_OAuth\Zoom_API, EM_Exception;

/**
 * Class used by Zoom Event Locations for admin area functions
 */
class Zoom_Meeting_Admin {
	
	private static $js_init;
	protected static $static_class = 'EM_Event_Locations\Zoom_Meeting';
	
	/**
	 * Loads admin template automatically if static $admin_template is set to a valid path in templates folder.
	 * Classes with custom forms outside of template folders can override this function and provide their own HTML that will go in the loop of event location type forms.
	 */
	public static function load_admin_template(){
		global $EM_Event;
		$zoom_location = $EM_Event->get_event_location(); /* @var Zoom_Meeting $zoom_location */
		$static_class = static::$static_class; /* @var Zoom_Meeting $static_class */
		try{
			Zoom_API::get_client();
			if( $EM_Event->has_event_location($static_class::$type) && !empty($zoom_location->id) ){
				// zoom meeting alraedy created
				echo "<p><em>". esc_html__('A Zoom Meeting has been created for this event, updating the event information will automatically update the meeting.', 'events-manager-zoom') . '</em></p>';
				echo '<ul>';
				$zoom_admin_link = '<a href="https://zoom.us/'.$zoom_location::$zoom_admin_url_base.'/'.$zoom_location->id.'" target="_blank">'. __('View/Edit on Zoom.us', 'events-manager-zoom').'</a>';
				echo "<li>". sprintf(esc_html__('Meeting ID : %s'), $zoom_location->id . ' - ' . $zoom_admin_link) . '</li>';
				if( isset($zoom_location->settings['approval_type']) && $zoom_location->settings['approval_type'] != 2 ){
					$registration_link = '<a href="'.esc_url($zoom_location->registration_url).'" target="_blank">'. __('Registration URL', 'events-manager-zoom').'</a>';
					$registration_desc = esc_html__('This link will allow users to directly register via Zoom.us. You can display this link using the %s placeholder. Sharing this link when bookings are enabled will allow users to register on Zoom without registering (and paying if there is a booking fee) on your site.', 'events-manager-zoom');
					echo "<li>". $registration_link. ' - <em>'. sprintf($registration_desc, '<code>#_EVENTLOCATION{registration_url}</code>') .'</em></li>';
				}
				// Join link
				$join_link = '<a href="'.esc_url($zoom_location->join_url).'" target="_blank">'.esc_html__('Join URL', 'events-manager-zoom').'</a>';
				$join_desc = esc_html__('This offers direct access to the Zoom meeting/webinar. Only share with users that do not require registration, via the %s placeholder.', 'events-manager-zoom');
				echo '<li>'.$join_link.' - <em>'. sprintf($join_desc, '<code>#_EVENTLOCATION{join_url}</code>').'</em></li>';
				// Sign In URL
				$start_link = '<a href="'.esc_url($zoom_location->start_url).'" target="_blank">'.esc_html__('Start Meeting URL', 'events-manager-zoom').'</a>';
				$start_desc = esc_html__('DO NOT SHARE this with regular users. This link will allow you to start the meeting as a host, and can be output via the %s placeholder if needed.', 'events-manager-zoom');
				echo '<li>'.$start_link.' - <em>'. sprintf($start_desc, '<code>#_EVENTLOCATION{start_url}</code>').'</em></li>';
				echo '</ul>';
				$zoom_settings = $zoom_location->settings;
			}else{
				// no meeting yet
				echo "<p><em>". esc_html__('A Zoom Meeting will be created automatically for this event.', 'events-manager-zoom') . '</em></p>';
				$zoom_settings = array();
			}
			?>
			<a href="#" class="em-event-location-zoom-advanced-settings-toggle" data-toggletext="<?php esc_html_e('hide advanced settings', 'events-manager-zoom'); ?>"><?php esc_html_e('show advanced settings', 'events-manager-zoom'); ?></a>
			<div class="em-event-location-zoom-advanced-settings">
				<?php
				$field_settings =  static::admin_fields_settings( $EM_Event );
				foreach( $field_settings['groups'] as $group_name => $fields ){
					echo '<h4>'. $group_name .'</h4>';
					foreach( $fields as $field_id ){
						$field = $field_settings['fields'][$field_id];
						$field['key'] = $field_id;
						if( isset($zoom_settings[$field_id]) ) $field['value'] = $zoom_settings[$field_id];
						switch( $field['type'] ){
							case 'text':
								static::admin_field_input($field);
								break;
							case 'boolean':
								static::admin_field_boolean($field);
								break;
							case 'select':
								static::admin_field_select($field);
								break;
						}
					}
				}
				?>
			</div>
			<?php
			static::admin_js();
		}catch( EM_Exception $e ){
			$message = __('Zoom is not properly set up: %s', 'events-manager-zoom');
			$message = sprintf(esc_html($message), '<code>'.$e->get_message().'</code>');
			echo '<p><em>'. $message . '</p></em>';
		}
	}
	
	public static function admin_js(){
		if( !self::$js_init ){
			?>
			<script type="text/javascript">
				jQuery(document).ready(function($){
					$('.zoom-location-setting-multiple').select2();
					$('.em-event-location-zoom-advanced-settings').hide();
					$('.em-event-location-zoom-advanced-settings-toggle').click( function(e){
						e.preventDefault();
						let el = $(this);
						let toggle_text = el.data('toggletext');
						$(this).data('toggletext', el.text());
						el.text(toggle_text)
						$('.em-event-location-zoom-advanced-settings').slideToggle();
					});
				});
			</script>
			<?php
			self::$js_init = true;
		}
	}
	
	public static function admin_field_input( $field ){
		$class = static::$static_class;
		$type = $class::$type;
		$field_default = array('key' => '', 'label' => '', 'value' => '', 'description' => false, 'placeholder' => '');
		$field = array_merge( $field_default, $field );
		?>
		<div class="em-input-field em-input-field-input">
			<label for="zoom-<?php echo $type; ?>-location-setting-<?php echo $field['key']; ?>"><?php echo esc_html($field['label']); ?></label>
			<input type="text" id="zoom-<?php echo $type; ?>-location-setting-<?php echo $field['key']; ?>" name="event_location_zoom_meeting_settings[<?php echo $field['key'] ?>]" value="<?php echo esc_attr($field['value']); ?>">
			<?php if( $field['description'] ): ?>
			<em class="em-em-input-field-description"><?php echo $field['description']; ?></em>
			<?php endif; ?>
		</div>
		<?php
	}
	
	public static function admin_field_select( $field ){
		$class = static::$static_class;
		$type = $class::$type;
		$field_default = array('key' => '', 'label' => '', 'value' => '', 'description' => false, 'placeholder' => '', 'multiple' => false, 'values' => array());
		$field = array_merge( $field_default, $field );
		?>
		<p class="em-input-field em-input-field-select">
			<label for="zoom-<?php echo $type; ?>-location-setting-<?php echo $field['key']; ?>"><?php echo esc_html($field['label']); ?></label>
			<select id="zoom-<?php echo $type; ?>-location-setting-<?php echo $field['key']; ?>" name="event_location_zoom_meeting_settings[<?php echo $field['key'] ?>]" <?php if( $field['multiple'] ) echo 'multiple class="zoom-location-setting-multiple"'; ?>>
				<?php foreach( $field['values'] as $option_value => $option_label ): ?>
				<?php $selected = $option_value == $field['value'] || (is_array($field['value']) && in_array($option_value, $field['value'])); ?>
				<option <?php if( $selected ) echo 'selected="selected"'; ?> value="<?php echo esc_attr($option_value); ?>"><?php echo esc_html($option_label); ?></option>
				<?php endforeach; ?>
			</select>
			<?php if( $field['description'] ): ?>
				<em class="em-em-input-field-description"><?php echo $field['description']; ?></em>
			<?php endif; ?>
		</p>
		<?php
	}
	
	public static function admin_field_boolean( $field ){
		$class = static::$static_class;
		$type = $class::$type;
		$field_default = array('key' => '', 'label' => '', 'value' => '', 'description' => false, 'placeholder' => '');
		$field = array_merge( $field_default, $field );
		?>
		<div class="em-input-field em-input-field-boolean">
			<input type="checkbox" id="zoom-<?php echo $type; ?>-location-setting-<?php echo $field['key']; ?>" name="event_location_zoom_meeting_settings[<?php echo $field['key'] ?>]" <?php if( $field['value'] ) echo 'checked'; ?>>
			<label for="zoom-<?php echo $type; ?>-location-setting-<?php echo $field['key']; ?>"><?php echo esc_html($field['label']); ?></label>
			<?php if( $field['description'] ): ?>
				<em class="em-em-input-field-description"><?php echo $field['description']; ?></em>
			<?php endif; ?>
		</div>
		<?php
	}
	
	/**
	 * @param \EM_Event $EM_Event
	 * @return mixed|void
	 */
	public static function admin_fields_settings( $EM_Event ){
		$return['groups'] = array(
			__('Meeting Options', 'events-manager-zoom') => array('join_before_host', 'mute_upon_entry', 'watermark', 'use_pmi', 'waiting_room', 'allow_multiple_devices'),
			__('Host Options', 'events-manager-zoom') => array('alternative_hosts', 'contact_name', 'contact_email'),
			__('Registration', 'events-manager-zoom') => array('approval_type', 'close_registration', 'meeting_authentication', 'authentication_domains'),
			__('Password', 'events-manager-zoom') => array('password'),
			__('Emails', 'events-manager-zoom') => array('registrants_email_notification', 'registrants_confirmation_email'),
			__('Video', 'events-manager-zoom') => array('host_video', 'participant_video', 'auto_recording'),
			__('Audio', 'events-manager-zoom') => array('audio', 'global_dial_in_countries'),
		);
		$zoom_countries = array('AD','AE','AF','AG','AI','AL','AM','AN','AO','AQ','AR','AS','AT','AU','AW','AX','AZ','BA','BB','BD','BE','BF','BG','BH','BI','BJ','BM','BN','BO','BR','BS','BT','BV','BW','BY','BZ','CA','CD','CF','CG','CH','CI','CK','CL','CM','CN','CO','CR','CS','CU','CV','CY','CZ','DE','DJ','DK','DM','DO','DZ','EC','EE','EG','ER','ES','ET','FI','FJ','FK','FM','FO','FR','GA','GB','GD','GE','GF','GG','GH','GI','GL','GM','GN','GP','GQ','GR','GS','GT','GU','GW','GY','HK','HN','HR','HT','HU','ID','IE','IL','IM','IN','IO','IQ','IR','IS','IT','JE','JM','JO','JP','KE','KG','KH','KI','KM','KN','KP','KR','KW','KY','KZ','LA','LB','LC','LI','LK','LR','LS','LT','LU','LV','LY','MA','MC','MD','ME','MF','MG','MH','MK','ML','MM','MN','MO','MP','MQ','MR','MS','MT','MU','MV','MW','MX','MY','MZ','NA','NC','NE','NF','NG','NI','NL','NO','NP','NR','NU','NZ','OM','PA','PE','PF','PG','PH','PK','PL','PM','PR','PS','PT','PW','PY','QA','RE','RO','RS','RU','RW','SA','SB','SC','SD','SE','SG','SI','SK','SL','SM','SN','SO','SR','SS','ST','SV','SY','SZ','TC','TD','TF','TG','TH','TJ','TK','TL','TM','TN','TO','TR','TT','TV','TW','TZ','UA','UG','UK','UM','US','UY','UZ','VA','VC','VE','VG','VI','VN','VU','WF','WS','YE','YT','ZA','ZM','ZW');
		$em_countries = em_get_countries();
		$countries = array_intersect_key($em_countries, array_flip($zoom_countries));
		$return['fields'] = array(
			'approval_type' => array(
				'label' => esc_html__('Require Registration?', 'events-manager-zoom'),
				'value' => 1,
				'type' => 'select',
				'values' => array(
					0 => esc_html__('Registration required, automatic approval.', 'events-manager-zoom'),
					1 => esc_html__('Registration required, manual approval.', 'events-manager-zoom'),
					2 => esc_html__('No registration required')
				),
				'description' => esc_html__('If required, you can either limit registrations to the event booking form, or link visitors directly to the Zoom registration page (link available on zoom meeting settings page). Registrations made on Zoom.com will not appear here. If no registration is required, you can display the Join URL using the placeholder #_EVENTLOCATION{join_url}.', 'events-manager-zoom'),
			),
			'contact_name' => array(
				'label' => esc_html__('Contact name', 'events-manager-zoom'),
				'value' => $EM_Event->get_contact()->get_name(),
				'type' => 'text',
				'description' => __('Contact name for registration.', 'events-manager-zoom'),
			),
			'contact_email' => array(
				'label' => esc_html__('Contact email', 'events-manager-zoom'),
				'value' => $EM_Event->get_contact()->user_email,
				'type' => 'text',
				'description' => __('Contact email for registration.', 'events-manager-zoom'),
			),
			'registrants_confirmation_email' => array(
				'label' => esc_html__('Send Confirmation Email to Registrants', 'events-manager-zoom'),
				'value' => false,
				'type' => 'boolean',
			),
			'registrants_email_notification' => array(
				'label' => esc_html__('Registrants Email Notification', 'events-manager-zoom'),
				'value' => false,
				'type' => 'boolean',
				'description' => esc_html__('Send email notifications to registrants about approval, cancellation, denial of the registration. The value of this field must be set to true in order to use the  field.', 'events-manager-zoom'),
			),
			'host_video' => array(
				'label' => esc_html__('Host Video', 'events-manager-zoom'),
				'value' => false,
				'type' => 'boolean',
				'description' => esc_html__('Start video when the host joins the meeting.', 'events-manager-zoom'),
			),
			'password' => array(
				'label' => esc_html__('Passcode', 'events-manager-zoom'),
				'value' => substr(wp_generate_uuid4(), 0, 10),
				'type' => 'text',
				'description' => esc_html__('Passcode may only contain the following characters: [a-z A-Z 0-9 @ - _ * !]. Max of 10 characters.', 'events-manager-zoom'),
			),
			'participant_video' => array(
				'label' => esc_html__('Participant Video', 'events-manager-zoom'),
				'value' => false,
				'type' => 'boolean',
				'description' => esc_html__('Start video when participants join the meeting.', 'events-manager-zoom'),
			),
			'join_before_host' => array(
				'label' => esc_html__('Enable join before host', 'events-manager-zoom'),
				'value' => false,
				'type' => 'boolean',
				'description' => esc_html__('Allow participants to join the meeting before the host starts the meeting.', 'events-manager-zoom'),
			),
			'mute_upon_entry' => array(
				'label' => esc_html__('Mute participants upon entry', 'events-manager-zoom'),
				'value' => false,
				'type' => 'boolean',
			),
			'watermark' => array(
				'label' => esc_html__('Watermark', 'events-manager-zoom'),
				'value' => false,
				'type' => 'boolean',
				'description' => esc_html__('Add watermark when viewing a shared screen.', 'events-manager-zoom'),
			),
			'use_pmi' => array(
				'label' => esc_html__('Use Personal Meeting ID', 'events-manager-zoom'),
				'value' => false,
				'type' => 'boolean',
				'description' => esc_html__('Use Personal Meeting ID instead of an automatically generated meeting ID.', 'events-manager-zoom'),
			),
			'audio' => array(
				'label' => esc_html__('Audio recording', 'events-manager-zoom'),
				'value' => 'both',
				'type' => 'select',
				'values' => array(
					'both' => __('Both Telephony and VoIP', 'events-manager-zoom'),
					'telephony' => __('Telephony only', 'events-manager-zoom'),
					'voip' => __('VoIP only', 'events-manager-zoom'),
				),
				'description' => esc_html__('Determine how participants can join the audio portion of the meeting.', 'events-manager-zoom'),
			),
			'auto_recording' => array(
				'label' => esc_html__('Automatic recording', 'events-manager-zoom'),
				'value' => 'none',
				'type' => 'select',
				'values' => array(
					'none' => __('Disabled', 'events-manager-zoom'),
					'local' => __('Record on local', 'events-manager-zoom'),
					'cloud' => __('Record on cloud', 'events-manager-zoom'),
				),
			),
			'alternative_hosts' => array(
				'label' => esc_html__('Alternative Hosts', 'events-manager-zoom'),
				'value' => '',
				'type' => 'text',
				'description' => __('Alternative host’s emails or IDs: multiple values separated by a comma.', 'events-manager-zoom'),
			),
			'close_registration' => array(
				'label' => esc_html__('Close registration after meeting date', 'events-manager-zoom'),
				'value' => false,
				'type' => 'boolean',
			),
			'waiting_room' => array(
				'label' => esc_html__('Enable waiting room', 'events-manager-zoom'),
				'value' => true,
				'type' => 'boolean',
			),
			'meeting_authentication' => array(
				'label' => esc_html__('Only authenticated users can join', 'events-manager-zoom'),
				'value' => false,
				'type' => 'boolean',
			),
			'authentication_domains' => array(
				'label' => esc_html__('Authentication Domains', 'events-manager-zoom'),
				'value' => '',
				'type' => 'text',
				'description' => esc_html__('Meeting authentication domains. This option, allows you to specify the rule so that Zoom users, whose email address contains a certain domain, can join the meeting. You can either provide multiple domains, using a comma in between and/or use a wildcard for listing domains.', 'events-manager-zoom'),
			),
			'allow_multiple_devices' => array(
				'label' => esc_html__('Allow Multiple Devices', 'events-manager-zoom'),
				'value' => false,
				'type' => 'boolean',
				'description' => esc_html__('Allow attendees to join from multiple devices.', 'events-manager-zoom'),
			),
			'global_dial_in_countries' => array(
				'label' => esc_html__('Global dial-in countries', 'events-manager-zoom'),
				'value' => '',
				'type' => 'select',
				'multiple' => true,
				'values' => $countries,
				'description' => esc_html__('Select countries users can dial-in from. This information will be displayed on Zoom confirmation emails.', 'events-manager-zoom'),
			),
		);
		$webinar = array(
			'panelists_video' => array(
				'label' => esc_html__('Panelists Video', 'events-manager-zoom'),
				'value' => false,
				'type' => 'boolean',
				'description' => esc_html__('Allow attendees to join from multiple devices.', 'events-manager-zoom'),
			),
			'hd_video' => true,
			'registration_type' => 2,
			'show_share_button' => true,
			'on_demand' => false,
			'post_webinar_survey' => false,
			'survey_url' => null, //string
		);
		return apply_filters('em_event_location_zoom_meeting_admin_fields_settings', $return);
	}
}