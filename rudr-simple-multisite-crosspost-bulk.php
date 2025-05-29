<?php
/*
 * Plugin name: Simple Multisite Crossposting – Bulk Actions
 * Author: Misha Rudrastyh
 * Author URI: https://rudrastyh.com
 * Description: Allows to crosspost multiple posts at once.
 * Version: 2.1
 * Plugin URI: https://rudrastyh.com/support/bulk-crossposting
 * Network: true
 */

class Rudr_SMC_Bulk{

	const PER_TICK = 20;

	function __construct(){
		add_action( 'admin_init', array( $this, 'init' ), 999 );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		// cron
		add_action( 'rudr_smc_bulk', array( $this, 'run_cron' ), 10, 2 );
		add_filter( 'cron_schedules', array( $this, 'cron_intervals' ) );
		add_action( 'admin_footer', array( $this, 'js' ) );
	}

	// bulk action hooks
	public function init(){

		// do absolutely nothing is the main plugin is not activated
		if( ! class_exists( 'Rudr_Simple_Multisite_Crosspost' ) ) {
			return;
		}

		$post_types = Rudr_Simple_Multisite_Crosspost::get_allowed_post_types();
		if( $post_types ) {
			foreach( $post_types as $post_type ) {
				add_filter( 'bulk_actions-edit-' . $post_type, array( $this, 'bulk_action' ) );
				add_filter( 'handle_bulk_actions-edit-' . $post_type, array( $this, 'do_bulk_action' ), 10, 3 );
			}
		}

	}

	// display the bulk actions
	public function bulk_action( $bulk_array ) {

		$blogs = Rudr_Simple_Multisite_Crosspost::get_blogs();
		if( $blogs ) {
			foreach( $blogs as $blog_id => $blogname ) {
				$bulk_array[ 'crosspost_to_' . absint( $blog_id ) ] = 'Crosspost to ' . esc_attr( $blogname );
			}
		}
		return $bulk_array;

	}


	// run the actions
	public function do_bulk_action( $redirect, $doaction, $object_ids ){

		set_time_limit(300);

		// first, remove errors query args
		$redirect = remove_query_arg(
			array( 'rudr_crosspost_too_much_to_crosspost', 'rudr_connected', 'rudr_crossposted' ),
			$redirect
		);

		// bulk action check
		if( 'crosspost_to_' !== substr( $doaction, 0, 13 ) ) {
			return $redirect;
		}

		// get a post type
		$screen = get_current_screen();
		$post_type = ! empty( $screen->post_type ) ? $screen->post_type : false;
		// just in case
		if( ! $post_type ) {
			return $redirect;
		}

		// extract blog ID from bulk action
		$blog_id = str_replace( 'crosspost_to_', '', $doaction );

		// depending on how many objects have been selected we may additionally run a cron task
		// 20 objects per iteration seems pretty safe, depends of course, but for the majority
		if( count( $object_ids ) > self::PER_TICK ) {
			$this->start_cron( $object_ids, $blog_id, $post_type );
		}

		$this->do_bulk( array_slice( $object_ids, 0, self::PER_TICK ), $blog_id, $post_type );

		return add_query_arg( array( 'rudr_crossposted' => count( $object_ids ) ), $redirect );

	}


	// doing the bulk
	private function do_bulk( $object_ids, $blog_id, $post_type ) {

		// a lot of fields are exluded in bulk edit, we can not exclude them here as well
		unset( $_REQUEST[ '_wpnonce' ] );

		// we will need to double check whether this status is allowed
		$allowed_post_statuses = ( $allowed_post_statuses = get_site_option( 'rudr_smc_post_statuses' ) ) ? $allowed_post_statuses : array( 'publish' );

		if( function_exists( 'wc_get_product' ) && 'product' === $post_type ) {
			$c = new Rudr_Simple_Multisite_Woo_Crosspost();
		} else {
			$c = new Rudr_Simple_Multisite_Crosspost();
		}

		foreach ( $object_ids as $object_id ) {
			// check the object itself
			$object = get_post( $object_id );
			if( ! $object ) {
				continue;
			}
			// now let's check the status
			if ( ! in_array( $object->post_status, $allowed_post_statuses ) ) {
				continue;
	    }

			// I think we are ready, start with updating a meta key
			update_post_meta( $object_id, '_crosspost_to_' . $blog_id, true );

			$blog_ids = array();
			$blog_ids[] = $blog_id;

			if( function_exists( 'wc_get_product' ) && 'product' === $object->post_type ) {
				$c->crosspost_product( $object_id, $blog_ids );
			} else {
				$c->crosspost( $object, $blog_ids );
			}

			do_action( 'save_post', $object_id, $object, true ); // TODO add $update parameter value
		}

	}


	public function cron_intervals( $intervals ) {

		$intervals[ 'smc_min' ] = array(
			'interval' => 60,
			'display' => 'Every min (Simple Multisite Crossposting)'
		);
		return $intervals;

	}

	// starting the cron job
	private function start_cron( $object_ids, $blog_id, $post_type ) {
		if( ! wp_next_scheduled( 'rudr_smc_bulk', array( (int) $blog_id, $post_type ) ) ) {
			wp_schedule_event( time() + 30, 'smc_min', 'rudr_smc_bulk', array( (int) $blog_id, $post_type ) );
		} else {
			// TODO maybe we can display some error messages
			return;
		}

		update_option( "rudr_smc_bulk_{$post_type}_ids_total", $object_ids );
		update_option( "rudr_smc_bulk_{$post_type}_ids", array_slice( $object_ids, self::PER_TICK ) );
		delete_option( "rudr_smc_bulk_{$post_type}_finished" );

	}

	// doing the cron job iteration
	public function run_cron( $blog_id, $post_type ) {

		// get remaining object IDs first
		$object_ids = get_option( "rudr_smc_bulk_{$post_type}_ids" );

		if( $object_ids ) {
			// run sync
			$this->do_bulk( array_slice( $object_ids, 0, self::PER_TICK ), $blog_id, $post_type );

			if( count( $object_ids ) > self::PER_TICK ) {
				// remove first 10 objects
				$object_ids = array_slice( $object_ids, self::PER_TICK );
				// update option
				update_option( "rudr_smc_bulk_{$post_type}_ids", $object_ids );
				return;
			}
		}

		delete_option( "rudr_smc_bulk_{$post_type}_ids" );
		update_option( "rudr_smc_bulk_{$post_type}_finished", 'yes' );
		// unschedule cron
		wp_clear_scheduled_hook( 'rudr_smc_bulk', array( $blog_id, $post_type ) );

	}


	public function notices(){

		// get some screen information about post type etc
		$screen = get_current_screen();
		$post_type = ! empty( $screen->post_type ) ? $screen->post_type : false;
		// something is not right here
		if( 'edit' !== $screen->base || ! $post_type ) {
			return;
		}

		if( ! class_exists( 'Rudr_Simple_Multisite_Crosspost' ) ) {
			return;
		}

		$display_notices = true;

		// seems like we need to loop all the blogs and check whhether a cron job is running
		$blogs = Rudr_Simple_Multisite_Crosspost::get_blogs();
		// if there is no blogs added, nothing else to do here anyway
		if( ! $blogs ) {
			return;
		}

		// post type object will be useful
		$post_type_object = get_post_type_object( $post_type );
		// we are going to display blog names in some notifications
		$use_domains = apply_filters( 'rudr_smc_use_domains_as_names', false );

		// Schedule action message

		foreach( $blogs as $blog_id => $blogname ) {
			if( wp_next_scheduled( 'rudr_smc_bulk', array( (int) $blog_id, $post_type ) ) ) {
				if( $use_domains ) {
					$blog = get_site( $blog_id );
					$blogname = $blog->domain . $blog->path;
				}
				$display_notices = false;
				?><div class="notice-info notice smc-bulk-notice--in-progress"><p><?php echo esc_html( sprintf( '%s are currently being crossposted to %s in the background. It may take some time depending on how many %s you have selected.', $post_type_object->label, $blogname, mb_strtolower( $post_type_object->label ) ) ) ?></p></div><?php
			}
		}

		$object_ids = isset( $_REQUEST[ 'rudr_crossposted' ] ) && $_REQUEST[ 'rudr_crossposted' ] ? absint( $_REQUEST[ 'rudr_crossposted' ] ) : 0;
		if( $display_notices ) {

			if( $object_ids && $object_ids <= self::PER_TICK ) {

				if( $object_ids > 0 ) {
					?><div class="updated notice is-dismissible"><p><?php
					echo esc_html( sprintf(
						'' . _n( '%d %s has been successfully crossposted.', '%d %s have been successfully crossposted.', $object_ids ),
						$object_ids,
						mb_strtolower( $object_ids > 1 ? $post_type_object->label : $post_type_object->labels->singular_name )
					) );
					?></p></div><?php
				}

			} elseif( 'yes' == get_option( "rudr_smc_bulk_{$post_type}_finished" ) ) {

				delete_option( "rudr_smc_bulk_{$post_type}_finished" );
				$object_ids = get_option( "rudr_smc_bulk_{$post_type}_ids_total", array() );
				$object_ids = is_array( $object_ids ) ? count( $object_ids ) : 0;

				if( $object_ids > 0 ) {
					?><div class="updated notice is-dismissible"><p><?php
					echo esc_html( sprintf(
						'' . _n( '%d %s has been successfully crossposted.', '%d %s have been successfully crossposted.', $object_ids ),
						$object_ids,
						mb_strtolower( $object_ids > 1 ? $post_type_object->label : $post_type_object->labels->singular_name )
					) );
					?></p></div><?php
				}

			}
		}



	}

	// JS check, the beautiful way
	public function js() {
		?><script>
		jQuery( function( $ ) {
			if( $( '.smc-bulk-notice--in-progress' ).length > 0 ) {
				$( '#bulk-action-selector-top option, #bulk-action-selector-bottom option' ).each( function() {
					if( $(this).val().startsWith( 'crosspost_to_' ) ) {
						$(this).prop( 'disabled', true );
					}
				} );
			}
		});
		</script><?php
	}

}
new Rudr_SMC_Bulk;
