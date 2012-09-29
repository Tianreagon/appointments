<?php
/*
Plugin Name: Appointments+
Description: Lets you accept and manage appointments from front end or create them from admin side
Plugin URI: http://premium.wpmudev.org/project/appointments-plus/
Version: 1.0.4.3
Author: Hakan Evin <hakan@incsub.com>
Author URI: http://premium.wpmudev.org/
Textdomain: appointments
WDP ID: 679841
*/

/* 
Copyright 2007-2012 Incsub (http://incsub.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License (Version 2 - GPLv2) as published by
the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/


/**
 * Find blogs and uninstall tables for each of them
 * @since 1.0.2
 */
if ( !function_exists( 'wpmudev_appointments_uninstall' ) ) {
	function wpmudev_appointments_uninstall() {
		global $wpdb;

		if ( function_exists('is_multisite') && is_multisite() ) {
				$network = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : "";
				$activate = isset($_GET['action']) ? $_GET['action'] : "";
				$is_network = ($network=='/wp-admin/network/plugins.php') ? true:false;
				$is_activation = ($activate=='deactivate') ? false:true;

			if ($is_network && !$is_activation){
				$old_blog = $wpdb->blogid;
				// Get all blog ids
				$blogids = $wpdb->get_col($wpdb->prepare("SELECT blog_id FROM $wpdb->blogs"));
				foreach ($blogids as $blog_id) {
					switch_to_blog($blog_id);
					_wpmudev_appointments_uninstall( );
				}
				switch_to_blog($old_blog);
				return;
			}	
		}
		// If not multisite, just make an ordinary uninstall		
		_wpmudev_appointments_uninstall( );		
	}
}

if ( !function_exists( '_wpmudev_appointments_uninstall' ) ) {
	function _wpmudev_appointments_uninstall( ) {
		global $wpdb;
		
		delete_option( 'appointments_options' );
		delete_option( 'app_last_update' );
		
		$wpdb->query( "DROP TABLE " . $wpdb->prefix . "app_working_hours" );
		$wpdb->query( "DROP TABLE " . $wpdb->prefix . "app_exceptions" );
		$wpdb->query( "DROP TABLE " . $wpdb->prefix . "app_services" );
		$wpdb->query( "DROP TABLE " . $wpdb->prefix . "app_workers" );
		$wpdb->query( "DROP TABLE " . $wpdb->prefix . "app_appointments" );
		$wpdb->query( "DROP TABLE " . $wpdb->prefix . "app_transactions" );
		$wpdb->query( "DROP TABLE " . $wpdb->prefix . "app_cache" );
	}
}

register_uninstall_hook(  __FILE__ , "wpmudev_appointments_uninstall" );
register_activation_hook( __FILE__, array('Appointments', 'install') );

if ( !class_exists( 'Appointments' ) ) {

class Appointments {

	var $version = "1.0.4.3";

	/**
     * Constructor
     */
	function Appointments() {
		$this->__construct();
	}
	function __construct() {
	
		$this->plugin_dir = WP_PLUGIN_DIR . '/appointments';
		$this->plugin_url = plugins_url( 'appointments' );

		// Read all options at once
		$this->options = get_option( 'appointments_options' );
		
		// Critical values
		$this->min_time = $this->get_min_time();
		$this->app_limit = $this->get_app_limit();
		
		// To follow WP Start of week, time, date settings
		$this->local_time			= current_time('timestamp');
		if ( !$this->start_of_week	= get_option('start_of_week') )
			$this->start_of_week	= 0;
		$this->time_format			= get_option('time_format'); 
		$this->date_format			= get_option('date_format');
		$this->datetime_format		= $this->date_format . " " . $this->time_format;
		
		add_action( 'wpmu_new_blog', array( &$this, 'new_blog' ), 10, 6);	// Install database tables for a new blog 
		add_action( 'delete_blog', array( &$this, 'delete_blog' ), 10, 2);	// Uninstall tables for a deleted blog
		add_action( 'delete_user', array( &$this, 'delete_user' ) );		// Modify database in case a user is deleted
		add_action( 'wpmu_delete_user', array( &$this, 'delete_user' ) );	// Same as above

		add_action( 'plugins_loaded', array(&$this, 'localization') );		// Localize the plugin
		add_action( 'init', array( &$this, 'init' ) ); 						// Initial stuff
		add_filter( 'the_posts', array(&$this, 'load_styles') );			// Determine if we use shortcodes on the page
		add_action( 'wp_ajax_nopriv_app_paypal_ipn', array(&$this, 'handle_paypal_return')); // Send Paypal to IPN function
		
		// Add/edit some fields on the user pages
		add_action( 'show_user_profile', array(&$this, 'show_profile') );
		add_action( 'personal_options_update', array(&$this, 'save_profile') );
		add_action( 'edit_user_profile_update', array(&$this, 'save_profile') );
		
		// Admin hooks
		add_action( 'admin_init', array(&$this, 'tutorial1') );							// Add tutorial 1
		add_action( 'admin_init', array(&$this, 'tutorial2') );							// Add tutorial 2
		add_action( 'admin_menu', array( &$this, 'admin_init' ) ); 						// Creates admin settings window
		add_action( 'admin_notices', array( &$this, 'admin_notices' ) ); 				// Warns admin
		add_action( 'admin_print_scripts', array(&$this, 'admin_scripts') );			// Load scripts
		add_action( 'admin_print_styles', array(&$this, 'admin_css') );					// Add style to all admin pages
		add_action( 'admin_print_styles-appointments_page_app_settings', array( &$this, 'admin_css_settings' ) ); // Add style to settings page
		add_action( 'right_now_content_table_end', array($this, 'add_app_counts') );	// Add app counts
		add_action( 'wp_ajax_delete_log', array( &$this, 'delete_log' ) ); 				// Clear log
		add_action( 'wp_ajax_inline_edit', array( &$this, 'inline_edit' ) ); 			// Add/edit appointments
		add_action( 'wp_ajax_inline_edit_save', array( &$this, 'inline_edit_save' ) ); 	// Save edits
		add_action( 'wp_ajax_js_error', array( &$this, 'js_error' ) ); 					// Track js errors
		
		// Shortcodes
		add_shortcode( 'app_my_appointments', array(&$this,'my_appointments') );
		add_shortcode( 'app_services', array(&$this,'services') );
		add_shortcode( 'app_service_providers', array(&$this,'service_providers') );
		add_shortcode( 'app_schedule', array(&$this,'weekly_calendar') );
		add_shortcode( 'app_monthly_schedule', array(&$this,'monthly_calendar') );
		add_shortcode( 'app_pagination', array(&$this,'pagination') );
		add_shortcode( 'app_confirmation', array(&$this,'confirmation') );
		add_shortcode( 'app_login', array(&$this,'login') );
		add_shortcode( 'app_paypal', array(&$this,'paypal') );

		// Front end ajax hooks
		add_action( 'wp_ajax_pre_confirmation', array( &$this, 'pre_confirmation' ) ); 			// Get pre_confirmation results
		add_action( 'wp_ajax_nopriv_pre_confirmation', array( &$this, 'pre_confirmation' ) ); 	// Get pre_confirmation results
		add_action( 'wp_ajax_post_confirmation', array( &$this, 'post_confirmation' ) ); 		// Do after final confirmation
		add_action( 'wp_ajax_nopriv_post_confirmation', array( &$this, 'post_confirmation' ) ); // Do after final confirmation
		
		// API login after the options have been initialized
		if (@$this->options['accept_api_logins']) {
			add_action('wp_ajax_nopriv_app_facebook_login', array($this, 'handle_facebook_login'));
			add_action('wp_ajax_nopriv_app_get_twitter_auth_url', array($this, 'handle_get_twitter_auth_url'));
			add_action('wp_ajax_nopriv_app_twitter_login', array($this, 'handle_twitter_login'));
			add_action('wp_ajax_nopriv_app_ajax_login', array($this, 'ajax_login'));
			add_action('wp_ajax_nopriv_app_get_google_auth_url', array($this, 'handle_get_google_auth_url'));
			add_action('wp_ajax_nopriv_app_google_login', array($this, 'handle_google_login'));
			
			// Google+ login
			if ( !session_id() )
				session_start();
			if (!class_exists('LightOpenID')) 
				include_once( $this->plugin_dir . '/includes/lightopenid/openid.php' );
			$this->openid = new LightOpenID;
			
			$this->openid->identity = 'https://www.google.com/accounts/o8/id';
			$this->openid->required = array('namePerson/first', 'namePerson/last', 'namePerson/friendly', 'contact/email');
			if (!empty($_REQUEST['openid_ns'])) {
				$cache = $this->openid->getAttributes();
				if (isset($cache['namePerson/first']) || isset($cache['namePerson/last']) || isset($cache['contact/email'])) {
					$_SESSION['app_google_user_cache'] = $cache;
				}
			}
			if ( isset( $_SESSION['app_google_user_cache'] ) )
				$this->_google_user_cache = $_SESSION['app_google_user_cache'];
			else
				$this->_google_user_cache = '';
		}
		
		// Widgets
		require_once( $this->plugin_dir . '/includes/widgets.php' );
		add_action( 'widgets_init', array( &$this, 'widgets_init' ) );
		
		// Buddypress
		add_action( 'bp_setup_nav', array( &$this, 'setup_nav'), 20 );
		add_action( 'bp_init', array( &$this, 'bp_init') );
		add_action( 'template_redirect', array( &$this, 'bp_template_redirect') ); 	// bp_template_redirect is not working
		add_action( 'wp_footer', array( &$this, 'bp_footer' ) ); 					// The same action as wp_footer
		
		// Caching
		if ( 'yes' == @$this->options['use_cache'] ) {
			add_filter( 'the_content', array( &$this, 'pre_content' ), 8 );				// Check content before do_shortcode
			add_filter( 'the_content', array( &$this, 'post_content' ), 100 );			// Serve this later than do_shortcode
			add_action( 'wp_footer', array( &$this, 'save_script' ), 8 );				// Save script to database
			add_action( 'permalink_structure_changed', array( &$this, 'flush_cache' ) );// Clear cache in case permalink changed
			add_action( 'save_post', array( &$this, 'save_post' ), 10, 2 ); 			// Clear cache if it has shortcodes
		}
		$this->pages_to_be_cached = array();
		$this->had_filter = false; // There can be a wpautop filter. We will check this later on.
		
		// Membership integration
		$this->membership_active = false;
		add_action( 'plugins_loaded', array( &$this, 'check_membership_plugin') );
		
		// Marketpress integration
		$this->marketpress_active = $this->mp = false;
		$this->mp_posts = array();
		add_action( 'plugins_loaded', array( &$this, 'check_marketpress_plugin') );
	
		// Database variables
		global $wpdb;
		$this->db 					= &$wpdb;
		$this->wh_table 			= $wpdb->prefix . "app_working_hours";
		$this->exceptions_table 	= $wpdb->prefix . "app_exceptions";
		$this->services_table 		= $wpdb->prefix . "app_services";
		$this->workers_table 		= $wpdb->prefix . "app_workers";
		$this->app_table 			= $wpdb->prefix . "app_appointments";
		$this->transaction_table 	= $wpdb->prefix . "app_transactions";
		$this->cache_table 			= $wpdb->prefix . "app_cache";
		
		// Set log file location
		$uploads = wp_upload_dir();
		if ( isset( $uploads["basedir"] ) )
			$this->uploads_dir 	= $uploads["basedir"] . "/";
		else
			$this->uploads_dir 	= WP_CONTENT_DIR . "/uploads/";
			
		$this->log_file 		= $this->uploads_dir . "appointments-log.txt";	
		
		// Other default settings
		$this->script = $this->bp_script = $this->uri = $this->error_url = '';
		$this->location = $this->service = $this->worker = 0;
		$this->gcal_image = '<img src="' . $this->plugin_url . '/images/gc_button1.gif" />';
	}
	
/**
*****************************************************
* Methods for optimization
*
* $l: location ID - For future use
* $s: service ID
* $w: worker ID
* $stat: Status (open: working or closed: not working)
******************************************************
*/
	 
	/**
	 * Get location, service, worker
	 */
	function get_lsw() {
		$this->location = $this->get_location_id();
		$this->service = $this->get_service_id();
		$this->worker = $this->get_worker_id();
	}

	/**
	 * Get location ID for future use
	 */
	function get_location_id() {
		if ( isset( $_REQUEST["app_location_id"] ) )
			return $_REQUEST["app_location_id"];
			
		return 0;
	}

	/**
	 * Get smallest service ID
	 * We assume total number of services is not too high, which is the practical case.
	 * Otherwise this method might be expensive
	 * @return integer
	 */	
	function get_first_service_id() {
		$min = wp_cache_get( 'min_service_id' );
		if ( false === $min ) {
			$services = $this->get_services();
			if ( $services ) {
				$min = 9999999;
				foreach ( $services as $service ) {
					if ( $service->ID < $min )
						$min = $service->ID;
				}
				wp_cache_set( 'min_service_id', $min );
			}
			else
				$min = 0; // No services ?? - Not possible but let's be safe
		}
		return $min;
	}

	/**
	 * Get service ID from front end
	 * @return integer
	 */
	function get_service_id() {
		if ( isset( $_REQUEST["app_service_id"] ) )
			return $_REQUEST["app_service_id"];
		else if ( !$service_id = $this->get_first_service_id() )
			$service_id = 0;
			
		return $service_id;
	}

	/**
	 * Get worker ID from front end
	 * worker = provider
	 * @return integer
	 */
	function get_worker_id() {
		if ( isset( $_REQUEST["app_provider_id"] ) )
			return $_REQUEST["app_provider_id"];
			
		if ( isset( $_REQUEST["app_worker_id"] ) )
			return $_REQUEST["app_worker_id"];
			
		return 0;
	}

	/**
	 * Get all services
	 * @return array of objects
	 */	
	function get_services() {
		$services = wp_cache_get( 'all_services' );
		if ( false === $services ) {
			$services = $this->db->get_results("SELECT * FROM " . $this->services_table . " " );
			wp_cache_set( 'all_services', $services );
		}
		return $services;
	}
	
	/**
	 * Get a single service with given ID
	 * @return object
	 */	
	function get_service( $ID ) {
		$service = wp_cache_get( 'service_'. $ID );
		if ( false === $service ) {
			$services = $this->get_services();
			if ( $services ) {
				foreach ( $services as $service ) {
					if ( $service->ID == $ID )
						break;
				}
				wp_cache_set( 'service_'. $ID, $service );
			}
			else
				$service = null;
		}
		return $service;
	}

	/**
	 * Get all workers
	 * @return array of objects
	 */	
	function get_workers() {
		$workers = wp_cache_get( 'all_workers' );
		if ( false === $workers ) {
			$workers = $this->db->get_results("SELECT * FROM " . $this->workers_table . " " );
			wp_cache_set( 'all_workers', $workers );
		}
		return $workers;
	}
	
	/**
	 * Get a single worker with given ID
	 * @return object
	 */	
	function get_worker( $ID ) {
		$worker = null;
		$workers = $this->get_workers();
		if ( $workers ) {
			foreach ( $workers as $worker ) {
				if ( $worker->ID == $ID )
					break;
			}
		}
		return $worker;
	}
	
	/**
	 * Get workers giving a specific service (by its ID)
 	 * We assume total number of workers is not too high, which is the practical case.
	 * Otherwise this method would be expensive
	 * @return array of objects
	 */	
	function get_workers_by_service( $ID ) {
		$workers_by_service = false;
		$workers = $this->get_workers();
		if ( $workers ) {
			$workers_by_service = array();
			foreach ( $workers as $worker ) {
				if ( strpos( $worker->services_provided, ':'.$ID.':' ) !== false )
					$workers_by_service[] = $worker;
			}
		}
		return $workers_by_service;
	}

	/**
	 * Return a row from working hours table, i.e. days/hours we are working or we have break
	 * stat: open (works), or closed (breaks).
	 * @return object
	 */	
	function get_work_break( $l, $w, $stat ) {
		$wb = null;
		$work_breaks = wp_cache_get( 'work_breaks_'. $l . '_' . $w );
		if ( false === $work_breaks ) {
			$work_breaks = $this->db->get_results( "SELECT * FROM ". $this->wh_table. " WHERE worker=".$w." AND location=".$l." ");
			wp_cache_set( 'work_breaks_'. $l . '_' . $w, $work_breaks );
		}
		if ( $work_breaks ) { 
			foreach ( $work_breaks as $wb ) {
				if ( $wb->status == $stat )
					break;
			}
		}
		return $wb;
	}
	
	/**
	 * Return a row from exceptions table, i.e. days we are working or having holiday
	 * @return object
	 */	
	function get_exception( $l, $w, $stat ) {
		$exception = null;
		$exceptions = wp_cache_get( 'exceptions_'. $l . '_' . $w );
		if ( false === $exceptions ) {
			$exceptions = $this->db->get_results( "SELECT * FROM " . $this->exceptions_table . " WHERE worker=".$w." AND location=".$l." " );
			wp_cache_set( 'exceptions_'. $l . '_' . $w, $exceptions );
		}
		if ( $exceptions ) { 
			foreach ( $exceptions as $exception ) {
				if ( $exception->status == $stat )
					break;
			}
		}
		return $exception;
	}
	
	/**
	 * Return all reserve appointments (i.e. pending, paid or confirmed)
	 * @return array of objects
	 */	
	function get_reserve_apps( $l, $s, $w ) {
		$apps = wp_cache_get( 'reserve_apps_'. $l . '_' . $s . '_' . $w );
		if ( false === $apps ) {
			$apps = $this->db->get_results( "SELECT * FROM " . $this->app_table . " 
				WHERE location=".$l." AND service=".$s." AND worker=".$w." 
				AND (status='pending' OR status='paid' OR status='confirmed') " );
			wp_cache_set( 'reserve_apps_'. $l . '_' . $s . '_' . $w, $apps );
		}
		return $apps;
	}
	
	/**
	 * Return all reserve appointments by worker ID
	 * @return array of objects
	 */	
	function get_reserve_apps_by_worker( $l, $w ) {
		$apps = wp_cache_get( 'reserve_apps_by_worker_'. $l . '_' . $w );
		if ( false === $apps ) {
			$services = $this->get_services();
			if ( $services ) {
				$apps = array();
				foreach ( $services as $service ) {
					$apps_worker = $this->get_reserve_apps( $l, $service->ID, $w );
					if ( $apps_worker )
						$apps = array_merge( $apps, $apps_worker );
				}
			}
			wp_cache_set( 'reserve_apps_by_worker_'. $l . '_' . $w, $apps );
		}
		return $apps;
	}
	
	/**
	 * Find if a user is worker
	 * @return bool
	 */	
	function is_worker( $user_id=0 ) {
		global $wpdb, $current_user;
		if ( !$user_id )
			$user_id = $current_user->ID;
		
		$result = $this->get_worker( $user_id );
		if ( $result != null )
			return true;
		
		return false;
	}

	/**
	 * Find worker name given his ID
	 * @return string
	 */	
	function get_worker_name( $worker=0 ) {
		global $current_user;
		if ( 0 == $worker ) {
			// Show different text to authorized people
			if ( is_admin() || current_user_can( 'manage_options' ) || $this->is_worker( $current_user->ID ) )
				$user_name = __('Our staff', 'appointments');
			else
				$user_name = __('A specialist', 'appointments');
		}
		else {
			$userdata = get_userdata( $worker );
			$user_name = $userdata->display_name;
			if ( !$user_name )
				$user_name = $userdata->first_name . " " . $userdata->last_name;
			if ( "" == trim( $user_name ) )
				$user_name = $userdata->user_login;
		}
		return apply_filters( 'app_get_worker_name', $user_name, $worker );
	}

	/**
	 * Find service name given its ID
	 * @return string
	 */	
	function get_service_name( $service=0 ) {
		// Safe text if we delete a service
		$name = __('Not defined', 'appointments');
		$result = $this->get_service( $service );
		if ( $result != null )
			$name = $result->name;
			
		$name = apply_filters( 'app_get_service_name', $name, $service );
		
		return stripslashes( $name );
	}

	/**
	 * Find client name given his appointment
	 * @return string
	 */	
	function get_client_name( $app_id ) {
		$name = '';
		// This is only used on admin side, so an optimisation is not required.
		$result = $this->db->get_row( "SELECT * FROM " . $this->app_table . " WHERE ID=".$app_id." " );
		if ( $result !== null ) {
			// Client can be a user
			if ( $result->user ) {
				$userdata = get_userdata( $result->user );
				if ( $userdata ) {
					$name = '<a href="'. admin_url("user-edit.php?user_id="). $result->user . '" target="_blank">'. $userdata->user_login . '</a>';
				}
				else
					$name = $result->name;
			}
			else {
				$name = $result->name;
				if ( !$name )
					$name = $result->email;
			}
		}
		return apply_filters( 'app_get_client_name', $name, $app_id );
	}

	/**
	 * Get price for the current service and worker
	 * If worker has additional price (optional), it is added to the service price
	 * @param paypal: If set true, deposit price is calculated
	 * @return string
	 */	
	function get_price( $paypal=false ) {
		global $current_user;
		$this->get_lsw();
		$service_obj = $this->get_service( $this->service );
		$worker_obj = $this->get_worker( $this->worker );
		
		if ( $worker_obj !== null && $worker_obj->price )
			$worker_price = $worker_obj->price;
		else
			$worker_price = 0;
		
		$price = $service_obj->price + $worker_price;
		
		// Discount
		if ( $this->is_member() && isset( $this->options["members_discount"] ) && $this->options["members_discount"] ) {
			// Special condition: Free for members
			if ( 100 == $this->options["members_discount"] )
				$price = 0;
			else
				$price = number_format( $price * ( 100 - $this->options["members_discount"] )/100, 2 );
		}
		
		if ( $paypal ) {
			// Deposit
			if ( isset( $this->options["percent_deposit"] ) && $this->options["percent_deposit"] )
				$price = number_format( $price * $this->options["percent_deposit"] / 100, 2 );
			if ( isset( $this->options["fixed_deposit"] ) && $this->options["fixed_deposit"] )
				$price = $this->options["fixed_deposit"];
			
			// It is possible to ask special amounts to be paid
			return apply_filters( 'app_paypal_amount', $price, $this->service, $this->worker, $current_user->ID );
		}
		
		return apply_filters( 'app_get_price', $price, $this->service, $this->worker, $current_user->ID );
	}
	
	/**
	 * Get the capacity of the current service
	 * @return integer
	 */		
	function get_capacity() {
		$capacity = wp_cache_get( 'capacity_'. $this->service );
		if ( false === $capacity ) {
			// If no worker is defined, capacity is always 1
			$count = count( $this->get_workers() );
			if ( !$count ) {
				$capacity = 1;
			}
			else {
				// Else, find number of workers giving that service and capacity of the service
				$worker_count = count( $this->get_workers_by_service( $this->service ) );
				$service = $this->get_service( $this->service );
				if ( $service != null ) {
					if ( !$service->capacity ) {
						$capacity = $worker_count; // No service capacity limit
					}
					else 
						$capacity = min( $service->capacity, $worker_count ); // Return whichever smaller 
				}
				else
					$capacity = 1; // No service ?? - Not possible but let's be safe
			}
			wp_cache_set( 'capacity_'. $this->service, $capacity );
		}
		return $capacity;
	}
	
/**
**************************************
* Methods for Specific Content Caching
* Developed especially for this plugin
**************************************
*/

	/**
	 * Check if plugin should use cache
	 * Available for visitors for the moment
	 * TODO: extend this for logged in users too
	 * @since 1.0.2
	 */		
	function use_cache() {
		if ( 'yes' == $this->options["use_cache"] && !is_user_logged_in() )
			return true;
			
		return false;
	}

	/**
	 * Add a post ID to the array to be cached
	 *
	 */		
	function add_to_cache( $post_id ) {
		if ( $this->use_cache() )
			$this->pages_to_be_cached[] = $post_id;
	}

	/**
	 * Serve content from cache DB if is available and post is supposed to be cached
	 * This is called before do_shortcode (this method's priority: 8)
	 * @return string (the content)
	 */		
	function pre_content( $content ) {
		global $post;
		// Check if this page is to be cached
		if ( !in_array( $post->ID, $this->pages_to_be_cached ) )
			return $content;
		
		// Get uri and mark it for other functions too
		// The other functions are called after this (content with priority 100 and the other with footer hook)
		$this->uri = $this->get_uri();
			
		$result = $this->db->get_row( "SELECT * FROM " . $this->cache_table . " WHERE uri= '". $this->uri . "' " );
		if ( $result != null ) {
			// Clear uri so other functions do not deal with update/insert
			$this->uri = false;
			// We need to serve the scripts too
			$this->script = $result->script;
			
			// If wpautop had filter, it is almost certain that it was removed
			if ( $this->had_filter )
				$new_content = $result->content;
			else
				$new_content = wpautop( $result->content );
			
			return $new_content . '<!-- Served from WPMU DEV Appointments+ Cache '. $result->created .' -->';
		}
		// If cache is empty return content
		// If wpautop had filter, it is almost certain that it was removed
		if ( $this->had_filter )
			return $content;
		else
			return wpautop( $content ); // Add wpautop which we removed before
	}

	/**
	 * Save newly created content to cache DB
	 * @return string (the content)
	 */		
	function post_content( $content ) {
		// Check if this page is to be cached
		if ( !$this->uri )
			return $content;
		// At this point it means no such a row, so we can safely insert	
		$this->db->insert( $this->cache_table,
					array( 
						'uri' 		=> $this->uri, 
						'created' 	=> date ("Y-m-d H:i:s", $this->local_time ),
						'content'	=> $content
					)
			);
		return $content;
	}

	/**
	 * Save newly created scripts at wp footer location
	 * @return none
	 */		
	function save_script() {
		// Check if this page is to be cached
		if ( !$this->uri )
			return;
		// There must be already such a row	
		$this->db->update( $this->cache_table,
			array( 'script'	=> $this->script ),
			array( 'uri' 	=> $this->uri )
		);
	}

	/**
	 * Get request uri
	 * @return string
	 */		
	function get_uri() {
		// Get rid of # part
		if ( strpos( $_SERVER['REQUEST_URI'], '#' ) !== false ) {
			$uri_arr = explode( '#', $_SERVER['REQUEST_URI'] );
			$uri = $uri_arr[0];
		}
		else
			$uri = $_SERVER['REQUEST_URI'];
			
		return $uri;
	}
	
	/**
	 * Clear cache in case saved post has our shortcodes
	 * @return none
	 */		
	function save_post( $post_id, $post ) {
		if ( strpos( $post->post_content, '[app_' ) !== false )
			$this->flush_cache();
	}

	/**
	 * Flush both database and object caches
	 * 
	 */		
	function flush_cache( ) {
		wp_cache_flush();
		if ( 'yes' == @$this->options["use_cache"] )
			$result = $this->db->query( "TRUNCATE TABLE " . $this->cache_table . " " );
	}

/****************
* General methods
*****************
*/
	
	/**
     * Provide options if asked outside the class
 	 * @return array
     */
	function get_options() {
		return $this->options;
	}
	
	/**
	 * Save a message to the log file
	 */	
	function log( $message='' ) {
		if ( $message )
			@file_put_contents( $this->log_file, '<b>['. date_i18n( $this->datetime_format, $this->local_time ) .']</b> '. $message . chr(10). chr(13), FILE_APPEND ); 
	}

	/**
	 * Remove tabs and breaks
	 */	
	function esc_rn( $text ) {
		$text = str_replace( array("\t","\n","\r"), "", $text );
		return $text;
	}

	/**
	 * Converts number of seconds to hours:mins acc to the WP time format setting
	 * @return string
	 */	
	function secs2hours( $secs ) {
		$min = (int)($secs / 60);
		$hours = "00";
		if ( $min < 60 )
			$hours_min = $hours . ":" . $min;
		else {
			$hours = (int)($min / 60);
			if ( $hours < 10 )
				$hours = "0" . $hours;
			$mins = $min - $hours * 60;
			if ( $mins < 10 )
				$mins = "0" . $mins;
			$hours_min = $hours . ":" . $mins;			
		}
		if ( $this->time_format )
			$hours_min = date( $this->time_format, strtotime( $hours_min . ":00" ) );
			
		return $hours_min;
	}

	/**
	 * Return an array of preset base times, so that strange values are not set
	 * @return array
	 */		
	function time_base() {
		$default = array( 10, 15, 30, 60, 120 );
		$a = $this->options["additional_min_time"];
		// Only allow additional numbers divisible by 10 or 15
		if ( isset( $a ) && $a && is_numeric( $a) && ( 0 == $a %10 || 0 == $a %15 ) )
			$default[] = $a;
		return apply_filters( 'app_time_base', $default );
	}

	/**
	 *	Return minimum set interval time
	 *  If not set, return a safe time.
	 *	@return integer
	 */		
	function get_min_time(){
		if ( isset( $this->options["min_time"] ) && $this->options["min_time"] && $this->options["min_time"]>apply_filters( 'app_safe_min_time', 9 ) )
			return (int)$this->options["min_time"];
		else
			return apply_filters( 'app_safe_time', 30 );
	}

	/**
	 *	Number of days that an appointment can be taken
	 *	@return integer
	 */
	function get_app_limit() {
		if ( isset( $this->options["app_limit"] ) && $this->options["app_limit"] )
			return (int)$this->options["app_limit"];
		else
			return apply_filters( 'app_limit', 30 );
	}

	/**
	 * Return an array of weekdays
	 * @return array
	 */		
	function weekdays() {
		return array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );
	}

	/**
	 * Return all available statuses
	 * @return array
	 */		
	function get_statuses() {
		return apply_filters( 'app_statuses',
					array( 
						'pending'	=> __('Pending', 'appointments'),
						'paid'		=> __('Paid', 'appointments'),
						'confirmed'	=> __('Confirmed', 'appointments'),
						'removed'	=> __('Removed', 'appointments'),
						'completed'	=> __('Completed', 'appointments')
						)
				);
	}
	
	
	/**
	 * Return a selected field name to further customize them and make translation easier
	 * @return string (name of the field)
	 */		
	function get_field_name( $key ) {

		$field_names = array( 
						'name'		=> __('Name', 'appointments'),
						'email'		=> __('Email', 'appointments'),
						'phone'		=> __('Phone', 'appointments'),
						'address'	=> __('Address', 'appointments'),
						'city'		=> __('City', 'appointments'),
						'note'		=> __('Note', 'appointments')
					);
					
		$field_names = apply_filters( 'app_get_field_name', $field_names );

		if ( array_key_exists( $key, $field_names ) )
			return $field_names[$key];
		else
			return __( 'Not defined', 'appointments' );
	}

	/**
	 * Return an array of all available front end box classes
	 * @return array
	 */	
	function get_classes() {
		return apply_filters( 'app_box_class_names', 
							array( 
								'free'			=> __('Free', 'appointments'),
								'busy'			=> __('Busy', 'appointments'),
								'notpossible'	=> __('Not possible', 'appointments')
								)
				);
	}
	
	/**
	 * Return a default color for a selected box class
	 * @return string
	 */		
	function get_preset( $class, $set ) {
		if ( 1 == $set )
			switch ( $class ) {
				case 'free'			:	return '48c048'; break;
				case 'busy'			:	return 'ffffff'; break;
				case 'notpossible'	:	return 'ffffff'; break;
				default				:	return '111111'; break;
			}
		else if ( 2 == $set )
			switch ( $class ) {
				case 'free'			:	return '73ac39'; break;
				case 'busy'			:	return '616b6b'; break;
				case 'notpossible'	:	return '8f99a3'; break;
				default				:	return '111111'; break;
			}
		else if ( 3 == $set )
			switch ( $class ) {
				case 'free'			:	return '40BF40'; break;
				case 'busy'			:	return '454C54'; break;
				case 'notpossible'	:	return '454C54'; break;
				default				:	return '111111'; break;
			}
	}
	
	/**
	 * Change status for a given app ID
	 * @return bool
	 */		
	function change_status( $stat, $app_id ) {
		global $wpdb;
		if ( !$app_id || !$stat )
			return false;
			
		$result = $wpdb->update( $this->app_table,
									array('status'	=> $stat),
									array('ID'		=> $app_id)
					);
		if ( $result ) {
			$this->flush_cache();
			do_action( 'app_change_status', $stat, $app_id );
			return true;
		}
		else
			return false;
	}


/************************************************************
* Methods for Shortcodes and those related to shortcodes only
*************************************************************
*/	

	/**
	 *	Shortcode showing user's or worker's appointments
	 */
	function my_appointments( $atts ) {
	
		extract( shortcode_atts( array(
		'provider'		=> 0, // Set this 1 to get list of worker's appointments
		'provider_id'	=> 0,
		'title'			=> __('<h3>My Appointments</h3>', 'appointments' ),
		'status'		=> 'paid,confirmed',
		'gcal'			=> 1
		), $atts ) );
		
		global $wpdb, $current_user;
		
		$statuses = explode( ',', $status );
		
		if ( !is_array( $statuses ) || empty( $statuses ) )
			return;
			
		$stat = '';
		foreach ( $statuses as $s ) {
			$stat .= " status='".$s."' OR ";
		}
		$stat = rtrim( $stat, "OR " );
		
		// If this is a client shortcode
		if ( !$provider ) {
			if ( isset( $_COOKIE["wpmudev_appointments"] ) )
				$apps = unserialize( stripslashes( $_COOKIE["wpmudev_appointments"] ) );
			else
				$apps = array();
				
			if ( !is_array( $apps) || empty( $apps ) )
				return;
				
			$provider_or_client = __('Provider', 'appointments' );
		
			$q = '';
			foreach ( $apps as $app_id ) {
				$q .= " ID=".$app_id." OR ";
			}
			$q = rtrim( $q, "OR " );
			
			// But he may as well has appointments added manually (requires being registered user)
			if ( is_user_logged_in() ) {
				$q .= " OR user=".$current_user->ID;
			}
			$results = $wpdb->get_results("SELECT * FROM " . $this->app_table . " WHERE (".$q.") AND (".$stat.") " );
		}
		else {
			$provider_or_client = __('Client', 'appointments' );
			// If no id is given, get current user
			if ( !$provider_id )
				$provider_id = $current_user->ID;
			$results = $wpdb->get_results("SELECT * FROM " . $this->app_table . " WHERE worker=".$provider_id." AND (".$stat.") " );
		}
		
		if ( !$results )
			return;

		$ret  = '';
		$ret .= '<div class="appointments-my-appointments">';
		$ret .= $title;
		$ret  = apply_filters( 'app_my_appointments_before_table', $ret );
		$ret .= '<table>';
		$ret .= '<th class="my-appointments-service">'. __('Service', 'appointments' ) . '</th><th class="my-appointments-worker">' . $provider_or_client . 
			'</th><th class="my-appointments-date">' . __('Date and time', 'appointments' ) . '</th><th class="my-appointments-status">' . __('Status', 'appointments' ) . '</th>'; 
		if ( $gcal )
			$ret .= '<th class="my-appointments-gcal">&nbsp;</th>';
		foreach ( $results as $r ) {
			$ret .= '<tr><td>';
			$ret .= $this->get_service_name( $r->service ) . '</td><td>';
			if ( !$provider )
				$ret .= $this->get_worker_name( $r->worker ) . '</td><td>';
			else
				$ret .= $this->get_client_name( $r->ID ) . '</td><td>';
			$ret .= date( $this->datetime_format, strtotime( $r->start ) ) . '</td><td>';
			$ret .= $r->status;
			$ret .= '</td>';
			if ( $gcal ) {
				$ret .= '<td><a title="'.__('Click to submit this appointment to your Google Calendar account','appointments')
				.'" href="'.$this->gcal( $r->service, strtotime( $r->start, $this->local_time), strtotime( $r->end, $this->local_time), true )
				.'" target="_blank">'.$this->gcal_image.'</a></td>';
			}
			$ret .= '</tr>';
		}
		$ret .= '</table>';
		$ret  = apply_filters( 'app_my_appointments_after_table', $ret );
		$ret .= '</div>';
		
		return $ret;
	}
	
	/**
	 * Generate dropdown menu for services
	 */	
	function services( $atts ) {

		global $wpdb;
		$this->get_lsw();
		
		extract( shortcode_atts( array(
		'select'			=> __('Please select a service:', 'appointments'),
		'show'				=> __('Show available times', 'appointments'),
		'description'		=> 'excerpt',
		'thumb_size'		=> '96,96',
		'thumb_class'		=> 'alignleft'
		), $atts ) );
		
		$services = $this->get_services();
		
		$script ='';
		$s = '';
		$e = '';
		
		$s .= '<div class="app_services">';
		$s .= '<div class="app_services_dropdown">';
		$s .= '<div class="app_services_dropdown_title">';
		$s .= $select;
		$s .= '</div>';
		$s .= '<div class="app_services_dropdown_select">';
		$s .= '<select name="app_select_services" class="app_select_services">';
		if ( $services ) {
			foreach ( $services as $service ) {
				// Check if this is the first service, so it would be displayed by default
				if ( $service->ID == $this->service ) {
					$d = '';
					$sel = ' selected="selected"';
				}
				else {
					$d = ' style="display:none"';
					$sel = '';
				}
				// Add options
				$s .= '<option value="'.$service->ID.'"'.$sel.'>'. stripslashes( $service->name ) . '</option>';
				// Include excerpts	
				$e .= '<div '.$d.' class="app_service_excerpt" id="app_service_excerpt_'.$service->ID.'" >';
				switch ( $description ) {
					case 'none'		:		break;
					case 'excerpt'	:		$e .= $this->get_excerpt( $service->page, $thumb_size, $thumb_class ); break;
					case 'content'	:		$e .= $this->get_content( $service->page, $thumb_size, $thumb_class ); break;
					default			:		$e .= $this->get_excerpt( $service->page, $thumb_size, $thumb_class ); break;
				}
				$e .= '</div>';
			}
		}
		$s .= '</select>';
		$s .= '<input type="button" class="app_services_button" value="'.$show.'">';
		$s .= '</div>';
		$s .= '</div>';
		
		$s .= '<div class="app_service_excerpts">';
		$s .= $e;
		$s .= '</div>';
		$s .= '</div>';
		$href = add_query_arg( "app_service_id", "'+selected_service" );
		
		$script .= "$('.app_select_services').change(function(){";
		$script .= "var selected_service=$('.app_select_services option:selected').val();";
		$script .= "$('.app_service_excerpt').hide();";
		$script .= "$('#app_service_excerpt_'+selected_service).show();";
		$script .= "});";
		
		$script .= "$('.app_services_button').click(function(){";
		$script .= "var selected_service=$('.app_select_services option:selected').val();";
		$script .= "window.location.href='".$href.";";
		$script .= "});";
		
		$this->script = $this->script . $script;
		
		return $s;
	}

	/**
	 * Generate dropdown menu for service providers
	 */	
	function service_providers( $atts ) {

		global $wpdb;
		$this->get_lsw();
		
		extract( shortcode_atts( array(
		'select'			=> __('Please choose a service provider:', 'appointments'),
		'show'				=> __('Show available times', 'appointments'),
		'description'		=> 'excerpt',
		'thumb_size'		=> '96,96',
		'thumb_class'		=> 'alignleft'
		), $atts ) );
		
		// Select only providers that can give this service
		if ( 0 == $this->service )
			$workers = $this->get_workers();
		else
			$workers = $this->get_workers_by_service( $this->service );
		
		$script ='';
		$s = $e = '';
		
		$s .= '<div class="app_workers">';
		$s .= '<div class="app_workers_dropdown">';
		$s .= '<div class="app_workers_dropdown_title">';
		$s .= $select;
		$s .= '</div>';
		$s .= '<div class="app_workers_dropdown_select">';
		$s .= '<select name="app_select_workers" class="app_select_workers">';
		// Do not show "Anyone" if there is only ONE provider
		if ( 1 != count( $workers ) )
			$s .= '<option value="0">'. __('Anyone', 'appointments') . '</option>';
		if ( $workers ) {
			foreach ( $workers as $worker ) {
				if ( $this->worker == $worker->ID ) {
					$d = '';
					$sel = ' selected="selected"';
				}
				else {
					$d = ' style="display:none"';
					$sel = '';
				}
				$s .= '<option value="'.$worker->ID.'"'.$sel.'>'. $this->get_worker_name( $worker->ID )  . '</option>';
				// Include excerpts	
				$e .= '<div '.$d.' class="app_worker_excerpt" id="app_worker_excerpt_'.$worker->ID.'" >';
				switch ( $description ) {
					case 'none'		:		break;
					case 'excerpt'	:		$e .= $this->get_excerpt( $worker->page, $thumb_size, $thumb_class, $worker->ID ); break;
					case 'content'	:		$e .= $this->get_content( $worker->page, $thumb_size, $thumb_class, $worker->ID ); break;
					default			:		$e .= $this->get_excerpt( $worker->page, $thumb_size, $thumb_class, $worker->ID ); break;
				}
				$e .= '</div>';

			}
		}
		$s .= '</select>';
		$s .= '<input type="button" class="app_workers_button" value="'.$show.'">';
		$s .= '</div>';
		$s .= '</div>';
		$s .= '<div class="app_worker_excerpts">';
		$s .= $e;
		$s .= '</div>';

		$s .= '</div>';
		$href = add_query_arg( "app_provider_id", "'+selected_worker" );
		
		$script .= "$('.app_select_workers').change(function(){";
		$script .= "var selected_worker=$('.app_select_workers option:selected').val();";
		$script .= "$('.app_worker_excerpt').hide();";
		$script .= "$('#app_worker_excerpt_'+selected_worker).show();";
		$script .= "});";
		
		$script .= "$('.app_workers_button').click(function(){";
		$script .= "var selected_worker=$('.app_select_workers option:selected').val();";
		$script .= "window.location.href='".$href.";";
		$script .= "});";
		
		$this->script = $this->script . $script;
		
		return $s;
	}

	/**
	 * Generate an excerpt from the selected service/worker page
	 */	
	function get_excerpt( $page_id, $thumb_size, $thumb_class, $worker_id=0 ) {
		$text = '';
		$page = get_post( $page_id );
		if ( !$page )
			return $text;
			
		$text = $page->post_content;

		$text = strip_shortcodes( $text );

		$text = apply_filters('app_the_content', $text);
		$text = str_replace(']]>', ']]&gt;', $text);
		$excerpt_length = apply_filters('app_excerpt_length', 55);
		$excerpt_more = apply_filters('app_excerpt_more', ' &hellip; <a href="'. esc_url( get_permalink($page->ID) ) . '" target="_blank">' . __( 'More information <span class="meta-nav">&rarr;</span>', 'appointments' ) . '</a>');
		$text = wp_trim_words( $text, $excerpt_length, $excerpt_more );
		
		$thumb = $this->get_thumbnail( $page_id, $thumb_size, $thumb_class, $worker_id );
		
		return apply_filters( 'app_excerpt', $thumb. $text, $page_id );
	}

	/**
	 * Fetch content from the selected service/worker page
	 */	
	function get_content( $page_id, $thumb_size, $thumb_class, $worker_id=0 ) {
		$content = '';
		$page = get_post( $page_id );
		if ( !$page )
			return $content;
			
		$thumb = $this->get_thumbnail( $page_id, $thumb_size, $thumb_class, $worker_id );

		return apply_filters('app_the_content', $thumb. $content, $page_id);
	}

	/**
	 * Get html code for thumbnail or avatar
	 */	
	function get_thumbnail( $page_id, $thumb_size, $thumb_class, $worker_id ) {
		
		if ( $thumb_size && 'none' != $thumb_size ) {
			if ( strpos( $thumb_size, 'avatar' ) !== false ) {
				if ( strpos( $thumb_size, ',' ) !== false ) {
					$size_arr = explode( ",", $thumb_size );
					$size = $size_arr[1];
				}
				else
					$size = 96;
				$thumb = get_avatar( $worker_id, $size );
				if ( $thumb_class ) {
					// Dirty, but faster than preg_replace
					$thumb = str_replace( "class='", "class='".$thumb_class." ", $thumb );
					$thumb = str_replace( 'class="', 'class="'.$thumb_class.' ', $thumb );
				}
			}
			else {
				if ( strpos( $thumb_size, ',' ) !== false )
					$size = explode( ",", $thumb_size );
				else
					$size = $thumb_size;
					
				$thumb = get_the_post_thumbnail( $page_id, $size, apply_filters( 'app_thumbnail_attr', array('class'=>$thumb_class) ) );
			}
		}
		else
			$thumb = '';
	
		return apply_filters( 'app_thumbnail', $thumb, $page_id, $worker_id );
	}

	/**
	 * Shortcode function for frontend login
	 */	
	function login( $atts ){
		extract( shortcode_atts( array(
		'login_text'	=> __('Please click here to login:', 'appointments' ),
		'redirect_text'	=> __('Login required to make an appointment. Now you will be redirected to login page.', 'appointments' )
		), $atts ) );
		
		$ret  = '';
		$ret .= '<div class="appointments-login">';
		if ( !is_user_logged_in() && $this->options["login_required"] == 'yes' ){
			$ret .= $login_text. " ";
			$ret .= '<a href="javascript:void(0)" class="appointments-login_show_login" >'. __('Login', 'appointments') . '</a>';
		}
		$ret .= '<div class="appointments-login_inner">';
		$ret .= '</div>';
		$ret .= '</div>';
		
		$script  = '';
		$script .= "$('.appointments-login_show_login').click(function(){";
		if ( !isset( $this->options["accept_api_logins"] ) ) {
			$script .= 'var app_redirect=confirm("'.esc_js($redirect_text).'");';
			$script .= ' if(app_redirect){';
			$script .= 'window.location.href= "'.wp_login_url( get_permalink() ).'";';
			$script .= '}';
		}
		else {
			$script .= '$(".appointments-login_link-cancel").focus();';
		}
		$script .= "});";
		
		$this->script = $this->script . $script;
		
		return $ret;
	}
	
	/**
	 * Shortcode function to generate a confirmation box
	 */	
	function confirmation( $atts ) {
	
		extract( shortcode_atts( array(
		'title'			=> __('<h3>Please check the appointment details below and confirm:</h3>', 'appointments' ),
		'button_text'	=> __('Please click here to confirm this appointment', 'appointments' ),
		'confirm_text'	=> __('We have received your appointment. Thanks!', 'appointments' ),
		'warning_text'	=> __('Please enter the requested field','appointments'),
		'name'			=> __('Your name:','appointments'),
		'email'			=> __('Your email:','appointments'),
		'phone'			=> __('Your phone:','appointments'),
		'address'		=> __('Your address:','appointments'),
		'city'			=> __('City:','appointments'),
		'note'			=> __('Your notes:','appointments'),
		'gcal'			=> __('Access Google Calendar and submit appointment','appointments'),
		), $atts ) );
		
		// Get user form data from his cookie
		if ( isset( $_COOKIE["wpmudev_appointments_userdata"] ) )
			$data = unserialize( stripslashes( $_COOKIE["wpmudev_appointments_userdata"] ) );
		else
			$data = array();
			
		$n = isset( $data["n"] ) ? $data["n"] : ''; // Name
		$e = isset( $data["e"] ) ? $data["e"] : ''; // Email
		$p = isset( $data["p"] ) ? $data["p"] : ''; // Phone
		$a = isset( $data["a"] ) ? $data["a"] : ''; // Address
		$c = isset( $data["c"] ) ? $data["c"] : ''; // City
		$g = isset( $data["g"] ) ? $data["g"] : ''; // GCal selection
		if ( $g )
			$gcal_checked = ' checked="checked"';
		else
			$gcal_checked = '';
			
		$ret = '';
		$ret .= '<div class="appointments-confirmation-wrapper"><fieldset>';
		$ret .= '<legend>';
		$ret .= $title;
		$ret .= '</legend>';
		$ret .= '<div class="appointments-confirmation-service">';
		$ret .= '</div>';
		$ret .= '<div class="appointments-confirmation-start">';
		$ret .= '</div>';
		$ret .= '<div class="appointments-confirmation-end">';
		$ret .= '</div>';
		$ret .= '<div class="appointments-confirmation-price" style="display:none">';
		$ret .= '</div>';
		$ret .= '<div class="appointments-name-field" style="display:none">';
		$ret .= '<label><span>'. $name . '</span><input type="text" class="appointments-name-field-entry" value="'.$n.'" /></label>';
		$ret .= '</div>';
		$ret .= '<div class="appointments-email-field" style="display:none">';
		$ret .= '<label><span>'. $email . '</span><input type="text" class="appointments-email-field-entry" value="'.$e.'" /></label>';
		$ret .= '</div>';
		$ret .= '<div class="appointments-phone-field" style="display:none">';
		$ret .= '<label><span>'. $phone . '</span><input type="text" class="appointments-phone-field-entry" value="'.$p.'" />';
		$ret .= '</div>';
		$ret .= '<div class="appointments-address-field" style="display:none">';
		$ret .= '<label><span>'. $address . '</span><input type="text" class="appointments-address-field-entry" value="'.$a.'" />';
		$ret .= '</div>';
		$ret .= '<div class="appointments-city-field" style="display:none">';
		$ret .= '<label><span>'. $city . '</span><input type="text" class="appointments-city-field-entry" value="'.$c.'" />';
		$ret .= '</div>';
		$ret .= '<div class="appointments-note-field" style="display:none">';
		$ret .= '<label><span>'. $note . '</span><input type="text" class="appointments-note-field-entry" />';
		$ret .= '</div>';
		$ret .= '<div class="appointments-gcal-field" style="display:none">';
		$ret .= '<label><span>'.$this->gcal_image.'</span><input type="checkbox" class="appointments-gcal-field-entry" '.$gcal_checked.' />&nbsp;';
		$ret .= $gcal;
		$ret .= '</div>';
		$ret  = apply_filters( 'app_additional_fields', $ret ); 
		$ret .= '<div style="clear:both"></div>';
		$ret .= '<div class="appointments-confirmation-buttons">';
		$ret .= '<input type="hidden" class="appointments-confirmation-final-value" />';
		$ret .= '<input type="button" class="appointments-confirmation-button" value="'.$button_text.'" />';
		$ret .= '<input type="button" class="appointments-confirmation-cancel-button" value="'.__('Cancel', 'appointments').'" />';
		$ret .= '</div>';
		$ret .= '</fieldset></div>';
		$ret  = apply_filters( 'app_confirmation_fields', $ret );
		
		$script  = '';
		$script .= 'var wait_img= "<img class=\'wait_img\' src=\''.plugins_url('appointments/images/waiting.gif'). '\' />";';
		if ( is_user_logged_in() || 'yes' != $this->options["login_required"] ) {
			$script .= '$(".appointments-list table td.free, .app_timetable div.free").not(".app_monthly_schedule_wrapper table td.free").click(function(){';
			$script .= '$(this).css("text-align","center").append(wait_img);';
			$script .= 'var app_value = $(this).find(".appointments_take_appointment").val();';
			$script .= '$(".appointments-confirmation-final-value").val(app_value);';
			$script .= 'var pre_data = {action: "pre_confirmation", value: app_value, nonce: "'. wp_create_nonce() .'"};';
			$script .= '$.post(_appointments_data.ajax_url, pre_data, function(response) {
						$(".wait_img").remove();
						if ( response && response.error )
							alert(response.error);
						else{
							$(".appointments-confirmation-wrapper").show();
							$(".appointments-confirmation-service").html(response.service);
							$(".appointments-confirmation-start").html(response.start);
							$(".appointments-confirmation-end").html(response.end);
							$(".appointments-confirmation-price").html(response.price);
							if (response.price != "0"){
								$(".appointments-confirmation-price").show();
							}
							if (response.name =="ask"){
								$(".appointments-name-field").show();
							}
							if (response.email =="ask"){
								$(".appointments-email-field").show();
							}
							if (response.phone =="ask"){
								$(".appointments-phone-field").show();
							}
							if (response.address =="ask"){
								$(".appointments-address-field").show();
							}
							if (response.city =="ask"){
								$(".appointments-city-field").show();
							}
							if (response.note =="ask"){
								$(".appointments-note-field").show();
							}
							if (response.gcal =="ask"){
								$(".appointments-gcal-field").show();
							}
							if (response.additional =="ask"){
								$(".appointments-additional-field").show();
							}
							$(".appointments-confirmation-button").focus();
					}
					},"json");';
			$script .= '});';
		}
		
		$script .= '$(".appointments-confirmation-cancel-button").click(function(){';
		$script .= 'window.location.href=window.location.href;';
		$script .= '});';
		
		$script .= '$(".appointments-confirmation-button").click(function(){';
		$script .= 'var final_value = $(".appointments-confirmation-final-value").val();';
		$script .= 'var app_name = $(".appointments-name-field-entry").val();';
		$script .= 'var app_email = $(".appointments-email-field-entry").val();';
		$script .= 'var app_phone = $(".appointments-phone-field-entry").val();';
		$script .= 'var app_address = $(".appointments-address-field-entry").val();';
		$script .= 'var app_city = $(".appointments-city-field-entry").val();';
		$script .= 'var app_note = $(".appointments-note-field-entry").val();';
		$script .= 'var app_gcal = "";';
		$script .= 'if ($(".appointments-gcal-field-entry").is(":checked")){app_gcal=1;}';
		$script .= 'var post_data = {action: "post_confirmation", value: final_value, app_name: app_name, app_email: app_email, app_phone: app_phone, app_address: app_address, app_city: app_city, app_note: app_note, app_gcal: app_gcal, nonce: "'. wp_create_nonce() .'"};';
		if ( $this->options["ask_name"] ) {
		$script .= 'if($(".appointments-name-field-entry").val()=="" ) {';
			$script .= 'alert("'.esc_js($warning_text).'");';
			$script .= '$(".appointments-name-field-entry").focus();';
			$script .= 'return false;';
			$script .= '}';
		}
		if ( $this->options["ask_email"] ) {
		$script .= 'if($(".appointments-email-field-entry").val()=="" ) {';
			$script .= 'alert("'.esc_js($warning_text).'");';
			$script .= '$(".appointments-email-field-entry").focus();';
			$script .= 'return false;';
			$script .= '}';
		}
		if ( $this->options["ask_phone"] ) {
		$script .= 'if($(".appointments-phone-field-entry").val()=="" ) {';
			$script .= 'alert("'.esc_js($warning_text).'");';
			$script .= '$(".appointments-phone-field-entry").focus();';
			$script .= 'return false;';
			$script .= '}';
		}
		if ( $this->options["ask_address"] ) {
		$script .= 'if($(".appointments-address-field-entry").val()=="" ) {';
			$script .= 'alert("'.esc_js($warning_text).'");';
			$script .= '$(".appointments-address-field-entry").focus();';
			$script .= 'return false;';
			$script .= '}';
		}
		if ( $this->options["ask_city"] ) {
		$script .= 'if($(".appointments-city-field-entry").val()=="" ) {';
			$script .= 'alert("'.esc_js($warning_text).'");';
			$script .= '$(".appointments-city-field-entry").focus();';
			$script .= 'return false;';
			$script .= '}';
		}
		$script .= '$(".appointments-confirmation-cancel-button").after(wait_img);';
		$script .= '$.post(_appointments_data.ajax_url, post_data, function(response) {
						$(".wait_img").remove();
						if ( response && response.error ) {
							alert(response.error);
						}
						else if ( response && ( response.refresh=="1" || response.price==0 ) ) {
							alert("'.esc_js($confirm_text).'");
							if ( response.gcal_url != "" ) {
								window.open(response.gcal_url,"_blank");
							}
							else {
								window.location.href=window.location.href;
							}
						}
						else if ( response ) {
							$(".appointments-paypal").find(".app_amount").val(response.price);
							$(".appointments-paypal").find(".app_custom").val(response.app_id);
							var old_val = $(".appointments-paypal").find(".app_submit_btn").val();
							var new_val = old_val.replace("PRICE",response.price).replace("SERVICE",response.service_name);
							$(".appointments-paypal").find(".app_submit_btn").val(new_val);
							var old_val2 = $(".appointments-paypal").find(".app_item_name").val();
							var new_val2 = old_val2.replace("SERVICE",response.service_name);
							$(".appointments-paypal").find(".app_item_name").val(new_val2);
							$(".appointments-paypal .app_submit_btn").focus();
							if ( response.gcal_url != "" ) {
								window.open(response.gcal_url,"_blank");
							}
							if ( response.mp == 1 ) {
								$(".mp_buy_form input[name=\'variation\']").val(response.var);
								$(".mp_buy_form").show();
							}
							else {
								$(".appointments-paypal").show();							
							}
						}
						else{
							alert("'.esc_js(__('A connection problem occurred. Please try again.','appointments')).'");
						}
					},"json");';
		$script .= '});';
		
		$this->script = $this->script . $script;
		
		return $ret;
	}
	
	/**
	 * Check and return necessary fields to the front end
	 * @return json object
	 */
	function pre_confirmation() {    
		$values 		= explode( ":", $_POST["value"] );
		$location 		= $values[0];
		$service 		= $values[1];
		$worker 		= $values[2];
		$start 			= $values[3];
		$end 			= $values[4];
		$post_id		= $values[5];

		// A little trick to pass correct lsw variables to the get_price, is_busy and get_capacity functions
		$_REQUEST["app_location_id"] = $location;
		$_REQUEST["app_service_id"] = $service;
		$_REQUEST["app_provider_id"] = $worker;
		$this->get_lsw();

		$price = $this->get_price( );
			
		// It is possible to apply special discounts
		$price = apply_filters( 'app_display_amount', $price, $service, $worker );
		
		global $wpdb;
		
		if ( $this->is_busy( $start,  $end, $this->get_capacity() ) )
			die( json_encode( array("error"=>apply_filters( 'app_booked_message',__( 'We are sorry, but this time slot is no more available. It seems that it has been just booked. Please refresh the page and try another time slot.', 'appointments')))));
		
		$service_obj = $this->get_service( $service );		
		$service = '<label><span>'. __('Service name: ', 'appointments' ).  '</span>'. stripslashes( $service_obj->name ) . '</label>';
		$start = '<label><span>'.__('Date and time: ', 'appointments' ). '</span>'. date_i18n( $this->datetime_format, $start ) . '</label>';
		$end = '<label><span>'.__('Lasts (approx): ', 'appointments' ). '</span>'. $service_obj->duration . " ". __('minutes', 'appointments') . '</label>';
		if ( $price > 0 )
			$price = '<label><span>'.__('Price: ', 'appointments' ).  '</span>'. $price . " " . $this->options["currency"] . '</label>';
		else
			$price = 0;
			
		if ( $this->options["ask_name"] )
			$ask_name = "ask";
		else
			$ask_name = "";
			
		if ( $this->options["ask_email"] )
			$ask_email = "ask";
		else
			$ask_email = "";
			
		if ( $this->options["ask_phone"] )
			$ask_phone = "ask";
		else
			$ask_phone = "";
			
		if ( $this->options["ask_address"] )
			$ask_address = "ask";
		else
			$ask_address = "";
			
		if ( $this->options["ask_city"] )
			$ask_city = "ask";
		else
			$ask_city = "";
		
		if ( $this->options["ask_note"] )
			$ask_note = "ask";
		else
			$ask_note = "";
			
		if ( isset( $this->options["gcal"] ) && 'yes' == $this->options["gcal"] )
			$ask_gcal = "ask";
		else
			$ask_gcal = "";
			
		$reply_array = array(
							'service'	=> $service,
							'start'		=> $start,
							'end'		=> $end,
							'price'		=> $price,
							'name'		=> $ask_name,
							'email'		=> $ask_email,
							'phone'		=> $ask_phone,
							'address'	=> $ask_address,
							'city'		=> $ask_city,
							'note'		=> $ask_note,
							'gcal'		=> $ask_gcal
						); 
			
		$reply_array = apply_filters( 'app_pre_confirmation_reply', $reply_array );
			
		die( json_encode( $reply_array ));
	}
	
	/**
	 * Save appointment
	 * @return json object
	 */
	function post_confirmation() {

		if ( !$this->check_spam() )
			die( json_encode( array("error"=>apply_filters( 'app_spam_message',__( 'You have already applied for an appointment. Please wait until you hear from us.', 'appointments')))));

		global $wpdb, $current_user, $post;

		$values 		= explode( ":", $_POST["value"] );
		$location 		= $values[0];
		$service 		= $values[1];
		$worker 		= $values[2];
		$start 			= $values[3];
		$end 			= $values[4];
		$post_id		= $values[5];
		
		if ( is_user_logged_in( ) ) {
			$user_id = $current_user->ID;
			$userdata = get_userdata( $current_user->ID );
			$user_email = $userdata->email;
			
			$user_name = $userdata->display_name;
			if ( !$user_name )
				$user_name = $userdata->first_name . " " . $userdata->last_name;
			if ( "" == trim( !$user_name ) )
				$user_name = $userdata->user_login;
		}
		else{
			$user_id = 0;
			$user_email = '';
			$user_name = '';
		}
		
		// A little trick to pass correct lsw variables to the get_price, is_busy and get_capacity functions
		$_REQUEST["app_location_id"] = $location;
		$_REQUEST["app_service_id"] = $service;
		$_REQUEST["app_provider_id"] = $worker;
		$this->get_lsw();
		
		// Default status
		$status = 'pending';

		// We may have 2 prices now: 1) Service price, 2) Amount that will be paid to Paypal
		$price = $this->get_price( );
		$paypal_price = $this->get_price( true );
			
		if ( isset( $_POST["app_name"] ) )
			$name = sanitize_text_field( $_POST["app_name"] );
		else
			$name = $user_name;
			
		$name_check = apply_filters( "app_name_check", true, $name );
		if ( !$name_check )
			$this->json_die( 'name' );	
			
		if ( isset( $_POST["app_email"] ) )
			$email = $_POST["app_email"];
		else
			$email = $user_email;
			
		if ( $this->options["ask_email"] && !is_email( $email ) )
			$this->json_die( 'email' );
			
		if ( isset( $_POST["app_phone"] ) )
			$phone = sanitize_text_field( $_POST["app_phone"] );
		else
			$phone = '';
			
		$phone_check = apply_filters( "app_phone_check", true, $phone );
		if ( !$phone_check )
			$this->json_die( 'phone' );
			
		if ( isset( $_POST["app_address"] ) )
			$address = sanitize_text_field( $_POST["app_address"] );
		else
			$address = '';

		$address_check = apply_filters( "app_address_check", true, $address );
		if ( !$address_check )
			$this->json_die( 'address' );
			
		if ( isset( $_POST["app_city"] ) )
			$city = sanitize_text_field( $_POST["app_city"] );
		else
			$city = '';
			
		$city_check = apply_filters( "app_city_check", true, $city );
		if ( !$city_check )
			$this->json_die( 'city' );		
		
		if ( isset( $_POST["app_note"] ) )
			$note = sanitize_text_field( $_POST["app_note"] );
		else
			$note = '';
			
		if ( isset( $_POST["app_gcal"] ) && $_POST["app_gcal"] )
			$gcal = $_POST["app_gcal"];
		else
			$gcal = '';
			
		// It may be required to add additional data here 	
		$note = apply_filters( 'app_note_field', $note );
		
		$service_result = $this->get_service( $service );
		
		if ( $service_result !== null )
			$duration = $service_result->duration;
		if ( !$duration )
			$duration = $this->min_time; // In minutes
		
		if ( $this->is_busy( $start,  $start + ($duration * 60 ), $this->get_capacity() ) )
			die( json_encode( array("error"=>apply_filters( 'app_booked_message', __( 'We are sorry, but this time slot is no more available. It seems that it has been just booked. Please refresh the page and try another time slot.', 'appointments')))));
				
		$status = apply_filters( 'app_post_confirmation_status', $status, $price, $service, $worker, $user_id );
		
		$result = $wpdb->insert( $this->app_table,
							array(
								'created'	=>	date ("Y-m-d H:i:s", $this->local_time ),
								'user'		=>	$user_id,
								'name'		=>	$name,
								'email'		=>	$email,
								'phone'		=>	$phone,
								'address'	=>	$address,
								'city'		=>	$city,
								'location'	=>	$location,
								'service'	=>	$service,
								'worker'	=> 	$worker,
								'price'		=>	$price,
								'status'	=>	$status,
								'start'		=>	date ("Y-m-d H:i:s", $start),
								'end'		=>	date ("Y-m-d H:i:s", $start + ($duration * 60 ) ),
								'note'		=>	$note
							)
						);
		if ( !$result ) {
			die( json_encode( array("error"=>__( 'Appointment could not be saved. Please contact website admin.', 'appointments'))));
		}

		// A new appointment is accepted, so clear cache
		$this->flush_cache();
		$this->save_cookie( $wpdb->insert_id, $name, $email, $phone, $address, $city, $gcal );
		do_action( 'app_new_appointment', $wpdb->insert_id );
		
		// Send confirmation for pending, non payment required cases, if selected so
		if ( 'yes' != $this->options["payment_required"] && isset( $this->options["send_notification"] ) 
			&& $this->options["send_notification"] && 'pending' == $status )
			$this->send_notification( $wpdb->insert_id );
		
		if ( isset( $this->options["gcal"] ) && 'yes' == $this->options["gcal"] && $gcal )
			$gcal_url = $this->gcal( $service, $start, $end );
		else
			$gcal_url = '';
		
		// Check if this is a App Product page and add variation if it is
		$post = get_post( $post_id );
		if ( $this->check_marketpress_plugin() && 'product' == $post->post_type && strpos( $post->post_content, '[app_' ) !== false ) {
			$mp = 1;
			$var = $this->add_variation( $wpdb->insert_id, $post_id );
		}
		else
			$mp = $var = 0;

		if ( isset( $this->options["payment_required"] ) && 'yes' == $this->options["payment_required"] ) {
			die( json_encode( 
							array(
							"cell"			=> $_POST["value"], 
							"app_id"		=> $wpdb->insert_id,
							"refresh"		=> 0,
							"price"			=> $paypal_price,
							"service_name"	=> stripslashes( $service_result->name ),
							'gcal_url'		=> $gcal_url,
							'mp'			=> $mp,
							'var'			=> $var
							)
						)
					);
		}
		else {
			die( json_encode( 
							array(
							"cell"			=> $_POST["value"], 
							"app_id"		=> $wpdb->insert_id,
							"refresh"		=> 1,
							'gcal_url'		=> $gcal_url
							)
				));
		}
	}
	
	/**
	 * Build GCal url. It requires UTC time.
	 * @param start: Timestamp of the start of the app
	 * @param end: Timestamp of the end of the app
	 * @param php: If this is called for php. If false, called for js
	 * @return string
	 */
	function gcal( $service, $start, $end, $php=false ) {
		// Find time difference from Greenwich as GCal asks UTC
		$tdif = current_time('timestamp') - time();
		$text = sprintf( __('%s Appointment', 'appointments'), $this->get_service_name( $service ) );
		if ( !$php )
			$text = esc_js( $text );
			
		if ( isset( $this->options["gcal_location"] ) && '' != trim( $this->options["gcal_location"] ) )
			$location = esc_js( $this->options["gcal_location"] );
		else
			$location = esc_js( get_bloginfo( 'description' ) );
			
		$param = array(
					'action'	=> 'TEMPLATE',
					'text'		=> $text,
					'dates'		=> date( "Ymd\THis\Z", $start - $tdif ) . "/" . date( "Ymd\THis\Z", $end - $tdif ),
					'sprop'		=> 'website:' . home_url(),
					'location'	=> $location
				);
				
		return add_query_arg( apply_filters( 'app_gcal_variables', $param, $service, $start, $end ), 
				'http://www.google.com/calendar/event' );
	}
	
	/**
	 * Die showing which field has a problem
	 * @return json object
	 */
	function json_die( $field_name ) {
		die( json_encode( array("error"=>sprintf( __( 'Something wrong about the submitted %s', 'appointments'), $this->get_field_name($field_name)))));
	}

	/**
	 * Check for too frequent back to back apps
	 * return true means no spam
	 * @return bool
	 */
	function check_spam() {
		global $wpdb;
		if ( !isset( $this->options["spam_time"] ) || !$this->options["spam_time"] || 
			!isset( $_COOKIE["wpmudev_appointments"] ) )
			return true;
			
		$apps = unserialize( stripslashes( $_COOKIE["wpmudev_appointments"] ) );
		
		if ( !is_array( $apps ) || empty( $apps ) )
			return true;
			
		// Get details of the appointments
		$q = '';
		foreach ( $apps as $app_id ) {
			$q .= " ID=".$app_id." OR ";
		}
		$q = rtrim( $q, "OR " );
		
		$checkdate = date( 'Y-m-d H:i:s', $this->local_time - $this->options["spam_time"] );  
		
		$results = $wpdb->get_results("SELECT * FROM " . $this->app_table . " WHERE created>'".$checkdate."' AND status='pending' AND (".$q.")  " );
		// A recent app is found
		if ( $results )
			return false;
		
		return true;
	}
	
	/**
	 *	Prepare the Paypal form
	 */
	function paypal( $atts ) {
	
		extract( shortcode_atts( array(
		'item_name'		=> __('Payment for SERVICE', 'appointments'),
		'button_text'	=> __('Please confirm PRICE CURRENCY payment for SERVICE', 'appointments' )
		), $atts ) );
		
		if ( 'Payment for SERVICE' == $item_name && ( ( isset( $this->options["percent_deposit"] ) && $this->options["percent_deposit"] ) 
		|| ( isset( $this->options["fixed_deposit"] ) && $this->options["fixed_deposit"] ) ) )
			$item_name = __('Deposit for SERVICE', 'appointments');
		
		// Let's be on the safe side and select the default currency
		if(empty($this->options['currency']))
			$this->options['currency'] = 'USD';

		$form = '';
		
		$form .= '<div class="appointments-paypal">';

		global $post, $current_user;
		
		if ( !isset( $this->options["return"] ) || !$return = get_permalink( $this->options["return"] ) )
			$return = get_permalink( $post->ID );
		// Never let an undefined page, just in case
		if ( !$return )
			$return = home_url();

		if ($this->options['mode'] == 'live') {
			$form .= '<form action="https://www.paypal.com/cgi-bin/webscr" method="post">';
		} else {
			$form .= '<form action="https://www.sandbox.paypal.com/cgi-bin/webscr" method="post">';
		}
		$form .= '<input type="hidden" name="business" value="' . esc_attr($this->options['merchant_email']) . '" />';
		$form .= '<input type="hidden" name="cmd" value="_xclick">';
		$form .= '<input type="hidden" class="app_item_name" name="item_name" value="' . $item_name . '" />';
		$form .= '<input type="hidden" name="no_shipping" value="1" />';
		$form .= '<input type="hidden" name="currency_code" value="' . $this->options['currency'] .'" />';
		$form .= '<input type="hidden" name="return" value="' . $return . '" />';
		$form .= '<input type="hidden" name="cancel_return" value="' . get_option('home') . '" />';
		$form .= '<input type="hidden" name="notify_url" value="' . admin_url('admin-ajax.php?action=app_paypal_ipn') . '" />';
		$form .= '<input type="hidden" name="src" value="0" />';
		$form .= '<input class="app_custom" type="hidden" name="custom" value="" />';
		$form .= '<input class="app_amount" type="hidden" name="amount" value="" />';
		$form .= '<input class="app_submit_btn';
		// Add a class if user not logged in. May be required for addons.
		if ( !is_user_logged_in() )
			$form .= ' app_not_loggedin';
		$form .= '" type="submit" name="submit_btn" value="'. str_replace( 
				array("CURRENCY"), array($this->options["currency"]), $button_text).'" />';
		
		// They say Paypal uses this for tracking. I would prefer to remove it if it is not mandatory.
		$form .= '<img style="display:none" alt="" border="0" width="1" height="1" src="https://www.paypal.com/en_US/i/scr/pixel.gif" />';
		
		$form = apply_filters( 'app_paypal_additional_fields', $form, $this->location, $this->service, $this->worker );
		
		$form .= '</form>';
		
		$form .= '</div>';

		return $form;
	}
	
	/**
	 *	IPN handling for Paypal
	 */	
	function handle_paypal_return() {
		// PayPal IPN handling code
		$this->options = get_option( 'appointments_options' );

		if ((isset($_POST['payment_status']) || isset($_POST['txn_type'])) && isset($_POST['custom'])) {

			if ($this->options['mode'] == 'live') {
				$domain = 'https://www.paypal.com';
			} else {
				$domain = 'https://www.sandbox.paypal.com';
			}

			$req = 'cmd=_notify-validate';
			if (!isset($_POST)) $_POST = $HTTP_POST_VARS;
			foreach ($_POST as $k => $v) {
				if (get_magic_quotes_gpc()) $v = stripslashes($v);
				$req .= '&' . $k . '=' . $v;
			}

			$header = 'POST /cgi-bin/webscr HTTP/1.0' . "\r\n"
					. 'Content-Type: application/x-www-form-urlencoded' . "\r\n"
					. 'Content-Length: ' . strlen($req) . "\r\n"
					. "\r\n";

			@set_time_limit(60);
			if ($conn = @fsockopen($domain, 80, $errno, $errstr, 30)) {
				fputs($conn, $header . $req);
				socket_set_timeout($conn, 30);

				$response = '';
				$close_connection = false;
				while (true) {
					if (feof($conn) || $close_connection) {
						fclose($conn);
						break;
					}

					$st = @fgets($conn, 4096);
					if ($st === false) {
						$close_connection = true;
						continue;
					}

					$response .= $st;
				}

				$error = '';
				$lines = explode("\n", str_replace("\r\n", "\n", $response));
				// looking for: HTTP/1.1 200 OK
				if (count($lines) == 0) $error = 'Response Error: Header not found';
				else if (substr($lines[0], -7) != ' 200 OK') $error = 'Response Error: Unexpected HTTP response';
				else {
					// remove HTTP header
					while (count($lines) > 0 && trim($lines[0]) != '') array_shift($lines);

					// first line will be empty, second line will have the result
					if (count($lines) < 2) $error = 'Response Error: No content found in transaction response';
					else if (strtoupper(trim($lines[1])) != 'VERIFIED') $error = 'Response Error: Unexpected transaction response';
				}

				if ($error != '') {
					$this->log( $error );
					exit;
				}
			}

			// We are using server time. Not Paypal time.
			$timestamp = $this->local_time;
			
			$new_status = false;
			// process PayPal response
			switch ($_POST['payment_status']) {
				case 'Partially-Refunded':
					break;

				case 'In-Progress':
					break;

				case 'Completed':
				case 'Processed':
					// case: successful payment
					$amount = $_POST['mc_gross'];
					$currency = $_POST['mc_currency'];
					
					$this->record_transaction($_POST['custom'], $amount, $currency, $timestamp, $_POST['txn_id'], $_POST['payment_status'], '');
					if ( $this->change_status( 'paid', $_POST['custom'] ) )
						$this->send_confirmation( $_POST['custom'] );
					else {
						// Something wrong. Warn admin
						$admin_email = get_site_option('admin_email');
						if ( !$admin_email )
							$admin_email = 'admin@' . $current_site->domain;
							
						$message = sprintf( __('Paypal confirmation arrived, but status could not be changed for some reason. Please check appointment with ID %s', 'appointments'), $_POST['custom'] );
							
						wp_mail( $admin_email, __('Appointment status could not be changed','appointments'), $message, $this->message_headers() );
					}
					break;

				case 'Reversed':
					// case: charge back
					$note = __('Last transaction has been reversed. Reason: Payment has been reversed (charge back)', 'appointments');
					$amount = $_POST['mc_gross'];
					$currency = $_POST['mc_currency'];
					
					$this->record_transaction($_POST['custom'], $amount, $currency, $timestamp, $_POST['txn_id'], $_POST['payment_status'], $note);
					break;

				case 'Refunded':
					// case: refund
					$note = __('Last transaction has been reversed. Reason: Payment has been refunded', 'appointments');
					$amount = $_POST['mc_gross'];
					$currency = $_POST['mc_currency'];
					
					$this->record_transaction($_POST['custom'], $amount, $currency, $timestamp, $_POST['txn_id'], $_POST['payment_status'], $note);
					break;

				case 'Denied':
					// case: denied
					$note = __('Last transaction has been reversed. Reason: Payment Denied', 'appointments');
					$amount = $_POST['mc_gross'];
					$currency = $_POST['mc_currency'];
					
					$this->record_transaction($_POST['custom'], $amount, $currency, $timestamp, $_POST['txn_id'], $_POST['payment_status'], $note);

					break;

				case 'Pending':
					// case: payment is pending
					$pending_str = array(
						'address' => __('Customer did not include a confirmed shipping address', 'appointments'),
						'authorization' => __('Funds not captured yet', 'appointments'),
						'echeck' => __('eCheck that has not cleared yet', 'appointments'),
						'intl' => __('Payment waiting for aproval by service provider', 'appointments'),
						'multi-currency' => __('Payment waiting for service provider to handle multi-currency process', 'appointments'),
						'unilateral' => __('Customer did not register or confirm his/her email yet', 'appointments'),
						'upgrade' => __('Waiting for service provider to upgrade the PayPal account', 'appointments'),
						'verify' => __('Waiting for service provider to verify his/her PayPal account', 'appointments'),
						'*' => ''
						);
					$reason = @$_POST['pending_reason'];
					$note = __('Last transaction is pending. Reason: ', 'appointments') . (isset($pending_str[$reason]) ? $pending_str[$reason] : $pending_str['*']);
					$amount = $_POST['mc_gross'];
					$currency = $_POST['mc_currency'];
					
					// Save transaction.
					$this->record_transaction($_POST['custom'], $amount, $currency, $timestamp, $_POST['txn_id'], $_POST['payment_status'], $note);

					break;

				default:
					// case: various error cases
			}
		} else {
			// Did not find expected POST variables. Possible access attempt from a non PayPal site.
			// This is IPN response, so echoing will not help. Let's log it.
			$this->log( 'Error: Missing POST variables. Identification is not possible.' );
			exit;
		}
		exit;
	}
	
	/**
	 * Shortcode function to generate pagination links. Includes legend area
	 */	
	function pagination( $atts ) {
	
		extract( shortcode_atts( array(
		'step'	=> 1,
		'month'	=> 0
		), $atts ) );
		
		if ( isset( $_GET["wcalendar"] ) )
			$time = $_GET["wcalendar"] ;
		else
			$time = $this->local_time;

		$c = '';
		$script = '';
		// Legends
		if ( isset( $this->options['show_legend'] ) && 'yes' == $this->options['show_legend'] ) {
			$c .= '<div class="appointments-legend">';
			$c .= '<table class="appointments-legend-table">';
			$n = 0;
			$c .= '<tr>';
			foreach ( $this->get_classes() as $class=>$name ) {
				$c .= '<td class="class-name">' .$name . '</td>';
				$c .= '<td class="'.$class.'">&nbsp;</td>';
				$n++;
				if ( 3 == $n )
				$c .= '</tr><tr>';
			}
			$c .= '</tr>';
			$c .= '</table>';
			$c .= '</div>';
			// Do not let clicking box inside legend area
			$script .= '$("table.appointments-legend-table td.free").click(false);';
		}
		
		// Pagination
		$c .= '<div class="appointments-pagination">';
		if ( !$month ) {
			$prev = $time - ($step*7*86400); 
			$next = $time + ($step*7*86400);
			$prev_min = $this->local_time - $step*7*86400;
			$next_max = $this->local_time + ($this->app_limit + 7*$step ) *86400;
			$month_week = __('Week', 'appointments');
		}
		else {
			$prev = $this->first_of_month( $time, -1 * $step );
			$next = $this->first_of_month( $time, $step );
			$prev_min = $this->first_of_month( $this->local_time, -1 * $step );
			$next_max = $this->first_of_month( $this->local_time, $step ) + $this->app_limit * 86400;
			$month_week = __('Month', 'appointments');
		}
		
		if ( $prev > $prev_min ) {
			$c .= '<div class="previous">';
			$c .= '<a href="'. add_query_arg( "wcalendar", $prev ) .'#app_schedule"><< '. __('Previous', 'appointments') . ' '. $month_week. '</a>';
			$c .= '</div>';
		}
		if ( $next < $next_max ) {
			$c .= '<div class="next">';
			$c .= '<a href="'. add_query_arg( "wcalendar", $next ). '#app_schedule">'. __('Next', 'appointments') . ' ' . $month_week. ' >></a>';
			$c .= '</div>';
		}
		$c .= '<div style="clear:both"></div>';
		$c .= '</div>';
		
		$this->script = $this->script . $script;
		
		return $c;
	}

	/**
	 * Find timestamp of first day of month for a given time
	 * @param time: input whose first day will be found
	 * @param add: how many months to add
	 * @return integer (timestamp)
	 * @since 1.0.4
	 */	
	function first_of_month( $time, $add ) {
		$year = date( "Y", $time );
		$month = date( "n",  $time ); // Notice "n"
		
		return mktime( 0, 0, 0, $month+$add, 1, $year );
	}	
	
	/**
	 * Shortcode function to generate monthly calendar
	 */	
	function monthly_calendar( $atts ) {
	
		extract( shortcode_atts( array(
		'title'			=> __('<h3>Our schedule for START</h3>', 'appointments'),
		'logged'		=> __('Click a free day to apply for an appointment.', 'appointments'),
		'notlogged'		=> __('You need to login to make an appointment. Please click here to register/login: LOGIN_PAGE', 'appointments'),
		'service'		=> 0,
		'worker'		=> 0,
		'long'			=> 0,
		'class'			=> '',
		'add'			=> 0,
		'widget'		=> 0
		), $atts ) );
		
		// Force service and worker
		if ( $service )
			$_REQUEST["app_service_id"] = $service;
		if ( $worker )
			$_REQUEST["app_provider_id"] = $worker;
			
		if ( isset( $_GET["wcalendar"] ) )
			$time = $this->first_of_month( $_GET["wcalendar"], $add  );
		else
			$time = $this->first_of_month( $this->local_time, $add  );
			
		$year = date("Y", $time);
		$month = date("m",  $time);
		
		if ( '' != $title )
			$title = str_replace( 
								array( "START"),
								array( 
									date_i18n("F Y",  strtotime("{$year}-{$month}-01") )
									),
								$title
						);
		else
			$title = '';
			
		$c  = '';
        $c .= '<div class="appointments-wrapper">';
        $c .= $title;
		
		if ( is_user_logged_in() || 'yes' != $this->options["login_required"] ) {
			$c .= $logged;
		}
		else if ( !isset( $this->options["accept_api_logins"] ) )
			$c .= str_replace( 'LOGIN_PAGE', '<a class="appointments-login_show_login" href="'.site_url( 'wp-login.php').'">'. __('Login','appointments'). '</a>', $notlogged );
		else {
			$c .= '<div class="appointments-login">';
			$c .= str_replace( 'LOGIN_PAGE', '<a class="appointments-login_show_login" href="javascript:void(0)">'. __('Login','appointments'). '</a>', $notlogged );
			$c .= '<div class="appointments-login_inner">';
			$c .= '</div>';
			$c .= '</div>';
		}
		
		$c .= '<div class="appointments-list">';
 		$c .= $this->get_monthly_calendar($time, $class, $long, $widget);
			
		$c .= '</div>
		</div>';
		$script = '';

		$this->script = $this->script . $script;
		
		return $c;
	}

	/**
	 * Helper function to create a monthly schedule
	 */	
	function get_monthly_calendar( $timestamp=false, $class='', $long, $widget ) {
		global $wpdb;
		
		$this->get_lsw();
	
		$price = $this->get_price( );
		
		$date = $timestamp ? $timestamp : $this->local_time;
	
		$year = date("Y", $date);
		$month = date("m",  $date);
		$time = strtotime("{$year}-{$month}-01");
		
		$days = (int)date('t', $time);
		$first = (int)date('w', strtotime(date('Y-m-01', $time)));
		$last = (int)date('w', strtotime(date('Y-m-' . $days, $time)));
		
		$tbl_class = $class;
		$tbl_class = $tbl_class ? "class='{$tbl_class}'" : '';
		
		$ret = '';
		$ret .= '<div class="app_monthly_schedule_wrapper">';
		
		$ret .= '<a name="app_schedule">&nbsp;</a>';
		$ret  = apply_filters( 'app_monthly_schedule_before_table', $ret );
		$ret .= "<table width='100%' {$tbl_class}>";
		$ret .= $this->_get_table_meta_row_monthly('thead', $long);
		$ret .= '<tbody>';
		
		$ret = apply_filters( 'app_monthly_schedule_before_first_row', $ret );
		
		if ( $first > $this->start_of_week )
			$ret .= '<tr><td class="no-left-border" colspan="' . ($first - $this->start_of_week) . '">&nbsp;</td>';
		else if ( $first < $this->start_of_week )
			$ret .= '<tr><td class="no-left-border" colspan="' . (7 + $first - $this->start_of_week) . '">&nbsp;</td>';
		else
			$ret .= '<tr>';
		
		$todays_no = date("w", $this->local_time ); // Number of today
		$working_days = $this->get_working_days( $this->worker, $this->location ); // Get an array of working days
		$capacity = $this->get_capacity();
		$time_table = '';
		
		for ($i=1; $i<=$days; $i++) {
			$date = date('Y-m-' . sprintf("%02d", $i), $time);
			$dow = (int)date('w', strtotime($date));
			$ccs = strtotime("{$date} 00:00"); 
			$cce = strtotime("{$date} 23:59");
			if ($this->start_of_week == $dow) 
				$ret .= '</tr><tr>';
				
			$class_name = '';
			// First mark passed days
			if ( $this->local_time > $cce )
				$class_name = 'notpossible';
			// Check today is holiday
			else if ( $this->is_holiday( $ccs, $cce ) )
				$class_name = 'notpossible';
			// Check if we are working today
			else if ( !in_array( date("l", $ccs ), $working_days ) && !$this->is_exceptional_working_day( $ccs, $cce ) )
				$class_name = 'notpossible';
			// Check if we are exceeding app limit at the end of day
			else if ( $cce > $this->local_time + ( $this->app_limit + 1 )*86400 )
				$class_name = 'notpossible';
			// If nothing else, then it must be free
			else {
				$class_name = 'free';
				// Do not add timetable for widget
				if ( !$widget )
					$time_table .= $this->get_timetable( $ccs, $capacity );
			}
			// Check for today
			if ( $this->local_time > $ccs && $this->local_time < $cce )
				$class_name = $class_name . ' today';

			
			$ret .= '<td class="'.$class_name.'" title="'.date_i18n($this->date_format, $ccs).'"><p>'.$i.'</p>
			<input type="hidden" class="appointments_select_time" value="'.$ccs .'" /></td>';
		
		}
		if ( $last > $this->start_of_week )
			$ret .= '<td class="no-right-border" colspan="' . (6 - $last + $this->start_of_week) . '">&nbsp;</td></tr>'; 
		else if ( $last + 1 == $this->start_of_week )
			$ret .= '</tr>'; 
		else
			$ret .= '<td class="no-right-border" colspan="' . (6 + $last - $this->start_of_week) . '">&nbsp;</td></tr>';

		$ret = apply_filters( 'app_monthly_schedule_after_last_row', $ret );		
		$ret .= '</tbody>';
		$ret .= $this->_get_table_meta_row_monthly('tfoot', $long);
		$ret .= '</table>';
		$ret  = apply_filters( 'app_monthly_schedule_after_table', $ret );
		$ret .= '</div>';
		
		$ret .= '<div class="app_timetable_wrapper">';
		$ret .= $time_table;
		$ret .= '</div>';
		
		$ret .= '<div style="clear:both"></div>';
		
		$script  = '';
		$script .= '$(".app_monthly_schedule_wrapper table td.free").click(function(){';
		$script .= 'var selected_timetable=$(".app_timetable_"+$(this).find(".appointments_select_time").val());';
		$script .= '$(".app_timetable:not(selected_timetable)").hide();';
		$script .= 'selected_timetable.show("slow");';
		$script .= '});';

		$this->script = $this->script . $script;

		return $ret;			
	}
	
	/**
	 * Helper function to create a time table for monthly schedule
	 */	
	function get_timetable( $day_start, $capacity ) {
		
		// We need this only for the first timetable
		// Otherwise $time will be calculated from $day_start
		if ( isset( $_GET["wcalendar"] ) )
			$time = $_GET["wcalendar"];
		else
			$time = $this->local_time;
			
		// Are we looking to today?
		// If today is a working day, shows its free times by default
		if ( date( 'Ymd', $day_start ) == date( 'Ymd', $time ) )
			$style = '';
		else
			$style = ' style="display:none"';
		
		$start = $end = 0;
		if ( $min_max = $this->min_max_wh( 0, 0 ) ) {
			$start = $min_max["min"];
			$end = $min_max["max"];
		}
		if ( $start >= $end ) {
			$start = 8;
			$end = 18;
		}
		$start = apply_filters( 'app_schedule_starting_hour', $start );
		$end = apply_filters( 'app_schedule_ending_hour', $end );
		
		$first = $start *3600 + $day_start; // Timestamp of the first cell
		$last = $end *3600 + $day_start; // Timestamp of the last cell
		
		$step = $this->min_time * 60; // Timestamp increase interval to one cell ahead
		
		$ret  = '';
		$ret .= '<div class="app_timetable app_timetable_'.$day_start.'"'.$style.'>';
		$ret .= '<div class="app_timetable_title">';
		$ret .= date( $this->date_format, $day_start );
		$ret .= '</div>';
		
		for ( $t=$first; $t<$last; $t=$t+$step ) {
					
			$ccs = $t; 				// Current cell starts
			$cce = $ccs + $step;	// Current cell ends
			
			$class_name = '';
			// Mark now
			if ( $this->local_time > $ccs && $this->local_time < $cce )
				$class_name = 'notpossible now';
			// Mark passed hours
			else if ( $this->local_time > $ccs )
				$class_name = 'notpossible';
			// Check if this is break
			else if ( $this->is_break( $ccs, $cce ) )
				$class_name = 'notpossible';
			// Then look for appointments
			else if ( $this->is_busy( $ccs, $cce, $capacity ) )
				$class_name = 'busy';
			// Then check if we have enough time to fulfill this app
			else if ( !$this->is_service_possible( $ccs, $cce, $capacity ) )
				$class_name = 'notpossible';
			// If nothing else, then it must be free
			else
				$class_name = 'free';
			
			$ret .= '<div class="app_timetable_cell '.$class_name.'" title="'.date_i18n($this->datetime_format, $ccs).'">'.
						$this->secs2hours( $ccs - $day_start ). '<input type="hidden" class="appointments_take_appointment" value="'.$this->pack( $ccs, $cce ).'" />';
	
			$ret .= '</div>';
		}
		
		$ret .= '<div style="clear:both"></div>';
	
		$ret .= '</div>';
		
		return $ret;
	
	}
	
	function _get_table_meta_row_monthly ($which, $long) {
		if ( !$long )
			$day_names_array = $this->arrange( $this->get_short_day_names(), false );
		else
			$day_names_array = $this->arrange( $this->get_day_names(), false );
		$cells = '<th>' . join('</th><th>', $day_names_array) . '</th>';
		return "<{$which}><tr>{$cells}</tr></{$which}>";
	}

	/**
	 * Shortcode function to generate weekly calendar
	 */	
	function weekly_calendar( $atts ) {
	
		extract( shortcode_atts( array(
		'title'			=> __('<h3>Our schedule from START to END</h3>', 'appointments'),
		'logged'		=> __('Click on a free time slot to apply for an appointment.', 'appointments'),
		'notlogged'		=> __('You need to login to make an appointment. Please click here to register/login: LOGIN_PAGE', 'appointments'),
		'service'		=> 0,
		'worker'		=> 0,
		'long'			=> 0,
		'class'			=> '',
		'add'			=> 0
		), $atts ) );
		
		// Force service and worker
		if ( $service )
			$_REQUEST["app_service_id"] = $service;
		if ( $worker )
			$_REQUEST["app_provider_id"] = $worker;
		
		if ( isset( $_GET["wcalendar"] ) )
			$time = $_GET["wcalendar"] + ($add * 7 * 86400) ;
		else
			$time = $this->local_time + ($add * 7 * 86400);
			
		$start_of_calendar = $this->sunday( $time ) + $this->start_of_week * 86400;
		
		if ( '' != $title )
			$title = str_replace( 
								array( "START", "END" ),
								array( 
									date($this->date_format, $start_of_calendar ),
									date($this->date_format, $start_of_calendar + 6*86400 )
									),
								$title
						);
		else
			$title = '';
			
		$c  = '';
        $c .= '<div class="appointments-wrapper">';
        $c .= $title;
		
		if ( is_user_logged_in() || 'yes' != $this->options["login_required"] ) {
			$c .= $logged;
		}
		else if ( !isset( $this->options["accept_api_logins"] ) )
			$c .= str_replace( 'LOGIN_PAGE', '<a class="appointments-login_show_login" href="'.site_url( 'wp-login.php').'">'. __('Login','appointments'). '</a>', $notlogged );
		else {
			$c .= '<div class="appointments-login">';
			$c .= str_replace( 'LOGIN_PAGE', '<a class="appointments-login_show_login" href="javascript:void(0)">'. __('Login','appointments'). '</a>', $notlogged );
			$c .= '<div class="appointments-login_inner">';
			$c .= '</div>';
			$c .= '</div>';
		}
			
        $c .= '<div class="appointments-list">';
 		$c .= $this->get_weekly_calendar($time, $class, $long);
		
		$c .= '</div>
		</div>';
		$script = '';

		$this->script = $this->script . $script;
		
		return $c;
	}

	/**
	 * Helper function to create a weekly schedule
	 */	
	function get_weekly_calendar( $timestamp=false, $class='', $long ) {
		global $wpdb;
		
		$this->get_lsw();
	
		$price = $this->get_price( );
	
		$year = date("Y", $this->local_time);
		$month = date("m",  $this->local_time);
		
		$date = $timestamp ? $timestamp : $this->local_time;
		
		$sunday = $this->sunday( $date ); // Timestamp of first Sunday of any date
		
		$start = $end = 0;
		if ( $min_max = $this->min_max_wh( 0, 0 ) ) {
			$start = $min_max["min"];
			$end = $min_max["max"];
		}
		if ( $start >= $end ) {
			$start = 8;
			$end = 18;
		}
		$start = apply_filters( 'app_schedule_starting_hour', $start );
		$end = apply_filters( 'app_schedule_ending_hour', $end );
		
		$first = $start *3600 + $sunday; // Timestamp of the first cell of first Sunday
		$last = $end *3600 + $sunday; // Timestamp of the last cell of first Sunday
		
		$step = $this->min_time * 60; // Timestamp increase interval to one cell below
		
		$days = $this->arrange( array(0,1,2,3,4,5,6), -1, true ); // Arrange days acc. to start of week
		
		$tbl_class = $class;
		$tbl_class = $tbl_class ? "class='{$tbl_class}'" : '';
		
		$ret = '';
		$ret .= '<a name="app_schedule">&nbsp;</a>';
		$ret = apply_filters( 'app_schedule_before_table', $ret );
		$ret .= "<table width='100%' {$tbl_class}>";
		$ret .= $this->_get_table_meta_row('thead', $long);
		$ret .= '<tbody>';
		
		$ret = apply_filters( 'app_schedule_before_first_row', $ret );
		
		$todays_no = date("w", $this->local_time ); // Number of today
		$working_days = $this->get_working_days( $this->worker, $this->location ); // Get an array of working days
		$capacity = $this->get_capacity();
		
		for ( $t=$first; $t<$last; $t=$t+$step ) {
			foreach ( $days as $key=>$i ) {
				if ( $i == -1 ) {
					$from = $this->secs2hours( $t - $sunday );
					$to = $this->secs2hours( $t - $sunday + $step );
					$ret .= "<td class='appointments-weekly-calendar-hours-mins'>".$from." &#45; ".$to."</td>";
				}
				else {
					$ccs = $t + $i * 86400; // Current cell starts
					$cce = $ccs + $step;	// Current cell ends
					
					$class_name = '';
					
					// Also mark now
					if ( $this->local_time > $ccs && $this->local_time < $cce )
						$class_name = 'notpossible now';
					// Mark passed hours
					else if ( $this->local_time > $ccs )
						$class_name = 'notpossible';
					// Check today is holiday
					else if ( $this->is_holiday( $ccs, $cce ) )
						$class_name = 'notpossible';
					// Check if we are working today
					else if ( !in_array( date("l", $ccs ), $working_days ) && !$this->is_exceptional_working_day( $ccs, $cce ) )
						$class_name = 'notpossible';
					// Check if this is break
					else if ( $this->is_break( $ccs, $cce ) )
						$class_name = 'notpossible';
					// Then look for appointments
					else if ( $this->is_busy( $ccs, $cce, $capacity ) )
						$class_name = 'busy';
					// Then check if we have enough time to fulfill this app
					else if ( !$this->is_service_possible( $ccs, $cce, $capacity ) )
						$class_name = 'notpossible';
					// If nothing else, then it must be free
					else
						$class_name = 'free';
					
					$ret .= '<td class="'.$class_name.'" title="'.date_i18n($this->datetime_format, $ccs).'">
					<input type="hidden" class="appointments_take_appointment" value="'.$this->pack( $ccs, $cce ).'" /></td>';
				}
			}
			$ret .= '</tr><tr>'; // Close the last day of the week
		}
		$ret = apply_filters( 'app_schedule_after_last_row', $ret );		
		$ret .= '</tbody>';
		$ret .= $this->_get_table_meta_row('tfoot', $long);
		$ret .= '</table>';
		$ret = apply_filters( 'app_schedule_after_table', $ret );
		
		return $ret;			
	}

	function _get_table_meta_row ($which, $long) {
		if ( !$long )
			$day_names_array = $this->arrange( $this->get_short_day_names(), __(' ', 'appointments') );
		else
			$day_names_array = $this->arrange( $this->get_day_names(), __(' ', 'appointments') );
		$cells = '<th class="hourmin_column">&nbsp;' . join('</th><th>', $day_names_array) . '</th>';
		return "<{$which}><tr>{$cells}</tr></{$which}>";
	}
	
	function get_day_names () {
		return array(
			__('Sunday',	'appointments'),
			__('Monday',	'appointments'),
			__('Tuesday',	'appointments'),
			__('Wednesday',	'appointments'),
			__('Thursday',	'appointments'),
			__('Friday',	'appointments'),
			__('Saturday',	'appointments'),
		);
	}
	
	function get_short_day_names () {
		return array(
			__('Su', 'appointments'),
			__('Mo', 'appointments'),
			__('Tu', 'appointments'),
			__('We', 'appointments'),
			__('Th', 'appointments'),
			__('Fr', 'appointments'),
			__('Sa', 'appointments'),
		);
	}
	
	/**
	 * Returns the timestamp of Sunday of the current week or selected date
	 */	
	function sunday( $timestamp=false ) {
	
		$date = $timestamp ? $timestamp : $this->local_time;
		// Return today's timestamp if today is sunday and start of the week is set as Sunday
		if ( "Sunday" == date( "l", $date ) && 0 == $this->start_of_week )
			return strtotime("today");
		// Else return last week's timestamp
		else
			return strtotime("last Sunday", $date );
	}

	/**
	 * Arranges days array acc. to start of week, e.g 1234567 (Week starting with Monday)
	 * @ days: input array, @ prepend: What to add as first element
	 */	
	function arrange( $days, $prepend, $nod=false ) {
		if ( $this->start_of_week ) {
			for ( $n = 1; $n<=$this->start_of_week; $n++ ) {
				array_push( $days, array_shift( $days ) );
			}
			// Fix for displaying past days; apply only for number of days
			if ( $nod ) {
				$first = false;
				$temp = array();
				foreach ( $days as $key=>$day ) {
					if ( !$first ) 
						$first = $day; // Save the first day
					if ( $day < $first )
						$temp[$key] = $day + 7; // Latter days should be higher than the first day
					else
						$temp[$key] = $day;
				}
				$days = $temp;
			}
		}
		if ( false !== $prepend )
			array_unshift( $days, $prepend );
	
		return $days;
	}

	/**
	 * Get which days of the week we are working
	 * Always return an array (may be empty)
	 */		
	function get_working_days( $worker=0, $location=0 ) {
		global $wpdb;
		$working_days = array();
		$result = $this->get_work_break( $location, $worker, 'open' );
		if ( $result !== null ) {
			$days = maybe_unserialize( $result->hours );
			if ( is_array( $days ) ) {
				foreach ( $days as $day_name=>$day ) {
					if ( 'yes' == $day["active"] ) {
						$working_days[] = $day_name;
					}
				}
			}
		}
		return $working_days;
	}

	/**
	 * Check if this is an exceptional working day
	 * @return bool
	 */		
	function is_exceptional_working_day( $ccs, $cce ) {
		$result = $this->get_exception( $this->location, $this->worker, 'open' );
		if ( $result != null  && strpos( $result->days, date( 'Y-m-d', $ccs ) ) )
			return true;

		return false;
	}
	
	/**
	 * Check if today is holiday
	 * @return bool
	 */		
	function is_holiday( $ccs, $cce ) {
		$result = $this->get_exception( $this->location, $this->worker, 'closed' );
		if ( $result != null  && strpos( $result->days, date( 'Y-m-d', $ccs ) ) )
			return true;

		return false;
	}

	/**
	 * Check if it is break time
	 * @return bool
	 */		
	function is_break( $ccs, $cce ) {
		// Look where our working hour ends
		$result_days = $this->get_work_break( $this->location, $this->worker, 'closed' );
		if ( $result_days !== null ) {
			$days = maybe_unserialize( $result_days->hours );
			if ( is_array( $days ) ) {
				// What is the name of this day?	
				$this_days_name = date("l", $ccs );
				// This days midnight
				$this_day = date("d F Y", $ccs );
				
				foreach( $days as $day_name=>$day ) {
					if ( $day_name == $this_days_name && 'yes' == $day["active"] ) {
						if ( $ccs >= strtotime( $this_day. " ". $this->to_military( $day["start"] ), $this->local_time ) &&
							$cce <= strtotime( $this_day. " ". $this->to_military( $day["end"] ), $this->local_time ) )
							return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * Check if time is enough for this service
	 * e.g if we are working until 6pm, it is not possible to take an app with 60 mins duration at 5:30pm
	 * Please note that "not possible" is an exception
	 * @return bool
	 */		
	function is_service_possible( $ccs, $cce, $capacity ) {

		// If this cell exceeds app limit then return false
		if ( $this->app_limit < ceil( ( $ccs - $this->local_time ) /86400 ) )
			return false;
		
		$result = $this->get_service( $this->service );
		if ( $result !== null ) {
			if( !$duration = $result->duration || $result->duration == $this->min_time )
				return true; // This means min time will be applied. No need to look
				
			// The same for break time
			if ( isset( $this->options["allow_overwork_break"] ) && 'yes' == $this->options["allow_overwork_break"] )
				$allow_overwork_break = true;
			else
				$allow_overwork_break = false;
				
			// Check for further appointments or breaks on this day, if this is a lasting appointment
			if ( $duration > $this->min_time ) {
				$step = ceil( $duration/$this->min_time );
				$min_secs = $this->min_time *60;
				if ( $step < 20 ) { // Let's not exaggerate !
					for ( $n =1; $n < $step; $n++ ) {
						if ( $this->is_busy( $ccs + $n * $min_secs, $ccs + ($n+1) * $min_secs, $capacity ) )  
							return false; // There is an appointment in the predeeding times
						// We can check breaks here too
						if ( !$allow_overwork_break ) {
							if ( $this->is_break( $ccs + $n * $min_secs, 
								$ccs + ($n+1) * $min_secs, $location, $service, $worker ) )  
								return false; // There is a break in the predeeding times
						}
					}
				}
			}
			// Now look where our working hour ends
			$result_days = $this->get_work_break( $this->location, $this->worker, 'open' );
			if ( $result_days !== null ) {
				$days = maybe_unserialize( $result_days->hours );
				if ( is_array( $days ) ) {
				
					// If overwork is allowed, lets mark this
					if ( isset( $this->options["allow_overwork"] ) && 'yes' == $this->options["allow_overwork"] )
						$allow_overwork = true;
					else
						$allow_overwork = false;
						
					// What is the name of this day?	
					$this_days_name = date("l", $ccs );
					// This days midnight
					$this_day = date("d F Y", $ccs );
					// Will the service exceed or working time?
					$css_plus_duration = $ccs + ($duration *60);

					foreach( $days as $day_name=>$day ) {
						if ( $day_name == $this_days_name && 'yes' == $day["active"] ) {
	
							if ( $allow_overwork ) {
								if ( $ccs >= strtotime( $this_day . " " . $this->to_military( $day["end"] ) , $this->local_time ) )
									return false;
							}
							else {
								if (  $css_plus_duration > strtotime( $this_day . " " . $this->to_military( $day["end"] ) , $this->local_time ) ) 
									return false;
							}
						}
					}
				}
			}
		}
		return true;
	}
	
	/**
	 * Check if a cell is not available, i.e. all appointments taken
	 * @return bool
	 */		
	function is_busy( $start, $end, $capacity ) {
		
		// If a specific worker is selected, we will look at his schedule first.  
		if ( 0 != $this->worker ) {
			$apps = $this->get_reserve_apps_by_worker( $this->location, $this->worker );
			if ( $apps ) {
				foreach ( $apps as $app ) {
					if ( $start >= strtotime( $app->start ) && $end <= strtotime( $app->end ) )
						return true;
				}
			}
		}
		// Then we must look for anon workers apps too. But only for this service.
		$apps = $this->get_reserve_apps( $this->location, $this->service, 0 );
		$n = 0;
		foreach ( $apps as $app ) {
			if ( $start >= strtotime( $app->start ) && $end <= strtotime( $app->end ) )
				$n++; // Number of appointments for this time frame
		}
		if ( $n >= $capacity )
			return true;
		// Nothing found, so this time frame is not busy
		return false;
	}

	/**
	 * Get the maximum and minimum working hour
	 @return array
	 */	
	function min_max_wh( $worker=0, $location=0 ) {
		$this->get_lsw();
		$result = $this->get_work_break( $this->location, $this->worker, 'open' );
		if ( $result !== null ) {
			$days = maybe_unserialize( $result->hours );
			if ( is_array( $days ) ) {
				$min = 24; $max = 0;
				foreach ( $days as $day ) {
					if ( 'yes' == $day["active"] ) {
						$start = date( "G", strtotime( $this->to_military( $day["start"] ) ) );
						$end = date( "G", strtotime( $this->to_military( $day["end"] ) ) );
						if ( $start < $min )
							$min = $start;
						if ( $end > $max )
							$max = $end;
					}
				}
				return array( "min"=>$min, "max"=>$max );
			}
		}
		return false;
	}

	/**
	 * Convert any time format to military format
	 * @since 1.0.3
	 * @return string
	 */	
	function to_military( $time ) {
		// Already in military format
		if ( 'H:i' == $this->time_format )
			return $time;
		// In one of the default formats
		if ( 'g:i a' == $this->time_format  || 'g:i A' == $this->time_format )
			return date( 'H:i', strtotime( $time ) );
			
		// Custom format. Use a reference time
		// ref will something like 23saat45dakika
		$ref = date_i18n( $this->time_format, strtotime( "23:45" ) );
		if ( strpos( $ref, "23" ) !== false )
			$twentyfour = true;
		else
			$twentyfour = false;
		// Now ref is something like saat,dakika	
		$ref = ltrim( str_replace( array( '23', '45' ), ',', $ref ), ',' );
		$ref_arr = explode( ',', $ref );
		if ( isset( $ref_arr[0] ) ) {
			$s = $ref_arr[0]; // separator. We will replace it by :
			if ( isset($ref_arr[1]) && $ref_arr[1] )
				$e = $ref_arr[1];
			else {
				$e = 'PLACEHOLDER';
				$time = $time. $e; // Add placeholder at the back
			}
			if ( $twentyfour )
				$new_e = '';
			else
				$new_e = ' a';
		}
		else
			return $time; // Nothing found ??
	
		return date( 'H:i', strtotime( str_replace( array($s,$e), array(':',$new_e), $time ) ) );
	}

	
	/**
	 * Pack several fields as a string using glue ":"
	 * location : service : worker : ccs : cce : post ID
	 * @return string
	 */	
	function pack( $ccs, $cce ){
		global $post;
		if ( is_object( $post ) )
			$post_id = $post->ID;
		else
			$post_id = 0;
		return $this->location . ":" . $this->service . ":" . $this->worker . ":" . $ccs . ":" . $cce . ":" . $post_id;
	}

	/**
	 * Save a cookie so that user can see his appointments
	 */	
	function save_cookie( $app_id, $name, $email, $phone, $address, $city, $gcal ) {
		if ( isset( $_COOKIE["wpmudev_appointments"] ) )
			$apps = unserialize( stripslashes( $_COOKIE["wpmudev_appointments"] ) );
		else
			$apps = array();
			
		$apps[] = $app_id;
		
		// Prevent duplicates
		$apps = array_unique( $apps );
		// Add 365 days grace time
		$expire = $this->local_time + 3600 * 24 * ( $this->options["app_limit"] + 365 ); 
		
		if ( defined('COOKIEPATH') ) $cookiepath = COOKIEPATH;
		else $cookiepath = "/";
		if ( defined('COOKIEDOMAIN') ) $cookiedomain = COOKIEDOMAIN;
		else $cookiedomain = '';
			
		@setcookie("wpmudev_appointments", serialize($apps), $expire, $cookiepath, $cookiedomain);
		
		$data = array(
					"n"	=> $name,
					"e"	=> $email,
					"p"	=> $phone,
					"a"	=> $address,
					"c"	=> $city,
					"g"	=> $gcal
					);
		@setcookie("wpmudev_appointments_userdata", serialize($data), $expire, $cookiepath, $cookiedomain);
	}


/*******************************
* Methods for frontend login API
********************************
*/		
	/**
	 * Login fron front end by Wordpress
	 */
	function ajax_login( ) {

		header("Content-type: application/json");
		$user = wp_signon( );
		
		if ( !is_wp_error($user) ) {
			
			die(json_encode(array(
				"status" => 1,
				"user_id"=>$user->ID
			)));
		}	
		die(json_encode(array(
				"status" => 0,
				"error" => $user->get_error_message()
			)));
	}

	/**
	 * Handles Facebook user login and creation
	 * Modified from Events and Bookings by S H Mohanjith
	 */
	function handle_facebook_login () {
		header("Content-type: application/json");
		$resp = array(
			"status" => 0,
		);
		$fb_uid = @$_POST['user_id'];
		$token = @$_POST['token'];
		if (!$token) die(json_encode($resp));
		
		$request = new WP_Http;
		$result = $request->request(
			'https://graph.facebook.com/me?oauth_token=' . $token, 
			array('sslverify' => false) // SSL certificate issue workaround
		);
		if (200 != $result['response']['code']) die(json_encode($resp)); // Couldn't fetch info
		
		$data = json_decode($result['body']);
		if (!$data->email) die(json_encode($resp)); // No email, can't go further
		
		$email = is_email($data->email);
		if (!$email) die(json_encode($resp)); // Wrong email
		
		$wp_user = get_user_by('email', $email);
		
		if (!$wp_user) { // Not an existing user, let's create a new one
			$password = wp_generate_password(12, false);
			$username = @$data->name
				? preg_replace('/[^_0-9a-z]/i', '_', strtolower($data->name))
				: preg_replace('/[^_0-9a-z]/i', '_', strtolower($data->first_name)) . '_' . preg_replace('/[^_0-9a-z]/i', '_', strtolower($data->last_name))
			;
	
			$wp_user = wp_create_user($username, $password, $email);
			if (is_wp_error($wp_user)) die(json_encode($resp)); // Failure creating user
		} else {
			$wp_user = $wp_user->ID;
		}
		
		$user = get_userdata($wp_user);

		wp_set_current_user($user->ID, $user->user_login);
		wp_set_auth_cookie($user->ID); // Logged in with Facebook, yay
		do_action('wp_login', $user->user_login);
		
		die(json_encode(array(
			"status" => 1,
			"user_id"=>$user->ID
		)));
	}

	/**
	 * Spawn a TwitterOAuth object.
	 */
	function _get_twitter_object ($token=null, $secret=null) {
		// Make sure options are loaded and fresh
		if ( !$this->options['twitter-app_id'] )
			$this->options = get_option( 'appointments_options' );
		if (!class_exists('TwitterOAuth')) 
			include WP_PLUGIN_DIR . '/appointments/includes/twitteroauth/twitteroauth.php';
		$twitter = new TwitterOAuth(
			$this->options['twitter-app_id'], 
			$this->options['twitter-app_secret'],
			$token, $secret
		);
		return $twitter;
	}
	
	/**
	 * Get OAuth request URL and token.
	 */
	function handle_get_twitter_auth_url () {
		header("Content-type: application/json");
		$twitter = $this->_get_twitter_object();
		$request_token = $twitter->getRequestToken($_POST['url']);
		echo json_encode(array(
			'url' => $twitter->getAuthorizeURL($request_token['oauth_token']),
			'secret' => $request_token['oauth_token_secret']
		));
		die;
	}
	
	/**
	 * Login or create a new user using whatever data we get from Twitter.
	 */
	function handle_twitter_login () {
		header("Content-type: application/json");
		$resp = array(
			"status" => 0,
		);
		$secret = @$_POST['secret'];
		$data_str = @$_POST['data'];
		$data_str = ('?' == substr($data_str, 0, 1)) ? substr($data_str, 1) : $data_str;
		$data = array();
		parse_str($data_str, $data);
		if (!$data) die(json_encode($resp));
		
		$twitter = $this->_get_twitter_object($data['oauth_token'], $secret);
		$access = $twitter->getAccessToken($data['oauth_verifier']);
		
		$twitter = $this->_get_twitter_object($access['oauth_token'], $access['oauth_token_secret']);
		$tw_user = $twitter->get('account/verify_credentials');
		
		// Have user, now register him/her
		$domain = preg_replace('/www\./', '', parse_url(site_url(), PHP_URL_HOST));
		$username = preg_replace('/[^_0-9a-z]/i', '_', strtolower($tw_user->name));
		$email = $username . '@twitter.' . $domain; //STUB email
		$wp_user = get_user_by('email', $email);
		
		if (!$wp_user) { // Not an existing user, let's create a new one
			$password = wp_generate_password(12, false);
			$count = 0;
			while (username_exists($username)) {
				$username .= rand(0,9);
				if (++$count > 10) break;
			}
	
			$wp_user = wp_create_user($username, $password, $email);
			if (is_wp_error($wp_user)) die(json_encode($resp)); // Failure creating user
		} else {
			$wp_user = $wp_user->ID;
		}
		
		$user = get_userdata($wp_user);
		wp_set_current_user($user->ID, $user->user_login);
		wp_set_auth_cookie($user->ID); // Logged in with Twitter, yay
		do_action('wp_login', $user->user_login);

		die(json_encode(array(
			"status" => 1,
			"user_id"=>$user->ID
		)));
	}
	
		/**
	 * Get OAuth request URL and token.
	 */
	function handle_get_google_auth_url () {
		header("Content-type: application/json");
		
		$this->openid->returnUrl = $_POST['url'];
		
		echo json_encode(array(
			'url' => $this->openid->authUrl()
		));
		exit();
	}
	
	/**
	 * Login or create a new user using whatever data we get from Google.
	 */
	function handle_google_login () {
		header("Content-type: application/json");
		$resp = array(
			"status" => 0,
		);
		
		$cache = $this->openid->getAttributes();
		
		if (isset($cache['namePerson/first']) || isset($cache['namePerson/last']) || isset($cache['namePerson/friendly']) || isset($cache['contact/email'])) {
			$this->_google_user_cache = $cache;
		}

		// Have user, now register him/her
		if ( isset( $this->_google_user_cache['namePerson/friendly'] ) )
			$username = $this->_google_user_cache['namePerson/friendly'];
		else
			$username = $this->_google_user_cache['namePerson/first'];
		$email = $this->_google_user_cache['contact/email'];
		$wordp_user = get_user_by('email', $email);
		
		if (!$wordp_user) { // Not an existing user, let's create a new one
			$password = wp_generate_password(12, false);
			$count = 0;
			while (username_exists($username)) {
				$username .= rand(0,9);
				if (++$count > 10) break;
			}
	
			$wordp_user = wp_create_user($username, $password, $email);
			if (is_wp_error($wordp_user)) 
				die(json_encode($resp)); // Failure creating user
			else {
				update_user_meta($wordp_user, 'first_name', $this->_google_user_cache['namePerson/first']);
				update_user_meta($wordp_user, 'last_name', $this->_google_user_cache['namePerson/last']);
			}
		} 
		else {
			$wordp_user = $wordp_user->ID;
		}
		
		$user = get_userdata($wordp_user);
		wp_set_current_user($user->ID, $user->user_login);
		wp_set_auth_cookie($user->ID); // Logged in with Google, yay
		do_action('wp_login', $user->user_login);
		
		die(json_encode(array(
			"status" => 1,
		)));
	}


/*******************************
* User methods
********************************
*/
	/**
	 * Saves working hours from user profile
	 */
	function save_profile( $user_id ) {
		global $current_user, $wpdb;

		// Only user who is a worker can save his data
		if ( $current_user->ID != $user_id || !$this->is_worker( $user_id ) )
			return;
		
		if ( isset($this->options["allow_worker_wh"]) && 'yes' == $this->options["allow_worker_wh"] ) {
			$result = $result2 = false;
			$location = 0;
			foreach ( array( 'closed', 'open' ) as $stat ) {
				$count = $wpdb->get_var("SELECT COUNT(*) FROM ". $this->wh_table .
						" WHERE location=".$location." AND worker=".$current_user->ID." AND status='".$stat."' ");
				
				if ( $count > 0 ) {
					$result = $wpdb->update( $this->wh_table,
						array( 'hours'=>serialize($_POST[$stat]), 'status'=>$stat ),
						array( 'location'=>$location, 'worker'=>$current_user->ID, 'status'=>$stat ),
						array( '%s', '%s' ),
						array( '%d', '%d', '%s' )
					);
				}
				else {
					$result = $wpdb->insert( $this->wh_table, 
						array( 'location'=>$location, 'worker'=>$current_user->ID, 'hours'=>serialize($_POST[$stat]), 'status'=>$stat ),
						array( '%d', '%d', '%s', '%s' )
						);
				}
				// Save exceptions
				$count2 = $wpdb->get_var( "SELECT COUNT(*) FROM ". $this->exceptions_table .
						" WHERE location=".$location." AND worker=".$current_user->ID." AND status='".$stat."' ");
						
				if ( $count2 > 0 ) {
					$result2 = $wpdb->update( $this->exceptions_table,
						array( 
								'days'		=> $_POST[$stat]["exceptional_days"], 
								'status'	=> $stat 
							),
						array( 
							'location'	=> $location, 
							'worker'	=> $current_user->ID, 
							'status'	=> $stat ),
						array( '%s', '%s' ),
						array( '%d', '%d', '%s' )
					);
				}
				else {
					$result2 = $wpdb->insert( $this->exceptions_table, 
						array( 'location'	=> $location, 
								'worker'	=> $current_user->ID, 
								'days'		=> $_POST[$stat]["exceptional_days"],
								'status'	=> $stat
							),
						array( '%d', '%d', '%s', '%s' )
						);
				}
			}
			if ( $result || $result2 ) {
				$message = sprintf( __('%s edited his working hours.', 'appointments'), $this->get_worker_name( $current_user->ID));
				$this->log( $message );
				// Employer can be noticed here
				do_action( "app_working_hour_update", $message, $user_id );
				// Also clear cache
				$this->flush_cache();
			}
		}
	}

	/**
	 * Displays appointment schedule on the user profile 
	 */
	function show_profile( $profileuser ) {
		global $current_user, $wpdb;
		
		// Only user can see his data
		if ( $current_user->ID != $profileuser->ID )
			return;
			
		if ( isset( $this->options["gcal"] ) && 'yes' == $this->options["gcal"] )
			$gcal = ''; // Default is already enabled
		else
			$gcal = ' gcal="0"';
	?>
		<h3><?php _e("Appointments+", 'appointments'); ?></h3>
	 
		<table class="form-table">
		<?php if ( !$this->is_worker( $current_user->ID ) ) { ?>
		<tr>
		<th><label><?php _e("My Appointments", 'appointments'); ?></label></th>
		<td>
		<?php echo do_shortcode("[app_my_appointments ".$gcal."]") ?>
		</td>
		</tr>
		<?php }
		else { ?>
		<tr>
		<th><label><?php _e("My Appointments as Provider", 'appointments'); ?></label></th>
		<td>
		<?php echo do_shortcode("[app_my_appointments provider=1 ".$gcal."]") ?>
		</td>
		</tr>
		<?php if ( isset($this->options["allow_worker_wh"]) && 'yes' == $this->options["allow_worker_wh"] ) { ?>
			<?php
			// A little trick to pass correct lsw variables to the related function
			$_REQUEST["app_location_id"] = 0;
			$_REQUEST["app_provider_id"] = $current_user->ID;
			
			$this->get_lsw();
						
			$result = array();
			$result_open = $this->get_exception( $this->location, $this->worker, 'open' );
			if ( $result_open )
				$result["open"] = $result_open->days;
			else
				$result["open"] = null;
				
			$result_closed = $this->get_exception( $this->location, $this->worker, 'closed' );
			if ( $result_closed )
				$result["closed"] = $result_closed->days;
			else
				$result["closed"] = null;
			?>
			<tr>
			<th><label><?php _e("My Working Hours", 'appointments'); ?></label></th>
			<td>
			<?php echo $this->working_hour_form('open') ?>
			</td>
			</tr>
			<tr>
			<th><label><?php _e("My Break Hours", 'appointments'); ?></label></th>
			<td>
			<?php echo $this->working_hour_form('closed') ?>
			</td>
			</tr>
			<tr>
			<th><label><?php _e("My Exceptional Working Days", 'appointments'); ?></label></th>
			<td>
			<input class="datepick" id="open_datepick" type="text" style="width:100%" name="open[exceptional_days]" value="<?php if (isset($result["open"])) echo $result["open"]?>" />
			</td>
			</tr>
			<tr>
			<th><label><?php _e("My Holidays", 'appointments'); ?></label></th>
			<td>
			<input class="datepick" id="closed_datepick" type="text" style="width:100%" name="closed[exceptional_days]" value="<?php if (isset($result["closed"])) echo $result["closed"]?>" />
			</td>
			</tr>
			<script type="text/javascript">
			jQuery(document).ready(function($){
				$("#open_datepick").datepick({dateFormat: 'yyyy-mm-dd',multiSelect: 999, monthsToShow: 2});
				$("#closed_datepick").datepick({dateFormat: 'yyyy-mm-dd',multiSelect: 999, monthsToShow: 2});
			});
			</script>
			<?php } ?>
		<?php } ?>
		</table>
	<?php
	}

/*******************************
* Methods for Buddypress
********************************
*/

	/**
     * Save setting submitted from front end
     */
	function bp_init() {
		if ( !isset( $_POST["app_bp_settings_submit"] ) || !isset( $_POST["app_bp_settings_user"] ) )
			return;
			
		// In the future we may use this function without BP too
		if ( function_exists( 'bp_loggedin_user_id') )
			$user_id = bp_loggedin_user_id();
		else {
			global $current_user;
			$user_id = $current_user->ID;
		}
			
		if ( !$user_id || !wp_verify_nonce($_POST['app_bp_settings_submit'],'app_bp_settings_submit')  
				|| $user_id != $_POST["app_bp_settings_user"] || !$this->is_worker( $user_id ) 
				|| !isset( $this->options["allow_worker_wh"] ) || 'yes' != $this->options["allow_worker_wh"] ) {
			wp_die( 'You don\'t have the authority to do this.', 'appointments' );
			exit;
		}
		// Checks are ok, let's save settings.
		$this->save_profile( $user_id );
	}

	/**
     * Determine which page we are on
	 * If it is correct, load necessary scripts and css files
     */
	function bp_template_redirect() {
		global $bp;
		if ( !is_object( $bp ) )
			return;
			
		$scheme        = is_ssl() ? 'https://' : 'http://';
		$requested_url = strtolower( $scheme . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] );

		$page_url = str_replace( $bp->displayed_user->domain . 'appointments/', '', $requested_url );
		
		// Load datepick if we are on settings page
		if ( strpos( $page_url, 'appointment-settings' ) !== false ) {
			$this->bp_script ='$("#open_datepick").datepick({dateFormat: "yyyy-mm-dd",multiSelect: 999, monthsToShow: 2});
				$("#closed_datepick").datepick({dateFormat: "yyyy-mm-dd",multiSelect: 999, monthsToShow: 2});';
			
			wp_enqueue_script( 'jquery-datepick', $this->plugin_url . '/js/jquery.datepick.min.js', array('jquery'), $this->version);
			wp_enqueue_style( "jquery-datepick", $this->plugin_url . "/css/jquery.datepick.css", false, $this->version );
		}
	}
	
	/**
	 * Load javascript to the footer
	 */
	function bp_footer() {
		$script = '';
		$this->bp_script = apply_filters( 'app_bp_footer_scripts', $this->bp_script );
		
		if ( $this->bp_script ) {
			$script .= '<script type="text/javascript">';
			$script .= "jQuery(document).ready(function($) {";
			$script .= $this->bp_script;
			$script .= "});</script>";
		}	
			
		echo $this->esc_rn( $script );
	}
	
	/**
     * Add a nav and two subnav items
     */
	function setup_nav() {
		global $bp;
		bp_core_new_nav_item( array(
			'name' => __( 'Appointments', 'appointments' ),
			'slug' => 'appointments',
			'show_for_displayed_user' => false,
			'screen_function' => array( &$this, 'tab_template' )
		) );
		
		$link = $bp->loggedin_user->domain . 'appointments/';
		
		$user_id = bp_loggedin_user_id();
		if ( !$this->is_worker( $user_id ) )
			$name = __( 'My Appointments', 'appointments' );
		else
			$name = __( 'My Appointments as Provider', 'appointments' );

		bp_core_new_subnav_item( array(
			'name' => $name,
			'slug' => 'my-appointments',
			'parent_url' => $link, 
			'parent_slug' => 'appointments',
			'screen_function' => array( &$this, 'tab_template_my_app' )
		) );
		
		// Generate this tab only if allowed
		if ( $this->is_worker( $user_id ) && isset($this->options["allow_worker_wh"]) && 'yes' == $this->options["allow_worker_wh"] ) {
			bp_core_new_subnav_item( array(
				'name' => __( 'Appointments Settings', 'appointments' ),
				'slug' => 'appointment-settings',
				'parent_url' => $link, 
				'parent_slug' => 'appointments',
				'screen_function' => array( &$this, 'tab_template_app_settings' )
			) );
		}
	}

	/**
     * Helper functions that BP requires
     */
	function tab_template() {
		bp_core_load_template( apply_filters( 'bp_core_template_plugin', 'members/single/plugins' ) );
	}
	
	function tab_template_my_app() {
		add_action( 'bp_template_content', array( &$this, 'screen_content_my_app' ) );
		bp_core_load_template( apply_filters( 'bp_core_template_plugin', 'members/single/plugins' ) );
	}

	function tab_template_app_settings() {
		add_action( 'bp_template_content', array( &$this, 'screen_content_app_settings' ) );
		bp_core_load_template( apply_filters( 'bp_core_template_plugin', 'members/single/plugins' ) );
	}

	/**
     * Generate content for my apps
     */
	function screen_content_my_app() {
		if ( isset( $this->options["gcal"] ) && 'yes' == $this->options["gcal"] )
			$gcal = ''; // Default is already enabled
		else
			$gcal = ' gcal="0"';

		$user_id = bp_loggedin_user_id();
		
		if ( !$this->is_worker( $user_id ) )
			echo do_shortcode("[app_my_appointments ".$gcal."]");
		else
			echo do_shortcode("[app_my_appointments provider=1 ".$gcal."]");
	}

	/**
     * Generate content for app settings
     */
	function screen_content_app_settings() {
		// In the future we may use this function without BP too
		if ( function_exists( 'bp_loggedin_user_id') )
			$user_id = bp_loggedin_user_id();
		else {
			global $current_user;
			$user_id = $current_user->ID;
		}
		
		if ( $this->is_worker( $user_id ) && isset($this->options["allow_worker_wh"]) && 'yes' == $this->options["allow_worker_wh"] ) {
			// A little trick to pass correct lsw variables to the related function
			$_REQUEST["app_location_id"] = 0;
			$_REQUEST["app_provider_id"] = $user_id;
			
			$this->get_lsw();
						
			$result = array();
			$result_open = $this->get_exception( $this->location, $this->worker, 'open' );
			if ( $result_open )
				$result["open"] = $result_open->days;
			else
				$result["open"] = null;
				
			$result_closed = $this->get_exception( $this->location, $this->worker, 'closed' );
			if ( $result_closed )
				$result["closed"] = $result_closed->days;
			else
				$result["closed"] = null;
			?>
			<div class="standard-form">
				<form method="post">
					<h4><?php _e('My Working Hours', 'appointments'); ?></h4>
					<?php echo $this->working_hour_form('open'); ?>
					<h4><?php _e('My Break Hours', 'appointments'); ?></h4>
					<?php echo $this->working_hour_form('closed'); ?>
					
					<h4><?php _e('My Exceptional Working Days', 'appointments'); ?></h4>

					<input class="datepick" id="open_datepick" type="text" style="width:100%" name="open[exceptional_days]" value="<?php if (isset($result["open"])) echo $result["open"]?>" />
					
					<h4><?php _e('My Holidays', 'appointments'); ?></h4>
					
					<input class="datepick" id="closed_datepick" type="text" style="width:100%" name="closed[exceptional_days]" value="<?php if (isset($result["closed"])) echo $result["closed"]?>" />
					<div class="submit">
						<input type="submit" name="app_bp_settings_submit" value="Save Changes" class="auto">
						<input type="hidden" name="app_bp_settings_user" value="<?php echo $user_id ?>">
						<?php wp_nonce_field('app_bp_settings_submit','app_bp_settings_submit'); ?>
					</div>
				</form>
			</div>
			
			<?php
		}
	}

/****************************************
* Methods for integration with Membership
*****************************************
*/

	/**
	 * Check if Membership plugin is active
	 *
	 */	
	function check_membership_plugin() {
		if( ( is_admin() AND class_exists('membershipadmin') ) 
			OR 
			( !is_admin() AND class_exists('membershippublic') ) )
				$this->membership_active = true;
	}
	
	/**
	* Finds if user is Membership member with sufficient level
	* @return bool
	*/	
	function is_member( ) {
		if ( $this->membership_active && isset( $this->options["members"] ) ) {
			global $current_user;
			$meta = maybe_unserialize( $this->options["members"] );
			$member = new M_Membership($current_user->ID);
			if( is_array( $meta ) && $current_user->ID > 0 && $member->has_levels()) {
				// Load the levels for this member
				$levels = $member->get_level_ids( );
				if ( is_array( $levels ) AND is_array( $meta["level"] ) ) {
					foreach ( $levels as $level ) {
						if ( in_array( $level->level_id, $meta["level"] ) )
							return true; // Yes, user has sufficent level
					}
				}
			}
		}
		return false;
	}
	
/*****************************************
* Methods for integration with Marketpress
******************************************
*/

	/**
	 * Check if Marketpress plugin is active
	 * @ Since 1.0.1 
	 */	
	function check_marketpress_plugin() {
		global $mp;
		if( class_exists('MarketPress') && is_object( $mp ) ) { 
			$this->marketpress_active = true;
			// Also check if it is activated
			if ( isset( $this->options["use_mp"] ) && $this->options["use_mp"] ) {
				$this->mp = true;
				add_action( 'manage_posts_custom_column', array(&$this, 'edit_products_custom_columns'), 1 );
				add_action( 'wp_ajax_nopriv_mp-update-cart', array(&$this, 'pre_update_cart'), 1 );
				add_action( 'wp_ajax_mp-update-cart', array(&$this, 'pre_update_cart'), 1 );
				add_action( 'wp', array(&$this, 'remove_from_cart_manual'), 1 );
				add_filter( 'the_content', array( &$this, 'product_page' ), 18 );
				add_action( 'mp_order_paid', array( &$this, 'handle_mp_payment' ) );
				add_filter( 'mp_product_list_meta', array( &$this, 'mp_product_list_meta' ), 10, 2 );
				add_filter( 'mp_order_notification_body', array( &$this, 'modify_email' ), 10, 2 );
				add_filter( 'mp_product_name_display_in_cart', array( &$this, 'modify_name' ), 10, 2 ); 
				return true;
			}
		}
		return false;
	}
	
	/**
	 * Determine if a page is A+ Product page from the shortcodes used
	 * @ param $product custom post object
	 * @ return bool
	 * @ Since 1.0.1 
	 */	
	function is_app_mp_page( $product ) {
		$result = false;
		if ( is_object( $product ) && strpos( $product->post_content, '[app_' ) !== false )
			$result = true;
		// May be required for templates
		return apply_filters( 'app_is_mp_page', $result, $product );
	}
	
	/**
	 * Hide column details for A+ products
	 * @ Since 1.0.1 
	 */	
	function edit_products_custom_columns($column) {

		global $post, $mp;
		if ( !$this->is_app_mp_page( $post ) )
			return;
		if ( 'variations' == $column || 'sku' == $column || 'pricing' == $column ) {
			remove_action( 'manage_posts_custom_column', array($mp, 'edit_products_custom_columns') );
			echo '-';
		}
		else
			add_action( 'manage_posts_custom_column', array($mp, 'edit_products_custom_columns') );
	}
	
	/**
	 * Remove download link from confirmation email
	 * @ Since 1.0.1 
	 */	
	function modify_email( $body, $order ) {
	
		if ( !is_object( $order ) || !is_array( $order->mp_cart_info ) )
			return $body;
			
		$order_id = $order->post_title; // Strange, but true :)
		
		foreach ( $order->mp_cart_info as $product_id=>$product_detail ) {
			$product = get_post( $product_id );
			// Find if this is an A+ product
			if ( $this->is_app_mp_page( $product ) ) {
					$find = get_permalink( $product_id ) . "?orderid=$order_id";
					$body = str_replace( $find, '-', $body );
			}
		}
		return $body;
	}

	/**
	 * Modify display name in the cart
	 * @ Since 1.0.1 
	 */	
	function modify_name( $name, $product_id ) {
		$product = get_post( $product_id );
		$var_names = get_post_meta( $product_id, 'mp_var_name', true );
		if ( !$this->is_app_mp_page( $product ) || !is_array( $var_names ) )
			return $name;
			
		list( $app_title, $app_id ) = split( ':', $name );
		if ( $app_id ) {
			global $wpdb;
			$result = $wpdb->get_row( "SELECT * FROM ". $this->app_table . " WHERE ID=".$app_id." " );
			if ( $result )
				$name = $name . " (". date_i18n( $this->datetime_format, strtotime( $result->start ) ) . ")";
		}
		return $name;
	}
	
	/**
	 * Handle after a successful Marketpress payment
	 * @ Since 1.0.1 
	 */	
	function handle_mp_payment( $order ) {

		if ( !is_object( $order ) || !is_array( $order->mp_cart_info ) )
			return;
		
		foreach ( $order->mp_cart_info as $product_id=>$product_detail ) {
			$product = get_post( $product_id );
			// Find if this is an A+ product
			if ( $this->is_app_mp_page( $product ) && is_array( $product_detail ) ) {
				foreach( $product_detail as $var ) {
					// Find variation = app id which should also be downloadable
					if ( isset( $var['name'] ) && isset( $var['download'] ) ) {
						list( $product_name, $app_id ) = split( ':', $var['name'] );
						$app_id = (int)trim( $app_id );
						if ( $this->change_status( 'paid', $app_id ) ) {
							// TODO: Consider adding a note
						}
					}
				}
			}
		}
	}
	
	/**
	 * Add to array of product pages where we have A+ shortcodes
	 * @ Since 1.0.1 
	 */	
	function add_to_mp( $post_id ) {
		$this->mp_posts[] = $post_id;
	}
	
	/**
	 * Check if this is a product page and if it is, add js codes to footer to hide some MP fields
	 * @ Since 1.0.1 
	 */	
	function product_page( $content ) {

		global $post;
		if ( is_object( $post ) && in_array( $post->ID, $this->mp_posts ) ) {
			$script  = '';
			$script .= '$(".mp_quantity,.mp_product_price,.mp_buy_form,.mp_product_variations,.appointments-paypal").hide();';
			$this->script = $this->script . $script;
		}
		return $content;
	}

	/**
	 * Hide meta (Add to chart button, price) for an A+ product
	 * @ Since 1.0.1 
	 */	
	function mp_product_list_meta( $meta, $post_id) {
	
		if ( in_array( $post_id, $this->mp_posts ) )
			return '<a class="mp_link_buynow" href="' . get_permalink($post_id) . '">' . __('Choose Option &raquo;', 'mp') . '</a>';
		else
			return	$meta;
	}
	
	/**
	 * Adds and returns a variation to the app product
	 * @ Since 1.0.1 
	 */	
	function add_variation( $app_id, $post_id ) {
		
		$meta = get_post_meta( $post_id, 'mp_var_name', true );
		// MP requires at least 2 variations, so we add a dummy one	if there is none
		if ( !$meta || !is_array( $meta ) ) {
			add_post_meta( $post_id, 'mp_var_name', array( 0 ) );
			add_post_meta( $post_id, 'mp_sku', array( 0 ) );
			add_post_meta( $post_id, 'mp_price', array( 0 ) );
			$meta = array( 0 );
		}

		$max = count( $meta );
		$meta[$max] = $app_id;
		update_post_meta( $post_id, 'mp_var_name', $meta );
		
		$sku = get_post_meta( $post_id, 'mp_sku', true );
		$sku[$max] = $this->service;
		update_post_meta( $post_id, 'mp_sku', $sku );
		
		$price = get_post_meta( $post_id, 'mp_price', true );
		$price[$max] = $this->get_price( true );
		update_post_meta( $post_id, 'mp_price', $price );

		// Add a download link, so that app will be a digital product
		$file = get_post_meta($post_id, 'mp_file', true);
		if ( !$file )
			add_post_meta( $post_id, 'mp_file', get_permalink( $post_id) );
			
		return $max;
	}

	/**
	 * If a pending app is removed automatically, also remove it from the cart
	 * @ Since 1.0.1 
	 */	
	function remove_from_cart( $app ) {
		global $mp;
		$changed = false;
		$cart = $mp->get_cart_cookie();
		
		if ( is_array( $cart ) ) {
			foreach ( $cart as $product_id=>$product_detail ) {
				$product = get_post( $product_id );
				$var_names = get_post_meta( $product_id, 'mp_var_name', true );
				// Find if this is an A+ product
				if ( $this->is_app_mp_page( $product ) && is_array( $product_detail ) && is_array( $var_names ) ) {
					foreach( $product_detail as $var_id=>$var_val ) {
						// Find variation = app id
						if ( isset( $var_names[$var_id] ) && $var_names[$var_id] == $app->ID ) {
							unset( $cart[$product_id] );
							$changed = true;
						}
					}
				}
			}
		}
		// Update cart only if something changed
		if ( $changed )
			$mp->set_cart_cookie($cart);
	}
	
	/**
	 * Clear appointment that is removed from the cart also from the database
	 * This is called before MP
	 * @ Since 1.0.1 
	 */	
	function remove_from_cart_manual( ) {
	
		if (isset($_POST['update_cart_submit'])) {
			if (isset($_POST['remove']) && is_array($_POST['remove'])) {
				foreach ($_POST['remove'] as $pbid) {
					list($bid, $product_id, $var_id) = split(':', $pbid);
					$product = get_post( $product_id );
					// Check if this is an app product page
					if ( $this->is_app_mp_page( $product ) ) {
						// We need to find var name = app_id
						$var_names = get_post_meta( $product_id, 'mp_var_name', true );
						if ( isset( $var_names[$var_id] ) ) {
							$this->change_status( 'removed', (int)trim( $var_names[$var_id] ) );
						}
					}
				}
			}
		}
	}
	
	/**
	 * Add the appointment to the cart
	 * This is called before MP
	 * @ Since 1.0.1 
	 */	
	function pre_update_cart( ) {
		global $mp;
		
		if ( isset( $_POST['product_id'] )  && isset( $_POST['variation'] ) && $_POST['product_id'] &&  $_POST['variation'] ) {
			$product_id = $_POST['product_id'];
			$product = get_post( $product_id );
			// Check if this is an app product page
			if ( $this->is_app_mp_page( $product ) ) {
				$variation = $_POST['variation'];
				
				$cart = $mp->get_cart_cookie();
				if ( !is_array( $cart ) )
					$cart = array();
					
				// Make quantity 1 
				$cart[$product_id][$variation] = 1;

				//save items to cookie
				$mp->set_cart_cookie($cart);
			}
		}
	}
	
/*******************************
* Methods for inits, styles, js
********************************
*/

	/**
     * Find blogs and install tables for each of them
	 * @since 1.0.2
     */
	function install() {
		global $wpdb;

		if ( function_exists('is_multisite') && is_multisite() ) {
				$network = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : "";
				$activate = isset($_GET['action']) ? $_GET['action'] : "";
				$is_network = ($network=='/wp-admin/network/plugins.php') ? true:false;
				$is_activation = ($activate=='deactivate') ? false:true;

			if ($is_network && $is_activation){
				$old_blog = $wpdb->blogid;
				// Get all blog ids
				$blogids = $wpdb->get_col($wpdb->prepare("SELECT blog_id FROM $wpdb->blogs"));
				foreach ($blogids as $blog_id) {
					switch_to_blog($blog_id);
					Appointments::_install();
				}
				switch_to_blog($old_blog);
				return;
			}	
		} 
		Appointments::_install();		
	}

	/**
     * Install database tables
     */
	function _install() {

		global $wpdb;
		
		$sql = "CREATE TABLE IF NOT EXISTS `" . $wpdb->prefix . "app_appointments" . "` (
		`ID` bigint(20) unsigned NOT NULL auto_increment,
		`created` datetime default NULL,
		`user` bigint(20) NOT NULL default '0',
		`name` varchar(250) default NULL,
		`email` varchar(250) default NULL,
		`phone` varchar(250) default NULL,
		`address` varchar(250) default NULL,
		`city` varchar(250) default NULL,
		`location` bigint(20) NOT NULL default '0',
		`service` bigint(20) NOT NULL default '0',
		`worker` bigint(20) NOT NULL default '0',
		`price` bigint(20) default NULL,
		`status` varchar(35) default NULL,
		`start` datetime default NULL,
		`end` datetime default NULL,
		`sent` text,
		`sent_worker` text,
		`note` text,
		PRIMARY KEY  (`ID`)
		);";
	
		$sql1 = "CREATE TABLE IF NOT EXISTS `" . $wpdb->prefix . "app_transactions" . "` (
		`transaction_ID` bigint(20) unsigned NOT NULL auto_increment,
		`transaction_app_ID` bigint(20) NOT NULL default '0',
		`transaction_paypal_ID` varchar(30) default NULL,
		`transaction_stamp` bigint(35) NOT NULL default '0',
		`transaction_total_amount` bigint(20) default NULL,
		`transaction_currency` varchar(35) default NULL,
		`transaction_status` varchar(35) default NULL,
		`transaction_note` text,
		PRIMARY KEY  (`transaction_ID`),
		KEY `transaction_app_ID` (`transaction_app_ID`)
		);";
		
		$sql2 = "CREATE TABLE IF NOT EXISTS `" .$wpdb->prefix . "app_working_hours" . "` (
		`ID` bigint(20) unsigned NOT NULL auto_increment,
		`location` bigint(20) NOT NULL default '0',
		`service` bigint(20) NOT NULL default '0',
		`worker` bigint(20) NOT NULL default '0',
		`status` varchar(30) default NULL,
		`hours` text,
		`note` text,
		PRIMARY KEY  (`ID`)
		);";
		
		// TODO: Make this WP time format free
		$sql21 = "INSERT INTO " .$wpdb->prefix . "app_working_hours (ID, location, worker,  `status`, hours, note) VALUES
		(NULL, 0, 0, 'open', 'a:7:{s:6:\"Sunday\";a:3:{s:6:\"active\";s:2:\"no\";s:5:\"start\";s:7:\"8:00 am\";s:3:\"end\";s:7:\"1:00 pm\";}s:6:\"Monday\";a:3:{s:6:\"active\";s:3:\"yes\";s:5:\"start\";s:7:\"8:00 am\";s:3:\"end\";s:7:\"5:00 pm\";}s:7:\"Tuesday\";a:3:{s:6:\"active\";s:3:\"yes\";s:5:\"start\";s:7:\"8:00 am\";s:3:\"end\";s:7:\"5:00 pm\";}s:9:\"Wednesday\";a:3:{s:6:\"active\";s:3:\"yes\";s:5:\"start\";s:7:\"8:00 am\";s:3:\"end\";s:7:\"5:00 pm\";}s:8:\"Thursday\";a:3:{s:6:\"active\";s:3:\"yes\";s:5:\"start\";s:7:\"8:00 am\";s:3:\"end\";s:7:\"5:00 pm\";}s:6:\"Friday\";a:3:{s:6:\"active\";s:3:\"yes\";s:5:\"start\";s:7:\"8:00 am\";s:3:\"end\";s:7:\"5:00 pm\";}s:8:\"Saturday\";a:3:{s:6:\"active\";s:2:\"no\";s:5:\"start\";s:7:\"8:00 am\";s:3:\"end\";s:7:\"1:00 pm\";}}', NULL),
		(NULL, 0, 0, 'closed', 'a:7:{s:6:\"Sunday\";a:3:{s:6:\"active\";s:2:\"no\";s:5:\"start\";s:8:\"12:00 pm\";s:3:\"end\";s:7:\"1:00 pm\";}s:6:\"Monday\";a:3:{s:6:\"active\";s:2:\"no\";s:5:\"start\";s:8:\"12:00 pm\";s:3:\"end\";s:7:\"1:00 pm\";}s:7:\"Tuesday\";a:3:{s:6:\"active\";s:2:\"no\";s:5:\"start\";s:8:\"12:00 pm\";s:3:\"end\";s:7:\"1:00 pm\";}s:9:\"Wednesday\";a:3:{s:6:\"active\";s:2:\"no\";s:5:\"start\";s:8:\"12:00 pm\";s:3:\"end\";s:7:\"1:00 pm\";}s:8:\"Thursday\";a:3:{s:6:\"active\";s:2:\"no\";s:5:\"start\";s:8:\"12:00 pm\";s:3:\"end\";s:7:\"1:00 pm\";}s:6:\"Friday\";a:3:{s:6:\"active\";s:2:\"no\";s:5:\"start\";s:8:\"12:00 pm\";s:3:\"end\";s:7:\"1:00 pm\";}s:8:\"Saturday\";a:3:{s:6:\"active\";s:2:\"no\";s:5:\"start\";s:8:\"12:00 pm\";s:3:\"end\";s:7:\"1:00 pm\";}}', NULL);
		";
		
		$sql3 = "CREATE TABLE IF NOT EXISTS `" .$wpdb->prefix . "app_exceptions" . "` (
		`ID` bigint(20) unsigned NOT NULL auto_increment,
		`location` bigint(20) NOT NULL default '0',
		`service` bigint(20) NOT NULL default '0',
		`worker` bigint(20) NOT NULL default '0',
		`status` varchar(30) default NULL,
		`days` text,
		`note` text,
		PRIMARY KEY  (`ID`)
		);";

		
		$sql4 = "CREATE TABLE IF NOT EXISTS `" . $wpdb->prefix . "app_services" . "` (
		`ID` bigint(20) unsigned,
		`name` varchar(255) default NULL,
		`capacity` bigint(20) NOT NULL default '0',
		`duration` bigint(20) NOT NULL default '0',
		`price` varchar(255) default NULL,
		`page` bigint(20) NOT NULL default '0',
		PRIMARY KEY  (`ID`)
		);";
		
		$sql41 = "INSERT INTO " . $wpdb->prefix . "app_services (ID, `name`, capacity, duration, `price`, page) 
		VALUES (1, 'Default Service', 0, 30, '1', 0)
		";
		
		$sql5 = "CREATE TABLE IF NOT EXISTS `" . $wpdb->prefix . "app_workers" . "` (
		`ID` bigint(20) unsigned,
		`price` varchar(255) default NULL,
		`services_provided` text,
		`page` bigint(20) NOT NULL default '0',
		PRIMARY KEY  (`ID`)
		);";
		
		$sql6 = "CREATE TABLE IF NOT EXISTS `" . $wpdb->prefix . "app_cache" . "` (
		`uri` varchar(255) default NULL,
		`created` datetime default NULL,
		`content` longtext,
		`script` longtext,
		 UNIQUE (`uri`)
		);";

		$wpdb->query($sql);
		$wpdb->query($sql1);
		// Add default working hours
		$wpdb->query($sql2);
		$count = $wpdb->get_var( "SELECT COUNT(ID) FROM " . $wpdb->prefix . "app_working_hours " );
		if ( !$count )
			$wpdb->query($sql21);
		$wpdb->query($sql3);
		// Add default service
		$wpdb->query($sql4);
		$count = $wpdb->get_var( "SELECT COUNT(ID) FROM " . $wpdb->prefix . "app_services " );
		if ( !$count )
			$wpdb->query($sql41);
		$wpdb->query($sql5);
		$wpdb->query($sql6);
	}
	
	/**
	 * Install tables for new blog
	 * @since 1.0.2
	 */	
	function new_blog($blog_id, $user_id, $domain, $path, $site_id, $meta ) {
		global $wpdb;
		
		if ( !function_exists( 'is_plugin_active_for_network' ) )
			require_once( ABSPATH . '/wp-admin/includes/plugin.php' );

		if ( is_plugin_active_for_network('appointments/appointments.php') ) {
			$old_blog = $wpdb->blogid;
			switch_to_blog( $blog_id );
			Appointments::_install();
			switch_to_blog( $old_blog );
		}
	}
	
	/**
	 * Remove tables for a deleted blog
	 * @since 1.0.2
	 */	
	function delete_blog( $blog_id, $drop ) {
		global $wpdb;
		
		if ( $blog_id >1 ) {
			$old_blog = $wpdb->blogid;
			switch_to_blog( $blog_id );
			_wpmudev_appointments_uninstall( );
			switch_to_blog( $old_blog );
		}
	}

	/**
	 * Initialize widgets
	 */	
	function widgets_init() {
		if ( !is_blog_installed() )
			return;

		register_widget( 'Appointments_Widget_Services' );
		register_widget( 'Appointments_Widget_Service_Providers' );
		register_widget( 'Appointments_Widget_Monthly_Calendar' );
	}
	
	/**
	 * Load javascript to the footer
	 */
	function wp_footer() {
		$script = '';
		$this->script = apply_filters( 'app_footer_scripts', $this->script );
		
		if ( $this->script ) {
			$script .= '<script type="text/javascript">';
			$script .= "jQuery(document).ready(function($) {";
			$script .= $this->script;
			$script .= "});</script>";
		}	
			
		echo $this->esc_rn( $script );
	}

	/**
	 * Load style and script only when they are necessary
	 * http://beerpla.net/2010/01/13/wordpress-plugin-development-how-to-include-css-and-javascript-conditionally-and-only-when-needed-by-the-posts/
	 */		
	function load_styles( $posts ) {
		if ( empty($posts) || is_admin() ) 
			return $posts;
	
		$this->shortcode_found = false; // use this flag to see if styles and scripts need to be enqueued
		foreach ($posts as $post) {
			if (stripos($post->post_content, '[app_') !== false) {
				$this->shortcode_found = true;
				$this->add_to_cache( $post->ID );
				// Also add MP posts if enabled
				if ( $this->mp && 'product' == $post->post_type )
					$this->add_to_mp( $post->ID );
			}
		}
 
		if ( $this->shortcode_found ) { 
			$this->load_scripts_styles( );
		}
		return $posts;
	}

	/**
	 * Function to load all necessary scripts and styles
	 * Can be called externally, e.g. when forced from a page template
	 */	
	function load_scripts_styles( ) {
		wp_enqueue_script( 'jquery' );
		add_action( 'wp_footer', array( &$this, 'wp_footer' ) );	// Publish plugin specific scripts in the footer 	
		
		// TODO: consider this
		wp_enqueue_script( 'app-js-check', $this->plugin_url . '/js/js-check.js', array('jquery'), $this->version);
		wp_localize_script( 'app-js-check', '_appointments_data',
			array(
				'ajax_url' => admin_url('admin-ajax.php'),
				'root_url' => plugins_url('appointments/images/')
				)
		);
		
		if ( !current_theme_supports( 'appointments_style' ) ) {
			wp_enqueue_style( "appointments", $this->plugin_url. "/css/front.css", array(), $this->version );
			add_action( 'wp_head', array( &$this, 'wp_head' ) );
		}
		
		// wpautop does strange things to cache content, so remove it first and add to output
		if ( $this->use_cache() ) {
			if ( has_filter( 'wpautop' ) ) {
					$this->had_filter = true;
			}
			remove_filter( 'the_content', 'wpautop' );
			remove_filter( 'the_excerpt', 'wpautop' );
		}
		
		// Prevent external caching plugins for this page
		if ( !defined( 'DONOTCACHEPAGE' ) )
			define( 'DONOTCACHEPAGE', true );
		
		// Load the rest only if API use is selected
		if ($this->options['accept_api_logins']) {
			wp_enqueue_script('appointments_api_js', $this->plugin_url . '/js/appointments-api.js', array('jquery'), $this->version );
			wp_localize_script('appointments_api_js', 'l10nAppApi', array(
				'facebook' => __('Login with Facebook', 'appointments'),
				'twitter' => __('Login with Twitter', 'appointments'),
				'google' => __('Login with Google+', 'appointments'),
				'wordpress' => __('Login with WordPress', 'appointments'),
				'submit' => __('Submit', 'appointments'),
				'cancel' => __('Cancel', 'appointments'),
				'please_wait' => __('Please, wait...', 'appointments'),
				'logged_in' => __('You are now logged in', 'appointments'),
				'error' => __('Login error. Please try again.', 'appointments'),
			));
						
			if (!$this->options['facebook-no_init']) {
				add_action('wp_footer', create_function('', "echo '" .
				sprintf(
					'<div id="fb-root"></div><script type="text/javascript">
					window.fbAsyncInit = function() {
						FB.init({
						  appId: "%s",
						  status: true,
						  cookie: true,
						  xfbml: true
						});
					};
					// Load the FB SDK Asynchronously
					(function(d){
						var js, id = "facebook-jssdk"; if (d.getElementById(id)) {return;}
						js = d.createElement("script"); js.id = id; js.async = true;
						js.src = "//connect.facebook.net/en_US/all.js";
						d.getElementsByTagName("head")[0].appendChild(js);
					}(document));
					</script>',
					$this->options['facebook-app_id']
				) .
				"';"));
			}
		}
	}

	/**
	 * css that will be added to the head, again only for app pages
	 */
	function wp_head() {

		if ( isset( $this->options["additional_css"] ) && '' != trim( $this->options["additional_css"] ) ) {
			?>
			<style type="text/css">
			<?php echo $this->options['additional_css'];
		}
		
		foreach ( $this->get_classes() as $class=>$name ) {
			if ( !isset( $this->options["color_set"] ) || !$this->options["color_set"] ) {
				if ( isset( $this->options[$class."_color"] ) )
					$color = $this->options[$class."_color"];
				else
					$color = $this->get_preset( $class, 1 );
			}
			else 
				$color = $this->get_preset( $class, $this->options["color_set"] );

			echo 'td.'.$class.',div.'.$class.' {background: #'. $color .';}';
		}
		?>
		</style>
		<?php		
	}
	
	/**
     * Localize the plugin
     */
	function localization() {
		// Load up the localization file if we're using WordPress in a different language
		// Place it in Appointments+'s "languages" folder and name it "appointments-[value in wp-config].mo"
		load_plugin_textdomain( 'appointments', false, '/appointments/languages/' );
	}

	/**
	 *	Add initial settings
	 *
	 */
	function init() {
		// Since wp-cron is not reliable, use this instead
		add_option( "app_last_update", time() );

$confirmation_message = "Dear CLIENT,

We are pleased to confirm your appointment for SITE_NAME.

Here are the appointment details:
Requested service: SERVICE
Date and time: DATE_TIME

SERVICE_PROVIDER will assist you for this service.

Kind regards,
SITE_NAME
";

$reminder_message = "Dear CLIENT,

We would like to remind your appointment with SITE_NAME.

Here are your appointment details:
Requested service: SERVICE
Date and time: DATE_TIME

SERVICE_PROVIDER will assist you for this service.

Kind regards,
SITE_NAME
";		
		add_option( 'appointments_options', array(
													'min_time'					=> 30,
													'additional_min_time'		=> '',
													'admin_min_time'			=> '',
													'app_limit'					=> 30,
													'clear_time'				=> 60,
													'spam_time'					=> 0,
													'allow_worker_selection'	=> 'no',
													'allow_overwork'			=> 'no',
													'allow_overwork_break'		=> 'no',
													'app_page_type'				=> 'monthly',
													'accept_api_logins'			=> '',
													'facebook-app_id'			=> '',
													'twitter-app_id'			=> '',
													'twitter-app_secret'		=> '',
													'show_legend'				=> 'yes',
													'gcal'						=> 'yes',
													'gcal_location'				=> '',
													'color_set'					=> 1,
													'free_color'				=> '48c048',
													'busy_color'				=> 'ffffff',
													'notpossible_color'			=> 'ffffff',
													'make_an_appointment'		=> '',
													'ask_name'					=> '1',
													'ask_email'					=> '1',
													'ask_phone'					=> '1',
													'ask_address'				=> '',
													'ask_city'					=> '',
													'ask_note'					=> '',
													'additional_css'			=> '.entry-content td{border:none;width:50%}',
													'payment_required'			=> 'no',
													'percent_deposit'			=> '',
													'fixed_deposit'				=> '',
													'currency'					=> 'USD',
													'mode'						=> 'sandbox',
													'merchant_email'			=> '',
													'return'					=> 1,
													'login_required'			=> 'no',
													'send_confirmation'			=> 'yes',
													'send_confirmation_pending'	=> 'no',
													'send_reminder'				=> 'yes',
													'reminder_time'				=> '24',
													'send_reminder_worker'		=> 'yes',
													'reminder_time_worker'		=> '4',
													'confirmation_subject'		=> 'Confirmation of your Appointment',
													'confirmation_message'		=> $confirmation_message,
													'reminder_subject'			=> 'Reminder for your Appointment',
													'reminder_message'			=> $reminder_message,
													'log_emails'				=> 'yes',
													'use_cache'					=> 'no',
													'use_mp'					=> false
										)
		);
		
		//  Run this code not before 10 mins
		if ( ( time( ) - get_option( "app_last_update" ) ) < apply_filters( 'app_update_time', 600 ) ) 
			return;
		$this->remove_appointments();
		$this->send_reminder();
		$this->send_reminder_worker();
	}

/*******************************
* Methods for Confirmation
********************************
	
	/**
	 *	Send confirmation email
	 */		
	function send_confirmation( $app_id ) {
		if ( !isset( $this->options["send_confirmation"] ) || 'yes' != $this->options["send_confirmation"] )
			return;
		global $wpdb;
		$r = $wpdb->get_row( "SELECT * FROM " . $this->app_table . " WHERE ID=".$app_id." " );
		if ( $r != null ) {
			$worker_data = get_userdata( $r->worker );
			if ( $worker_data->user_email )
				$worker_email = $worker_data->user_email;
			else
				$worker_email = '';
			wp_mail( 
					$r->email,
					$this->_replace( $this->options["confirmation_subject"], $r->name, $this->get_service_name( $r->service), $this->get_worker_name( $r->worker), $r->start ),
					$this->_replace( $this->options["confirmation_message"], $r->name, $this->get_service_name( $r->service), $this->get_worker_name( $r->worker), $r->start ),
					$this->message_headers( true, $worker_email )
					);
			if ( isset( $this->options["log_emails"] ) && 'yes' == $this->options["log_emails"] )
				$this->log( sprintf( __('Confirmation message sent to %s for appointment ID:%s','appointments'), $r->email, $app_id ) ); 
		}
	}
	
	/**
	 * Send notification email
	 * since 1.0.2
	 */		
	function send_notification( $app_id ) {
		if ( !isset( $this->options["send_notification"] ) || 'yes' != $this->options["send_notification"] )
			return;
		global $wpdb;
		$r = $wpdb->get_row( "SELECT * FROM " . $this->app_table . " WHERE ID=".$app_id." " );
		if ( $r != null ) {
			global $current_site;
			$admin_email = get_site_option('admin_email');
			if ( !$admin_email )
				$admin_email = 'admin@' . $current_site->domain;

			$mail_result = wp_mail( 
					$admin_email,
					__('An appointment requires your confirmation', 'appointments'),
					sprintf( __('The new appointment has an ID %s and you can edit it clicking this link: %s','appointments'), $app_id, admin_url("admin.php?page=appointments&type=pending") ),
					$this->message_headers( false, '' )
				);
			if ( $mail_result && isset( $this->options["log_emails"] ) && 'yes' == $this->options["log_emails"] )
				$this->log( sprintf( __('Notification message sent to %s for appointment ID:%s','appointments'), $admin_email, $app_id ) ); 
		}
	}
	
	/**
	 *	Check and send reminders to clients for appointments
	 *
	 */		
	function send_reminder() {
		if ( !isset( $this->options["reminder_time"] ) || !$this->options["reminder_time"] || 'yes' != $this->options["send_reminder"] )
			return;
			
		$hours = explode( "," , $this->options["reminder_time"] );

		if ( !is_array( $hours ) )
			return;
			
		global $wpdb;
		
		$messages = array();
		foreach ( $hours as $hour ) {
			$results = $wpdb->get_results( "SELECT * FROM " . $this->app_table . " 
				WHERE (status='paid' OR status='confirmed') 
				AND (sent NOT LIKE '%:".trim($hour).":%' OR sent IS NULL)
				AND DATE_ADD('".date( 'Y-m-d H:i:s', $this->local_time )."', INTERVAL ".$hour." HOUR) > start " );
		
			if ( $results ) {
				foreach ( $results as $r ) {
					$messages[] = array(
								'ID'		=> $r->ID,
								'to'		=> $r->email,
								'subject'	=> $this->_replace( $this->options["reminder_subject"], $r->name, $this->get_service_name( $r->service), $this->get_worker_name( $r->worker), $r->start ),
								'message'	=> $this->_replace( $this->options["reminder_message"], $r->name, $this->get_service_name( $r->service), $this->get_worker_name( $r->worker), $r->start )
							);
					// Update "sent" field		
					$wpdb->update( $this->app_table,
									array( 'sent'	=> rtrim( $r->sent, ":" ) . ":" . $hour . ":" ),
									array( 'ID'		=> $r->ID ),
									array ( '%s' )
								);
				}
			}
		}
		// Remove duplicates
		$messages = $this->array_unique_by_ID( $messages );
		if ( is_array( $messages ) && !empty( $messages ) ) {
			foreach ( $messages as $message ) {
				$mail_result = wp_mail( $message["to"], $message["subject"], $message["message"], $this->message_headers() );
				if ( $mail_result && isset( $this->options["log_emails"] ) && 'yes' == $this->options["log_emails"] )
					$this->log( sprintf( __('Reminder message sent to %s for appointment ID:%s','appointments'), $message["to"], $message["ID"] ) ); 
			}
		}
	}

	/**
	 *	Remove duplicate messages by app ID
	 */		
	function array_unique_by_ID( $messages ) {
		if ( !is_array( $messages ) )
			return false;
		$idlist = array();
		// Save array to a temp area
		$result = $messages;
		foreach ( $messages as $key=>$message ) {
			if ( in_array( $message['ID'], $idlist ) )
				unset( $result[$key] );
			else
				$idlist[] = $message['ID'];
		}
		return $result;
	}
	
	/**
	 *	Check and send reminders to worker for appointments
	 */		
	function send_reminder_worker() {
		if ( !isset( $this->options["reminder_time_worker"] ) || !$this->options["reminder_time_worker"] || 'yes' != $this->options["send_reminder_worker"] )
			return;
			
		$hours = explode( "," , $this->options["reminder_time_worker"] );

		if ( !is_array( $hours ) )
			return;
			
		global $wpdb;
		
		$messages = array();
		foreach ( $hours as $hour ) {
			$results = $wpdb->get_results( "SELECT * FROM " . $this->app_table . " 
				WHERE (status='paid' OR status='confirmed')
				AND worker <> 0
				AND (sent_worker NOT LIKE '%:".trim($hour).":%' OR sent_worker IS NULL)
				AND DATE_ADD('".date( 'Y-m-d H:i:s', $this->local_time )."', INTERVAL ".$hour." HOUR) > start " );
		
			$provider_add_text  = __('You are getting this reminder message for your appointment as a provider. The below is a copy of what may have been sent to your client:', 'appointments');
			$provider_add_text .= "\n\n\n";
			
			if ( $results ) {
				foreach ( $results as $r ) {
					$worker_data = get_userdata( $r->worker );
					$messages[] = array(
								'ID'		=> $r->ID,
								'to'		=> $worker_data->user_email,
								'subject'	=> $this->_replace( $this->options["reminder_subject"], $r->name, $this->get_service_name( $r->service), $this->get_worker_name( $r->worker), $r->start ),
								'message'	=> $provider_add_text . $this->_replace( $this->options["reminder_message"], $r->name, $this->get_service_name( $r->service), $this->get_worker_name( $r->worker), $r->start )
							);
					// Update "sent" field		
					$wpdb->update( $this->app_table,
									array( 'sent_worker' => rtrim( $r->sent_worker, ":" ) . ":" . $hour . ":" ),
									array( 'ID'		=> $r->ID ),
									array ( '%s' )
								);
				}
			}
		}
		// Remove duplicates
		$messages = $this->array_unique_by_ID( $messages );
		if ( is_array( $messages ) && !empty( $messages ) ) {
			foreach ( $messages as $message ) {
				$mail_result = wp_mail( $message["to"], $message["subject"], $message["message"], $this->message_headers() );
				if ( $mail_result && isset( $this->options["log_emails"] ) && 'yes' == $this->options["log_emails"] )
					$this->log( sprintf( __('Reminder message sent to %s for appointment ID:%s','appointments'), $message["to"], $message["ID"] ) ); 
			}
		}
	}

	/**
	 *	Replace placeholders with real values for email subject and content
	 */	
	function _replace( $text, $user, $service, $worker, $datetime ) {
		return str_replace( 
					array( "SITE_NAME", "CLIENT", "SERVICE_PROVIDER", "SERVICE", "DATE_TIME" ),
					array( get_option( 'blogname' ), $user, $worker, $service, date_i18n( $this->datetime_format, strtotime($datetime) ) ),
					$text
				);
	}
	
	/**
	 *	Email message headers
	 *  Will also send bcc to admin and worker if set so
	 */	
	function message_headers( $admin=false, $worker_email='' ) {
		global $current_site;
		$admin_email = get_site_option('admin_email');
		if ( !$admin_email ){
			$admin_email = 'admin@' . $current_site->domain;
		}
		$bcc = '';
		if ( $admin )
			$bcc .= "bcc: " . $admin_email . "\n";
		if ( '' != $worker_email )
			$bcc .= "bcc: " . $worker_email . "\n";
		$message_headers = "MIME-Version: 1.0\n" . "From: " . get_option( 'blogname' ) .  " <{$admin_email}>\n" . $bcc. "Content-Type: text/plain; charset=\"" . get_option('blog_charset') . "\"\n";
		// Modify message headers
		$message_headers = apply_filters( 'app_message_headers', $message_headers );
		
		return $message_headers;
	}			

	/**
	 *	Remove an appointment if not paid or expired
	 *	Clear expired appointments.
	 *	Change status to completed if they are confirmed or paid
	 *	Change status to removed if they are pending
	 *
	 */	
	function remove_appointments( ) {
		
		global $wpdb;
		
		$expireds = $wpdb->get_results( "SELECT * FROM " . $this->app_table . " WHERE start<'" . date ("Y-m-d H:i:s", $this->local_time ). "' " );
		if ( $expireds ) {
			foreach ( $expireds as $expired ) {
				if ( 'pending' == $expired->status )
					$new_status = 'removed';
				else if ( 'confirmed' == $expired->status || 'paid' == $expired->status )
					$new_status = 'completed';
				else
					$new_status = $expired->status; // Do nothing ??
				$update = $wpdb->update( $this->app_table,
								array( 'status'	=> $new_status ),
								array( 'ID'	=> $expired->ID )
							);
				if ( $update )
					do_action( 'app_remove_expired', $expired, $new_status );
			}
		}
		
		// Clear appointments that are staying in pending status long enough
		if ( isset( $this->options["clear_time"] ) && $this->options["clear_time"] > 0 ) {
			$clear_secs = $this->options["clear_time"] * 60;
			$expireds = $wpdb->get_results( "SELECT * FROM " . $this->app_table . " WHERE status='pending' AND created<'" . date ("Y-m-d H:i:s", $this->local_time - $clear_secs ). "' " );
			if ( $expireds ) {
				foreach ( $expireds as $expired ) {
					$update = $wpdb->update( $this->app_table,
									array( 'status'	=> 'removed' ),
									array( 'ID'	=> $expired->ID )
								);
					if ( $update ) {
						do_action( 'app_remove_pending', $expired );
						if ( $this->mp )
							$this->remove_from_cart( $expired );
					}
				}
			}
		}
		update_option( "app_last_update", time() );
		
		// Appointment status probably changed, so clear cache. 
		// Anyway it is good to clear the cache in certain intervals.
		// This can be removed for pages with very heavy visitor traffic, but little appointments
		$this->flush_cache();
	}

/*******************************
* Methods for Admin
********************************
*/
	/**
	 * Add app status counts in admin Right Now Dashboard box
	 * http://codex.wordpress.org/Plugin_API/Action_Reference/right_now_content_table_end
	 */	
	function add_app_counts() {
	
		global $wpdb;
		
		$num_active = $wpdb->get_var("SELECT COUNT(ID) FROM " . $this->app_table . " WHERE status='paid' OR status='confirmed' " );

        $num = number_format_i18n( $num_active );
        $text = _n( 'Active Appointment', 'Active Appointments', intval( $num_active ) );
        if ( current_user_can( 'manage_options' ) ) {
            $num = "<a href='admin.php?page=appointments'>$num</a>";
            $text = "<a href='admin.php?page=appointments'>$text</a>";
        }
        echo '<td class="first b b-appointment">' . $num . '</td>';
        echo '<td class="t appointment">' . $text . '</td>';

        echo '</tr>';
		
		$num_pending = $wpdb->get_var("SELECT COUNT(ID) FROM " . $this->app_table . " WHERE status='pending' " );

        if ( $num_pending > 0 ) {
            $num = number_format_i18n( $num_pending );
            $text = _n( 'Pending Appointment', 'Pending Appointments', intval( $num_pending ) );
            if ( current_user_can( 'manage_options' ) ) {
                $num = "<a href='admin.php?page=appointments&type=pending'>$num</a>";
                $text = "<a href='admin.php?page=appointments&type=pending'>$text</a>";
            }
            echo '<td class="first b b-appointment">' . $num . '</td>';
            echo '<td class="t appointment">' . $text . '</td>';

            echo '</tr>';
        }
	}
	
	// Enqeue js on admin pages
	function admin_scripts() {
		wp_enqueue_script( 'jquery-colorpicker', $this->plugin_url . '/js/colorpicker.js', array('jquery'), $this->version);
		wp_enqueue_script( 'jquery-datepick', $this->plugin_url . '/js/jquery.datepick.min.js', array('jquery'), $this->version);
		if ( !@$this->options["disable_js_check_admin"] )
			wp_enqueue_script( 'app-js-check', $this->plugin_url . '/js/js-check.js', array('jquery'), $this->version);
		
	}
	
	// Enqeue css on settings page
	function admin_css_settings() {
		wp_enqueue_style( 'jquery-colorpicker-css', $this->plugin_url . '/css/colorpicker.css', false, $this->version);
	}

	// Enqeue css for all admin pages
	function admin_css() {
		wp_enqueue_style( "appointments-admin", $this->plugin_url . "/css/admin.css", false, $this->version );
		wp_enqueue_style( "jquery-datepick", $this->plugin_url . "/css/jquery.datepick.css", false, $this->version );
	}

	/**
	 * Track javascript errors
	 * @since 1.0.3
	 */	
	function js_error() {
		// TODO: Activate this again in future releases
		if  ( false && isset( $_POST['url'] ) ) {
			$this->error_url = $_POST['url'];
			$this->log( __('Javascript error on : ', 'appointments') . $this->error_url );
			die( json_encode( array( 'message'	=> '<div class="error"><p>' .
				sprintf( __('<b>[Appointments+]</b> You have at least one javascript error on %s.
				<br />Error message: %s<br />File: %s<br />Line: %s', 'appointments'), $this->error_url, @$_POST['errorMessage'], @$_POST['file'], @$_POST['lineNumber']) .
			'</p></div>')
			)
			);
		}
		die();
	}

	/**
	 *	Warn admin if no services defined or duration is wrong
	 */	
	function admin_notices() {
		global $wpdb;
		$r = false;
		$results = $this->get_services();
		if ( !$results ) {
			echo '<div class="error"><p>' .
				__('<b>[Appointments+]</b> You must define at least once service', 'appointments') .
			'</p></div>';
			$r = true;
		}
		else {
			foreach ( $results as $result ) {
				if ( $result->duration < $this->min_time ) {
					echo '<div class="error"><p>' .
						__('<b>[Appointments+]</b> One of your services has a duration smaller than time base', 'appointments') .
					'</p></div>';
					$r = true;
					break;
				}
			}
		}
		return $r;
	}

	/**
	 *	Admin pages init stuff, save settings
	 *
	 */
	function admin_init() {
	
		$page = add_menu_page('appointments', __('Appointments','appointments'), 'manage_options',  'appointments', array(&$this,'appointment_list'),'div');
		add_submenu_page('appointments', __('Transactions','appointments'), __('Transactions','appointments'), 'manage_options', "app_transactions", array(&$this,'transactions'));
		add_submenu_page('appointments', __('Settings','appointments'), __('Settings','appointments'), 'manage_options', "app_settings", array(&$this,'settings'));
		
		// Add datepicker to appointments page
		add_action( "admin_print_scripts-$page", array( &$this, 'admin_scripts' ) );
		
		if ( isset($_POST["action_app"]) && !wp_verify_nonce($_POST['app_nonce'],'update_app_settings') ) {
			add_action( 'admin_notices', array( &$this, 'warning' ) );
			return;
		}
		
		// Read Location, Service, Worker
		$this->get_lsw();
		global $wpdb;
		
		if ( isset($_POST["action_app"]) && 'save_general' == $_POST["action_app"] ) {
			$this->options["min_time"]					= $_POST["min_time"];
			$this->options["additional_min_time"]		= $_POST["additional_min_time"];
			$this->options["admin_min_time"]			= $_POST["admin_min_time"];
			$this->options["app_limit"]					= $_POST["app_limit"];
			$this->options["clear_time"]				= $_POST["clear_time"];
			$this->options["spam_time"]					= $_POST["spam_time"];
			$this->options["allow_worker_wh"]			= $_POST["allow_worker_wh"];
			$this->options["allow_overwork"]			= $_POST["allow_overwork"];
			$this->options["allow_overwork_break"]		= $_POST["allow_overwork_break"];

			$this->options["login_required"]			= $_POST["login_required"];
			$this->options["accept_api_logins"]			= isset( $_POST["accept_api_logins"] );
			$this->options["facebook-no_init"]			= isset( $_POST["facebook-no_init"] );
			$this->options['facebook-app_id']			= trim( $_POST['facebook-app_id'] );
			$this->options['twitter-app_id']			= trim( $_POST['twitter-app_id'] );
			$this->options['twitter-app_secret']		= trim( $_POST['twitter-app_secret'] );
			
			$this->options["app_page_type"]				= $_POST["app_page_type"];
			$this->options["show_legend"]				= $_POST["show_legend"];
			$this->options["color_set"]					= $_POST["color_set"];
			foreach ( $this->get_classes() as $class=>$name ) {
				$this->options[$class."_color"]				= $_POST[$class."_color"];
			}
			$this->options["ask_name"]					= isset( $_POST["ask_name"] );
			$this->options["ask_email"]					= isset( $_POST["ask_email"] );
			$this->options["ask_phone"]					= isset( $_POST["ask_phone"] );
			$this->options["ask_phone"]					= isset( $_POST["ask_phone"] );
			$this->options["ask_address"]				= isset( $_POST["ask_address"] );
			$this->options["ask_city"]					= isset( $_POST["ask_city"] );
			$this->options["ask_note"]					= isset( $_POST["ask_note"] );
			$this->options["gcal"]						= $_POST["gcal"];
			$this->options["gcal_location"]				= @$_POST["gcal_location"];
			$this->options["additional_css"]			= $_POST["additional_css"];
			
			$this->options["payment_required"]			= $_POST["payment_required"];
			$this->options["percent_deposit"]			= trim( str_replace( '%', '', $_POST["percent_deposit"] ) );
			$this->options["fixed_deposit"]				= trim( str_replace( $this->options["currency"], '', $_POST["fixed_deposit"] ) );
			$this->options['members_no_payment'] 		= isset( $_POST['members_no_payment'] );
			$this->options['members_discount'] 			= trim( str_replace( '%', '', $_POST['members_discount'] ) );
			$this->options["members"]					= maybe_serialize( @$_POST["members"] );
			$this->options['currency'] 					= $_POST['currency'];
			$this->options['mode'] 						= $_POST['mode'];
			$this->options['merchant_email'] 			= $_POST['merchant_email'];
			$this->options['return'] 					= $_POST['return'];

			$this->options["send_confirmation"]			= $_POST["send_confirmation"];
			$this->options["send_notification"]			= @$_POST["send_notification"];
			$this->options["confirmation_subject"]		= $_POST["confirmation_subject"];
			$this->options["confirmation_message"]		= $_POST["confirmation_message"];
			$this->options["send_reminder"]				= $_POST["send_reminder"];
			$this->options["reminder_time"]				= $_POST["reminder_time"];
			$this->options["send_reminder_worker"]		= $_POST["send_reminder_worker"];
			$this->options["reminder_time_worker"]		= $_POST["reminder_time_worker"];
			$this->options["reminder_subject"]			= $_POST["reminder_subject"];
			$this->options["reminder_message"]			= $_POST["reminder_message"];
			$this->options["log_emails"]				= $_POST["log_emails"];
	
			$this->options['use_cache'] 				= $_POST['use_cache'];
			$this->options['disable_js_check_admin']	= isset( $_POST['disable_js_check_admin'] );
			$this->options['disable_js_check_frontend']	= isset( $_POST['disable_js_check_frontend'] );
			
			$this->options['use_mp']	 				= isset( $_POST['use_mp'] );
			$this->options["app_page_type_mp"]			= @$_POST["app_page_type_mp"];
		
			$saved = false;
			if ( update_option( 'appointments_options', $this->options ) ) {
				if ( ! $this->admin_notices() )
					add_action( 'admin_notices', array ( &$this, 'saved' ) );
				$saved = true;
			}
			
			// Flush cache
			if ( isset( $_POST["force_flush"] ) || $saved ) {
				$this->flush_cache();
				add_action( 'admin_notices', array ( &$this, 'cleared' ) );
			}

// Bimonthly schedule			
$two_months = '
<td colspan="2">
[app_monthly_schedule]
</td>
</tr>
<td colspan="2">
[app_monthly_schedule add="1"]
</td>
</tr>
<tr>
<td colspan="2">
[app_pagination step="2" month="1"]
</td>
';
			
// Monthly schedule			
$one_month = '
<td colspan="2">
[app_monthly_schedule]
</td>
</tr>
<tr>
<td colspan="2">
[app_pagination month="1"]
</td>
';

// Two week schedule			
$two_weeks = '
<td>
[app_schedule]
</td>
<td>
[app_schedule add="1"]
</td>
</tr>
<tr>
<td colspan="2">
[app_pagination step="2"]
</td>
';
			
// One week schedule			
$one_week = '
<td colspan="2">
[app_schedule long="1"]
</td>
</tr>
<tr>
<td colspan="2">
[app_pagination]
</td>
';

// Common parts			
$template = '
<table>
<tbody>
<tr>
<td colspan="2">
[app_my_appointments]
</td>
</tr>
<tr>
<td>[app_services]</td>
<td>[app_service_providers]</td>
</tr>
<tr>
PLACEHOLDER
</tr>
<tr>
<td colspan="2">
[app_login]
</td>
</tr>
<tr>
<td colspan="2">
[app_confirmation]
</td>
</tr>
<tr>
<td colspan="2">
[app_paypal]
</td>
</tr>
</tbody>
</table>
';
			// Add an appointment page			
			if ( isset( $_POST["make_an_appointment"] ) ) {
				
				switch( $_POST["app_page_type"] ) {
					case 'two_months':	$content = str_replace( 'PLACEHOLDER', $two_months, $template ); break;
					case 'one_month':	$content = str_replace( 'PLACEHOLDER', $one_month, $template ); break;
					case 'two_weeks':	$content = str_replace( 'PLACEHOLDER', $two_weeks, $template ); break;
					case 'one_week':	$content = str_replace( 'PLACEHOLDER', $one_week, $template ); break;
					default:			$content = str_replace( 'PLACEHOLDER', $one_month, $template ); break;
				}
				
				wp_insert_post( 
						array(
							'post_title'	=> 'Make an Appointment',
							'post_status'	=> 'publish',
							'post_type'		=> 'page',
							'post_content'	=> $content
						)
				);
			}
			
			// Add an appointment product page			
			if ( isset( $_POST["make_an_appointment_product"] ) && $this->marketpress_active ) {
				
				switch( $_POST["app_page_type_mp"] ) {
					case 'two_months':	$content = str_replace( 'PLACEHOLDER', $two_months, $template ); break;
					case 'one_month':	$content = str_replace( 'PLACEHOLDER', $one_month, $template ); break;
					case 'two_weeks':	$content = str_replace( 'PLACEHOLDER', $two_weeks, $template ); break;
					case 'one_week':	$content = str_replace( 'PLACEHOLDER', $one_week, $template ); break;
					default:			$content = str_replace( 'PLACEHOLDER', $one_month, $template ); break;
				}
				
				$post_id = wp_insert_post( 
						array(
							'post_title'	=> 'Appointment',
							'post_status'	=> 'publish',
							'post_type'		=> 'product',
							'post_content'	=> $content
						)
				);
				if ( $post_id ) {
					// Add a download link, so that app will be a digital product
					$file = get_post_meta($post_id, 'mp_file', true);
					if ( !$file )
						add_post_meta( $post_id, 'mp_file', get_permalink( $post_id) );
					// MP requires at least 2 variations, so we add a dummy one	
					add_post_meta( $post_id, 'mp_var_name', array( 0 ) );
					add_post_meta( $post_id, 'mp_sku', array( 0 ) );
					add_post_meta( $post_id, 'mp_price', array( 0 ) );
				}
			}
		}
		
		$result = $updated = $inserted = false;
		// Save Working Hours
		if ( isset($_POST["action_app"]) && 'save_working_hours' == $_POST["action_app"] ) {
			$location = (int)$_POST['location'];
			foreach ( array( 'closed', 'open' ) as $stat ) {
				$query = "SELECT COUNT(*) FROM ". $this->wh_table .
						" WHERE location=".$location." AND worker=".$this->worker." AND status='".$stat."' ";
						
				$count = $wpdb->get_var($query);
				
				if ( $count > 0 ) {
					$result = $wpdb->update( $this->wh_table,
								array( 'hours'=>serialize($_POST[$stat]), 'status'=>$stat ),
								array( 'location'=>$location, 'worker'=>$this->worker, 'status'=>$stat ),
								array( '%s', '%s' ),
								array( '%d', '%d', '%s' )
							);
				}
				else {
					$result = $wpdb->insert( $this->wh_table, 
								array( 'location'=>$location, 'worker'=>$this->worker, 'hours'=>serialize($_POST[$stat]), 'status'=>$stat ),
								array( '%d', '%d', '%s', '%s' )
							);
				}
				if ( $result )
					add_action( 'admin_notices', array ( &$this, 'saved' ) );
			}
		}
		// Save Exceptions
		if ( isset($_POST["action_app"]) && 'save_exceptions' == $_POST["action_app"] ) {
			$location = (int)$_POST['location'];
			foreach ( array( 'closed', 'open' ) as $stat ) {
				$count = $wpdb->get_var( "SELECT COUNT(*) FROM ". $this->exceptions_table .
						" WHERE location=".$location." AND worker=".$this->worker." AND status='".$stat."' ");
						
				if ( $count > 0 ) {
					$result = $wpdb->update( $this->exceptions_table,
								array( 
										'days'		=> $_POST[$stat]["exceptional_days"], 
										'status'	=> $stat 
									),
								array( 
									'location'	=> $location, 
									'worker'	=> $this->worker, 
									'status'	=> $stat ),
								array( '%s', '%s' ),
								array( '%d', '%d', '%s' )
							);
				}
				else {
					$result = $wpdb->insert( $this->exceptions_table, 
								array( 'location'	=> $location, 
										'worker'	=> $this->worker, 
										'days'		=> $_POST[$stat]["exceptional_days"],
										'status'	=> $stat
									),
								array( '%d', '%d', '%s', '%s' )
								);
				}
				if ( $result )
					add_action( 'admin_notices', array ( &$this, 'saved' ) );
			}
		}
		// Save Services
		if ( isset($_POST["action_app"]) && 'save_services' == $_POST["action_app"] && is_array( $_POST["services"] ) ) {
			foreach ( $_POST["services"] as $ID=>$service ) {
				if ( '' != trim( $service["name"] ) ) {
					// Update or insert?
					$count = $wpdb->get_var( "SELECT COUNT(ID) FROM " . $this->services_table . " WHERE ID=".$ID." " );
					if ( $count ) {
						$r = $wpdb->update( $this->services_table, 
									array( 
										'name'		=> $service["name"], 
										'capacity'	=> $service["capacity"], 
										'duration'	=> $service["duration"], 
										'price'		=> $service["price"], 
										'page'		=> $service["page"] 
										),
									array( 'ID'		=> $ID ),
									array( '%s', '%d', '%d','%s','%d' )
								);
						if ( $r )
							$result = true;
					}
					else {
						$r = $wpdb->insert( $this->services_table, 
									array( 
										'ID'		=> $ID, 
										'name'		=> $service["name"], 
										'capacity'	=> $service["capacity"], 
										'duration'	=> $service["duration"], 
										'price'		=> $service["price"], 
										'page'		=> $service["page"] 
										),
									array( '%d', '%s', '%d', '%d','%s','%d' )
									);
						if ( $r )
							$result = true;
					}
				}
				else {
					// Entering an empty name means deleting of a service 
					$r = $wpdb->query( "DELETE FROM ". $this->services_table . " WHERE ID=".$ID." LIMIT 1 " );
					// Remove deleted service also from workers table 
					$r1 = $wpdb->query( "UPDATE ". $this->workers_table . " SET services_provided = REPLACE(services_provided,':".$ID.":','') ");
					if ( $r || $r1 )
						$result = true;
				}
			}
			if( $result )
				add_action( 'admin_notices', array ( &$this, 'saved' ) );
		}
		// Save Workers
		if ( isset($_POST["action_app"]) && 'save_workers' == $_POST["action_app"] && is_array( $_POST["workers"] ) ) {
			foreach ( $_POST["workers"] as $worker ) {
				$ID = $worker["user"];
				if ( $ID && !empty ( $worker["services_provided"] ) ) {
					$inserted = false;
					// Does the worker have already a record?
					$count = $wpdb->get_var( "SELECT COUNT(*) FROM " . $this->workers_table . " WHERE ID=".$ID." " );
					if ( $count ) {
						$r = $wpdb->update( $this->workers_table, 
										array( 											
											'price'				=> $worker["price"], 
											'services_provided'	=> $this->_implode( $worker["services_provided"] ),
											'page'				=> $worker["page"]
											),
										array( 'ID'				=> $worker["user"] ),
										array( '%s', '%s','%d' )
										);
						if ( $r )
							$updated = true;
					}
					else {
						$r = $wpdb->insert( $this->workers_table, 
										array( 
											'ID'				=> $worker["user"], 
											'price'				=> $worker["price"], 
											'services_provided'	=> $this->_implode( $worker["services_provided"] ),
											'page'				=> $worker["page"]
											),
										array( '%d', '%s', '%s','%d' )
										);
						if ( $r ) {
							// Insert the default working hours to the worker's working hours
							foreach ( array('open', 'closed') as $stat ) {
								$result_wh = $wpdb->get_row( "SELECT * FROM " . $this->wh_table . " WHERE location=0 AND service=0 AND status='".$stat."' ", ARRAY_A );
								if ( $result_wh != null ) {
									$result_wh["ID"] = 'NULL';
									$result_wh["worker"] = $ID;
									$wpdb->insert( $this->wh_table,
													$result_wh
												);
								}
							}
							// Insert the default holidays to the worker's holidays
							foreach ( array('open', 'closed') as $stat ) {
								$result_wh = $wpdb->get_row( "SELECT * FROM " . $this->exceptions_table . " WHERE location=0 AND service=0 AND status='".$stat."' ", ARRAY_A );
								if ( $result_wh != null ) {
									$result_wh["ID"] = 'NULL';
									$result_wh["worker"] = $ID;
									$wpdb->insert( $this->exceptions_table,
													$result_wh
												);
								}
							}
							$inserted = true;
						}
					}
				}
				// Entering an empty service name means deleting of a worker
				else if ( $ID ) {
					$r = $wpdb->query( "DELETE FROM " . $this->workers_table . " WHERE ID=".$ID." LIMIT 1 " ); 
					$r1 = $wpdb->query( "DELETE FROM " . $this->wh_table . " WHERE worker=".$ID." " );
					$r2 = $wpdb->query( "DELETE FROM " . $this->exceptions_table . " WHERE worker=".$ID." " );
					if ( $r || $r1 || $r2 )
						$result = true;
				}
			}
			if( $result || $updated || $inserted )
				add_action( 'admin_notices', array ( &$this, 'saved' ) );
		}
		
		// Delete removed app records
		if ( isset($_POST["delete_removed"]) && 'delete_removed' == $_POST["delete_removed"] 
			&& isset( $_POST["app"] ) && is_array( $_POST["app"] ) ) {
			$q = '';
			foreach ( $_POST["app"] as $app_id ) {
				$q .= " ID=". $app_id. " OR";
			}
			$q = rtrim( $q, " OR" );
			$result = $wpdb->query( "DELETE FROM " . $this->app_table . " WHERE " . $q . " " );
			if ( $result )
				add_action( 'admin_notices', array ( &$this, 'deleted' ) );
		}

		if ( ( isset($_POST["action_app"]) ) & ( $result || $updated || $inserted ) || 
			( isset($_POST["delete_removed"]) && 'delete_removed' == $_POST["delete_removed"] )  ) 
			// As it means any setting is saved, lets clear cache
			$this->flush_cache();
	}

	/**
	 *	Packs an array into a string with : as glue
	 */	
	function _implode( $input ) {
		if ( !is_array( $input ) || empty( $input ) )
			return false;
		return ':'. implode( ':', array_filter( $input ) ) . ':';
	}
	
	/**
	 *	Packs a string into an array assuming : as glue
	 */	
	function _explode( $input ){
		if ( !is_string( $input ) )
			return false;
		return array_filter( explode( ':' , ltrim( $input , ":") ) );
	}

	/**
	 * Deletes a worker's database records in case he is deleted
	 * @since 1.0.4
	 */
	function delete_user( $ID ) {
		if ( !$ID )
			return;
			
		global $wpdb;
		$r = $wpdb->query( "DELETE FROM " . $this->workers_table . " WHERE ID=".$ID." LIMIT 1 " ); 
		$r1 = $wpdb->query( "DELETE FROM " . $this->wh_table . " WHERE worker=".$ID." " );
		$r2 = $wpdb->query( "DELETE FROM " . $this->exceptions_table . " WHERE worker=".$ID." " );
		
		// Also modify app table
		$r3 = $wpdb->update( $this->app_table,
						array( 'worker'	=>	0 ),
						array( 'worker'	=> $ID )
					);

		if ( $r || $r1 || $r2 || $r3 )
			$this->flush_cache();
	}
	
	/**
	 *	Prints "cleared" message on top of Admin page 
	 */
	function cleared( ) {
		echo '<div class="updated fade"><p><b>[Appointments+]</b> Cache cleared.</p></div>';
	}
	
	/**
	 *	Prints "saved" message on top of Admin page 
	 */
	function saved( ) {
		echo '<div class="updated fade"><p><b>[Appointments+]</b> Settings saved.</p></div>';
	}
	
	/**
	 *	Prints "deleted" message on top of Admin page 
	 */
	function deleted( ) {
		echo '<div class="updated fade"><p><b>[Appointments+]</b> Selected record(s) deleted.</p></div>';
	}

	/**
	 *	Prints warning message on top of Admin page 
	 */
	function warning( ) {
		echo '<div class="updated fade"><p><b>[Appointments+] You are not authorised to do this.</b></p></div>';
	}

	/**
	 *	Admin settings HTML code 
	 */
	function settings() {

		if (!current_user_can('manage_options')) {
			wp_die( __('You do not have sufficient permissions to access this page.') );
		}
		$this->get_lsw();
		global $wpdb;
	?>
		<div class="wrap">
		<div class="icon32" style="margin:10px 0 0 0"><img src="<?php echo $this->plugin_url . '/images/general.png'; ?>" /></div>
		<h2><?php echo __('Appointments+ Settings','appointments'); ?></h2>
		<h3 class="nav-tab-wrapper">
			<?php
			$tab = ( !empty($_GET['tab']) ) ? $_GET['tab'] : 'main';
			
			$tabs = array(
				'working_hours'	=> __('Working Hours', 'appointments'),
				'exceptions'	=> __('Exceptions', 'appointments'),
				'services'      => __('Services', 'appointments'),
				'workers' 	    => __('Service Providers', 'appointments'),
				'shortcodes'    => __('Shortcodes', 'appointments'),
				'log'    		=> __('Logs', 'appointments'),
				'faq'    		=> __('FAQ', 'appointments'),
			);
			
			$tabhtml = array();

			// If someone wants to remove or add a tab
			$tabs = apply_filters( 'appointments_tabs', $tabs );

			$class = ( 'main' == $tab ) ? ' nav-tab-active' : '';
			$tabhtml[] = '	<a href="' . admin_url( 'admin.php?page=app_settings' ) . '" class="nav-tab'.$class.'">' . __('General', 'appointments') . '</a>';

			foreach ( $tabs as $stub => $title ) {
				$class = ( $stub == $tab ) ? ' nav-tab-active' : '';
				$tabhtml[] = '	<a href="' . admin_url( 'admin.php?page=app_settings&amp;tab=' . $stub ) . '" class="nav-tab'.$class.'" id="app_tab_'.$stub.'">'.$title.'</a>';
			}

			echo implode( "\n", $tabhtml );
			?>
		</h3>
		<div class="clear"></div>
		<?php switch( $tab ) {
		case 'main':	?>
		
		<div id="poststuff" class="metabox-holder">
		<span class="description"><?php _e('Appointments+ plugin makes it possible for your clients to make appointments from the front end or for you to enter appointments from backend. You can define services with different durations and assign service providers to any of them. In this page, you can set settings which will be valid in general.', 'appointments') ?></span>
		<br />
		<br />
			<form method="post" action="" >
			
				<div class="postbox">
					<h3 class='hndle'><span><?php _e('General Settings', 'appointments') ?></span></h3>
					<div class="inside">
						<table class="form-table">
						
						<tr valign="top">
						<th scope="row" ><?php _e('Time base (minutes)', 'appointments')?></th>
						<td colspan="2">
						<select name="min_time">
						<?php
						foreach ( $this->time_base() as $min_time ) {
							if ( ( isset($this->options["min_time"]) ) && $this->options["min_time"] == $min_time )
								$s = ' selected="selected"';
							else
								$s = '';
							echo '<option value="'.$min_time .'"'. $s . '>'. $min_time . '</option>';
						}
						?>
						</select>
						<span class="description"><?php _e('Minimum time that will be effective for durations, appointment and schedule intervals. Default: 30. Do NOT change this after you started receiving appointments.', 'appointments') ?></span>
						</tr>
						
						<tr valign="top">
						<th scope="row" ><?php _e('Additional time base (minutes)', 'appointments')?></th>
						<td colspan="2"><input type="text" style="width:50px" name="additional_min_time" value="<?php if ( isset($this->options["additional_min_time"]) ) echo $this->options["additional_min_time"] ?>" />
						<span class="description"><?php _e('If the above time bases do not fit your business, you can add a new one, e.g. 240. Only numbers divisible by 10 or 15 are accepted.', 'appointments') ?></span>
						</tr>
						
						<tr valign="top">
						<th scope="row" ><?php _e('Admin side time base (minutes)', 'appointments')?></th>
						<td colspan="2"><input type="text" style="width:50px" name="admin_min_time" value="<?php if ( isset($this->options["admin_min_time"]) ) echo $this->options["admin_min_time"] ?>" />
						<span class="description"><?php _e('This gives flexibility while manually setting and editing the appointments. For example, if you enter here 15, you can reschedule an appointment for 15 minutes intervals even base time is 45 minutes. If you leave this empty, then the above selected time base will be applied on the admin side.', 'appointments') ?></span>
						</tr>
						
						<tr valign="top">
						<th scope="row" ><?php _e('Appointment limit (days)', 'appointments')?></th>
						<td colspan="2"><input type="text" style="width:50px" name="app_limit" value="<?php if ( isset($this->options["app_limit"]) ) echo $this->options["app_limit"] ?>" />
						<span class="description"><?php _e('Maximum number of days from today that a client can book an appointment. Default: 30', 'appointments') ?></span>
						</tr>
								
						<tr valign="top">
						<th scope="row" ><?php _e('Disable pending appointments after (mins)', 'appointments')?></th>
						<td colspan="2"><input type="text" style="width:50px" name="clear_time" value="<?php if ( isset($this->options["clear_time"]) ) echo $this->options["clear_time"] ?>" />
						<span class="description"><?php _e('Pending appointments will be automatically removed (not deleted - deletion is only possible manually) after this set time and that appointment time will be freed. Enter 0 to disable. Default: 60. Please note that pending appointments whose starting time have been passed will always be removed, regardless of any other setting.', 'appointments') ?></span>
						</tr>
						
						<tr valign="top">
						<th scope="row" ><?php _e('Minimum time to pass for new appointment (secs)', 'appointments')?></th>
						<td colspan="2"><input type="text" style="width:50px" name="spam_time" value="<?php if ( isset($this->options["spam_time"]) ) echo $this->options["spam_time"] ?>" />
						<span class="description"><?php _e('You can limit appointment application frequency to prevent spammers who can block your appointments. This is only applied to pending appointments. Enter 0 to disable. Tip: To prevent any further appointment applications of a client before a payment or manual confirmation, enter a huge number here.', 'appointments') ?></span>
						</tr>
						
						<tr valign="top">
						<th scope="row" ><?php _e('Allow Service Provider set working hours', 'appointments')?></th>
						<td colspan="2">
						<select name="allow_worker_wh">
						<option value="no" <?php if ( @$this->options['allow_worker_wh'] <> 'yes' ) echo "selected='selected'"?>><?php _e('No', 'appointments')?></option>
						<option value="yes" <?php if ( @$this->options['allow_worker_wh'] == 'yes' ) echo "selected='selected'"?>><?php _e('Yes', 'appointments')?></option>
						</select>
						<span class="description"><?php _e('Whether you let service providers to enter their working hours using their user page.', 'appointments') ?></span>
						</td>
						
						</tr>
						
						
						<tr valign="top">
						<th scope="row" ><?php _e('Allow Overwork (end of day)', 'appointments')?></th>
						<td colspan="2">
						<select name="allow_overwork">
						<option value="no" <?php if ( @$this->options['allow_overwork'] <> 'yes' ) echo "selected='selected'"?>><?php _e('No', 'appointments')?></option>
						<option value="yes" <?php if ( @$this->options['allow_overwork'] == 'yes' ) echo "selected='selected'"?>><?php _e('Yes', 'appointments')?></option>
						</select>
						<span class="description"><?php _e('Whether you accept appointments exceeding working hours for the end of day. For example, if you are working until 6pm, and a client asks an appointment for a 60 minutes service at 5:30pm, to allow such an appointment you should select this setting as Yes. Please note that this is only practical if the selected service lasts longer than the base time. Such time slots are marked as "not possible" in the schedule.', 'appointments') ?></span>
						</td>
						</tr>
						
						<tr valign="top">
						<th scope="row" ><?php _e('Allow Overwork (break hours)', 'appointments')?></th>
						<td colspan="2">
						<select name="allow_overwork_break">
						<option value="no" <?php if ( @$this->options['allow_overwork_break'] <> 'yes' ) echo "selected='selected'"?>><?php _e('No', 'appointments')?></option>
						<option value="yes" <?php if ( @$this->options['allow_overwork_break'] == 'yes' ) echo "selected='selected'"?>><?php _e('Yes', 'appointments')?></option>
						</select>
						<span class="description"><?php _e('Same as above, but valid for break hours. If you want to allow appointments exceeding break times, then select this as Yes.', 'appointments') ?></span>
						</td>
						</tr>
					
						</table>
					</div>
				</div>
				
				
				<div class="postbox">
					<h3 class='hndle'><span><?php _e('Display Settings', 'appointments') ?></span></h3>
					<div class="inside">
						<table class="form-table">

						<tr valign="top">
						<th scope="row" ><?php _e('Create an Appointment Page', 'appointments')?></th>
						<td colspan="2">
						<input type="checkbox" name="make_an_appointment" <?php if ( isset( $this->options["make_an_appointment"] ) && $this->options["make_an_appointment"] ) echo 'checked="checked"' ?> />
						&nbsp;<?php _e('with', 'appointments') ?>&nbsp;
						<select name="app_page_type">
						<option value="one_month"><?php _e('current month\'s schedule', 'appointments')?></option>
						<option value="two_months" <?php if ( 'two_months' == @$this->options["app_page_type"] ) echo 'selected="selected"' ?>><?php _e('current and next month\'s schedules', 'appointments')?></option>
						<option value="one_week" <?php if ( 'one_week' == @$this->options["app_page_type"] ) echo 'selected="selected"' ?>><?php _e('current week\'s schedule', 'appointments')?></option>
						<option value="two_weeks" <?php if ( 'two_weeks' == @$this->options["app_page_type"] ) echo 'selected="selected"' ?>><?php _e('current and next week\'s schedules', 'appointments')?></option>
						</select>
						<br />
						<span class="description"><?php _e('Creates a front end Appointment page with title "Make an Appointment" with the selected schedule type and inserts all necessary shortcodes (My Appointments, Service Selection, Service Provider Selection, Appointment Schedule, Front end Login, Confirmation Field, Paypal Form)  inside it. You can edit, add parameters to shortcodes, remove undesired shortcodes and customize this page later.', 'appointments') ?></span>
						<?php
						$page_id = $wpdb->get_var( "SELECT ID FROM ". $wpdb->posts. " WHERE post_title = 'Make an Appointment' AND post_type='page' ");
						if ( $page_id ) { ?>
							<br /><span class="description"><?php _e('<b>Note:</b> You already have such a page. If you check this checkbox, another page with the same title will be created. To edit existing page: ' , 'appointments') ?></span>
							<a href="<?php echo admin_url('post.php?post='.$page_id.'&action=edit')?>" target="_blank"><?php _e('Click here', 'appointments')?></a>
						&nbsp;
						<?php _e('To view the page:', 'appointments') ?>
						<a href="<?php echo get_permalink( $page_id)?>" target="_blank"><?php _e('Click here', 'appointments')?></a>
						<?php }
						?>
						</td>
						</tr>
						
					<tr valign="top">
						<th scope="row" ><?php _e('Show Legend', 'appointments')?></th>
						<td colspan="2">
						<select name="show_legend">
						<option value="no" <?php if ( @$this->options['show_legend'] <> 'yes' ) echo "selected='selected'"?>><?php _e('No', 'appointments')?></option>
						<option value="yes" <?php if ( @$this->options['show_legend'] == 'yes' ) echo "selected='selected'"?>><?php _e('Yes', 'appointments')?></option>
						</select>
						<span class="description"><?php _e('Whether to display description fields above the pagination area.', 'appointments') ?></span>
						</td>
					</tr>
						
					<tr valign="top">
						<th scope="row" ><?php _e('Color Set', 'appointments')?></th>
						<td style="width:10%">
						<select name="color_set">
						<option value="1" <?php if ( @$this->options['color_set'] == 1 ) echo "selected='selected'"?>><?php _e('Preset 1', 'appointments')?></option>
						<option value="2" <?php if ( @$this->options['color_set'] == 2 ) echo "selected='selected'"?>><?php _e('Preset 2', 'appointments')?></option>
						<option value="3" <?php if ( @$this->options['color_set'] == 3 ) echo "selected='selected'"?>><?php _e('Preset 3', 'appointments')?></option>
						<option value="0" <?php if ( @$this->options['color_set'] == 0 ) echo "selected='selected'"?>><?php _e('Custom', 'appointments')?></option>
						</select>
						</td>
						<td >
						<div class="preset_samples">
						<label style="width:15%;display:block;float:left;font-weight:bold;">
						<?php _e('Sample:', 'appointments') ?>
						</label>
						<?php foreach ( $this->get_classes() as $class=>$name ) { ?>
						<label style="width:28%;display:block;float:left;">
							<span style="float:left">
								<?php echo $name ?>:
							</span>
							<span style="float:left;margin-right:8px;">
								<a href="javascript:void(0)" class="pickcolor <?php echo $class?> hide-if-no-js" <?php if ( @$this->options['color_set'] != 0 ) echo 'style="background-color:#'. $this->get_preset($class, $this->options['color_set']). '"' ?>></a>
							</span>
						
						</label>
					<?php } ?>
						<div style="clear:both"></div>
						</div>
						</td>
					</tr>
						
					<tr valign="top">
						<th scope="row" >&nbsp;</th>
						<td colspan="2">
						<span class="description"><?php _e('You can select table cell colors from presets with the given samples or you can define your custom set below which is visible after you select "Custom".', 'appointments') ?></span>
						</td>
					</tr>
						
					<script type="text/javascript">
					jQuery(document).ready(function($){
						var hex = new Array;
						
						$('select[name="color_set"]').change(function() {
							var n = $('select[name="color_set"] :selected').val();
							if ( n == 0) { $(".custom_color_row").show(); $(".preset_samples").hide(); }
							else { $(".custom_color_row").hide(); 
							$(".preset_samples").show();
							<?php foreach ( $this->get_classes() as $class=>$name ) {
							echo $class .'=new Array;';
							for ( $k=1; $k<=3; $k++ ) {
								echo $class .'['. $k .'] = "'. $this->get_preset( $class, $k ) .'";';
							}
							echo '$(".preset_samples").find("a.'. $class .'").css("background-color", "#"+'. $class.'[n]);';
							} ?>
							}
						});
					});
					</script>
					
					<tr valign="top" class="custom_color_row" <?php if ( @$this->options['color_set'] != 0 ) echo 'style="display:none"'?>>
						<th scope="row" ><?php _e('Custom Color Set', 'appointments')?></th>
						<td colspan="2">
					<?php foreach ( $this->get_classes() as $class=>$name ) { ?>
						<label style="width:31%;display:block;float:left;">
							<span style="float:left"><?php echo $name ?>:</span>
							<span style="float:left;margin-right:8px;">
								<a href="javascript:void(0)" class="pickcolor hide-if-no-js" <?php if( isset($this->options[$class."_color"]) ) echo 'style="background-color:#'. $this->options[$class."_color"]. '"' ?>></a>
								<input style="width:50px" type="text" class="colorpicker_input" maxlength="6" name="<?php echo $class?>_color" id="<?php echo $class?>_color" value="<?php if( isset($this->options[$class."_color"]) ) echo $this->options[$class."_color"] ?>" />
							</span>
						
						</label>
					<?php } ?>
						<div style="clear:both"></div>
						<span class="description"><?php _e('If you have selected Custom color set, for each cell enter 3 OR 6-digit Hex code of the color manually without # in front or use the colorpicker.', 'appointments') ?></span>
						</td>
					</tr>

			<script type="text/javascript">

			jQuery(document).ready(function($){
			
				$('.colorpicker_input').each( function() {
					var id = this.id;
					$('#'+id).ColorPicker({
						onSubmit: function(hsb, hex, rgb, el) {
							$(el).val(hex);
							$(el).ColorPickerHide();
						},
						onBeforeShow: function () {
							$(this).ColorPickerSetColor(this.value);
						},
						onChange: function (hsb, hex, rgb) {
							$('#'+id).val(hex);
							$('#'+id).parent().find('a.pickcolor').css('background-color', '#'+hex);
						}
					  })
					  .bind('keyup', function(){
						$(this).ColorPickerSetColor(this.value);
					});;
				});
				
				$('.colorpicker_input').keyup( function() {
					var a = $(this).val();

					a = a.replace(/[^a-fA-F0-9]/, '');
					if ( a.length === 3 || a.length === 6 )
						$(this).parent().find('a.pickcolor').css('background-color', '#'+a);
				});
			});
			</script>
						
			<tr valign="top">
				<th scope="row" ><?php _e('Require these from the client:', 'appointments')?></th>
				<td colspan="2">
				<input type="checkbox" name="ask_name" <?php if ( isset( $this->options["ask_name"] ) && $this->options["ask_name"] ) echo 'checked="checked"' ?> />&nbsp;<?php echo $this->get_field_name('name') ?>&nbsp;&nbsp;&nbsp;
				<input type="checkbox" name="ask_email" <?php if ( isset( $this->options["ask_email"] ) && $this->options["ask_email"] ) echo 'checked="checked"' ?> />&nbsp;<?php echo $this->get_field_name('email') ?>&nbsp;&nbsp;&nbsp;
				<input type="checkbox" name="ask_phone" <?php if ( isset( $this->options["ask_phone"] ) && $this->options["ask_phone"] ) echo 'checked="checked"' ?> />&nbsp;<?php echo $this->get_field_name('phone') ?>&nbsp;&nbsp;&nbsp;
				<input type="checkbox" name="ask_address" <?php if ( isset( $this->options["ask_address"] ) && $this->options["ask_address"] ) echo 'checked="checked"' ?> />&nbsp;<?php echo $this->get_field_name('address') ?>&nbsp;&nbsp;&nbsp;
				<input type="checkbox" name="ask_city" <?php if ( isset( $this->options["ask_city"] ) && $this->options["ask_city"] ) echo 'checked="checked"' ?> />&nbsp;<?php echo $this->get_field_name('city') ?>&nbsp;&nbsp;&nbsp;
				<input type="checkbox" name="ask_note" <?php if ( isset( $this->options["ask_note"] ) && $this->options["ask_note"] ) echo 'checked="checked"' ?> />&nbsp;<?php echo $this->get_field_name('note') ?>&nbsp;&nbsp;&nbsp;
				<br />
				<span class="description"><?php _e('The selected fields will be available in the confirmation area and they will be asked from the client. If selected, filling of them is mandatory (except note field).', 'appointments') ?></span>
				</td>
				</tr>
				
				<tr valign="top">
					<th scope="row" ><?php _e('Add Google Calendar Button', 'appointments')?></th>
					<td colspan="2">
					<select name="gcal">
					<option value="no" <?php if ( @$this->options['gcal'] != 'yes' ) echo "selected='selected'"?>><?php _e('No', 'appointments')?></option>
					<option value="yes" <?php if ( @$this->options['gcal'] == 'yes' ) echo "selected='selected'"?>><?php _e('Yes', 'appointments')?></option>
					</select>
					<br />
					<span class="description"><?php _e('Whether to let client access his Google Calendar account using Google Calendar button. Button is inserted in the confirmation area, as well as My Appointments shortcode and user page/tab if applicable.', 'appointments') ?></span>
					</td>
				</tr>
				
				<tr valign="top">
					<th scope="row" ><?php _e('Google Calendar Location','appointments')?></th>
					<td colspan="2">
					<input type="text" style="width:400px" name="gcal_location" value="<?php if (isset($this->options["gcal_location"])) echo $this->options["gcal_location"] ?>" />
					<br /><span class="description"><?php _e('Enter the text that will be used as location field in Google Calendar. If left empty, your website description is sent instead', 'appointments')?></span>
					</td>
				</tr>
				
				<tr>
					<th scope="row"><?php _e('Additional css Rules', 'appointments') ?></th>
					<td colspan="2">
					<textarea cols="90" name="additional_css"><?php echo esc_textarea($this->options['additional_css']); ?></textarea>
					<br />
					<span class="description"><?php _e('You can add css rules to customize styling. These wiill be added to the front end appointment page only.', 'appointments') ?></span>
					</td>
				</tr>
				
										
				</table>
			</div>
		</div>

		<div class="postbox">
            <h3 class='hndle'><span><?php _e('Accessibility Settings', 'appointments') ?></span></h3>
            <div class="inside">
			
				<table class="form-table">
				
					<tr valign="top">
						<th scope="row" ><?php _e('Login Required', 'appointments')?></th>
						<td colspan="2">
						<select name="login_required">
						<option value="no" <?php if ( @$this->options['login_required'] != 'yes' ) echo "selected='selected'"?>><?php _e('No', 'appointments')?></option>
						<option value="yes" <?php if ( @$this->options['login_required'] == 'yes' ) echo "selected='selected'"?>><?php _e('Yes', 'appointments')?></option>
						</select>
						<span class="description"><?php _e('Whether you require the client to login to the website to apply for an appointment. Plugin lets front end logins, without the need for leaving the front end appointment page.', 'appointments') ?></span>
						</td>
					</tr>
					<?php
					if ( 'yes' != $this->options["login_required"] ) 
						$style='style="display:none"';
					else 
						$style='';
					?>
					<script type="text/javascript">
					jQuery(document).ready(function($){
						$('select[name="login_required"]').change(function() {
							if ( $('select[name="login_required"] :selected').val() == 'yes') { $(".api_detail").show(); }
							else { $(".api_detail").hide(); }
						});
					});
					</script>	
					<tr valign="top" class="api_detail" <?php echo $style?>>
						<th scope="row" ><?php _e('Accept Login from Front end','appointments')?></th>
						<td colspan="2">
						<input type="checkbox" id="accept_api_logins" name="accept_api_logins" value="true" <?php if ( isset($this->options["accept_api_logins"]) && $this->options["accept_api_logins"]) echo "checked='checked'"?>>
						<span class="description"><?php _e('Enables login to website from front end using Facebook, Twitter, Google+ or Wordpress.','appointments')?></span>
						</td>
					</tr>
										
					<tr valign="top" class="api_detail" <?php echo $style?>>
						<th scope="row" ><?php _e('My website already uses Facebook','appointments')?></th>
						<td colspan="2">
						<input type="checkbox" name="facebook-no_init" value="true" <?php if ( isset($this->options["facebook-no_init"]) && $this->options["facebook-no_init"]) echo "checked='checked'"?>>
						<span class="description"><?php _e('By default, Facebook script will be loaded by the plugin. If you are already running Facebook scripts, to prevent any conflict, check this option.','appointments')?></span>
						</td>
					</tr>
					
					<tr valign="top" class="api_detail" <?php echo $style?>>
						<th scope="row" ><?php _e('Facebook App ID','appointments')?></th>
						<td colspan="2">
						<input type="text" style="width:200px" name="facebook-app_id" value="<?php if (isset($this->options["facebook-app_id"])) echo $this->options["facebook-app_id"] ?>" />
						<br /><span class="description"><?php printf(__("Enter your App ID number here. If you don't have a Facebook App yet, you will need to create one <a href='%s'>here</a>", 'appointments'), 'https://developers.facebook.com/apps')?></span>
						</td>
					</tr>
					
					<tr valign="top" class="api_detail" <?php echo $style?>>
						<th scope="row" ><?php _e('Twitter Consumer Key','appointments')?></th>
						<td colspan="2">
						<input type="text" style="width:200px" name="twitter-app_id" value="<?php if (isset($this->options["twitter-app_id"])) echo $this->options["twitter-app_id"] ?>" />
						<br /><span class="description"><?php printf(__('Enter your Twitter App ID number here. If you don\'t have a Twitter App yet, you will need to create one <a href="%s">here</a>', 'appointments'), 'https://dev.twitter.com/apps/new')?></span>
						</td>
					</tr>
					
					<tr valign="top" class="api_detail" <?php echo $style?>>
						<th scope="row" ><?php _e('Twitter Consumer Secret','appointments')?></th>
						<td colspan="2">
						<input type="text" style="width:200px" name="twitter-app_secret" value="<?php if (isset($this->options["twitter-app_secret"])) echo $this->options["twitter-app_secret"] ?>" />
						<br /><span class="description"><?php _e('Enter your Twitter App ID Secret here.', 'appointments')?></span>
						</td>
					</tr>
					
				</table>
			</div>
		</div>
		
		<div class="postbox">
			<h3 class='hndle'><span><?php _e('Payment Settings', 'appointments'); ?></span></h3>
			<div class="inside">
			<table class="form-table">
			
			<tr valign="top">
				<th scope="row" ><?php _e('Payment required', 'appointments')?></th>
				<td colspan="2">
				<select name="payment_required">
				<option value="no" <?php if ( @$this->options['payment_required'] <> 'yes' ) echo "selected='selected'"?>><?php _e('No', 'appointments')?></option>
				<option value="yes" <?php if ( @$this->options['payment_required'] == 'yes' ) echo "selected='selected'"?>><?php _e('Yes', 'appointments')?></option>
				</select>
				<span class="description"><?php printf( __('Whether you require a payment to accept appointments. If selected Yes, client is asked to pay through Paypal and the appointment will be in pending status until the payment is confirmed by Paypal IPN. If selected No, appointment will be in pending status until you manually approve it using the %s.', 'appointments'), '<a href="'.admin_url('admin.php?page=appointments').'">'.__('Appointments page', 'appointments').'</a>' ) ?></span>
				</td>
			</tr>
			
			<tr class="payment_row" <?php if ( $this->options['payment_required'] != 'yes' ) echo 'style="display:none"'?>>
				<th scope="row"><?php _e('Paypal Currency', 'appointments') ?></th>
				<td colspan="2">
	          <select name="currency">
	          <?php
	          $sel_currency = ($this->options['currency']) ? $this->options['currency'] : $this->options['currency'];
	          $currencies = array(
	              'AUD' => 'AUD - Australian Dollar',
	              'BRL' => 'BRL - Brazilian Real',
	              'CAD' => 'CAD - Canadian Dollar',
	              'CHF' => 'CHF - Swiss Franc',
	              'CZK' => 'CZK - Czech Koruna',
	              'DKK' => 'DKK - Danish Krone',
	              'EUR' => 'EUR - Euro',
	              'GBP' => 'GBP - Pound Sterling',
	              'ILS' => 'ILS - Israeli Shekel',
	              'HKD' => 'HKD - Hong Kong Dollar',
	              'HUF' => 'HUF - Hungarian Forint',
	              'JPY' => 'JPY - Japanese Yen',
	              'MYR' => 'MYR - Malaysian Ringgits',
	              'MXN' => 'MXN - Mexican Peso',
	              'NOK' => 'NOK - Norwegian Krone',
	              'NZD' => 'NZD - New Zealand Dollar',
	              'PHP' => 'PHP - Philippine Pesos',
	              'PLN' => 'PLN - Polish Zloty',
	              'SEK' => 'SEK - Swedish Krona',
	              'SGD' => 'SGD - Singapore Dollar',
	              'TWD' => 'TWD - Taiwan New Dollars',
	              'THB' => 'THB - Thai Baht',
				  'TRY' => 'TRY - Turkish lira',
	              'USD' => 'USD - U.S. Dollar'
	          );

	          foreach ($currencies as $k => $v) {
	              echo '<option value="' . $k . '"' . ($k == $sel_currency ? ' selected' : '') . '>' . wp_specialchars($v, true) . '</option>' . "\n";
	          }
	          ?>
	          </select>
	        </td>
	        </tr>
				<tr class="payment_row" <?php if ( $this->options['payment_required'] != 'yes' ) echo 'style="display:none"'?>>
					<th scope="row"><?php _e('PayPal Mode', 'appointments') ?></th>
					<td colspan="2">
					<select name="mode">
					  <option value="sandbox"<?php selected($this->options['mode'], 'sandbox') ?>><?php _e('Sandbox', 'appointments') ?></option>
					  <option value="live"<?php selected($this->options['mode'], 'live') ?>><?php _e('Live', 'appointments') ?></option>
					</select>
					</td>
				</tr>
			
				<tr class="payment_row" <?php if ( $this->options['payment_required'] != 'yes' ) echo 'style="display:none"'?>>
					<th scope="row"><?php _e('PayPal Merchant E-mail', 'appointments') ?></th>
					<td colspan="2">
					<input value="<?php echo esc_attr($this->options['merchant_email']); ?>" size="30" name="merchant_email" type="text" />
					<?php
					printf( __('Just for your information, your IPN link is: <b>%s </b>. You may need this information in some cases.', 'appointments'), admin_url('admin-ajax.php?action=app_paypal_ipn') );
					?>
					</td>
				</tr>
			
				</tr>
				<tr class="payment_row" <?php if ( $this->options['payment_required'] != 'yes' ) echo 'style="display:none"'?>>
					<th scope="row"><?php _e('Thank You Page', 'appointments') ?></th>
					<td colspan="2">
					<?php wp_dropdown_pages( array( "name"=>"return", "selected"=>@$this->options["return"] ) ) ?>
					<span class="description"><?php _e('The page that client will be returned when he clicks the return link on Paypal website.', 'appointments') ?></span>
					</td>
					
				</tr>
				
			<tr class="payment_row" <?php if ( $this->options['payment_required'] != 'yes' ) echo 'style="display:none"'?>>
					<th scope="row"><?php _e('Deposit (%)', 'appointments') ?></th>
					<td colspan="2">
					<input value="<?php echo esc_attr(@$this->options['percent_deposit']); ?>" style="width:50px" name="percent_deposit" type="text" />
					<span class="description"><?php _e('You may want to ask a certain percentage of the service price as deposit, e.g. 25. Leave this field empty to ask for full price.', 'appointments') ?></span>
					</td>
			</tr>
			
			<tr class="payment_row" <?php if ( $this->options['payment_required'] != 'yes' ) echo 'style="display:none"'?>>
					<th scope="row"><?php _e('Deposit (fixed)', 'appointments') ?></th>
					<td colspan="2">
					<input value="<?php echo esc_attr(@$this->options['fixed_deposit']); ?>" style="width:50px" name="fixed_deposit" type="text" />
					<span class="description"><?php _e('Same as above, but a fixed deposit will be asked from the client per appointment. If both fields are filled, only the fixed deposit will be taken into account.', 'appointments') ?></span>
					</td>
			</tr>
			
			<tr class="payment_row" <?php if ( $this->options['payment_required'] != 'yes' ) echo 'style="display:none"'; else echo 'style="border-top: 1px solid lightgrey;"'?>>
					<th scope="row">&nbsp;</th>
					<td colspan="2">
					<span class="description"><?php printf( __('The below fields require %s plugin. ', 'appointments'), '<a href="http://premium.wpmudev.org/project/membership/" target="_blank">Membership</a>') ?></span>
					</td>
			</tr>
			
			<tr class="payment_row" <?php if ( $this->options['payment_required'] != 'yes' ) echo 'style="display:none"';?>>
					<th scope="row"><?php _e('Don\'t ask advance payment from selected Membership level(s)', 'appointments') ?></th>
					<td colspan="2">
					<input type="checkbox" name="members_no_payment" <?php if ( isset( $this->options["members_no_payment"] ) && $this->options["members_no_payment"] ) echo 'checked="checked"' ?> />
					<span class="description"><?php _e('Below selected level(s) will not be asked for an advance payment or deposit. This does not mean that service will be free of charge for them. Such member appointments are automatically confirmed.', 'appointments') ?></span>
					</td>
			</tr>
			
			<tr class="payment_row" <?php if ( $this->options['payment_required'] != 'yes' ) echo 'style="display:none"'; ?>>
					<th scope="row"><?php _e('Discount for selected Membership level(s) (%)', 'appointments') ?></th>
					<td colspan="2">
					<input type="text" name="members_discount" style="width:50px" value="<?php echo @$this->options["members_discount"] ?>" />
					<span class="description"><?php _e('Below selected level(s) will get a discount given in percent, e.g. 20. Leave this field empty for no discount. Tip: If you enter 100, service will be free of charge for these members.', 'appointments') ?></span>
					</td>
			</tr>

			<tr class="payment_row" <?php if ( $this->options['payment_required'] != 'yes' ) echo 'style="display:none"'?>>
					<th scope="row"><?php _e('Membership levels for the above selections', 'appointments') ?></th>
					<td colspan="2">
					<?php
					if ( $this->membership_active ) {
						$meta = maybe_unserialize( @$this->options["members"] );
						global $membershipadmin;
						$levels = $membershipadmin->get_membership_levels(array('level_id' => 'active'));
						if ( $levels && is_array( $levels ) ) {
							echo '<div style="float:left"><select multiple="multiple" name="members[level][]" >';
							foreach ( $levels as $level ) {
								if ( $level->level_slug != 'visitors' ) { // Do not include strangers
									if ( is_array( $meta["level"] ) AND in_array( $level->id, $meta["level"] ) )
										$sela = 'selected="selected"';
									else
										$sela = '';
									echo '<option value="'.$level->id.'"' . $sela . '>'. $level->level_title . '</option>';
								}
							}
							echo '</select></div>';
						}
						else
							echo '<input type="text" size="40" value="'. __('No level was defined yet','appointments').'" readonly="readonly" />';
					}
					else 
						echo '<input type="text" size="40" value="'. __('Membership plugin is not activated.','appointments').'" readonly="readonly" />';
					?>
					<div style="float:left;width:80%;margin-left:5px;">
					<span class="description"><?php _e('Selected level(s) will not be asked advance payment/deposit and/or will take a discount, depending on the above selections. You can select multiple levels using CTRL and SHIFT keys.', 'appointments') ?></span>
					</div>
					<div style="clear:both"></div>
					</td>
			</tr>
			
			<tr class="payment_row" <?php if ( $this->options['payment_required'] != 'yes' ) echo 'style="display:none"'; else echo 'style="border-top: 1px solid lightgrey;"'?>>
					<th scope="row">&nbsp;</th>
					<td colspan="2">
					<span class="description"><?php printf( __('The below fields require %s plugin. ', 'appointments'), '<a href="http://premium.wpmudev.org/project/e-commerce/" target="_blank">MarketPress</a>') ?></span>
					</td>
			</tr>
			
			<tr class="payment_row" <?php if ( $this->options['payment_required'] != 'yes' ) echo 'style="display:none"';?>>
					<th scope="row"><?php _e('Integrate with MarketPress', 'appointments') ?></th>
					<td colspan="2">
					<input type="checkbox" name="use_mp" <?php if ( isset( $this->options["use_mp"] ) && $this->options["use_mp"] ) echo 'checked="checked"' ?> />
					<span class="description"><?php _e('Appointments can be set as products. Any appointment shortcode added to a product page will make that page an "Appointment Product Page". For details, please see FAQ.', 'appointments') ?>
					<?php if ( !$this->marketpress_active ) {
					echo '<br />';
					_e( 'Note: MarketPress is not active', 'appointments' );
					}	?>
					</span>
					</td>
			</tr>
			
			<tr class="payment_row" <?php if ( $this->options['payment_required'] != 'yes' ) echo 'style="display:none"';?>>
				<th scope="row" ><?php _e('Create an Appointment Product Page', 'appointments')?></th>
				<td colspan="2">
				<input type="checkbox" name="make_an_appointment_product" <?php if ( isset( $this->options["make_an_appointment_product"] ) && $this->options["make_an_appointment_product"] ) echo 'checked="checked"' ?> />
				&nbsp;<?php _e('with', 'appointments') ?>&nbsp;
				<select name="app_page_type_mp">
				<option value="one_month"><?php _e('current month\'s schedule', 'appointments')?></option>
				<option value="two_months" <?php if ( 'two_months' == @$this->options["app_page_type_mp"] ) echo 'selected="selected"' ?>><?php _e('current and next month\'s schedules', 'appointments')?></option>
				<option value="one_week" <?php if ( 'one_week' == @$this->options["app_page_type_mp"] ) echo 'selected="selected"' ?>><?php _e('current week\'s schedule', 'appointments')?></option>
				<option value="two_weeks" <?php if ( 'two_weeks' == @$this->options["app_page_type_mp"] ) echo 'selected="selected"' ?>><?php _e('current and next week\'s schedules', 'appointments')?></option>
				</select>
				<br />
				<span class="description"><?php _e('Same as the above "Create an Appointment Page", but this time appointment shortcodes will be inserted in a new Product page and page title will be "Appointment". This is also the product name.', 'appointments') ?></span>
				<?php
				$page_id = $wpdb->get_var( "SELECT ID FROM ". $wpdb->posts. " WHERE post_title = 'Appointment' AND post_type='product' ");
				if ( $page_id ) { ?>
					<br /><span class="description"><?php _e('<b>Note:</b> You already have such a page. If you check this checkbox, another page with the same title will be created. To edit existing page: ' , 'appointments') ?></span>
					<a href="<?php echo admin_url('post.php?post='.$page_id.'&action=edit')?>" target="_blank"><?php _e('Click here', 'appointments')?></a>
				&nbsp;
				<?php _e('To view the page:', 'appointments') ?>
				<a href="<?php echo get_permalink( $page_id)?>" target="_blank"><?php _e('Click here', 'appointments')?></a>
				<?php }
				?>
				</td>
			</tr>
			
	        </table>
			</div>
			</div>
			<script type="text/javascript">
			jQuery(document).ready(function($){
				$('select[name="payment_required"]').change(function() {
					if ( $('select[name="payment_required"]').val() == "yes" ) { $(".payment_row").show(); }
					else { $(".payment_row").hide(); }
				});
			});
			</script>					
	
			<div class="postbox">
            <h3 class='hndle'><span class="notification_settings"><?php _e('Notification Settings', 'appointments') ?></span></h3>
            <div class="inside">
			
				<table class="form-table">
				
				<tr valign="top">
					<th scope="row" ><?php _e('Send Confirmation email', 'appointments')?></th>
					<td colspan="2">
					<select name="send_confirmation">
					<option value="no" <?php if ( @$this->options['send_confirmation'] <> 'yes' ) echo "selected='selected'"?>><?php _e('No', 'appointments')?></option>
					<option value="yes" <?php if ( @$this->options['send_confirmation'] == 'yes' ) echo "selected='selected'"?>><?php _e('Yes', 'appointments')?></option>
					</select>
					<span class="description"><?php _e('Whether to send an email after confirmation of the appointment. Note: Admin and service provider will also be bcc\'ed.', 'appointments') ?></span>
					</td>
					</tr>
   				<tr>
				
				<tr valign="top">
					<th scope="row" ><?php _e('Send Notification to admin if confirmation is required', 'appointments')?></th>
					<td colspan="2">
					<select name="send_notification">
					<option value="no" <?php if ( @$this->options['send_notification'] <> 'yes' ) echo "selected='selected'"?>><?php _e('No', 'appointments')?></option>
					<option value="yes" <?php if ( @$this->options['send_notification'] == 'yes' ) echo "selected='selected'"?>><?php _e('Yes', 'appointments')?></option>
					</select>
					<span class="description"><?php _e('You may want to receive a notification email whenever a new appointment is taken from front end in pending status. This email is only sent if you do not require a payment, that is, if your confirmation is required.', 'appointments') ?></span>
					</td>
					</tr>
   				<tr>
				
				<tr>
					<th scope="row"><?php _e('Confirmation Email Subject', 'appointments') ?></th>
					<td>
					<input value="<?php echo esc_attr($this->options['confirmation_subject']); ?>" size="90" name="confirmation_subject" type="text" />
					</td>
				</tr>
				
				<tr>
					<th scope="row"><?php _e('Confirmation email Message', 'appointments') ?></th>
					<td>
					<textarea cols="90" name="confirmation_message"><?php echo esc_textarea($this->options['confirmation_message']); ?></textarea>
					</td>
				</tr>
				
				<tr valign="top">
					<th scope="row" ><?php _e('Send Reminder email to the Client', 'appointments')?></th>
					<td colspan="2">
					<select name="send_reminder">
					<option value="no" <?php if ( @$this->options['send_reminder'] <> 'yes' ) echo "selected='selected'"?>><?php _e('No', 'appointments')?></option>
					<option value="yes" <?php if ( @$this->options['send_reminder'] == 'yes' ) echo "selected='selected'"?>><?php _e('Yes', 'appointments')?></option>
					</select>
					<span class="description"><?php _e('Whether to send reminder email(s) to the client before the appointment.', 'appointments') ?></span>
					</td>
					</tr>
   				<tr>
				
				<tr>
					<th scope="row"><?php _e('Reminder email Sending Time for the Client (hours)', 'appointments') ?></th>
					<td>
					<input value="<?php echo esc_attr($this->options['reminder_time']); ?>" size="90" name="reminder_time" type="text" />
					<br />
					<span class="description"><?php _e('Defines how many hours reminder will be sent to the client before the appointment will take place. Multiple reminders are possible. To do so, enter reminding hours separated with a comma, e.g. 48,24.', 'appointments') ?></span>
					</td>
				</tr>
				
				<tr valign="top">
					<th scope="row" ><?php _e('Send Reminder email to the Provider', 'appointments')?></th>
					<td colspan="2">
					<select name="send_reminder_worker">
					<option value="no" <?php if ( @$this->options['send_reminder_worker'] <> 'yes' ) echo "selected='selected'"?>><?php _e('No', 'appointments')?></option>
					<option value="yes" <?php if ( @$this->options['send_reminder_worker'] == 'yes' ) echo "selected='selected'"?>><?php _e('Yes', 'appointments')?></option>
					</select>
					<span class="description"><?php _e('Whether to send reminder email(s) to the service provider before the appointment.', 'appointments') ?></span>
					</td>
					</tr>
   				<tr>
				
				<tr>
					<th scope="row"><?php _e('Reminder email Sending Time for the Provider (hours)', 'appointments') ?></th>
					<td>
					<input value="<?php echo esc_attr($this->options['reminder_time_worker']); ?>" size="90" name="reminder_time_worker" type="text" />
					<br />
					<span class="description"><?php _e('Same as above, but defines the time for service provider.', 'appointments') ?></span>
					</td>
				</tr>
				
				<tr>
					<th scope="row"><?php _e('Reminder email Subject', 'appointments') ?></th>
					<td>
					<input value="<?php echo esc_attr($this->options['reminder_subject']); ?>" size="90" name="reminder_subject" type="text" />
					</td>
				</tr>
				
				<tr>
					<th scope="row"><?php _e('Reminder email Message', 'appointments') ?></th>
					<td>
					<textarea cols="90" name="reminder_message"><?php echo esc_textarea($this->options['reminder_message']); ?></textarea>
					</td>
				</tr>
				
				<tr valign="top">
					<th scope="row" ><?php _e('Log Sent email Records', 'appointments')?></th>
					<td colspan="2">
					<select name="log_emails">
					<option value="no" <?php if ( @$this->options['log_emails'] <> 'yes' ) echo "selected='selected'"?>><?php _e('No', 'appointments')?></option>
					<option value="yes" <?php if ( @$this->options['log_emails'] == 'yes' ) echo "selected='selected'"?>><?php _e('Yes', 'appointments')?></option>
					</select>
					<span class="description"><?php _e('Whether to log confirmation and reminder email records (Not the emails themselves).', 'appointments') ?></span>
					</td>
					</tr>
   				<tr>
				
				<tr>
				<th scope="row">&nbsp;</th>
				<td>
				<span class="description">
				<?php _e('For the above email subject and message contents, you can use the following placeholders which will be replaced by their real values:', 'appointments') ?>&nbsp;SITE_NAME, CLIENT, SERVICE, SERVICE_PROVIDER, DATE_TIME
				</span>
				</td>
				</tr>
				
				</table>
			</div>
			</div>
			
		<div class="postbox">
            <h3 class='hndle'><span><?php _e('Performance Settings', 'appointments') ?></span></h3>
            <div class="inside">
			
				<table class="form-table">
				
					<tr valign="top">
						<th scope="row" ><?php _e('Use Built-in Cache', 'appointments')?></th>
						<td colspan="2">
						<select name="use_cache">
						<option value="no" <?php if ( @$this->options['use_cache'] <> 'yes' ) echo "selected='selected'"?>><?php _e('No', 'appointments')?></option>
						<option value="yes" <?php if ( @$this->options['use_cache'] == 'yes' ) echo "selected='selected'"?>><?php _e('Yes', 'appointments')?></option>
						</select>
						<span class="description"><?php _e('Appointments+ has a built-in cache to increase performance. If you are making changes in the styling of your appointment pages, modifying shortcode parameters or having some issues while using it, disable it by selecting No.', 'appointments') ?></span>
						</td>
					</tr>
					
					<tr valign="top">
						<th scope="row" ><?php _e('Clear Cache', 'appointments')?></th>
						<td colspan="2">
						<input type="checkbox" name="force_cache" <?php if ( isset( $this->options["force_cache"] ) && $this->options["force_cache"] ) echo 'checked="checked"' ?> />
						<span class="description"><?php _e('Cache is automatically cleared at regular intervals (Default: 10 minutes) or when you change a setting. To clear it manually check this checkbox.', 'appointments') ?></span>
						</td>
					</tr>
					
					<tr valign="top">
						<th scope="row" ><?php _e('Disable Javascript Error Tracking for Admin Side', 'appointments')?></th>
						<td colspan="2">
						<input type="checkbox" name="disable_js_check_admin" <?php if ( isset( $this->options["disable_js_check_admin"] ) && $this->options["disable_js_check_admin"] ) echo 'checked="checked"' ?> />
						<span class="description"><?php _e('Appointments+ has a built in basic javascript check script. If any of the visited page on the admin side has javascript errors, a warning will be seen and a log record will be created. To disable this feature, check this checkbox. Note: Not all the errors may be caught.', 'appointments') ?></span>
						</td>
					</tr>
					
					<tr valign="top">
						<th scope="row" ><?php _e('Disable Javascript Error Tracking for Frontend', 'appointments')?></th>
						<td colspan="2">
						<input type="checkbox" name="disable_js_check_frontend" <?php if ( isset( $this->options["disable_js_check_frontend"] ) && $this->options["disable_js_check_frontend"] ) echo 'checked="checked"' ?> />
						<span class="description"><?php _e('The same as above but disables this feature which is used on front end Appointment pages only. Note: Errors are displayed on top of the page and they are only visible to admin.', 'appointments') ?></span>
						</td>
					</tr>

				</table>
			</div>
			</div>
			
				<input type="hidden" name="action_app" value="save_general" />
				<?php wp_nonce_field( 'update_app_settings', 'app_nonce' ); ?>
				<p class="submit">
				<input type="submit" class="button-primary" value="<?php _e('Save Settings') ?>" />
				</p>
			
			</form>
		
		</div>
		<?php break;
		
		case 'working_hours': _e( '<i>Here you can define working hours and breaks for your business. When you add new service providers, their working and break hours will be set to the default schedule. Then you can edit their schedule by selecting their names from the dropdown menu below.</i>', 'appointments'); ?>
			<br />
			<br />
			<?php
			$workers = $wpdb->get_results( "SELECT * FROM " . $this->workers_table . " " );
			?>
			<?php _e('List for:', 'appointments')?>
			&nbsp;
			<select id="app_provider_id" name="app_provider_id">
			<option value="0"><?php _e('No specific provider', 'appointments')?></option>
			<?php
			if ( $workers ) {
				foreach ( $workers as $worker ) {
					if ( $this->worker == $worker->ID )
						$s = " selected='selected'";
					else
						$s = '';
					echo '<option value="'.$worker->ID.'"'.$s.'>' . $this->get_worker_name( $worker->ID ) . '</option>';
				}
			}
			?>
			</select>
			<br /><br />
			<script type="text/javascript">
			jQuery(document).ready(function($){
				$('#app_provider_id').change(function(){
					var app_provider_id = $('#app_provider_id option:selected').val();
					window.location.href = "<?php echo admin_url('admin.php?page=app_settings&tab=working_hours')?>" + "&app_provider_id=" + app_provider_id;
				});
			});
			</script>
			<form method="post" action="" >
				<table class="widefat fixed">
				<tr>
				<th style="width:40%"><?php _e( 'Working Hours', 'appointments' ) ?></th>
				<th style="width:40%"><?php _e( 'Break Hours', 'appointments' ) ?></th>
				<tr>
				<td>
				<?php echo $this->working_hour_form( 'open' ); ?>
				</td>
				<td>
				<?php echo $this->working_hour_form( 'closed' ); ?>
				</td>
				</tr>
				</table>
				
				<input type="hidden" name="worker" value="0" />
				<input type="hidden" name="location" value="0" />
				<input type="hidden" name="action_app" value="save_working_hours" />
				<?php wp_nonce_field( 'update_app_settings', 'app_nonce' ); ?>
				<p class="submit">
				<input type="submit" class="button-primary" value="<?php _e('Save Working Hours') ?>" />
				</p>
			
			</form>
			<?php break; ?>
		
		<?php case 'exceptions': _e( '<i>Here you can define exceptional working or non working days for your business and for your service providers. You should enter holidays here. You can also define a normally non working week day (e.g. a specific Sunday) as a working day. When you add new service providers, their expections will be set to the default schedule.</i>', 'appointments'); ?>
			<br />
			<br />
			<?php
			$result = array();
			foreach ( array( 'open', 'closed' ) as $stat ) {
				$result[$stat] = $wpdb->get_var( "SELECT days FROM " . $this->exceptions_table . " WHERE status='".$stat."' AND worker=".$this->worker." " );
			}
			$workers = $this->get_workers();
			?>
			<?php _e('List for:', 'appointments')?>
			&nbsp;
			<select id="app_provider_id" name="app_provider_id">
			<option value="0"><?php _e('No specific provider', 'appointments')?></option>
			<?php
			if ( $workers ) {
				foreach ( $workers as $worker ) {
					if ( $this->worker == $worker->ID )
						$s = " selected='selected'";
					else
						$s = '';
					echo '<option value="'.$worker->ID.'"'.$s.'>' . $this->get_worker_name( $worker->ID ) . '</option>';
				}
			}
			?>
			</select>
			<br /><br />
			<form method="post" action="" >
				<table class="widefat fixed">
				<tr>
				<td>
				<?php _e( 'Exceptional working days, e.g. a specific Sunday you decided to work:', 'appointments') ?>
				</td>
				</tr>
				<tr>
				<td>
				<input class="datepick" id="open_datepick" type="text" style="width:100%" name="open[exceptional_days]" value="<?php if (isset($result["open"])) echo $result["open"]?>" />
				</td>
				</tr>
				
				<tr>
				<td>
				<?php _e( 'Exceptional NON working days, e.g. holidays:', 'appointments') ?>
				</td>
				</tr>
				<tr>
				<td>
				<input class="datepick" id="closed_datepick" type="text" style="width:100%" name="closed[exceptional_days]" value="<?php if (isset($result["closed"])) echo $result["closed"]?>" />
				</td>
				</tr>
				
				<tr>
				<td>
				<span class="description"><?php _e('Please enter each date using YYYY-MM-DD format (e.g. 2012-08-13) and separate each day with a comma. Datepick will allow entering multiple dates. ', 'appointments')?></span>
				</td>
				</tr>
				</table>
				
				<input type="hidden" name="location" value="0" />
				<input type="hidden" name="action_app" value="save_exceptions" />
				<?php wp_nonce_field( 'update_app_settings', 'app_nonce' ); ?>
				<p class="submit">
				<input type="submit" class="button-primary" value="<?php _e('Save Exceptional Days') ?>" />
				</p>
			
			</form>
			<script type="text/javascript">
			jQuery(document).ready(function($){
				$('#app_provider_id').change(function(){
					var app_provider_id = $('#app_provider_id option:selected').val();
					window.location.href = "<?php echo admin_url('admin.php?page=app_settings&tab=exceptions')?>" + "&app_provider_id=" + app_provider_id;
				});
				$("#open_datepick").datepick({dateFormat: 'yyyy-mm-dd',multiSelect: 999, monthsToShow: 2});
				$("#closed_datepick").datepick({dateFormat: 'yyyy-mm-dd',multiSelect: 999, monthsToShow: 2});
			});
			</script>

		<?php break; ?>
		
		<?php case 'services': _e( '<i>Here you should define your services for which your client will be making appointments. <b>There must be at least one service defined.</b> Capacity is the number of customers that can take the service at the same time. Enter 0 for no limit (Limited to number of service providers, or to 1 if no service provider is defined for that service). Price is only required if you request payment to accept appointments. You can define a description page for the service you are providing.</i>', 'appointments') ?>
			<div class='submit'>
			<input type="button" id="add_service" class='button-secondary' value='<?php _e( 'Add New Service', '' ) ?>' />
			</div>
			
			<form method="post" action="" >
			
				<table class="widefat fixed" id="services-table" >
				<tr>
				<th style="width:5%"><?php _e( 'ID', 'appointments') ?></th>
				<th style="width:35%"><?php _e( 'Name', 'appointments') ?></th>
				<th style="width:10%"><?php _e( 'Capacity', 'appointments') ?></th>
				<th style="width:15%"><?php _e( 'Duration (mins)', 'appointments') ?></th>
				<th style="width:10%"><?php _e( 'Price', 'appointments') ?></th>
				<th style="width:25%"><?php _e( 'Description page', 'appointments') ?></th>
				</tr>
				<?php
				$services = $this->get_services();				
				$max_id = null;
				if ( is_array( $services ) && $nos = count( $services ) ) {
					foreach ( $services as $service ) {
						echo $this->add_service( true, $service );
						if ( $service->ID > $max_id )
							$max_id = $service->ID;
					}
				}
				else {
					echo '<tr class="no_services_defined"><td colspan="4">'. __( 'No services defined', 'appointments' ) . '</td></tr>';
				}
				?>
				
				</table>
				
				<div class='submit' id='div_save_services' <?php if ($max_id==null) echo 'style="display:none"' ?>>
				<input type="hidden" name="number_of_services" id="number_of_services" value="<?php echo $max_id;?>" /> 
				<input type="hidden" name="action_app" value="save_services" />
				<?php wp_nonce_field( 'update_app_settings', 'app_nonce' ); ?>
				<input class='button-primary' type='submit' value='<?php _e( 'Save Services', 'appointments' ) ?>' />
				&nbsp;&nbsp;
				<?php _e( '<i>Tip: To delete a service, just clear its name and save.</i>', 'appointments' ); ?>
				</div>
			
			</form>
			<?php break; ?>
			
			<?php case 'workers': _e( '<i>Here you can optionally select your service providers, i.e. workers, and assign them to certain services. Your service providers must be members of the website. You can define additional price for them. This will be added to the price of the service. You can define a bio page for the service providers.</i>', 'appointments'); ?>
			<div class='submit'>
			<input type="button" id="add_worker" class='button-secondary' value='<?php _e( 'Add New Service Provider', '' ) ?>' />
			<img class="add-new-waiting" style="display:none;" src="<?php echo admin_url('images/wpspin_light.gif')?>" alt="">
			</div>
			
			<form method="post" action="" >
			
				<table class="widefat fixed" id="workers-table" >
				<tr>
				<th style="width:5%"><?php _e( 'ID', 'appointments') ?></th>
				<th style="width:30%"><?php _e( 'Service Provider', 'appointments') ?></th>
				<th style="width:10%"><?php _e( 'Additional Price', 'appointments') ?></th>
				<th style="width:30%"><?php _e( 'Services Provided*', 'appointments') ?></th>
				<th style="width:25%"><?php _e( 'Bio page', 'appointments') ?></th>
				</tr>
				<tr><td colspan="5"><span class="description" style="font-size:10px"><?php _e('* Use CTRL and SHIFT keys to select multiple options', 'appointments') ?></span>
				</td>
				</tr>
				<?php
				$workers = $wpdb->get_results("SELECT * FROM " . $this->workers_table . " " ); 
				$max_id = 0;
				if ( is_array( $workers ) && $nos = count( $workers ) ) {
					foreach ( $workers as $worker ) {
						echo $this->add_worker( true, $worker );
						if ( $worker->ID > $max_id )
							$max_id = $worker->ID;
					}
				}
				else {
					echo '<tr class="no_workers_defined"><td colspan="4">'. __( 'No service providers defined', 'appointments' ) . '</td></tr>';
				}
				?>
				
				</table>
				
				<div class='submit' id='div_save_workers' <?php if (!$max_id) echo 'style="display:none"' ?>>
				<input type="hidden" name="number_of_workers" id="number_of_workers" value="<?php echo $max_id;?>" /> 
				<input type="hidden" name="action_app" value="save_workers" />
				<?php wp_nonce_field( 'update_app_settings', 'app_nonce' ); ?>
				<input class='button-primary' type='submit' value='<?php _e( 'Save Service Providers', 'appointments' ) ?>' />
				&nbsp;&nbsp;
				<?php _e( '<i>Tip: To delete a service provider, just clear his services and save.</i>', 'appointments' ); ?>
				</div>
			
			</form>
			<?php break; ?>
			
		<?php case 'shortcodes':	?>
		<div class="wrap">
		<span class="description">
		<?php _e( 'Appointments+ uses shortcodes to generate output on the front end. This gives you the flexibility to customize your appointment pages using the WordPress post editor without the need for php coding. There are several parameters of the shortcodes by which your customizations can be really custom. On the other hand, if you don\'t set these parameters, Appointments+ will still work properly. Thus, setting parameters is fully optional. <br /><b>Important:</b> You should temporarily turn off built in cache while making changes in the parameters or adding new shortcodes.', 'appointments' ); ?>
		<br />
		<?php _e( '<b>Note:</b> As default, all "title" parameters are wrapped with h3 tag. But of course you can override them by entering your own title text, with a different h tag, or without any tag.', 'appointments') ?> 
		</span>
			<ul>
			<li><code>[app_my_appointments]</code></li>
			<li><?php _e('<b>Description:</b> Inserts a table where client or service provider can see his upcoming appointments', 'appointments' ) ?>
			</li>
			<li><?php _e('<b>Parameters:</b>', 'appointments' ) ?>
				<ul>
				<li><?php _e('provider: Enter 1 if this appointment list belongs to a service provider. Default: "0" (client)', 'appointments' ) ?></li>
				<li><?php _e('provider_id: Enter the user ID of the provider whose list will be displayed. If ommitted, current service provider will be displayed. Default: "0" (current service provider)', 'appointments' ) ?></li>
				<li><?php _e('title: Title text. Default: "My Appointments"', 'appointments' ) ?></li>
				<li><?php _e('status: Which status(es) will be included. Possible values: paid, confirmed, completed, pending, removed. Default: "paid, confirmed"', 'appointments' ) ?></li>
				<li><?php _e('gcal: Enter 0 to disable Google Calendar button by which clients can add appointments to their Google Calendar after booking the appointment. Default: "1" (enabled)', 'appointments' ) ?></li>
				</ul>
			</li>
			</ul>

			<ul>
			<li><code>[app_services]</code></li>
			<li><?php _e('<b>Description:</b> Creates a dropdown menu of all available services', 'appointments' ) ?>
			</li>
			<li><?php _e('<b>Parameters:</b>', 'appointments' ) ?>
				<ul>
				<li><?php _e('select: Text above the select menu', 'appointments' ) ?></li>
				<li><?php _e('show: Button text to show the results for the selected', 'appointments' ) ?></li>
				<li><?php _e('description: Selects which part of the description page will be displayed under the dropdown menu when a service is selected . Selectable values are "none", "excerpt", "content". Default: "excerpt"', 'appointments' ) ?></li>
				<li><?php _e('thumb_size: Inserts the post thumbnail if page has a featured image. Selectable values are "none", "thumbnail", "medium", "full" or a 2 numbers separated by comma representing width and height in pixels, e.g. 32,32. Default: "96,96"', 'appointments' ) ?></li>
				<li><?php _e('thumb_class: css class that will be applied to the thumbnail. Default: "alignleft"', 'appointments' ) ?></li>
				</ul>
			</li>
			</ul>
			
			<ul>
			<li><code>[app_service_providers]</code></li>
			<li><?php _e('<b>Description:</b> Creates a dropdown menu of all available service providers', 'appointments' ) ?>
			</li>
			<li><?php _e('<b>Parameters:</b>', 'appointments' ) ?>
				<ul>
				<li><?php _e('select: Text above the select menu', 'appointments' ) ?></li>
				<li><?php _e('show: Button text to show the results for the selected', 'appointments' ) ?></li>
				<li><?php _e('description: Selects which part of the bio page  will be displayed under the dropdown menu when a service provider is selected . Selectable values are "none", "excerpt", "content". Default: "excerpt"', 'appointments' ) ?></li>
				<li><?php _e('thumb_size: Inserts the post thumbnail if page has a featured image or service provider avatar. Selectable values are "none", "thumbnail", "medium", "full" or a 2 numbers separated by comma representing width and height in pixels, e.g. 32,32 in case of post thumbnail, or "avatar" and an optional avatar size separated by comma, e.g. "avatar,72". Note: If selected so, avatar is displayed even service provider does not have a bio page. Default: "96,96"', 'appointments' ) ?></li>
				<li><?php _e('thumb_class: css class that will be applied to the thumbnail. Default: "alignleft"', 'appointments' ) ?></li>
				</ul>
			</li>
			</ul>
			
			<ul>
			<li><code>[app_schedule]</code></li>
			<li><?php _e('<b>Description:</b> Creates a weekly table whose cells are clickable to apply for an appointment', 'appointments' ) ?>
			</li>
			<li><?php _e('<b>Parameters:</b>', 'appointments' ) ?>
				<ul>
				<li><?php _e('title: Text that will be displayed as the schedule title. Placeholders START and END will be replaced by their real values. Default: "Our schedule from START to END"', 'appointments' ) ?></li>
				<li><?php _e('logged: Text that will be displayed after the title only to the clients who are logged in or you don\'t require a login. Default: "Click on a free box to apply for an appointment."', 'appointments' ) ?></li>
				<li><?php _e('notlogged: Text that will be displayed after the title only to the clients who are not logged in and you require a login. LOGIN_PAGE will be replaced with your website\'s login page. Default: "You need to login to make an appointment. Please click here to register/login: LOGIN_PAGE"', 'appointments' ) ?></li>
				<li><?php _e('service: Enter service ID only if you want to force the table display the service with entered ID. Default: "0" (Service is selected by dropdown)', 'appointments' ) ?></li>
				<li><?php _e('worker: Enter service provider ID only if you want to force the table display the service provider with entered ID. Default: "0" (Service provider is selected by dropdown)', 'appointments' ) ?></li>
				<li><?php _e('long: If entered 1, long week days are displayed on the schedule table row, e.g. "Saturday" instead of "Sa". Default: "0"', 'appointments' ) ?></li>
				<li><?php _e('class: A css class name for the schedule table. Default is empty.', 'appointments' ) ?></li>
				<li><?php _e('add: Number of weeks to add to the schedule to use for preceding weeks\' schedules. Enter 1 for next week, 2 for the other week, so on. Default: "0" (Current week) ', 'appointments' ) ?></li>
				</ul>
			</li>
			</ul>

			<ul>
			<li><code>[app_monthly_schedule]</code></li>
			<li><?php _e('<b>Description:</b> Creates a monthly calendar plus time tables whose free time slots are clickable to apply for an appointment', 'appointments' ) ?>
			</li>
			<li><?php _e('<b>Parameters:</b>', 'appointments' ) ?>
				<ul>
				<li><?php _e('title: Text that will be displayed as the schedule title. Placeholders START and END will be replaced by their real values. Default: "Our schedule for START"', 'appointments' ) ?></li>
				<li><?php _e('logged: Text that will be displayed after the title only to the clients who are logged in or you don\'t require a login. Default: "Click on a free box to apply for an appointment."', 'appointments' ) ?></li>
				<li><?php _e('notlogged: Text that will be displayed after the title only to the clients who are not logged in and you require a login. LOGIN_PAGE will be replaced with your website\'s login page. Default: "You need to login to make an appointment. Please click here to register/login: LOGIN_PAGE"', 'appointments' ) ?></li>
				<li><?php _e('service: Enter service ID only if you want to force the table display the service with entered ID. Default: "0" (Service is selected by dropdown)', 'appointments' ) ?></li>
				<li><?php _e('worker: Enter service provider ID only if you want to force the table display the service provider with entered ID. Default: "0" (Service provider is selected by dropdown)', 'appointments' ) ?></li>
				<li><?php _e('long: If entered 1, long week days are displayed on the calendar row, e.g. "Saturday" instead of "Sa". Default: "0"', 'appointments' ) ?></li>
				<li><?php _e('class: A css class name for the calendar. Default is empty.', 'appointments' ) ?></li>
				<li><?php _e('add: Number of months to add to the schedule to use for preceding months\' schedules. Enter 1 for next month, 2 for the other month, so on. Default: "0" (Current month) ', 'appointments' ) ?></li>
				</ul>
			</li>
			</ul>
			
			<ul>
			<li><code>[app_pagination]</code></li>
			<li><?php _e('<b>Description:</b> Inserts pagination codes (previous, next week or month links) and Legend area.', 'appointments' ) ?>
			</li>
			<li><?php _e('<b>Parameters:</b>', 'appointments' ) ?>
				<ul>
				<li><?php _e('step: Number of weeks or months that selected time will increase or decrease with each next or previous link click. You may consider entering 4 if you have 4 schedule tables on the page. Default: "1"', 'appointments' ) ?></li>
				<li><?php _e('month: If entered 1, step parameter will mean month, otherwise week. In short, enter 1 for monthly schedule. Default: "0"', 'appointments' ) ?></li>
				</ul>
			</li>
			</ul>
			
			<ul>
			<li><code>[app_login]</code></li>
			<li><?php _e('<b>Description:</b> Inserts front end login buttons for Facebook, Twitter and Wordpress', 'appointments' ) ?>
			</li>
			<li><?php _e('<b>Parameters:</b>', 'appointments' ) ?>
				<ul>
				<li><?php _e('login_text: Text above the login buttons, proceeded by a login link. Default: "Please click here to login:"', 'appointments' ) ?></li>
				<li><?php _e('redirect_text: Javascript text if front end login is not set and user is redirected to login page', 'appointments' ) ?></li>
				</ul>
			</li>
			</ul>
			
			<ul>
			<li><code>[app_confirmation]</code></li>
			<li><?php _e('<b>Description:</b> Inserts a form which displays the details of the selected appointment and has fields which should be filled by the client', 'appointments' ) ?>
			</li>
			</li>
			<li><?php _e('<b>Parameters:</b>', 'appointments' ) ?>
				<ul>
				<li><?php _e('title: Text above fields. Default: "Please check the appointment details below and confirm:"', 'appointments' ) ?></li>
				<li><?php _e('button_text: Text of the button that asks client to confirm the appointment. Default: "Please click here to confirm this appointment"', 'appointments' ) ?></li>
				<li><?php _e('confirm_text: Javascript text that will be displayed after receiving of the appointment. This will only be displayed if you do not require payment. Default: "We have received your appointment. Thanks!"', 'appointments' ) ?></li>
				<li><?php _e('warning_text: Javascript text displayed if client does not fill a required field. Default: "Please enter the requested field"', 'appointments' ) ?></li>
				<li><?php _e('name, email, phone, address, city, note: Descriptive title of the fields. e.g. to ask for post code instead of address, use <code>address="Post code"</code>.', 'appointments' ) ?></li>
				<li><?php _e('gcal: Text that will be displayed beside Google Calendar selection checkbox. Default: "Open Google Calendar and submit appointment "', 'appointments' ) ?></li>
				
				</ul>
			</li>
			</ul>
			
			<ul>
			<li><code>[app_paypal]</code></li>
			<li><?php _e('<b>Description:</b> Inserts Paypal Pay button and form', 'appointments' ) ?>
			</li>
			<li><?php _e('<b>Parameters:</b>', 'appointments' ) ?>
				<ul>
				<li><?php _e('item_name: Item name that will be seen on Paypal. Default: "Payment for SERVICE" if deposit is not asked, "Deposit for SERVICE" if deposit is asked', 'appointments' ) ?></li>
				<li><?php _e('button_text: Text that will be displayed on Paypal button. Default: "Please confirm PRICE CURRENCY payment for SERVICE"', 'appointments' ) ?></li>
				<li><?php _e('For the above Paypal parameters, you can use SERVICE, PRICE, CURRENCY placeholders which will be replaced by their real values.', 'appointments' ) ?></li>
				</ul>
			</li>
			</ul>
			
		</div>
		<?php break; ?>
		
		<?php case 'log':	?>
		<div class="postbox">
			<div class="inside" id="app_log">
			<?php
				if ( is_writable( $this->uploads_dir ) ) {
					if ( file_exists( $this->log_file ) ) 
						echo nl2br( file_get_contents( $this->log_file ) );
					else
						echo __( 'There are no log records yet.', 'appointments' );
				}
				else
					echo __( 'Uploads directory is not writable.', 'appointments' );
				?>
			</div>
		</div>
			<table class="form-table">
				<tr valign="top">
					<th scope="row" >
					<input type="button" id="log_clear_button" class="button-secondary" value="<?php _e('Clear Log File') ?>" title="<?php _e('Clicking this button deletes logs saved on the server') ?>" />
					</th>
				</tr>
			</table>
		<?php break; ?>
		
		<?php case 'faq':	?>
		<div class="wrap">
		<ul>
			<li>
			<?php _e('<b>How can I restart the tutorial?</b>', 'appointments');?>
			<br />
			<?php _e('To restart tutorial about settings click here:', 'appointments');?>
			<?php
			$link = add_query_arg( array( "tutorial"=>"restart1" ), admin_url("admin.php?page=app_settings") );
			?>
			<a href="<?php echo $link ?>" ><?php _e( 'Settings Tutorial Restart', 'appointments' ) ?></a>
			<br />
			<?php _e('To restart tutorial about entering and editing Appointments click here:', 'appointments');?>
			<?php
			$link = add_query_arg( array( "tutorial"=>"restart2" ), admin_url("admin.php?page=app_settings") );
			?>
			<a href="<?php echo $link ?>" ><?php _e( 'Appointments Creation and Editing Tutorial Restart', 'appointments' ) ?></a>
			</li>
		</ul>
		<ul>
			<li>
			<?php _e('<b>What is the importance of Time Base and how should I set it?</b>', 'appointments');?>
			<br />
			<?php _e('<i>Time Base</i> is the most important parameter of Appointments+. It is the minimum time that you can select for your appointments. If you set it too high then you may not be possible to optimize your appointments. If you set it too low, your schedule will be too crowded and you may have difficulty in managing your appointments. You should enter here the duration of the shortest service you are providing.', 'appointments');?>
			</li>
		</ul>

		<ul>
			<li>
			<?php _e('<b>I don\'t see the time base that I need. For example I need 240 minutes appointments. How can I do that?</b>', 'appointments');?>
			<br />
			<?php _e('You can add one more time base using <i>Additional time base</i> setting. This value must be divisible by 10 or 15. Otherwise it will not be included in the seelctable time bases.', 'appointments');?>
			</li>
		</ul>

		<ul>
			<li>
			<?php _e('<b>What is the complete process to take an appointment?</b>', 'appointments');?>
			<br />
			<?php _e('With the widest settings, client will do the followings on the front page:', 'appointments');?>
				<ul>
				<li>
				<?php _e('Select a service', 'appointments');?>
				</li>
				<li>
				<?php _e('Select a service provider', 'appointments');?>
				</li>
				<li>
				<?php _e('Select a free time on the schedule', 'appointments');?>
				</li>
				<li>
				<?php _e('Login (if required)', 'appointments');?>
				</li>
				<li>
				<?php _e('Enter the required fields (name, email, phone, address, city) and confirm the selected appointment', 'appointments');?>
				</li>
				<li>
				<?php _e('Clicks Paypal payment button (if required)', 'appointments');?>
				</li>
				<li>
				<?php _e('Redirected to a Thank You page after Paypal payment', 'appointments');?>
				</li>
				</ul>
			</li>
		</ul>
		<ul>
			<li>
			<?php _e('<b>Is it necessary to have at least one service?</b>', 'appointments');?>
			<br />
			<?php _e('Yes. Appointments+ requires at least one service to be defined. Please note that a default service should have been already installed during installation. If you delete it, and no other service remains, then you will get a warning message. In this case plugin may not function properly.', 'appointments');?>
			</li>
		</ul>
		<ul>
			<li>
			<?php _e('<b>Is it necessary to define service providers?</b>', 'appointments');?>
			<br />
			<?php _e('No. You may as well be working by yourself, doing your own business. Plugin will work properly without any service provider, i.e worker, defined. In this case Appointments+ assumes that there is ONE service provider working, giving all the services.', 'appointments');?>
			</li>
		</ul>
		<ul>
			<li>
			<?php _e('<b>Is it necessary to use Services and Service Providers shortcodes?</b>', 'appointments');?>
			<br />
			<?php _e('No. If you do not use these shortcodes then your client will not be able to select a service and Appointments+ will pick the service with the smallest ID. We have already noted that a service provider definition is only optional.', 'appointments');?>
			</li>
		</ul>
		<ul>
			<li>
			<?php _e('<b>Does Appointments+ provide some widgets?</b>', 'appointments');?>
			<br />
			<?php _e('Yes. Appointments+ has Services and Service Providers widgets which provides a list of service or service providers with links to their description pages and a Monthly Calendar widget that redirects user to the selected appointment page when a free day is clicked.', 'appointments');?>
			</li>
		</ul>
		<ul>
			<li>
			<?php _e('<b>Can I use the shortcodes in any page as I wish?</b>', 'appointments');?>
			<br />
			<?php _e('Some shortcodes have only meaning if they are used in combination with some others. For example the Services shortcode will not have a function unless you have a Schedule on the same page. They are defined as separate shortcodes so that you can customize them on your pages. But for the My Appointments and Schedule shortcodes, there is no restriction.', 'appointments');?>
			</li>
		</ul>
		<ul>
			<li>
			<?php _e('<b>Can I show more than one week schedule in one page?</b>', 'appointments');?>
			<br />
			<?php printf( __('Yes. Use "add" parameter of schedule shortcode to add additional week schedules. See %s tabs for details.', 'appointments'), '<a href="'.admin_url('admin.php?page=app_settings&tab=shortcodes').'">'.__('Shortcodes', 'appointments') .'</a>');?>
			</li>
		</ul>
		<ul>
			<li>
			<?php _e('<b>Does the client need to be registered to the website to apply for an appointment?</b>', 'appointments');?>
			<br />
			<?php _e('You can set whether this is required with <i>Login Required</i> setting. You can ask details (name, email, phone, address, city) about the client before accepting the appointment, thus you may not need user registrations. These data are saved in a cookie and autofilled when they apply for a new appointment, so your regular clients do not need to refill them.', 'appointments');?>
			</li>
		</ul>
		<ul>
			<li>
			<?php _e('<b>How are the appointments confirmed?</b>', 'appointments');?>
			<br />
			<?php _e('If you have selected <i>Payment Required</i> field as Yes, then an appointment is automatically confirmed after a succesful Paypal payment and confirmation of Paypal IPN. If you selected Payment Required as No, then confirmation should be done manually, for example after connecting via phone.', 'appointments');?>
			</li>
		</ul>
		<ul>
			<li>
			<?php _e('<b>How can I manually confirm an appointment?</b>', 'appointments');?>
			<br />
			<?php printf( __('Using the %s, find the appointment based on user name and change the status after you click <i>See Details and Edit</i> link. Note that this link will be visible only after you take the cursor over the record. Please also note that you can edit all the appointment data here.', 'appointments'), '<a href="'. admin_url("admin.php?page=appointments").'" target="_blank">'.__('Appointments admin page', 'appointments'). '</a>');?>
			</li>
		</ul>
		<ul>
			<li>
			<?php _e('<b>Can I enter a manual appointment from admin side?</b>', 'appointments');?>
			<br />
			<?php printf( __('Yes. You may as well be having manual appointments, e.g. by phone. Just click <i>Add New</i> link on top of the %s and enter the fields and save the record. Please note that NO checks (Is that time frame free? Are we working that day? etc...) are done when you are entering a manual appointment. Consider entering or checking appointments from the front end to prevent mistakes.', 'appointments'), '<a href="'. admin_url("admin.php?page=appointments").'" target="_blank">'.__('Appointments admin page', 'appointments'). '</a>');?>
			</li>
		</ul>
		<ul>
			<li>
			<?php _e('<b>I don\'t want front end appointments, I want to enter them only manually from admin side. What should I do?</b>', 'appointments');?>
			<br />
			<?php _e('If you don\'t want your schedule to be seen either, then simply do not add Schedule shortcode in your pages, or set that page as "private" for admin use. But if you want your schedule to be seen publicly, then just use Schedule shortcode, but no other shortcodes else.', 'appointments');?>
			</li>
		</ul>
		<ul>
			<li>
			<?php _e('<b>I don\'t want my break times and holidays to be seen by the clients. How can I do that?</b>', 'appointments');?>
			<br />
			<?php _e('Select css background color for busy and not possible fields to be the same (for example white). Select <i>Show Legend</i> setting as No. Now, visitors can only see your free times and apply for those; they cannot distinguish if you are occupied or not working for the rest.', 'appointments');?>
			</li>
		</ul>
		
		<ul>
			<li>
			<?php _e('<b>How can I prevent a second appointment by a client until I confirm his first appointment?</b>', 'appointments');?>
			<br />
			<?php _e('Enter a huge number, e.g. 10000000, in <i>Minimum time to pass for new appointment</i> field. Please note that this is not 100% safe and there is no safe solution against this unless you require payment to accept an appointment.', 'appointments');?>
			</li>
		</ul>
		
		<ul>
			<li>
			<?php _e('<b>I have several service providers (workers) and each of them has different working hours, break hours and holidays. Does Appointments+ support this?</b>', 'appointments');?>
			<br />
			<?php _e('Yes. For each and every service provider you can individually set working, break hours and exceptions (holidays and additional working days). To do so, use the <i>Working Hours</i> and <i>Exceptions</i> tabs and select the service provider you want to make the changes from the service provider dropdown menu, make necessary changes and save. Plase note that when a service provider is added, his working schedule is set to the business working schedule. Thus, you only need to edit the variations of his schedule.', 'appointments');?>
			</li>
		</ul>
		<ul>
			<li>
			<?php _e('<b>How can I set start day of the week and adjust date and time formats?</b>', 'appointments');?>
			<br />
			<?php printf(__('Appointments+ follows Wordpress date and time settings. If you change them from %s page, plugin will automatically adapt them.', 'appointments'), '<a href="'.admin_url('options-general.php').'" target="_blank">'.__('General Settings','appointments').'</a>');?>
			</li>
		</ul>
		<ul>
			<li>
			<?php _e('<b>What does service capacity mean? Can you give an example?</b>', 'appointments');?>
			<br />
			<?php _e('It is the capacity of a service (e.g. because of technical reasons) independent of number of service providers giving that service. Imagine a dental clinic with three dentists working, each having their examination rooms, but there is only one X-Ray unit. Then, X-Ray Service has a capacity 1, and examination service has 3. Please note that you should only define capacity of X-Ray service 1 in this case. The other services whose capacity are left as zero will be automatically limited to the number of dentists giving that particular service. Because for those, limitation comes from the service providers, not from the service itself.', 'appointments');?>
			</li>
		</ul>
		<ul>
			<li>
			<?php _e('<b>I have defined several services and service providers. For a particular service, there is no provider assigned. What happens?</b>', 'appointments');?>
			<br />
			<?php _e('For that particular service, clients cannot apply for an appointment because there will be no free time slot. Just delete services you are not using.', 'appointments');?>
			</li>
		</ul>
		
		<ul>
			<li>
			<?php _e('<b>I am giving a service only on certain days of the week. Is it possible to achieve this with Appointments+?</b>', 'appointments');?>
			<br />
			<?php _e('Yes. Create a "dummy" service provider and assign the service only to it. Then set its working days as those days. That\'s all.', 'appointments');?>
			</li>
		</ul>
		
		<ul>
			<li>
			<?php _e('<b>How can I permanently delete appointment records?</b>', 'appointments');?>
			<br />
			<?php printf( __('To avoid any mistakes, appointment records can only be deleted from Removed area of %s. First change the status of the appointment to "removed" and then delete it selecting the Removed area.', 'appointments'), '<a href="'. admin_url("admin.php?page=appointments&type=removed").'" target="_blank">'.__('Appointments admin page', 'appointments'). '</a>');?>
			</li>
		</ul>
		
		<ul>
			<li>
			<?php _e('<b>What happens if a client was applying for an appointment but at the same time another client booked the same time slot?</b>', 'appointments');?>
			<br />
			<?php _e('Appointments+ checks the availability of the appointment twice: First when client clicks a free box and then when he clicks the confirmation button. If that time slot is taken by another client during these checks, he will be acknowledged that that time frame is not avaliable any more. All these checks are done in real time by ajax calls, so duplicate appointments are not possible.', 'appointments');?>
			</li>
		</ul>
		
		<ul>
			<li>
			<?php _e('<b>What does the Built-in Cache do? Can I still use other caching plugins?</b>', 'appointments');?>
			<br />
			<?php _e('Appointments+ comes with a built-in specific cache. It functions only on appointment pages and caches the content part of the page only. It is recommended to enable it especially if you have a high traffic appointment page. You can continue to use other general purpose caching plugins like W3T, WP Super Cache, Quick Cache.', 'appointments');?>
			</li>
		</ul>
		
		<ul>
			<li>
			<?php _e('<b>I have just installed Appointments+ and nothing happens as I click a free time slot on the Make an Appointment page. What can be the problem?</b>', 'appointments');?>
			<br />
			<?php _e('You most likely have a javascript error on the page. This may be coming from a faulty theme or plugin. To confirm the javascript error, open the page using Google Chrome or Firefox and then press Ctrl+Shift+j. In the opening window if you see any warnings or errors, then switch to the default theme to locate the issue. If errors disappear, then you need to check and correct your theme files. If they don\'t disappear, then deactivate all your plugins and re-activate them one by one, starting from Appointments+ and check each time as you activate a plugin.', 'appointments');?>
			</li>
		</ul>
		
		<ul>
			<li>
			<?php _e('<b>How is the plugin supposed to work by the way?</b>', 'appointments');?>
			<br />
			<?php printf( __('Please visit our %s.', 'appointments'), '<a href="http://appointmentsplus.org/" target="_blank">'.__('Demo website', 'appointments' ).'</a>');?>
			</li>
		</ul>
		
		<ul>
			<li>
			<?php _e('<b>How does integration with Membership work? Are there special considerations?</b>', 'appointments');?>
			<br />
			<?php _e('Membership member levels can be let exempt from advance payments/deposits. Also you can apply discounts for the selected membership levels. There are no special considerations: Appointments+ would manage them automatically.', 'appointments');?>

			</li>
		</ul>
		
		
		<ul>
			<li>
			<?php _e('<b>How does integration with MarketPress work? Are there special considerations?</b>', 'appointments');?>
			<br />
			<?php _e('If you select "Integrate with MarketPress" any MarketPress product page having Appointments+ shortcodes will be regarded as an "Appointment Product Page". Those pages are automatically modified and you are not supposed to be doing anything special. For your information, here is how the integration works:', 'appointments');?>
			<br />
			<ul>
			<li>
			<?php _e('An Appointment will be regarded as a digital product, therefore shipping information is not asked if ordered alone.', 'appointments');?>
			</li>
			<li>
			<?php _e('Like any other digital product, quantity of an appointment is always fixed to 1, but client can add as many appointments as he wishes with different variations, that is, with different date and time.', 'appointments');?>
			</li>
			<li>
			<?php _e('Download link that is normally added to confirmation email for digital product orders is removed.', 'appointments');?>
			</li>
			<li>
			<?php _e('Appointments in the cart are shown as "Appointment Product Page Title: Appointment ID (Appointment date and time)".', 'appointments');?>
			</li>
			<li>
			<?php _e('"Add to Cart" and "Buy Now" buttons on the Appointment Product page are not visible until client confirms the appointment.', 'appointments');?>
			</li>
			<li>
			<?php _e('"Add to Cart" and "Buy Now" buttons are only possible for a full appointment product page, therefore on products list page, an Appointments+ product will always have a "Choose Option" button. No price will be shown. For the same reason, please use Single Product shortcode with only content="full" setting.', 'appointments');?>
			</li>
			<li>
			<?php _e('Paypal button of Appointments+ is invisible and thus its own Paypal Standard Payments option is disabled. Client will use the payment gateways MarketPress is providing. You can use all MarketPress payment gateways.', 'appointments');?>
			</li>
			<li>
			<?php _e('Quantity and Variation fields on the product page are always invisible.', 'appointments');?>
			</li>
			<li>
			<?php _e('Price of the appointment on the cart is the deposit price, if a deposit field is set. Otherwise it is the full price.', 'appointments');?>
			</li>
			<li>
			<?php _e('If an appointment product is manually removed from the cart by the client, its record will also be removed from the appointments table.', 'appointments');?>
			</li>
			<li>
			<?php _e('An appointment product can be automatically removed from the cart if "Disable pending appointments after" setting is set and client does not finalize the purchase during that time. Thus you may consider to add a warning note that transaction should be completed within the selected time.', 'appointments');?>
			</li>
			<li>
			<?php _e('If this happens while client is paying and client does pay, however, that appointment will be taken out from removed status and it will be marked as paid.', 'appointments');?>
			</li>
			<li>
			<?php _e('On the admin product management page if it is an Appointments+ Product, variations, SKU, price column fields will display "-".', 'appointments');?>
			</li>
			<li>
			<?php _e('Transactions are shown in MarketPress, but related appointment record is updated, that is, status is changed to "paid".', 'appointments');?>
			</li>
			</ul>
			</li>
		</ul>
		
		<h2><?php _e( 'Advanced', 'appointments') ?></h2>
		<ul>
			<li>
			<?php _e('This part of FAQ requires some knowledge about HTML, php and/or WordPress coding.', 'appointments');?>
			</li>
		</ul>
		
		<ul>
			<li>
			<?php _e('<b>Can I create my own page templates?</b>', 'appointments');?>
			<br />
			<?php _e('Yes. Using <code>do_shortcode</code> function and loading Appointments+ css and javascript files, you can do this. See sample-appointments-page.php file in /includes directory for a sample.', 'appointments');?>
			</li>
		</ul>
		
		<ul>
			<li>
			<?php _e('<b>I have customized the front.css file. How can I prevent it being overwritten by plugin updates?</b>', 'appointments');?>
			<br />
			<?php _e('Copy front.css content and paste them in css file of your theme. Add this code inside functions.php of the theme: <code>add_theme_support( "appointments_style" )</code>. Then, integral plugin css file front.css will not be called.', 'appointments');?>
			</li>
		</ul>
		
		<ul>
			<li>
			<?php _e('<b>Is it possible not to ask payment or deposit for certain users?</b>', 'appointments');?>
			<br />
			<?php _e('Please note that this is quite simple if you are using Membership plugin. If not, referring this filter create a function in your functions.php to set Paypal price as zero for the selected user, user role and/or service: <code>$paypal_price = apply_filters( \'app_paypal_amount\', $paypal_price, $service, $worker, $user_id );</code> This will not make the service free of charge, but user will not be asked for an advance payment. Also you may want to change status to confirmed. See next FAQ.', 'appointments');?>
			</li>
		</ul>
		
		<ul>
			<li>
			<?php _e('<b>I am not requiring advance payment from the users. It possible to automatically confirm appointments of certain users?</b>', 'appointments');?>
			<br />
			<?php _e('Yes. Referring this filter create a function in your functions.php to set status as "confirmed" for the selected user, user role and/or service: <code>$status = apply_filters( \'app_post_confirmation_status\', $status, $price, $service, $worker, $user_id );</code> ', 'appointments');?>
			</li>
		</ul>
		
		
		</div>
		<?php break; ?>
		
		<?php } // End of the big switch ?>	
		</div>
		<script type="text/javascript">
		jQuery(document).ready(function($){
			$('#add_service').click(function(){
				$('.add-new-waiting').show();
				var n = 1;
				if ( $('#number_of_services').val() > 0 ) {
					n = parseInt( $('#number_of_services').val() ) + 1;
				}
				$('#services-table').append('<?php echo $this->esc_rn( $this->add_service() )?>');
				$('#number_of_services').val(n);
				$('#div_save_services').show();
				$('.no_services_defined').hide();
				$('.add-new-waiting').hide();
			});
			$('#add_worker').click(function(){
				$('.add-new-waiting').show();
				var k = parseInt( $('#number_of_workers').val() ) + 1;
				$('#workers-table').append('<?php echo $this->esc_rn( $this->add_worker() )?>');
				$('#number_of_workers').val(k);
				$('#div_save_workers').show();
				$('.no_workers_defined').hide();
				$('.add-new-waiting').hide();
			});
			$('#log_clear_button').click(function() {
				if ( !confirm('<?php _e("Are you sure to clear the log file?","appointments") ?>') ) {return false;}
				else{
					$('.add-new-waiting').show();
					var data = {action: 'delete_log', nonce: '<?php echo wp_create_nonce() ?>'};
					$.post(ajaxurl, data, function(response) {
						$('.add-new-waiting').hide();
						if ( response && response.error ) {
							alert(response.error);
						}
						else{
							$("#app_log").html('<?php _e("Log file cleared...","appointments") ?>');
						}
					},'json');							
				}
			});
		});
		</script>

	<?php
	}

	function delete_log(){
		// check_ajax_referer( );
		if ( !unlink( $this->log_file ) )
			die( json_encode( array('error' => __('Log file could not be deleted','appointments'))));
		die();
	}
	
	
	function add_service( $php=false, $service='' ) {
		if ( $php ) {
			if ( is_object($service)) {
				$n = $service->ID;
				$name = $service->name;
				$capacity = $service->capacity;
				$price = $service->price;
			 }
			 else return;
		}
		else {
			$n = "'+n+'";
			$name = '';
			$capacity = '0';
			$price = '';
		}
		$html = '';
		$html .= '<tr><td>';
		$html .= $n;
		$html .= '</td><td>';
		$html .= '<input style="width:100%" type="text" name="services['.$n.'][name]" value="'.stripslashes( $name ).'" />';
		$html .= '</td><td>';
		$html .= '<input style="width:90%" type="text" name="services['.$n.'][capacity]" value="'.$capacity.'" />';
		$html .= '</td><td>';
		$html .= '<select name="services['.$n.'][duration]" >';
		$k_max = apply_filters( 'app_selectable_durations', 5 );
		for ( $k=1; $k<=$k_max; $k++ ) {
			if ( $php && is_object( $service ) && $k * $this->min_time == $service->duration )
				$html .= '<option selected="selected">'. ($k * $this->min_time) . '</option>';
			else
				$html .= '<option>'. ($k * $this->min_time) . '</option>';
		}
		$html .= '</select>';
		$html .= '</td><td>';
		$html .= '<input style="width:90%" type="text" name="services['.$n.'][price]" value="'.$price.'" />';
		$html .= '</td><td>';
		$pages = get_pages( apply_filters('app_pages_filter',array() ) );
		$html .= '<select name="services['.$n.'][page]" >';
		$html .= '<option value="0">'. __('None','appointments') .'</option>';
		foreach( $pages as $page ) {
			if ( $php )
				$title = esc_attr( $page->post_title );
			else
				$title = esc_js( $page->post_title );
				
			if ( $php && is_object( $service ) && $service->page == $page->ID )
				$html .= '<option value="'.$page->ID.'" selected="selected">'. $title . '</option>';
			else
				$html .= '<option value="'.$page->ID.'">'. $title . '</option>';
		}
		$html .= '</select>';
		$html .= '</td></tr>';
		return $html;
	}


	function add_worker( $php=false, $worker='' ) {
		if ( $php ) {
			if ( is_object($worker)) {
				$k = $worker->ID;
				$price = $worker->price;
				$workers = wp_dropdown_users( array( 'echo'=>0, 'selected' => $worker->ID, 'name'=>'workers['.$k.'][user]', 'exclude'=>apply_filters('app_filter_providers', null) ) );
			}
			 else return;
		}
		else {
			$k = "'+k+'";
			$price = '';
			$workers =str_replace( array("\t","\n","\r"), "", str_replace( array("'", "&#039;"), array('"', "'"), wp_dropdown_users( array ( 'echo'=>0, 'include'=>0, 'name'=>'workers['.$k.'][user]', 'exclude'=>apply_filters('app_filter_providers', null)) ) ) );
		}
		global $wpdb;
		
		$html = '';
		$html .= '<tr><td>';
		$html .= $k;
		$html .= '</td><td>';
		$html .= $workers;
		$html .= '</td><td>';
		$html .= '<input type="text" name="workers['.$k.'][price]" style="width:80%" value="'.$price.'" />';
		$html .= '</td><td>';
		$services = $this->get_services();
		if ( $services ) {
			if ( $php && is_object( $worker ) )
				$services_provided = $this->_explode( $worker->services_provided );
			else
				$services_provided = false;
			$html .= '<select style="width:90%" multiple="multiple" name="workers['.$k.'][services_provided][]" >';
			foreach ( $services as $service ) {
				if ( $php )
					$title = stripslashes( $service->name );
				else
					$title = esc_js( $service->name );
					
				if ( is_array( $services_provided ) && in_array( $service->ID, $services_provided ) )
					$html .= '<option value="'. $service->ID . '" selected="selected">'. $title . '</option>';
				else
					$html .= '<option value="'. $service->ID . '">'. $title . '</option>';
			}
			$html .= '</select>';
		}
		else
			$html .= __( 'No services defined', 'appointments' );
		$html .= '</td><td>';
		$pages = get_pages( apply_filters('app_pages_filter',array() ) );
		$html .= '<select name="workers['.$k.'][page]" >';
		$html .= '<option value="0">'. __('None','appointments') .'</option>';
		foreach( $pages as $page ) {
			if ( $php )
				$title = esc_attr( $page->post_title );
			else
				$title = esc_js( $page->post_title );
				
			if ( $php && is_object( $worker ) && $worker->page == $page->ID )
				$html .= '<option value="'.$page->ID.'" selected="selected">'. $title . '</option>';
			else
				$html .= '<option value="'.$page->ID.'">'. $title . '</option>';
		}
		$html .= '</select>';
		$html .= '</td></tr>';
		return $html;
	}

	/**
	 *	Creates a working hour form
	 *  Worker can be selected. 
	 */	
	function working_hour_form( $status='open' ) {
	
		$this->get_lsw();

		$min_secs = 60 * $this->min_time;
		
		$wb = $this->get_work_break( $this->location, $this->worker, $status );
		if ( $wb )
			$whours = maybe_unserialize( $wb->hours );
		else
			$whours = array();
		
		$form = '';
		$form .= '<table>';
		if ( 'open' == $status )
			$form .= '<tr><th>'.__('Day', 'appointments').'</th><th>'.__('Work?', 'appointments' ).'</th><th>'.__('Start', 'appointments').'</th><th>'.__('End', 'appointments').'</th></tr>';
		else
			$form .= '<tr><th>'.__('Day', 'appointments').'</th><th>'.__('Give break?','appointments').'</th><th>'.__('Start','appointments').'</th><th>'.__('End','appointments').'</th></tr>';
		foreach ( $this->weekdays() as $day ) {
			$form .= '<tr><td>';
			$form .= $day;
			$form .= '</td>';
			$form .= '<td>';
			$form .= '<select name="'.$status.'['.$day.'][active]">';
			if ( isset($whours[$day]['active']) && 'yes' == $whours[$day]['active'] )
				$s = " selected='selected'";
			else $s = '';
			$form .= '<option value="no">'.__('No', 'appointments').'</option>';
			$form .= '<option value="yes"'.$s.'>'.__('Yes', 'appointments').'</option>';
			$form .= '</select>';
			$form .= '</td>';
			$form .= '<td>';
			$form .= '<select name="'.$status.'['.$day.'][start]">';
			for ( $t=0; $t<3600*24; $t=$t+$min_secs ) {
				$dhours = $this->secs2hours( $t ); // Hours in 08:30 format
				if ( isset($whours[$day]['start']) && $dhours == $whours[$day]['start'] )
					$s = " selected='selected'";
				else $s = '';
				
				$form .= '<option'.$s.'>';
				$form .= $dhours;
				$form .= '</option>';
			}
			$form .= '</select>';
			$form .= '</td>';
			
			$form .= '<td>';
			$form .= '<select name="'.$status.'['.$day.'][end]">';
			for ( $t=$min_secs; $t<=3600*24; $t=$t+$min_secs ) {
				$dhours = $this->secs2hours( $t ); // Hours in 08:30 format
				if ( isset($whours[$day]['end']) && $dhours == $whours[$day]['end'] )
					$s = " selected='selected'";
				else $s = '';
				
				$form .= '<option'.$s.'>';
				$form .= $dhours;
				$form .= '</option>';
			}
			$form .= '</select>';
			$form .= '</td>';
			
			$form .= '</tr>';
		}
		
		$form .= '</table>';
		
		return $form;
	}

	/**
	 *	Return results for appointments
	 */		
	function get_admin_apps($type, $startat, $num) {

		switch($type) {

			case 'active':
						$sql = $this->db->prepare( "SELECT SQL_CALC_FOUND_ROWS * FROM {$this->app_table} WHERE status IN ('confirmed', 'paid') ORDER BY ID DESC  LIMIT %d, %d", $startat, $num );
						break;
			case 'pending':
						$sql = $this->db->prepare( "SELECT SQL_CALC_FOUND_ROWS * FROM {$this->app_table} WHERE status IN ('pending') ORDER BY ID DESC LIMIT %d, %d", $startat, $num );
						break;
			case 'completed':
						$sql = $this->db->prepare( "SELECT SQL_CALC_FOUND_ROWS * FROM {$this->app_table} WHERE status IN ('completed') ORDER BY ID DESC LIMIT %d, %d", $startat, $num );
						break;
			case 'removed':
						$sql = $this->db->prepare( "SELECT SQL_CALC_FOUND_ROWS * FROM {$this->app_table} WHERE status IN ('removed') ORDER BY ID DESC LIMIT %d, %d", $startat, $num );
						break;

		}

		return $this->db->get_results( $sql );

	}
	
	function get_apps_total() {
		return $this->db->get_var( "SELECT FOUND_ROWS();" );
	}

	/**
	 *	Creates the list for Appointments admin page
	 */		
	function appointment_list() {

		global $page, $action, $type;

		wp_reset_vars( array('type') );

		if(empty($type)) $type = 'active';

		?>
		<div id="wpbody-content">
		<div class='wrap'>
			<div class="icon32" style="margin:8px 0 0 0"><img src="<?php echo $this->plugin_url . '/images/appointments.png'; ?>" /></div>
			<h2><?php echo __('Appointments','appointments'); ?><a href="javascript:void(0)" class="add-new-h2"><?php _e('Add New', 'appointments')?></a>
			<img class="add-new-waiting" style="display:none;" src="<?php echo admin_url('images/wpspin_light.gif')?>" alt="">
			</h2>

			<ul class="subsubsub">
				<li><a href="<?php echo add_query_arg('type', 'active'); ?>" class="rbutton <?php if($type == 'active') echo 'current'; ?>"><?php  _e('Active appointments', 'appointments'); ?></a> | </li>
				<li><a href="<?php echo add_query_arg('type', 'pending'); ?>" class="rbutton <?php if($type == 'pending') echo 'current'; ?>"><?php  _e('Pending appointments', 'appointments'); ?></a> | </li>
				<li><a href="<?php echo add_query_arg('type', 'completed'); ?>" class="rbutton <?php if($type == 'completed') echo 'current'; ?>"><?php  _e('Completed appointments', 'appointments'); ?></a> | </li>
				<li><a href="<?php echo add_query_arg('type', 'removed'); ?>" class="rbutton <?php if($type == 'removed') echo 'current'; ?>"><?php  _e('Removed appointments', 'appointments'); ?></a></li>
				<li><a href="javascript:void(0)" class="info-button" title="<?php _e('Click to toggle information about statuses', 'appointments')?>"><img src="<?php echo $this->plugin_url . '/images/information.png'?>" alt="" /></a></li>
			</ul>
		<br /><br />
		<span class="description status-description" style="display:none;">
		<ul>
		<li><?php _e('<b>Completed:</b> Appointment became overdue after it is confirmed or paid', 'appointments') ?></li>
		<li><?php _e('<b>Removed:</b> Appointment was not paid for or was not confirmed manually in the allowed time', 'appointments') ?></li>
		<li><?php _e('If you require payment:', 'appointments') ?></li>
		<li><?php _e('<b>Active/Paid:</b> Paid and confirmed by Paypal', 'appointments') ?></li>
		<li><?php _e('<b>Pending:</b> Client applied for the appointment, but not yet paid.', 'appointments') ?></li>
		</ul>
		<ul>
		<li><?php _e('If you do not require payment:', 'appointments') ?></li>
		<li><?php _e('<b>Active/Confirmed:</b> Manually confirmed', 'appointments') ?></li>
		<li><?php _e('<b>Pending:</b> Client applied for the appointment, but it is not manually confirmed.', 'appointments') ?></li>
		</ul>
		</span>

			<?php
				$this->myapps($type);

			?>
		</div> <!-- wrap -->
		</div>
		<script type="text/javascript">
		jQuery(document).ready(function($){
			$(".info-button").click(function(){
				$(".status-description").toggle('fast');
			});
		});
		</script>
		<?php

	}
	
	function myapps($type = 'active') {

		if(empty($_GET['paged'])) {
			$paged = 1;
		} else {
			$paged = ((int) $_GET['paged']);
		}

		$startat = ($paged - 1) * 50;

		$apps = $this->get_admin_apps($type, $startat, 50);
		$total = $this->get_apps_total();

		$columns = array();

		if ( isset( $_GET["type"] ) && 'removed' == $_GET["type"] )
			$columns['delete'] = '<input type="checkbox" />';
		$columns['app_ID'] = __('ID','appointments');
		$columns['user'] = __('Client','appointments');
		$columns['date'] = __('Date/Time','appointments');
		$columns['service'] = __('Service','appointments');
		$columns['worker'] = __('Provider','appointments');
		$columns['status'] = __('Status','appointments');

		$trans_navigation = paginate_links( array(
			'base' => add_query_arg( 'paged', '%#%' ),
			'format' => '',
			'total' => ceil($total / 50),
			'current' => $paged
		));

		echo '<div class="tablenav">';
		if ( $trans_navigation ) echo "<div class='tablenav-pages'>$trans_navigation</div>";
		echo '</div>';
		
		// Only for "Removed" tab
		if ( isset( $_GET["type"] ) && 'removed' == $_GET["type"] ) {
		?>
			<form method="post" >
		
		<?php
		}
		?>

			<table cellspacing="0" class="widefat">
				<thead>
				<tr>
				<?php
					foreach($columns as $key => $col) {
						?>
						<th style="" class="manage-column column-<?php echo $key; ?>" id="<?php echo $key; ?>" scope="col"><?php echo $col; ?></th>
						<?php
					}
				?>
				</tr>
				</thead>

				<tfoot>
				<tr>
				<?php
					reset($columns);
					foreach($columns as $key => $col) {
						?>
						<th style="" class="manage-column column-<?php echo $key; ?>" id="<?php echo $key; ?>" scope="col"><?php echo $col; ?></th>
						<?php
					}
				?>
				</tr>
				</tfoot>

				<tbody>
					<?php
					if($apps) {
						foreach($apps as $key => $app) {
							
							?>
							<tr valign="middle" class="alternate app-tr">
							<?php
							// Only for "Removed" tab
							if ( isset( $_GET["type"] ) && 'removed' == $_GET["type"] ) {
							?>
								<td class="column-delete check-column">
								<input type="checkbox" name="app[]" value="<?php echo $app->ID;?>" />	
								</td>
							
							<?php
							}
							?>
								<td class="column-app_ID">
									<span class="span_app_ID"><?php	echo $app->ID;?></span>
									
								</td>
								<td class="column-user">
									<?php
										echo $this->get_client_name( $app->ID );
									?>
									<div class="row-actions">
									<a href="javascript:void(0)" class="app-inline-edit"><?php _e('See Details and Edit', 'appointments') ?></a>
									<img class="waiting" style="display:none;" src="<?php echo admin_url('images/wpspin_light.gif')?>" alt="">
									</div>
								</td>
								<td class="column-date">
									<?php
										echo mysql2date($this->datetime_format, $app->start);

									?>
								</td>
								<td class="column-service">
									<?php								
									echo $this->get_service_name( $app->service );
									?>
								</td>
								<td class="column-worker">
									<?php
										echo $this->get_worker_name( $app->worker );
									?>
								</td>
								<td class="column-status">
									<?php
										if(!empty($app->status)) {
											echo $app->status;
										} else {
											echo __('None yet','appointments');
										}
									?>
								</td>
							</tr>
							<?php
		
						}
					} 
					else {
						$columncount = count($columns);
						?>
						<tr valign="middle" class="alternate" >
							<td colspan="<?php echo $columncount; ?>" scope="row"><?php _e('No appointments have been found.','appointments'); ?></td>
						</tr>
						<?php
					}
					?>

				</tbody>
			</table>
		<?php
		// Only for "Removed" tab
		if ( isset( $_GET["type"] ) && 'removed' == $_GET["type"] ) {
		?>
			<p>
			<input type="submit" id="delete_removed" class="button-secondary" value="<?php _e('Permanently Delete Selected Records', 'appointments') ?>" title="<?php _e('Clicking this button deletes logs saved on the server') ?>" />
			<input type="hidden" name="delete_removed" value="delete_removed" />
			
			</p>		
			</form>
		
		<?php } ?>

			<script type="text/javascript">
			jQuery(document).ready(function($){
				$("#delete_removed").click( function() {
					if ( !confirm('<?php _e("Are you sure to delete the selected record(s)?","appointments") ?>') ) 
					{return false;}
					else {
						return true;
					}
				});
				var th_sel = $("th.column-delete input:checkbox");
				var td_sel = $("td.column-delete input:checkbox");
				th_sel.change( function() {
					if ( $(this).is(':checked') ) {
						td_sel.attr("checked","checked");
						th_sel.not(this).attr("checked","checked");
					}
					else{
						td_sel.removeAttr('checked');
						th_sel.not(this).removeAttr('checked');
					}
				});
				var col_len = $("table").find("tr:first th").length;
				// Add new
				$(".add-new-h2").click(function(){
					$(".add-new-waiting").show();
					var data = {action: 'inline_edit', col_len: col_len, app_id:0, nonce: '<?php echo wp_create_nonce() ?>'};
					$.post(ajaxurl, data, function(response) {
						$(".add-new-waiting").hide();
						if ( response && response.error ){
							alert(response.error);
						}
						else if (response) {
							$("table.widefat").prepend(response.result);
						}
						else {alert("<?php echo esc_js(__('Unexpected error','appointments'))?>");}
					},'json');
				});
				// Edit
				$(".app-inline-edit").click(function(){
					var app_parent = $(this).parents(".app-tr");
					app_parent.find(".waiting").show();
					var app_id = app_parent.find(".span_app_ID").html();
					var data = {action: 'inline_edit', col_len: col_len, app_id: app_id, nonce: '<?php echo wp_create_nonce() ?>'};
					$.post(ajaxurl, data, function(response) {
						app_parent.find(".waiting").hide();
						if ( response && response.error ){
							alert(response.error);
						}
						else if (response) {
							app_parent.hide();
							app_parent.after(response.result);
						}
						else {alert('<?php echo esc_js(__('Unexpected error','appointments'))?>');}
					},'json');
				});
				$("table").on("click", ".cancel", function(){
					$(".inline-edit-row").hide();
					$(".app-tr").show();
				});
				// Add datepicker only once and when focused
				// Ref: http://stackoverflow.com/questions/3796207/using-one-with-live-jquery
				$("table").on("focus", ".datepicker", function(e){
					if( $(e.target).data('focused')!='yes' ) {
						var php_date_format = "<?php echo $this->safe_date_format() ?>";
						var js_date_format = php_date_format.replace("F","MM").replace("j","dd").replace("Y","yyyy").replace("y","yy");
						$(".datepicker").datepick({dateFormat: js_date_format});
					}
					 $(e.target).data('focused','yes');
				});
				$("table").on("click", ".save", function(){
					var save_parent = $(this).parents(".inline-edit-row");
					save_parent.find(".waiting").show();
					var user = save_parent.find('select[name="user"] option:selected').val();
					var name = save_parent.find('input[name="cname"]').val();
					var email = save_parent.find('input[name="email"]').val();
					var phone = save_parent.find('input[name="phone"]').val();
					var address = save_parent.find('input[name="address"]').val();
					var city = save_parent.find('input[name="city"]').val();
					var service = save_parent.find('select[name="service"] option:selected').val();
					var worker = save_parent.find('select[name="worker"] option:selected').val();
					var price = save_parent.find('input[name="price"]').val();
					var date = save_parent.find('input[name="date"]').val();
					var time = save_parent.find('select[name="time"] option:selected').val();
					var note = save_parent.find('textarea').val();
					var status = save_parent.find('select[name="status"] option:selected').val();
					var resend = save_parent.find('input[name="resend"]').val();
					var app_id = save_parent.find('input[name="app_id"]').val();
					var data = {action: 'inline_edit_save', user:user, name:name, email:email, phone:phone, address:address,city:city, service:service, worker:worker, price:price, date:date, time:time, note:note, status:status, resend:resend, app_id: app_id, nonce: '<?php echo wp_create_nonce() ?>'};
					$.post(ajaxurl, data, function(response) {
						save_parent.find(".waiting").hide();
						if ( response && response.error ){
							save_parent.find(".error").html(response.error).show();
						}
						else if (response) {
							save_parent.find(".error").html(response.result).show();
						}
						else {alert("<?php echo esc_js(__('Unexpected error','appointments'))?>");}
					},'json');
				});
			});
			</script>
		<?php
	}

	/**
	 * Return a safe date format that datepick can use
	 * @return string
	 * @since 1.0.4.2
	 */	
	function safe_date_format() {
		if ( 'd F Y' == $this->date_format || 'Y/m/d' == $this->date_format 
			|| 'm/d/Y' == $this->date_format || 'd/m/Y' == $this->date_format )
			return $this->date_format;
	
		else return 'd F Y';
	}
	
	function inline_edit() {
		global $wpdb;
		$app_id = $_POST["app_id"];
		if ( $app_id ) 
			$app = $wpdb->get_row( "SELECT * FROM " . $this->app_table . " WHERE ID=".$app_id." " );
		else {
			// Get maximum ID
			$app_max = $wpdb->get_var( "SELECT MAX(ID) FROM " . $this->app_table . " " );
			// Check if nothing has saved yet
			if ( !$app_max )
				$app_max = 0;
			$app = new stdClass(); // This means insert a new app object
			$app->ID = $app_max + 1 ; // We want to create a new record
			// Set other fields to default so that we don't get notice messages
			$app->user = $app->location = $app->service = $app->worker = $app->price = 0;
			$app->created = $app->name = $app->email = $app->phone = $app->address = $app->city = $app->status = $app->sent = $app->sent_worker = $app->note = '';
			$app->start = date_i18n( $this->datetime_format, $this->local_time + 60*$this->min_time );
			$app->end = date_i18n( $this->datetime_format, $this->local_time + 120*$this->min_time );
		}
		
		$html = '';
		$html .= '<tr class="inline-edit-row inline-edit-row-post quick-edit-row-post">';
		if ( isset( $_POST["col_len"] ) )		
			$html .= '<td colspan="'.$_POST["col_len"].'" class="colspanchange">';
		else
			$html .= '<td colspan="6" class="colspanchange">';
		
		$html .= '<fieldset class="inline-edit-col-left" style="width:33%">';
		$html .= '<div class="inline-edit-col">';
		$html .= '<h4>'.__('CLIENT', 'appointments').'</h4>';
		/* user */
		$html .= '<label>';
		$html .= '<span class="title">'.__('User', 'appointments'). '</span>';
		$html .= wp_dropdown_users( array( 'show_option_all'=>__('Not registered user','appointments'), 'echo'=>0, 'selected' => $app->user, 'name'=>'user' ) );
		$html .= '</label>';
		/* Client name */
		$html .= '<label>';
		$html .= '<span class="title">'.$this->get_field_name('name'). '</span>';
		$html .= '<span class="input-text-wrap">';
		$html .= '<input type="text" name="cname" class="ptitle" value="'.stripslashes( $app->name ).'" />';
		$html .= '</span>';
		$html .= '</label>';
		/* Client email */
		$html .= '<label>';
		$html .= '<span class="title">'.$this->get_field_name('email'). '</span>';
		$html .= '<span class="input-text-wrap">';
		$html .= '<input type="text" name="email" class="ptitle" value="'.$app->email.'" />';
		$html .= '</span>';
		$html .= '</label>';
		/* Client Phone */
		$html .= '<label>';
		$html .= '<span class="title">'.$this->get_field_name('phone'). '</span>';
		$html .= '<span class="input-text-wrap">';
		$html .= '<input type="text" name="phone" class="ptitle" value="'.stripslashes( $app->phone ).'" />';
		$html .= '</span>';
		$html .= '</label>';
		/* Client Address */
		$html .= '<label>';
		$html .= '<span class="title">'.$this->get_field_name('address'). '</span>';
		$html .= '<span class="input-text-wrap">';
		$html .= '<input type="text" name="address" class="ptitle" value="'.stripslashes( $app->address ).'" />';
		$html .= '</span>';
		$html .= '</label>';
		/* Client City */
		$html .= '<label>';
		$html .= '<span class="title">'.$this->get_field_name('city'). '</span>';
		$html .= '<span class="input-text-wrap">';
		$html .= '<input type="text" name="city" class="ptitle" value="'.stripslashes( $app->city ).'" />';
		$html .= '</span>';
		$html .= '</label>';
		$html .= '</div>';
		$html .= '</fieldset>';
		
		$html .= '<fieldset class="inline-edit-col-center" style="width:28%">';
		$html .= '<div class="inline-edit-col">';
		$html .= '<h4>'.__('SERVICE', 'appointments').'</h4>';
		/* Services */
		$services = $this->get_services();
		$html .= '<label>';
		$html .= '<span class="title">'.__('Name', 'appointments'). '</span>';
		$html .= '<select name="service">';
		if ( $services ) {
			foreach ( $services as $service ) {
				if ( $app->service == $service->ID )
					$sel = ' selected="selected"';
				else
					$sel = '';
				$html .= '<option value="'.$service->ID.'"'.$sel.'>'. stripslashes( $service->name ) . '</option>';
			}
		}
		$html .= '</select>';
		$html .= '</label>';
		/* Workers */
		$workers = $wpdb->get_results("SELECT * FROM " . $this->workers_table . " " );
		$html .= '<label>';
		$html .= '<span class="title">'.__('Provider', 'appointments'). '</span>';
		$html .= '<select name="worker">';
		// Always add an "Our staff" field
		$html .= '<option value="0">'. __('No specific provider', 'appointments') . '</option>';
		if ( $workers ) {
			foreach ( $workers as $worker ) {
				if ( $app->worker == $worker->ID ) {
					$sel = ' selected="selected"';
				}
				else
					$sel = '';
				$html .= '<option value="'.$worker->ID.'"'.$sel.'>'. $this->get_worker_name( $worker->ID ) . '</option>';
			}
		}
		$html .= '</select>';
		$html .= '</label>';
		/* Price */
		$html .= '<label>';
		$html .= '<span class="title">'.__('Price', 'appointments'). '</span>';
		$html .= '<span class="input-text-wrap">';
		$html .= '<input type="text" name="price" style="width:50%" class="ptitle" value="'.$app->price.'" />';
		$html .= '</span>';
		$html .= '</label>';
		$html .= '</div>';
		$html .= '</fieldset>';
		
		$html .= '<fieldset class="inline-edit-col-right" style="width:38%">';
		$html .= '<div class="inline-edit-col">';
		$html .= '<h4>'.__('APPOINTMENT', 'appointments').'</h4>';
		/* Created - Don't show for a new app */
		if ( $app_id ) {
			$html .= '<label>';
			$html .= '<span class="title">'.__('Created', 'appointments'). '</span>';
			$html .= '<span class="input-text-wrap" style="height:26px;padding-top:4px;">';
			$html .= date( $this->datetime_format, strtotime($app->created) );
			$html .= '</span>';
			$html .= '</label>';
		}
		/* Start */
		$html .= '<label style="float:left;width:65%">';
		$html .= '<span class="title">'.__('Start', 'appointments'). '</span>';
		$html .= '<span class="input-text-wrap" >';
		$html .= '<input type="text" name="date" class="datepicker" size="12" value="'.date( $this->date_format, strtotime($app->start) ).'" />';
		$html .= '</label>';
		$html .= '<label style="float:left;width:30%; padding-left:5px;">';
		
		// Check if an admin min time (time base) is set. @since 1.0.2
		if ( isset( $this->options["admin_min_time"] ) && $this->options["admin_min_time"] )
			$min_time = $this->options["admin_min_time"];
		else
			$min_time = $this->min_time;
			
		$min_secs = 60 * apply_filters( 'app_admin_min_time', $min_time );
		$html .= '<select name="time" >';
		for ( $t=0; $t<3600*24; $t=$t+$min_secs ) {
			$dhours = $this->secs2hours( $t ); // Hours in 08:30 format
			if ( $dhours == date( $this->time_format, strtotime($app->start) ) )
				$s = " selected='selected'";
			else $s = '';
			
			$html .= '<option'.$s.'>';
			$html .= $dhours;
			$html .= '</option>';
		}
		$html .= '</select>';
		$html .= '</span>';
		$html .= '</label>';
		$html .= '<div style="clear:both; height:0"></div>';
		/* End - Don't show for a new app */
		if ( $app_id ) {
			$html .= '<label style="margin-top:8px">';
			$html .= '<span class="title">'.__('End', 'appointments'). '</span>';
			$html .= '<span class="input-text-wrap" style="height:26px;padding-top:4px;">';
			$html .= date( $this->datetime_format, strtotime($app->end) );
			$html .= '</span>';
			$html .= '</label>';
		}
		/* Note */
		$html .= '<label>';
		$html .= '<span class="title">'.$this->get_field_name('note'). '</span>';
		$html .= '<textarea cols="22" rows=1">';
		$html .= stripslashes( $app->note );
		$html .= '</textarea>';
		$html .= '</label>';
		/* Status */
		$statuses = $this->get_statuses();
		$html .= '<label>';
		$html .= '<span class="title">'.__('Status', 'appointments'). '</span>';
		$html .= '<span class="input-text-wrap">';
		$html .= '<select name="status">';
		if ( $statuses ) {
			foreach ( $statuses as $status => $status_name ) {
				if ( $app->status == $status )
					$sel = ' selected="selected"';
				else
					$sel = '';
				$html .= '<option value="'.$status.'"'.$sel.'>'. $status_name . '</option>';
			}
		}
		$html .= '</select>';
		$html .= '</span>';
		$html .= '</label>';
		/* Confirmation email */
		// Default is "checked" for a new appointment
		if ( $app_id ) {
			$c = '';
			$text = __('(Re)send confirmation email', 'appointments');
		}
		else {
			$c = ' checked="checked"';
			$text = __('Send confirmation email', 'appointments');
		}
			
		$html .= '<label>';
		$html .= '<span class="title">'.__('Confirm','appointments').'</span>';
		$html .= '<span class="input-text-wrap">';
		$html .= '<input type="checkbox" name="resend" value="1" '.$c.'>&nbsp;' .$text;
		$html .= '</span>';
		$html .= '</label>';
		
		$html .= '</div>';
		$html .= '</fieldset>';
		/* General fields required for save and cancel */
		$html .= '<p class="submit inline-edit-save">';
		$html .= '<a href="javascript:void(0)" title="'.__('Cancel', 'appointments').'" class="button-secondary cancel alignleft">'.__('Cancel','appointments').'</a>';
		$html .= '<a href="javascript:void(0)" title="'.__('Save/Update', 'appointments').'" class="button-primary save alignright">'.__('Save / Update','appointments').'</a>';
		$html .= '<img class="waiting" style="display:none;" src="'.admin_url('images/wpspin_light.gif').'" alt="">';
		$html .= '<input type="hidden" name="app_id" value="'.$app->ID.'">';
		$html .= '<span class="error" style="display:none"></span>';
		$html .= '<br class="clear">';
		$html .= '</p>';
		
		$html .= '</td>';
		$html .= '</tr>';
		
		die( json_encode( array( 'result'=>$html)));
		
	}
	
	function inline_edit_save() {
		$app_id = $_POST["app_id"];
		global $wpdb, $current_user;
		$app = $wpdb->get_row( "SELECT * FROM " . $this->app_table . " WHERE ID=".$app_id." " );
		
		$data = array();
		if ( $app != null )
			$data['ID'] = $app_id;
		else {
			$data['created']	= date("Y-m-d H:i:s", $this->local_time );
			$data['ID'] 		= 'NULL';
		}
		$data['user']		= $_POST['user'];
		$data['email']		= $_POST['email'];
		$data['name']		= $_POST['name'];
		$data['phone']		= $_POST['phone'];
		$data['address'] 	= $_POST['address'];
		$data['city']		= $_POST['city'];
		$data['service']	= $_POST['service'];
		$service			= $this->get_service( $_POST['service'] );
		$data['worker']		= $_POST['worker'];
		$data['price']		= $_POST['price'];
		$data['start']		= date( 'Y-m-d H:i:s', strtotime( $_POST['date']. " " . $_POST['time'] ) );
		$data['end']		= date( 'Y-m-d H:i:s', strtotime( $_POST['date']. " " . $_POST['time'] ) + $service->duration *60 );
		$data['note']		= $_POST['note'];
		$data['status']		= $_POST['status'];
		$resend				= isset( $_POST["resend"] );

		$update_result = $insert_result = false;
		if( $app != null ) {
			// Update
			$update_result = $wpdb->update( $this->app_table, $data, array('ID' => $app_id) );
			if ( $update_result || $resend )
				$this->send_confirmation( $app_id );
		} else {
			// Insert
			$insert_result = $wpdb->insert( $this->app_table, $data );
			if ( $insert_result && $resend )
				$this->send_confirmation( $wpdb->insert_id );
		}
		
		if ( $update_result ) {
			// Log change of status
			if ( $data['status'] != $app->status ) {
				$this->log( $this->log( sprintf( __('Status changed from %s to %s by %s for appointment ID:%d','appointments'), $app->status, $data["status"], $current_user->user_login, $app->ID ) ) );
			}
			die( json_encode( array("result" => __('<b>Changes saved.</b>', 'appointments') ) ) );
		}
		else if ( $insert_result )
			die( json_encode( array("result" => __('<b>New appointment succesfully saved.</b>', 'appointments') ) ) );
		else
			die( json_encode( array("result" => __('<b>Record could not be saved OR you did not make any changes!</b>', 'appointments') ) ) );
	}

	 // For future use
	function reports() {
	}
	
	/**
	 *	Get transaction records
	 *  Modified from Membership plugin by Barry 
	 */
	function get_transactions($type, $startat, $num) {

		switch($type) {

			case 'past':
						$sql = $this->db->prepare( "SELECT SQL_CALC_FOUND_ROWS * FROM {$this->transaction_table} WHERE transaction_status NOT IN ('Pending', 'Future') ORDER BY transaction_ID DESC  LIMIT %d, %d", $startat, $num );
						break;
			case 'pending':
						$sql = $this->db->prepare( "SELECT SQL_CALC_FOUND_ROWS * FROM {$this->transaction_table} WHERE transaction_status IN ('Pending') ORDER BY transaction_ID DESC LIMIT %d, %d", $startat, $num );
						break;
			case 'future':
						$sql = $this->db->prepare( "SELECT SQL_CALC_FOUND_ROWS * FROM {$this->transaction_table} WHERE transaction_status IN ('Future') ORDER BY transaction_ID DESC LIMIT %d, %d", $startat, $num );
						break;

		}

		return $this->db->get_results( $sql );

	}

	/**
	 *	Find if a Paypal transaction is duplicate or not
	 */
	function duplicate_transaction($app_id, $amount, $currency, $timestamp, $paypal_ID, $status, $note,$content=0) {
		$sql = $this->db->prepare( "SELECT transaction_ID FROM {$this->transaction_table} WHERE transaction_app_ID = %d AND transaction_paypal_ID = %s AND transaction_stamp = %d LIMIT 1 ", $app_id, $paypal_ID, $timestamp );

		$trans = $this->db->get_var( $sql );
		if(!empty($trans)) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 *	Save a Paypal transaction to the database
	 */
	function record_transaction($app_id, $amount, $currency, $timestamp, $paypal_ID, $status, $note) {

		$data = array();
		$data['transaction_app_ID'] = $app_id;
		$data['transaction_paypal_ID'] = $paypal_ID;
		$data['transaction_stamp'] = $timestamp;
		$data['transaction_currency'] = $currency;
		$data['transaction_status'] = $status;
		$data['transaction_total_amount'] = (int) round($amount * 100);
		$data['transaction_note'] = $note;

		$existing_id = $this->db->get_var( $this->db->prepare( "SELECT transaction_ID FROM {$this->transaction_table} WHERE transaction_paypal_ID = %s LIMIT 1", $paypal_ID ) );

		if(!empty($existing_id)) {
			// Update
			$this->db->update( $this->transaction_table, $data, array('transaction_ID' => $existing_id) );
		} else {
			// Insert
			$this->db->insert( $this->transaction_table, $data );
		}

	}

	function get_total() {
		return $this->db->get_var( "SELECT FOUND_ROWS();" );
	}

	function transactions() {

		global $page, $action, $type;

		wp_reset_vars( array('type') );

		if(empty($type)) $type = 'past';

		?>
		<div class='wrap'>
			<div class="icon32" style="margin:8px 0 0 0"><img src="<?php echo $this->plugin_url . '/images/transactions.png'; ?>" /></div>
			<h2><?php echo __('Transactions','appointments'); ?></h2>

			<ul class="subsubsub">
				<li><a href="<?php echo add_query_arg('type', 'past'); ?>" class="rbutton <?php if($type == 'past') echo 'current'; ?>"><?php  _e('Recent transactions', 'appointments'); ?></a> | </li>
				<li><a href="<?php echo add_query_arg('type', 'pending'); ?>" class="rbutton <?php if($type == 'pending') echo 'current'; ?>"><?php  _e('Pending transactions', 'appointments'); ?></a> | </li>
				<li><a href="<?php echo add_query_arg('type', 'future'); ?>" class="rbutton <?php if($type == 'future') echo 'current'; ?>"><?php  _e('Future transactions', 'appointments'); ?></a></li>
			</ul>

			<?php
				$this->mytransactions($type);

			?>
		</div> <!-- wrap -->
		<?php

	}
	
	function mytransactions($type = 'past') {

		if(empty($_GET['paged'])) {
			$paged = 1;
		} else {
			$paged = ((int) $_GET['paged']);
		}

		$startat = ($paged - 1) * 50;

		$transactions = $this->get_transactions($type, $startat, 50);
		$total = $this->get_total();

		$columns = array();

		$columns['subscription'] = __('App ID','appointments');
		$columns['user'] = __('User','appointments');
		$columns['date'] = __('Date','appointments');
		$columns['service'] = __('Service','appointments');
		$columns['amount'] = __('Amount','appointments');
		$columns['transid'] = __('Transaction id','appointments');
		$columns['status'] = __('Status','appointments');

		$trans_navigation = paginate_links( array(
			'base' => add_query_arg( 'paged', '%#%' ),
			'format' => '',
			'total' => ceil($total / 50),
			'current' => $paged
		));

		echo '<div class="tablenav">';
		if ( $trans_navigation ) echo "<div class='tablenav-pages'>$trans_navigation</div>";
		echo '</div>';
		?>

			<table cellspacing="0" class="widefat fixed">
				<thead>
				<tr>
				<?php
					foreach($columns as $key => $col) {
						?>
						<th style="" class="manage-column column-<?php echo $key; ?>" id="<?php echo $key; ?>" scope="col"><?php echo $col; ?></th>
						<?php
					}
				?>
				</tr>
				</thead>

				<tfoot>
				<tr>
				<?php
					reset($columns);
					foreach($columns as $key => $col) {
						?>
						<th style="" class="manage-column column-<?php echo $key; ?>" id="<?php echo $key; ?>" scope="col"><?php echo $col; ?></th>
						<?php
					}
				?>
				</tr>
				</tfoot>

				<tbody>
					<?php
					if($transactions) {
						foreach($transactions as $key => $transaction) {
							?>
							<tr valign="middle" class="alternate">
								<td class="column-subscription">
									<?php
										echo $transaction->transaction_app_ID;
									?>
								
								</td>
								<td class="column-user">
									<?php
										echo $this->get_client_name( $transaction->transaction_app_ID );
									?>
								</td>
								<td class="column-date">
									<?php
										echo mysql2date("d-m-Y", $transaction->transaction_stamp);

									?>
								</td>
								<td class="column-service">
								<?php								
								echo $this->get_service_name( $transaction->transaction_app_ID );
								?>
								</td>
								<td class="column-amount">
									<?php
										$amount = $transaction->transaction_total_amount / 100;

										echo $transaction->transaction_currency;
										echo "&nbsp;" . number_format($amount, 2, '.', ',');
									?>
								</td>
								<td class="column-transid">
									<?php
										if(!empty($transaction->transaction_paypal_ID)) {
											echo $transaction->transaction_paypal_ID;
										} else {
											echo __('None yet','appointments');
										}
									?>
								</td>
								<td class="column-status">
									<?php
										if(!empty($transaction->transaction_status)) {
											echo $transaction->transaction_status;
										} else {
											echo __('None yet','appointments');
										}
									?>
								</td>
							</tr>
							<?php
						}
					} else {
						$columncount = count($columns);
						?>
						<tr valign="middle" class="alternate" >
							<td colspan="<?php echo $columncount; ?>" scope="row"><?php _e('No Transactions have been found, patience is a virtue.','appointments'); ?></td>
						</tr>
						<?php
					}
					?>

				</tbody>
			</table>
		<?php
	}
	
	function tutorial1() {
		//load the file
		require_once( $this->plugin_dir . '/includes/pointer-tutorials.php' );
		
		//create our tutorial, with default redirect prefs
		$tutorial = new Pointer_Tutorial('app_tutorial1', true, false);
		
		//add our textdomain that matches the current plugin
		$tutorial->set_textdomain = 'appointments';
		
		//add the capability a user must have to view the tutorial
		$tutorial->set_capability = 'manage_options';
		
		$tutorial->add_icon( $this->plugin_url . '/images/large-greyscale.png' );
		
		$tutorial->add_step(admin_url('admin.php?page=app_settings'), 'appointments_page_app_settings', '.menu-top .toplevel_page_appointments', __('Appointments+ Tutorial', 'appointments'), array(
		    'content'  => '<p>' . esc_js( __('Welcome to Appointments+ plugin. This tutorial will hopefully help you to make a quick start by adjusting the most important settings to your needs. You can restart this tutorial any time clicking the link on the FAQ page.', 'appointments' ) ) . '</p>',
		    'position' => array( 'edge' => 'left', 'align' => 'center' ),
		));
		
		$tutorial->add_step(admin_url('admin.php?page=app_settings'), 'appointments_page_app_settings', 'select[name="min_time"]', __('Time Base', 'appointments'), array(
		    'content'  => '<p>' . esc_js( __('Time Base is the most important parameter of Appointments+. It is the minimum time that you can select for your appointments. If you set it too high then you may not be possible to optimize your appointments. If you set it too low, your schedule will be too crowded and you may have difficulty in managing your appointments. You should enter here the duration of the shortest service you are providing.', 'appointments' ) ) . '</p>',
		    'position' => array( 'edge' => 'left', 'align' => 'center' ),
		));
		
		$tutorial->add_step(admin_url('admin.php?page=app_settings'), 'appointments_page_app_settings', 'input:checkbox[name="make_an_appointment"]', __('Creating a functional front end appointment page', 'appointments'), array(
		    'content'  => '<p>' . esc_js(__('Appointments+ provides an easy way of creating an appointment page. Check this checkbox to include all shortcodes in a full functional page. You can later edit this page.', 'appointments' )).'</p>',
		    'position' => array( 'edge' => 'left', 'align' => 'center' ),
		));
		
		$tutorial->add_step(admin_url('admin.php?page=app_settings'), 'appointments_page_app_settings', 'select[name="app_page_type"]', __('Creating a functional front end appointment page', 'appointments'), array(
		    'content'  => '<p>' . esc_js(__('You can select a schedule type from the list. To see how they look, you can also create more than one page, one by one and then delete unused ones.', 'appointments' )).'</p>',
		    'position' => array( 'edge' => 'right', 'align' => 'center' ),
		));
		
		$tutorial->add_step(admin_url('admin.php?page=app_settings'), 'appointments_page_app_settings', 'select[name="color_set"]', __('Selecting a Color Set to Match Your Theme', 'appointments'), array(
		    'content'  => '<p>' . esc_js(__('It is possible to select color sets for your schedule tables from predefined sets, or customize them. When you select Custom, you will be able to set your own colors for different statuses (Busy, free, not possible/not working).', 'appointments' )).'</p>',
		    'position' => array( 'edge' => 'right', 'align' => 'center' ),
		));
		
		$tutorial->add_step(admin_url('admin.php?page=app_settings'), 'appointments_page_app_settings', 'select[name="login_required"]', __('Do you require login?', 'appointments'), array(
		    'content'  => '<p>' . esc_js(__('You can set whether client is required to log into the website to apply for an appointment. When you select this setting as Yes, you will see additional settings.', 'appointments' )).'</p>',
		    'position' => array( 'edge' => 'left', 'align' => 'center' ),
		));
		
		$tutorial->add_step(admin_url('admin.php?page=app_settings'), 'appointments_page_app_settings', 'input:checkbox[name="ask_name"]', __('Requiring information from client', 'appointments'), array(
		    'content'  => '<p>' . esc_js(__('You may ask the client to fill some selectable fields so that they may not need to register on your website.', 'appointments' )).'</p>',
		    'position' => array( 'edge' => 'right', 'align' => 'center' ),
		));
		
		$tutorial->add_step(admin_url('admin.php?page=app_settings'), 'appointments_page_app_settings', 'select[name="payment_required"]', __('Do you require payment?', 'appointments'), array(
		    'content'  => '<p>' . esc_js(__('You can set whether client is asked for a payment to accept his appointment. If this setting is selected as Yes, appointment will be in pending status until a succesful Paypal payment is completed. After you select this, you will see additional fields for your Paypal account, deposits and integration with Membership plugin.', 'appointments' )).'</p>',
		    'position' => array( 'edge' => 'left', 'align' => 'center' ),
		));
		
		$tutorial->add_step(admin_url('admin.php?page=app_settings'), 'appointments_page_app_settings', '.notification_settings', __('Email notifications', 'appointments'), array(
		    'content'  => '<p>' . esc_js(__('There are several notification settings. Using these, you can confirm and remind your clients and also your service providers.', 'appointments' )).'</p>',
		    'position' => array( 'edge' => 'left', 'align' => 'center' ),
		));
		
		$tutorial->add_step(admin_url('admin.php?page=app_settings'), 'appointments_page_app_settings', 'select[name="use_cache"]', __('Built-in Cache', 'appointments'), array(
		    'content'  => '<p>' . esc_js(__('Appointments+ comes with a built-in specific cache. It functions only on appointment pages and caches the content part of the page only. It is recommended to enable it especially if you have a high traffic appointment page. You can continue to use other general purpose caching plugins like W3T, WP Super Cache, Quick Cache.', 'appointments' )).'</p>',
		    'position' => array( 'edge' => 'left', 'align' => 'center' ),
		));
		
		$tutorial->add_step(admin_url('admin.php?page=app_settings'), 'appointments_page_app_settings', '.button-primary', __('Save settings', 'appointments'), array(
		    'content'  => '<p>' . esc_js(__('Do not forget to save your settings.', 'appointments' )).'</p>',
		    'position' => array( 'edge' => 'left', 'align' => 'center' ),
		));
		
		$tutorial->add_step(admin_url('admin.php?page=app_settings'), 'appointments_page_app_settings', '#app_tab_working_hours', __('Setting your business hours', 'appointments'), array(
		    'content'  => '<p>' . esc_js(__('Now you should set your business working hours. Click Working Hours tab and then click Next.', 'appointments' )).'</p>',
		    'position' => array( 'edge' => 'left', 'align' => 'center' ),
		));
		
		$tutorial->add_step(admin_url('admin.php?page=app_settings&tab=working_hours'), 'appointments_page_app_settings', 'select[name="app_provider_id"]', __('Setting your business hours', 'appointments'), array(
		    'content'  => '<p>' . esc_js(__('Below you will see two tables, one for your working hours and the other for your break hours during the day. The second one is optional. On the left you will see no selection options yet. But as you add new services providers, you can set their working and break hours by selecting from this dropdown menu. This is only necessary if their working hours are different from those of your business.', 'appointments' )).'</p>',
		    'position' => array( 'edge' => 'left', 'align' => 'center' ),
		));
		
		$tutorial->add_step(admin_url('admin.php?page=app_settings&tab=working_hours'), 'appointments_page_app_settings', '.button-primary', __('Save settings', 'appointments'), array(
		    'content'  => '<p>' . esc_js(__('Do not forget to save your settings.', 'appointments' )).'</p>',
		    'position' => array( 'edge' => 'left', 'align' => 'center' ),
		));
		
		$tutorial->add_step(admin_url('admin.php?page=app_settings&tab=working_hours'), 'appointments_page_app_settings', '#app_tab_exceptions', __('Entering your holidays', 'appointments'), array(
		    'content'  => '<p>' . esc_js(__('Click the Exceptions tab to define your holidays and then click Next.', 'appointments' )).'</p>',
		    'position' => array( 'edge' => 'left', 'align' => 'center' ),
		));
		
		$tutorial->add_step(admin_url('admin.php?page=app_settings&tab=exceptions'), 'appointments_page_app_settings', 'select[name="app_provider_id"]', __('Setting exceptional days', 'appointments'), array(
		    'content'  => '<p>' . esc_js(__('Below you can define your holidays and exceptional working days, for example a specific Sunday you want to work on. These dates will override your weekly working schedule for that day only. Note that you will be able to set these exceptional days for each service provider individually, when you define them.', 'appointments' )).'</p>',
		    'position' => array( 'edge' => 'left', 'align' => 'center' ),
		));
		
		$tutorial->add_step(admin_url('admin.php?page=app_settings&tab=exceptions'), 'appointments_page_app_settings', '#app_tab_services', __('Setting your services', 'appointments'), array(
		    'content'  => '<p>' . esc_js(__('Click the Services tab to set your services and then click Next.', 'appointments' )).'</p>',
		    'position' => array( 'edge' => 'left', 'align' => 'center' ),
		));
		
		$tutorial->add_step(admin_url('admin.php?page=app_settings&tab=services'), 'appointments_page_app_settings', '#add_service', __('Setting your services', 'appointments'), array(
		    'content'  => '<p>' . esc_js(__('You can add new service by clicking this button. A default service should have been installed during installation. You can edit and even delete that too, but you should have at least one service in this table.', 'appointments' )).'</p>',
		    'position' => array( 'edge' => 'left', 'align' => 'center' ),
		));
		
		$tutorial->add_step(admin_url('admin.php?page=app_settings&tab=services'), 'appointments_page_app_settings', '.button-primary', __('Save settings', 'appointments'), array(
		    'content'  => '<p>' . esc_js(__('Do not forget to save your settings. Clicking Add New Service button does NOT save it to the database until you click the Save button.', 'appointments' )).'</p>',
		    'position' => array( 'edge' => 'left', 'align' => 'center' ),
		));
		
		$tutorial->add_step(admin_url('admin.php?page=app_settings&tab=services'), 'appointments_page_app_settings', '#app_tab_workers', __('Adding and setting your service providers', 'appointments'), array(
		    'content'  => '<p>' . esc_js(__('Click the Service Providers tab to set your service providers and then click Next.', 'appointments' )).'</p>',
		    'position' => array( 'edge' => 'right', 'align' => 'center' ),
		));
		
		$tutorial->add_step(admin_url('admin.php?page=app_settings&tab=workers'), 'appointments_page_app_settings', '#add_worker', __('Adding service providers', 'appointments'), array(
		    'content'  => '<p>' . esc_js(__('Adding service providers is optional. You may need this if the working schedule of your service providers are different or you want the client to pick a provider by his name. You can add new service provider by clicking this button. ', 'appointments' )).'</p>',
		    'position' => array( 'edge' => 'left', 'align' => 'center' ),
		));
		
		
		$tutorial->add_step(admin_url('admin.php?page=app_settings&tab=workers'), 'appointments_page_app_settings', '#app_tab_shortcodes', __('Additional Information', 'appointments'), array(
		    'content'  => '<p>' . esc_js(__('You can find detailed information about shortcode parameters on the Shortcodes page and answers to common questions on the FAQ page. Of course we will be glad to help you on our Community pages too.', 'appointments' )).'</p>',
		    'position' => array( 'edge' => 'right', 'align' => 'center' ),
		));
		
		$tutorial->add_step(admin_url('admin.php?page=app_settings&tab=workers'), 'appointments_page_app_settings', 'a.wp-first-item:contains("Appointments")', __('Appointment List', 'appointments'), array(
		    'content'  => '<p>' . esc_js(__('As you start receiving appointments, you will see them here. Click on the Appointments menu item to start the other tutorial, if you have not seen it yet.', 'appointments' )).'</p>',
		    'position' => array( 'edge' => 'left', 'align' => 'center' ),
		));
		
				
		if ( isset( $_GET["tutorial"] ) && 'restart1' == $_GET["tutorial"] )
			$tutorial->restart();
			
		//start the tutorial
		$tutorial->initialize();
		
		return $tutorial;
    }
	
	function tutorial2() {
		//load the file
		require_once( $this->plugin_dir . '/includes/pointer-tutorials.php' );
		
		//create our tutorial, with default redirect prefs
		$tutorial = new Pointer_Tutorial('app_tutorial2', true, false);
		
		//add our textdomain that matches the current plugin
		$tutorial->set_textdomain = 'appointments';
		
		//add the capability a user must have to view the tutorial
		$tutorial->set_capability = 'manage_options';
		
		$tutorial->add_icon( $this->plugin_url . '/images/large-greyscale.png' );
		
		$tutorial->add_step(admin_url('admin.php?page=appointments'), 'toplevel_page_appointments', '.info-button', __('Appointment List', 'appointments'), array(
		    'content'  => '<p>' . esc_js(__('Appointment records are grouped by their statuses. You can see these groupings by clicking the Info icon.', 'appointments' )).'</p>',
		    'position' => array( 'edge' => 'right', 'align' => 'center' ),
		));
		
		$tutorial->add_step(admin_url('admin.php?page=appointments'), 'toplevel_page_appointments', '.add-new-h2', __('Entering a Manual Appointment', 'appointments'), array(
		    'content'  => '<p>' . esc_js(__('When you received appointments from your clients, they will be added to this page automatically. But you can always add a new appointment manually. Please click the Add New link and then click Next.', 'appointments' )).'</p>',
		    'position' => array( 'edge' => 'left', 'align' => 'top' ),
		));
		
		$tutorial->add_step(admin_url('admin.php?page=appointments'), 'toplevel_page_appointments', 'select[name="status"]', __('Entering Data for the New Appointment', 'appointments'), array(
		    'content'  => '<p>' . esc_js(__('As you can see, you can enter all parameters here. Enter some random values and select status as PENDING, for this example. Then click Next', 'appointments' )).'</p>',
		    'position' => array( 'edge' => 'right', 'align' => 'center' ),
		));
		
		$tutorial->add_step(admin_url('admin.php?page=appointments'), 'toplevel_page_appointments', 'input[name="resend"]', __('Sending Confirmation emails Manually', 'appointments'), array(
		    'content'  => '<p>' . esc_js(__('If you require payment, confirmation email is automatically sent after a Paypal payment. However if you are confirming appointments manually, you should check this checkbox for a confirmation email to be sent. You can also use this option for resending the confirmation email, e.g. after rescheduling an appointment.', 'appointments' )).'</p>',
		    'position' => array( 'edge' => 'right', 'align' => 'center' ),
		));
		
		$tutorial->add_step(admin_url('admin.php?page=appointments'), 'toplevel_page_appointments', '.save', __('Entering Data for the New Appointment', 'appointments'), array(
		    'content'  => '<p>' . esc_js(__('Save and then click Next.', 'appointments' )).'</p>',
		    'position' => array( 'edge' => 'right', 'align' => 'center' ),
		));
		
		$tutorial->add_step(admin_url('admin.php?page=appointments'), 'toplevel_page_appointments', '.error', __('Entering Data for the New Appointment', 'appointments'), array(
		    'content'  => '<p>' . esc_js(__('The result is shown here. Normally you should get a success message. Otherwise it means that you have a javascript problem on admin side.', 'appointments' )).'</p>',
		    'position' => array( 'edge' => 'left', 'align' => 'center' ),
		));
		
		$tutorial->add_step(admin_url('admin.php?page=appointments'), 'toplevel_page_appointments', '.info-button', __('Save New Appointment', 'appointments'), array(
		    'content'  => '<p>' . esc_js(__('As we added this appointment as "Pending" we will see it under Pending appointments. Click Pending appointments and then click Next.', 'appointments' )).'</p>',
		    'position' => array( 'edge' => 'left', 'align' => 'center' ),
		));
		
		$tutorial->add_step(admin_url('admin.php?page=appointments&type=pending'), 'toplevel_page_appointments', '.info-button', __('Editing an Appointment', 'appointments'), array(
		    'content'  => '<p>' . esc_js(__('You can edit any appointment record. Just hover on the record and then click See Details and Edit', 'appointments' )).'</p>',
		    'position' => array( 'edge' => 'left', 'align' => 'center' ),
		));
		
		$tutorial->add_step(admin_url('admin.php?page=appointments&type=pending'), 'toplevel_page_appointments', '.cancel', __('Cancel', 'appointments'), array(
		    'content'  => '<p>' . esc_js(__('It is always possible to Cancel. Please note that these records are NOT saved until you click the Save button. Thanks for using Appointments+', 'appointments' )).'</p>',
		    'position' => array( 'edge' => 'left', 'align' => 'center' ),
		));
		
		if ( isset( $_GET["tutorial"] ) && 'restart2' == $_GET["tutorial"] )
			$tutorial->restart();
			
		//start the tutorial
		$tutorial->initialize();
		
		return $tutorial;
	}
	
}
}

$appointments = new Appointments();
global $appointments;

///////////////////////////////////////////////////////////////////////////
/* -------------------- WPMU DEV Dashboard Notice -------------------- */
if ( !class_exists('WPMUDEV_Dashboard_Notice') ) {
	class WPMUDEV_Dashboard_Notice {
		
		var $version = '2.0';
		
		function WPMUDEV_Dashboard_Notice() {
			add_action( 'plugins_loaded', array( &$this, 'init' ) ); 
		}
		
		function init() {
			if ( !class_exists( 'WPMUDEV_Update_Notifications' ) && current_user_can( 'install_plugins' ) && is_admin() ) {
				remove_action( 'admin_notices', 'wdp_un_check', 5 );
				remove_action( 'network_admin_notices', 'wdp_un_check', 5 );
				if ( file_exists(WP_PLUGIN_DIR . '/wpmudev-updates/update-notifications.php') ) {
					add_action( 'all_admin_notices', array( &$this, 'activate_notice' ), 5 );
				} else {
					add_action( 'all_admin_notices', array( &$this, 'install_notice' ), 5 );
					add_filter( 'plugins_api', array( &$this, 'filter_plugin_info' ), 10, 3 );
				}
			}
		}
		
		function filter_plugin_info($res, $action, $args) {
			global $wp_version;
			$cur_wp_version = preg_replace('/-.*$/', '', $wp_version);
		
			if ( $action == 'plugin_information' && strpos($args->slug, 'install_wpmudev_dash') !== false ) {
				$res = new stdClass;
				$res->name = 'WPMU DEV Dashboard';
				$res->slug = 'wpmu-dev-dashboard';
				$res->version = '';
				$res->rating = 100;
				$res->homepage = 'http://premium.wpmudev.org/project/wpmu-dev-dashboard/';
				$res->download_link = "http://premium.wpmudev.org/wdp-un.php?action=install_wpmudev_dash";
				$res->tested = $cur_wp_version;
				
				return $res;
			}
	
			return false;
		}
	
		function auto_install_url() {
			$function = is_multisite() ? 'network_admin_url' : 'admin_url';
			return wp_nonce_url($function("update.php?action=install-plugin&plugin=install_wpmudev_dash"), "install-plugin_install_wpmudev_dash");
		}
		
		function activate_url() {
			$function = is_multisite() ? 'network_admin_url' : 'admin_url';
			return wp_nonce_url($function('plugins.php?action=activate&plugin=wpmudev-updates%2Fupdate-notifications.php'), 'activate-plugin_wpmudev-updates/update-notifications.php');
		}
		
		function install_notice() {
			echo '<div class="error fade"><p>' . sprintf(__('Easily get updates, support, and one-click WPMU DEV plugin/theme installations right from in your dashboard - <strong><a href="%s" title="Install Now &raquo;">install the free WPMU DEV Dashboard plugin</a></strong>. &nbsp;&nbsp;&nbsp;<small><a href="http://premium.wpmudev.org/wpmu-dev/update-notifications-plugin-information/">(find out more)</a></small>', 'wpmudev'), $this->auto_install_url()) . '</a></p></div>';
		}
		
		function activate_notice() {
			echo '<div class="updated fade"><p>' . sprintf(__('Updates, Support, Premium Plugins, Community - <strong><a href="%s" title="Activate Now &raquo;">activate the WPMU DEV Dashboard plugin now</a></strong>.', 'wpmudev'), $this->activate_url()) . '</a></p></div>';
		}
	
	}
	new WPMUDEV_Dashboard_Notice();
}
