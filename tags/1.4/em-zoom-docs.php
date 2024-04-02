<?php
add_filter('em_documentation', function( $EM_Documentation ){
    $EM_Documentation['placeholders']['event-locations']['Zoom Meetings & Webinars'] = array(
        'placeholders' => array(
            '#_EVENTLOCATION{id}' => array( 'desc' => 'Meeting ID' ),
            '#_EVENTLOCATION{join_url}' => array( 'desc' => 'Universal (not unique) join URL.' ),
            '#_EVENTLOCATION{password}' => array( 'desc' => 'Password for entering the meeting.' ),
            '#_EVENTLOCATION{registration_url}' => array( 'desc' => 'URL for registering to the meeting. Useful only if you do not want to use Events Manager for bookings and would like users to register directly on the Zoom website.' ),
            '#_EVENTLOCATION{start_url}' => array( 'desc' => 'Link for meeting host to start the meeting. <strong>Warning!</strong> Use wisely, anyone with this link can start the meeting as the host, for safety reasons this link will be set to # if the user viewing it cannot manage the event in question (i.e. the owner or an events admin).' ),
            '#_BOOKINGZOOMJOINLINK' => array( 'desc' => 'To be used only on booking confirmation emails, provides user with a unique join link to the meeting/webinar.' )
        )
    );
	$EM_Documentation['placeholders']['event-locations']['Zoom Rooms'] = array(
		'placeholders' => array(
			'#_EVENTLOCATION{id}' => array( 'desc' => 'Unique Identifier for the Zoom Room.' ),
			'#_EVENTLOCATION{room_id}' => array( 'desc' => 'Globally Unique Identifier for the Zoom Room, used in APIs.' ),
			'#_EVENTLOCATION{name}' => array( 'desc' => 'The name of the room.' ),
		)
	);
    return $EM_Documentation;
});