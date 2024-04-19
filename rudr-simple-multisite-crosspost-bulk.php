<?php
/*
 * Plugin name: Simple Multisite Crossposting – Bulk Actions
 * Author: Misha Rudrastyh
 * Author URI: https://rudrastyh.com
 * Description: Allows to crosspost multiple posts at once.
 * Version: 1.5
 * Network: true
 */

// Add Bulk Actions for Any Existing Post Type
add_action( 'admin_init', function() {

	if( ! class_exists( 'Rudr_Simple_Multisite_Crosspost' ) ) {
		return;
	}

	$post_types = get_post_types( array( 'show_ui' => true ) );
	$allowed_post_types = ( $allowed_post_types = get_site_option( 'rudr_smc_post_types' ) ) ? $allowed_post_types : array();

	if( $allowed_post_types && is_array( $allowed_post_types ) ) {
		$post_types = array_intersect( $post_types, $allowed_post_types );
	}

	foreach( $post_types as $post_type ) {
		add_filter( 'bulk_actions-edit-' . $post_type, 'rudr_crosspost_bulk_actions' );
		add_filter( 'handle_bulk_actions-edit-' . $post_type, 'rudr_crosspost_handle_bulk_actions', 10, 3 );
	}

}, 9999 );

// Add Bulk Options here
function rudr_crosspost_bulk_actions( $bulk_array ) {

	$blogs = Rudr_Simple_Multisite_Crosspost::get_blogs();

	if( $blogs ) {
		foreach( $blogs as $blog_id => $blog_name ) {
			$bulk_array[ 'crosspost_to_'.absint($blog_id) ] = 'Crosspost to ' . esc_attr( $blog_name );
		}
	}
	return $bulk_array;

}

// Doing Crosspost
function rudr_crosspost_handle_bulk_actions( $redirect, $doaction, $object_ids ) {

	if( 'crosspost_to_' === substr( $doaction, 0, 13 ) ) {

		$redirect = remove_query_arg(
			array( 'rudr_crosspost_too_much_to_crosspost', 'rudr_connected', 'rudr_crossposted' ),
			$redirect
		);

		if( count( $object_ids ) > 40 ) {
			return add_query_arg( 'rudr_crosspost_too_much_to_crosspost', count( $object_ids ), $redirect );
		}

		$blog_id = str_replace( 'crosspost_to_', '', $doaction );

		// behaving like it is a normal post update, not a bulk edit thing
		$_POST[ 'cms_custom_nonce' ] = wp_create_nonce( 'cms-metabox-check' );
		$_POST[ 'action' ] = 'editpost';
		unset( $_REQUEST[ '_wpnonce' ] );

		foreach ( $object_ids as $object_id ) {
			if( $object = get_post( $object_id ) ) {
				$_POST[ '_crosspost_to_' . $blog_id ] = true;
				do_action( 'save_post', $object_id, $object, true );
			}
		}

		$redirect = add_query_arg( 'rudr_crossposted', count( $object_ids ), $redirect );


	}

	return $redirect;

}

// Doing notices
add_action( 'admin_notices', 'misha_bulk_action_notices' );

function misha_bulk_action_notices() {


	// but you can create an awesome message
	if( ! empty( $_REQUEST[ 'rudr_crosspost_too_much_to_crosspost' ] ) ) {

		// depending on ho much posts were changed, make the message different
		echo '<div id="message" class="error notice is-dismissible"><p>You selected more than 40 items to crosspost. I do not recommend to select so many at once, because it could be too much for the server to process.</p></div>';

	}

	if( ! empty( $_REQUEST[ 'rudr_crossposted' ] ) ) {

		printf( '<div id="message" class="updated notice is-dismissible"><p>' .
			_n( '%s item has been successfully crossposted.', '%s items have been successfully crossposted.', absint( $_REQUEST[ 'rudr_crossposted' ] ) )
			. '</p></div>', absint( $_REQUEST[ 'rudr_crossposted' ] ) );

	}

}
