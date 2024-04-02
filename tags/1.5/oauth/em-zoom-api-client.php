<?php
namespace EM_OAuth;
use EM_Exception, EM_Zoom;

class Zoom_API_Client extends OAuth_API_Client {

	/**
	 * We add the scope here so it doesn't produce issues with the parent constructor.
	 * @var string
	 */
	public $scope = 'basic+ageless+event_management';
	public $api_base = 'https://api.zoom.us/v2';
	public $oauth_authorize_url = 'https://zoom.us/oauth/authorize?client_id=CLIENT_ID&response_type=code&redirect_uri=REDIRECT_URI';
	public $oauth_request_token_url = 'https://zoom.us/oauth/token';
	public $oauth_refresh_token_url = 'https://zoom.us/oauth/token';
	public $oauth_verification_url = 'https://api.zoom.us/v2/users/me?access_token=ACCESS_TOKEN';
	public $oauth_revoke_url = 'https://zoom.us/oauth/revoke?access_token=ACCESS_TOKEN';
	public $oauth_authentication = 'basic';

	/**
	 * Gets access token via parent and converts the returned profile data by Meetup into the expected associative array format.
	 *
	 * @throws EM_Exception
	 */
	public function verify_access_token(){
		$profile_data = parent::verify_access_token();
		//got this far, so we're good, let's get profile info
		$access_token = array(
			'id' => $profile_data['id'],
			'email' => $profile_data['email'],
			'name' => $profile_data['first_name'].' '.$profile_data['last_name'],
			'photo' => EM_Zoom::get_directory_url().'/zoom-blue.svg', //no profile images
		);
		return $access_token;
	}
	
	/**
	 * @return bool
	 * @throws EM_Exception
	 */
	public function revoke_access_token(){
		$request_args = array(
			'headers'=> array(
				'authorization' => 'Basic '.$this->token->access_token,
			),
		);
		$request_url = str_replace('ACCESS_TOKEN', $this->token->access_token, $this->oauth_revoke_url);
		return $this->oauth_request('post', $request_url, $request_args); // we may want to override this depending on what's returned
	}
	
	/**
	 * @param array $zoom_registrant
	 * @param int $meeting_id
	 * @param string $api_basepoint 'meetings', 'webinars' or another type used in API endpoint
	 * @param boolean $auto_approve
	 * @return array
	 * @throws EM_Exception
	 */
	public function add_registrant( array $zoom_registrant, $meeting_id, $api_basepoint = 'meetings', $auto_approve = true ){
		if( class_exists('\EMP_Logs') ) \EMP_Logs::log($zoom_registrant, 'zoom');
		$registration_result = $this->post('/'.$api_basepoint.'/'.$meeting_id.'/registrants', $zoom_registrant, array(), true);
		if( !empty($registration_result['body']->registrant_id) ){
			$registrant_id = $registration_result['body']->registrant_id;
			if( $auto_approve ){
				// update registrant status, webinars seem to make their status pending by default if manual approval is required
				$registrant = array( array('id' => $registrant_id, 'email' => $zoom_registrant['email']) );
				$this->update_registrants_status( 'approve', $registrant, $meeting_id, $api_basepoint );
			}
			// determine the Join URL, get it if necessary
			if( empty($registration_result['body']->join_url) ){
				// we'll need to get all registrants and loop until we find the right one... limitation
				$request_args = array('page_number' => 1, 'page_size' => 2, 'status' => 'approved');
				$join_url = '#';
				do{
					$registrants_response = $this->get('/'.$api_basepoint.'/'.$meeting_id.'/registrants', $request_args);
					$registrants_result = $registrants_response['body'];
					foreach( $registrants_result->registrants as $registrant ){
						if( $registrant->id === $registrant_id ){
							if( !empty($registrant->join_url) ){
								$join_url = $registrant->join_url; // breaks the do...while loop
							}
							break;
						}
					}
					$request_args['page_number']++;
				}while( $join_url === '#' && $request_args['page_number'] <= $registrants_result->page_count );
			}else{
				$join_url = $registration_result['body']->join_url;
			}
			// return array of values
			return array(
				'id' => $registrant_id,
				'join_url' => $join_url,
			);
		}else{
			$log_error = array('error' => 'Could not add registrant to meeting ID '.$meeting_id, 'registrant' => $registration_result, 'response' => $registration_result);
			if( class_exists('\EMP_Logs') ) \EMP_Logs::log($log_error, 'zoom');
			$registrant_email = !empty($zoom_registrant['email']) ? $zoom_registrant['email'] : 'undefined';
			$error = sprintf(__('Could not add registrant %s to Zoom Meeting.', 'events-manager-zoom'), $registrant_email);
			throw new EM_Exception( $error );
		}
	}
	
	/**
	 * @param string $registrant_action
	 * @param array $registrants_array
	 * @param int $meeting_id
	 * @param string $api_basepoint 'meetings', 'webinars' or another type used in API endpoint
	 * @throws EM_Exception
	 * @return bool
	 */
	public function update_registrants_status( $registrant_action, $registrants_array, $meeting_id, $api_basepoint = 'meetings' ){
		if( !in_array( $registrant_action, array('cancel','approve','deny')) ){
			throw new EM_Exception( __('Invalid registrant action supplied', 'events-manager-zoom') );
		}
		$registrants_chunked = array_chunk($registrants_array, 30);
		foreach( $registrants_chunked as $registrants ){
			$response = $this->put('/'.$api_basepoint.'/'.$meeting_id.'/registrants/status', array('action' => $registrant_action, 'registrants' => $registrants), array(), true );
			if( $response['response']['code'] < 200 && $response['response']['code'] > 300 ){
				$exception = new EM_Exception( $response['response']['message'] );
			}
		}
		if( !empty($exception) ){
			throw $exception;
		}
		return true;
	}
	
	/**
	 * @param string $registrant_action
	 * @param string $registrant_id
	 * @param string $registrant_email
	 * @param int $meeting_id
	 * @param string $api_basepoint 'meetings', 'webinars' or another type used in API endpoint
	 * @throws EM_Exception
	 * @return bool
	 * @see Zoom_API_Client::update_registrants_status()
	 */
	public function update_registrant_status( $registrant_action, $registrant_id, $registrant_email, $meeting_id, $api_basepoint = 'meetings' ){
		$registrants = array( array(
				'id' => $registrant_id,
				'email' => $registrant_email,
		) );
		return $this->update_registrants_status( $registrant_action, $registrants, $meeting_id, $api_basepoint );
	}
}