=== Events Manager Zoom ===
Contributors: netweblogic
Donate link: http://wp-events-plugin.com
Tags: zoom, webinars, bookings, calendar, tickets, events, buddypress, event management, registration
Text Domain: events-manager-zoom
Requires at least: 5.2
Tested up to: 5.6
Stable tag: 1.1
Requires PHP: 5.3
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Integrates Zoom with Events Manager, automatically create webinars/meetings and handle bookings to them.

== Description ==

Integrate [Events Manager](https://wordpress.org/plugins/events-manager/) with Zoom and start creating webinars and meetings directly from your WordPress dashboard, as well as displaying these in calendars and handling bookings (including payments).

Seamless integration with both Events Manager and Zoom. Install and set up, create an event, choose a Zoom Meeting/Webinar as your location type, enable bookings, and you're good to go!

= Features =

* Create Webinars and Meetings while creating an event.
* Adjust all your webinar/meeting settings directly from your event editor.
* Enabling bookings on your events will automatically register users to that webinar/meeting.
* Send users unique URLs
* Integrated placeholders and search attributes for Events Manager, including:
 * Filter event types by Webinar, Meeting or Rooms
 * Conditional placeholders to display specific content for meetings/webinars/rooms.
 * Display meeting information via Placeholders
 * Provide unique Join URLs to users who've booked in custom email templates.
* Select a Zoom Room for an event (scheduling features not available in Zoom API).

Additionally, this plugin integrates automatically, **at no extra cost**, with [Events Manager Pro](http://wp-events-plugin.com/features/) including:

* Accept online and offline payments for webinars/meetings!
* Allow one person to book multiple individual attendees using our custom attendee booking forms, each with unique Join URLs!
* Custom booking forms integrate with Zoom fields and custom questions.
* Checkout-flow using our Multiple Bookings Mode

= How it works =

This integration creates a connection between Events Manager and your Zoom account. When creating events and you select a Zoom Webinar or Meeting as your location type, a new meeting/webinar will be created with the same name/time as your event.

Bookings are handled by Events Manager when a booking is made and approved (either automatically or manually), a registration will be made to the related meeting/webinar. Since bookings are handled by Events Manager, you can also make use of all the Pro features such as accepting payments for your event and asking custom questions.

You can alternatively link directly to your meetings and disable bookings via Events Manager entirely.

Visit our [documentation](https://wp-events-plugin.com/documentation/location-types/zoom/) pages for more information on setup.

== Installation ==

Events Manager for Zoom works like any standard Wordpress plugin, and requires little configuration to connect with Zoom. If you get stuck, visit our documentation and support forums.

Whenever installing or upgrading any plugin, or even Wordpress itself, we always recommended you back up your database first!

= Installing =

1. Please ensure you've installed [Events Manager](https://https://wordpress.org/plugins/events-manager/) beforehand.
2. If installing, go to Plugins > Add New in the admin area, and search for "Events Manager for Zoom".
3. Click install, once installed, activate and you're done!

= Setup =

Once installed, you will need to create an OAuth app on Zoom.us, add this information to your settings page, and connect your WordPress site with Zoom. For more information on this, please see our [Zoom Documentation](https://wp-events-plugin.com/documentation/location-types/zoom/)

== Screenshots ==

1. When creating an event, you'll be able to choose between Zoom Meeting, Webinar and Room, Meetings and Webinars are automatically created, and advanced settings are synchronized.

== Changelog ==
= 1.1 =
* Deprecated use of $post in get_post() functions
* Added sanitization for incoming post variable in zoom rooms

= 1.0 =
* First Release