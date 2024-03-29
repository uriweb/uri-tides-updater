<?php
/*
Plugin Name: URI Tides Updater
Plugin URI: http://www.uri.edu
Description: Retrieve live tide data from NOAA (requires URI Tides for display)
Version: 1.2.0
Author: URI Web Communications
Author URI: 
@author: Brandon Fuller <bjcfuller@uri.edu>
@author: John Pennypacker <jpennypacker@uri.edu>
@author: Alexandra Gauss <alexandra_gauss@uri.edu>
*/

// Block direct requests
if ( !defined('ABSPATH') )
	die('-1');


/**
 * WP CRON SETTINGS
 * Set up a cron interval to run every 10 minutes
 */
function uri_tides_updater_add_cron_interval( $schedules ) {
	$schedules['ten_minutes'] = array(
		'interval' => 60 * 10,
		'display' => esc_html__( 'Every Ten Minutes' ),
	);
	return $schedules;
}
add_filter( 'cron_schedules', 'uri_tides_updater_add_cron_interval' );

// set us up the cron hook
// https://developer.wordpress.org/plugins/cron/scheduling-wp-cron-events/
add_action( 'uri_tides_updater_cron_hook', 'uri_tides_updater_get_data' );

// finally, make sure that get_data is going run during the next 10 minute cron run
if ( ! wp_next_scheduled( 'uri_tides_updater_cron_hook' ) ) {
	wp_schedule_event( time(), 'ten_minutes', 'uri_tides_updater_cron_hook' );
}

 
/**
 * Deactivate the cron setting if the plugin is shut off
 */
function uri_tides_updater_deactivate() {
	$timestamp = wp_next_scheduled( 'uri_tides_updater_cron_hook' );
	wp_unschedule_event( $timestamp, 'uri_tides_updater_cron_hook' );
}
register_deactivation_hook( __FILE__, 'uri_tides_updater_deactivate' );


/**
 * Controller of the tides data for the plugin.
 * Runs based on cron settings
 * @see uri_tides_updater_add_cron_interval()
 * 
 * Checks for a cache
 * if we have a good cache, we use that.
 * otherwise, we query new tides data, and if it's good, we cache it.
 *
 * Why not a transient?  Because I'm a control freak 
 * who would rather have stale data than no data -JP
 */
function uri_tides_updater_get_data() {

	$refresh_cache = FALSE;
	
	// 1. load all cached tide data
	$tides_data = _uri_tides_updater_load_cache();

	// 2. check if we have a cache for this resource
	if ( $tides_data !== FALSE || $tides_data !== NULL ) {
		// we've got cached data
		// 3. check if the cache has sufficient recency
		$expires_on = isset($tides_data['expires_on']) ? $tides_data['expires_on'] : $tides_data['date'];
		if ( uri_tides_updater_is_expired( $expires_on ) ) {
			// cache is older than the specified recency, refresh it
			// 4. refresh tides / update cache if needed
			$refresh_cache = TRUE;
		}

	} else { // no cache data
		$refresh_cache = TRUE;
	}
	
	if( $refresh_cache ) {
		//echo '<pre>Pull fresh tides and cache them</pre>';
		
		$tides_data = uri_tides_updater_query_buoy();
		
		if($tides_data !== FALSE) {
			uri_tides_updater_write_cache($tides_data);
		} else {
			// the cache is expired, but the fresh buoy response is invalid.
			// extend the cache's lifespan for an hour
			$expires_on = strtotime( '+1 hour', strtotime('now') );
			$tides_data = _uri_tides_updater_load_cache();
			uri_tides_updater_write_cache($tides_data, $expires_on);
			
		}
		
	}
	// reload the tides data from the database to capitalize on cache updates
	//$tides_data = _uri_tides_updater_load_cache();

	//return $tides_data;
}

/**
 * Retrieve the tides data from the database
 */
function _uri_tides_updater_load_cache() {
	$tides_data = get_site_option( 'uri_tides_updater_cache', FALSE);
	if ( empty( $tides_data ) ) {
		$tides_data = array();
		$tides_data['date'] = strtotime('now -10 seconds');
		$tides_data['expires_on'] = strtotime('now -10 seconds');
	}
	return $tides_data;
}

/**
 * Query the NOAA buoy
 * @return mixed; arr on success, bool false on failure
 */
function uri_tides_updater_query_buoy() {
	$station = '8452660'; #8454049
	$tides_data = array();
	$tides_data['temperature'] = _uri_tides_updater_query( _uri_tides_updater_build_url ( 'temperature', $station ) );
	$tides_data['tide'] = _uri_tides_updater_query( _uri_tides_updater_build_url ( 'tide', $station ) );
	
	if ( $tides_data['temperature'] !== FALSE && $tides_data['tide'] !== FALSE ) {
		return $tides_data;
	}	else {
		// 
		return FALSE;
	}
}

/**
 * Build the URL for the tides request
 * @return str
 */
function _uri_tides_updater_build_url( $q, $station ) {
	$base = 'https://tidesandcurrents.noaa.gov/api/datagetter?';
	$application = 'NOS.COOPS.TAC.' . ($q == 'temperature') ? 'PHYSOCEAN' : 'WL';
	
	if($q == 'temperature' ) {
		$url = $base . 'product=water_temperature&application=' . $application . 
					'&date=latest&station=' . $station . 
					'&time_zone=GMT&units=english&interval=6&format=json';
	} else {
		$start_date = date( 'Ymd', strtotime( 'yesterday' ) );
		$end_date = date( 'Ymd', strtotime( '+2 days' ) );

		$url = $base . 'product=predictions&application=' .
					$application . '&begin_date=' . $start_date . '&end_date=' . $end_date . 
					'&datum=MLLW&station=' . $station . 
					'&time_zone=GMT&units=english&interval=hilo&format=json';
					
	}
	
	return $url;
}

/**
 * Save the data retrieved from the NOAA buoy as a WordPress site-wide option
 * @param arr $tides_data is an array of tides data [temperature, tide]
 * @param str $expires_on expects a date object for some time in the future, if empty, 
 *   it'll use the value set in the admin preferences (or the default five minutes)
 */
function uri_tides_updater_write_cache( $tides_data, $expires_on='' ) {

	// if expires on is empty or not in the future, set a new expiry date
	if ( empty ( $expires_on ) || !($expires_on > strtotime('now')) ) {
		$recency = get_site_option( 'uri_tides_updater_recency', '5 minutes' );
		$expires_on = strtotime( '+'.$recency, strtotime('now') );
	}

	$tides_data['date'] = strtotime('now');
	$tides_data['expires_on'] = $expires_on;
	update_site_option( 'uri_tides_updater_cache', $tides_data, TRUE );
}


/**
 * check if a date has recency
 * @param int date
 * @return bool
 */
function uri_tides_updater_is_expired( $date ) {
	return ( $date < strtotime('now') );
}



/**
 * Query the buoy for tide level
 * @return mixed arr on success; FALSE on failure
 */
function _uri_tides_updater_query( $url ) {

	$args = array(
		'user-agent' => 'URI Tides WordPress Plugin', // So the endpoint can figure out who we are
		'headers' => [ ],
		'timeout' => 5 // limit query time to 5 seconds
	);
	
	
	$response = wp_safe_remote_get ( $url, $args );
	

	if( is_wp_error ($response) ) {
		// there was an error making the API call
		// echo 'The error message is: ' . $response->get_error_message();
		return FALSE;	
	} 
	
	// still here?  good.  it means WP got an acceptable response.  Let's validate it.
	if ( isset( $response['body'] ) && !empty( $response['body'] ) && wp_remote_retrieve_response_code($response) == '200' ) {
		$data = json_decode ( wp_remote_retrieve_body ( $response ) );
		// check that the response has a body and that it contains the properties that we're looking for
		if( ( isset($data->metadata) || isset($data->predictions) ) ) {
			// hooray, all is well!
			return $data;
		}
	}

		// still here?  Then the content from API has been rejected
		// @todo: log sensible debugging information

		return FALSE;

}

