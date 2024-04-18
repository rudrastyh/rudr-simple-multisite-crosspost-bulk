<?php
/*
 * Plugin name: Simple Multisite Crossposting – Bulk Actions
 * Author: Misha Rudrastyh
 * Author URI: https://rudrastyh.com
 * Description: Allows to crosspost multiple posts at once.
 * Version: 1.4
 * Network: true
 */

// Add Bulk Actions for Any Existing Post Type
add_action( 'admin_init', function() {

	if( ! class_exists( 'Rudr_Simple_Multisite_Crosspost' ) ) {
		return;
	}

	$post_types = get_post_types( array( 'public' => true ) );
	$allowed_post_types = ( $allowed_post_types = get_site_option( 'rudr_smc_post_types' ) ) ? $allowed_post_types : array();
	$allowed_post_types = apply_filters( 'rudr_crosspost_allowed_post_types', $allowed_post_types );

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

	$redirect = remove_query_arg(
		array( 'rudr_crosspost_too_much_to_crosspost', 'rudr_crosspost_done' ),
		$redirect
	);

	if( 'crosspost_to_' === substr( $doaction, 0, 13 ) ) {

		if( count( $object_ids ) > 40 ) {
			return add_query_arg( 'rudr_crosspost_too_much_to_crosspost', count( $object_ids ), $redirect );
		}

		$blog_id = str_replace( 'crosspost_to_', '', $doaction );

		// let's set a coupld $_POST parameters to trick the plugin
		$_POST[ 'cms_custom_nonce' ] = wp_create_nonce( 'cms-metabox-check' );
		$_POST[ 'action' ] = 'editpost';

		foreach ( $object_ids as $object_id ) {
			if( $object = get_post( $object_id ) ) {
				$_POST[ '_crosspost_to_' . $blog_id ] = true;
				do_action( 'save_post', $object_id, $object, true );
			}
		}

		$redirect = add_query_arg( 'rudr_crosspost_done', count( $object_ids ), $redirect );


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

	if( ! empty( $_REQUEST[ 'rudr_crosspost_done' ] ) ) {

		printf( '<div id="message" class="updated notice is-dismissible"><p>' .
			_n( '%s post has been successfully crossposted.', '%s posts have been successfully crossposted.', absint( $_REQUEST[ 'rudr_crosspost_done' ] ) )
			. '</p></div>', absint( $_REQUEST[ 'rudr_crosspost_done' ] ) );

	}

}
