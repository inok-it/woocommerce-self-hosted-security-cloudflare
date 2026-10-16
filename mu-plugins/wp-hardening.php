<?php
/*
Plugin Name: WP Hardening & WooCommerce Optimizer (MU-Plugin)
Description: Blocks REST API user enumeration, masks login errors, strips version fingerprints, trims Action Scheduler bloat, and optimizes WooCommerce AJAX.
Version: 1.0.0
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 1. Block Unauthenticated REST API User Enumeration
add_filter( 'rest_endpoints', function( $endpoints ) {
    if ( ! is_user_logged_in() ) {
        if ( isset( $endpoints['/wp/v2/users'] ) ) {
            unset( $endpoints['/wp/v2/users'] );
        }
        if ( isset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] ) ) {
            unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
        }
    }
    return $endpoints;
});

// 2. Mask Verbose Login Errors (Prevent Username Discovery)
add_filter( 'login_errors', function() {
    return 'Nieprawidłowa nazwa użytkownika lub hasło.';
});

// 3. Strip WordPress Version Fingerprints & oEmbed Links
remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
remove_action( 'wp_head', 'wp_generator' );
add_filter( 'the_generator', '__return_empty_string' );

function inogi_remove_wp_version_from_assets( $src ) {
    if ( strpos( $src, 'ver=' . get_bloginfo( 'version' ) ) ) {
        $src = remove_query_arg( 'ver', $src );
    }
    return $src;
}
add_filter( 'style_loader_src', 'inogi_remove_wp_version_from_assets', 9999 );
add_filter( 'script_loader_src', 'inogi_remove_wp_version_from_assets', 9999 );

// 4. Trim WooCommerce Action Scheduler Log Retention to 3 Days (Default is 30)
add_filter( 'action_scheduler_retention_period', function() {
    return DAY_IN_SECONDS * 3;
});
