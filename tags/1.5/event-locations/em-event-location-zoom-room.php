<?php
namespace EM_Event_Locations;
use EM_OAuth\Zoom_API, EM_Exception;
/**
 * Adds a URL event location type by extending EM_Event_Location and registering itself with EM_Event_Locations
 *
 * @property string id          The unique of this zoom room.
 * @property string room_id     The global unique id of this zoom_room.
 * @property string name        The room name.
 */
class Zoom_Room extends Event_Location {
	
	public static $type = 'zoom_room';
	public static $admin_template = '/forms/event/event-locations/url.php';
	
	public $properties = array('id', 'room_id', 'name' );
	
	/**
	 * @param null $post deprecated, left to prevent warning due to removed $post arg from parent in 5.9.7.8
	 * @return bool
	 */
	public function get_post( $post = null ){
		$return = parent::get_post();
		if( !empty($_POST['zoom_room_id']) && preg_match('/^[a-zA-Z0-9\-_]+$/', $_POST['zoom_room_id']) ){
			$this->data['id'] = sanitize_text_field($_POST['zoom_room_id']);
		}
		return $return;
	}
	
	public function validate(){
		$result = false;
		if( empty($this->data['id']) ){
			$this->event->add_error( __('You must select a Zoom Room.', 'events-manager-zoom') );
			$result = false;
		}else{
			//check for a valid room ID, and if so populate the info about the room.
			try{
				$zoom_client = Zoom_API::get_client();
				$zoom_rooms = $zoom_client->get('/rooms');
				if( !empty($zoom_rooms['body']->rooms) ){
					foreach( $zoom_rooms['body']->rooms as $room ){
						if( $room->id == $this->id ){
							$result = true;
							$this->data['room_id'] = sanitize_text_field($room->room_id);
							$this->data['name'] = sanitize_text_field($room->name);
						}
					}
				}
				if( !$result ){
					$this->event->add_error( __('Could not find selected Zoom Room.', 'events-manager-zoom') );
				}
			}catch( EM_Exception $e ){
				$error = __('Could not select Zoom Room due to the following error: %s', 'events-manager-zoom');
				$error = sprintf(esc_html($error), '<code>'.$e->get_message().'</code>');
				$this->event->add_error( $error );
			}
		}
		return $result;
	}
	
	public function get_link( $new_target = true ){
		return '<a href="'.esc_url($this->room_id).'">'. esc_html($this->text).'</a>';
	}
	
	public function get_admin_column() {
		return '<strong>'. static::get_label() .'</strong><br>'.$this->name;
	}
	
	public static function get_label( $label_type = 'singular' ){
		switch( $label_type ){
			case 'plural':
				return esc_html__('Zoom Rooms', 'events-manager-zoom');
				break;
			case 'singular':
				return esc_html__('Zoom Room', 'events-manager-zoom');
				break;
		}
		return parent::get_label($label_type);
	}
	
	public function output( $what = null, $target = null ){
		if( $what === null){
			return esc_html($this->data['name']);
		}
		return parent::output($what);
	}
	
	/**
	 * Loads admin template automatically if static $admin_template is set to a valid path in templates folder.
	 * Classes with custom forms outside of template folders can override this function and provide their own HTML that will go in the loop of event location type forms.
	 */
	public static function load_admin_template(){
		global $EM_Event;
		try{
			$zoom_client = Zoom_API::get_client();
			$zoom_response = $zoom_client->get('/rooms');
			$zoom_rooms = $zoom_response['body'];
			$room_id = !empty($EM_Event->event_location_data['id']) && $EM_Event->has_event_location(static::$type) ? $EM_Event->event_location_data['id'] : 0;
			if( !empty($zoom_rooms->rooms) ){
				echo '<p>'.esc_html__('Select a Zoom Room from the list below. You can then list details about the Zoom Room in your events.', 'events-manager-zoom').'</p>';
				echo '<p>'.esc_html__('Please note that due to API limitations it is not possible to manage invitations/bookings for Rooms via Events Manager.', 'events-manager-zoom').'</p>';
				?>
				<select name="zoom_room_id" aria-label="<?php esc_attr_e('Select a Zoom Room', 'events-manager-zoom') ?>">
					<option value="0"><?php esc_html_e('Select a Zoom Room', 'events-manager-zoom') ?></option>
					<?php
					foreach( $zoom_rooms->rooms as $room ){
						$selected = $room->id == $room_id ? 'selected':'';
						echo '<option value="'. esc_attr($room->id). '" '.$selected.'>'.esc_html($room->name).'</option>';
					}
				?>
				</select>
				<?php
			}else{
				echo '<p><em>'. esc_html__('No Zoom Rooms found for connected account.', 'events-manager-zoom') . '</em></p>';
			}
		}catch( EM_Exception $e ){
			$message = __('Could not load Zoom Rooms due to the following error: %s', 'events-manager-zoom');
			$message = sprintf(esc_html($message), '<code>'.$e->get_message().'</code>');
			echo '<p><em>'. $message . '</p></em>';
		}
	}
}
Zoom_Room::init();