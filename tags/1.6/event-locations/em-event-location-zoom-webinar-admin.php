<?php
namespace EM_Event_Locations;

require_once('em-event-location-zoom-meeting-admin.php');

class Zoom_Webinar_Admin extends Zoom_Meeting_Admin {
	protected static $static_class = 'EM_Event_Locations\Zoom_Webinar';
	
	/**
	 * @param \EM_Event $EM_Event
	 * @return mixed|void
	 */
	public static function admin_fields_settings( $EM_Event ){
		$return = parent::admin_fields_settings( $EM_Event );
		$webinar_fields = array(
			'panelists_video' => array(
				'label' => esc_html__('Panelists Video', 'events-manager-zoom'),
				'value' => false,
				'type' => 'boolean',
				'description' => esc_html__('Start video when panelists join webinar.', 'events-manager-zoom'),
			),
			'hd_video' => array(
				'label' => esc_html__('HD Video', 'events-manager-zoom'),
				'value' => false,
				'type' => 'boolean',
				'description' => esc_html__('Default to HD video.', 'events-manager-zoom'),
			),
			'show_share_button' => array(
				'label' => esc_html__('Show Share Button', 'events-manager-zoom'),
				'value' => false,
				'type' => 'boolean',
				'description' => esc_html__('Show social share buttons on the Zoom.us registration page.', 'events-manager-zoom'),
			),
			'on_demand' => array(
				'label' => esc_html__('On Demand', 'events-manager-zoom'),
				'value' => false,
				'type' => 'boolean',
				'description' => esc_html__('Make the webinar on-demand.', 'events-manager-zoom'),
			),
			'post_webinar_survey' => array(
				'label' => esc_html__('Post Webinar Survey', 'events-manager-zoom'),
				'value' => false,
				'type' => 'boolean',
				'description' => esc_html__('Zoom will open a survey page in attendees’ browsers after leaving the webinar.', 'events-manager-zoom'),
			),
			'survey_url' => array(
				'label' => esc_html__('Survey URL', 'events-manager-zoom'),
				'value' => '',
				'type' => 'text',
				'description' => esc_html__('Survey url for post webinar survey.', 'events-manager-zoom'),
			),
		);
		$return['fields'] = array_merge($return['fields'], $webinar_fields);
		$return['groups'][__('Webinar Surveys', 'events-manager-zoom')] = array('post_webinar_survey', 'survey_url');
		$return['groups'][__('Video', 'events-manager-zoom')][] = 'panelists_video';
		$return['groups'][__('Video', 'events-manager-zoom')][] = 'hd_video';
		$return['groups'][__('Meeting Options', 'events-manager-zoom')][] = 'show_share_button';
		$return['groups'][__('Meeting Options', 'events-manager-zoom')][] = 'on_demand';
		return apply_filters('em_event_location_zoom_webinar_admin_fields_settings', $return);
	}
}