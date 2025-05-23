<?php
/**
 * Plugin Name: TWTXT
 * Plugin URI: https://github.com/pfefferle/wordpress-twtxt
 * Description: twtxt is a decentralised, minimalist microblogging service for hackers.
 * Author: Matthias Pfefferle
 * Author URI: https://notiz.blog
 * Version: 1.0.1
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: twtxt
 * Update URI: https://github.com/pfefferle/wordpress-twtxt
 */

register_activation_hook( __FILE__, 'twtxt_flush_rewrite_rules' );
register_deactivation_hook( __FILE__, 'twtxt_flush_rewrite_rules' );

/**
 * Init function
 */
function twtxt_init() {
	add_filter( 'feed_content_type', 'twtxt_feed_content_type', 10, 2 );

	add_feed( 'twtxt', 'do_feed_twtxt' );
	add_feed( 'twtxt.txt', 'do_feed_twtxt' );
	add_feed( 'tw.txt', 'do_feed_twtxt' );

	add_action( 'do_feed_twtxt', 'do_feed_twtxt', 10, 1 );
}
add_action( 'init', 'twtxt_init' );

/**
 * Adds "twtxt" content-type
 *
 * @param string $content_type the default content-type
 * @param string $type the feed-type
 *
 * @return string the as1 content-type
 */
function twtxt_feed_content_type( $content_type, $type ) {
	if ( in_array( $type, array( 'twtxt', 'twtxt.txt', 'tw.txt' ) ) ) {
		return 'text/plain';
	}

	return $content_type;
}

/**
 * Adds an twtxt json feed
 */
function do_feed_twtxt( $for_comments ) {
	if ( ! $for_comments ) {
		// load post template
		load_template( dirname( __FILE__ ) . '/templates/feed-twtxt.php' );
	}
}

/**
 * Reset rewrite rules
 */
function twtxt_flush_rewrite_rules() {
	global $wp_rewrite;
	$wp_rewrite->flush_rules();
}

/**
 * Retunr the excerpt of the blogpost
 *
 * @param integer $length The length of the text.
 *
 * @return string The shortened text.
 */
function twtxt_get_the_excerpt( $length = 100 ) {
	if ( get_the_title() ) {
		$excerpt = get_the_title();
	} else {
		$excerpt = get_the_excerpt();
	}

	$excerpt        = html_entity_decode( htmlspecialchars_decode( $excerpt ) );
	$excerpt_length = apply_filters( 'excerpt_length', $length );
	$excerpt_more   = apply_filters( 'excerpt_more', ' [...]' );

	return wp_trim_words( $excerpt, $excerpt_length, $excerpt_more );
}

/**
 * Return the nickname of the feed owner.
 *
 * @return string The nickname.
 */
function twtxt_get_nick() {
	if ( is_author() ) {
		return sanitize_title( get_the_author_meta( 'user_nicename' ) );
	}

	return sanitize_title( get_bloginfo( 'name' ) );
}

/**
 * Return the generator of the feed.
 *
 * @return string The generator.
 */
function twtxt_get_generator() {
	return sanitize_url( 'https://github.com/pfefferle/wordpress-twtxt' );
}

/**
 * Adds a discovery header to the feed
 */
function twtxt_add_discovery_header() {
	$feed_url = get_feed_link( 'tw.txt' );

	if ( ! $feed_url ) {
		return;
	}

	if ( ! is_home() && ! is_author() ) {
		return;
	}

	if ( ! headers_sent() ) {
		header( 'Link: <' . esc_url( $feed_url ) . '>; rel="alternate"; type="text/plain"; title="twtxt"' );
	}

	echo '<link rel="alternate" type="text/plain" title="twtxt" href="' . esc_url( $feed_url ) . '" />' . PHP_EOL;
}
add_action( 'wp_head', 'twtxt_add_discovery_header' );