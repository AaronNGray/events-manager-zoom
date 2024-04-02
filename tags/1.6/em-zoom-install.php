<?php
// add zoom options to EM settings
if( !get_option('dbem_zoom_version') ){
	$location_types = get_option('dbem_location_types');
	if( empty($location_types['zoom_webinar']) && empty($location_types['zoom_meeting']) && empty($location_types['zoom_room']) ){
		$location_types['zoom_webinar'] = 1;
		$location_types['zoom_meeting'] = 1;
		update_option( 'dbem_location_types', $location_types );
		$msg = esc_html__('Events Manager for Zoom has been successfully activated and Meeting and Webinar location types have been automatically enabled for location selection. You can modify this in your %s page.', 'events-manager-zoom');
		$link = '<a href="' . admin_url('edit.php?post_type='.EM_POST_TYPE_EVENT.'&page=events-manager-options') . '">' . __('Events Manager Settings','events-manager') . '</a>';
		$notice = new EM_Admin_Notice( 'zoom-install-notice', 'info', sprintf($msg, $link) );
		EM_Admin_Notices::add( $notice );
	}
}
update_option('dbem_zoom_version', EM_ZOOM_VERSION);