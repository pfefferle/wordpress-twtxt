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

/**
 * Register the feed.
 *
 * `add_feed()` already hooks the callback to `do_feed_tw.txt`.
 */
function twtxt_init() {
	add_feed( 'tw.txt', 'twtxt_do_feed' );
}
add_action( 'init', 'twtxt_init' );

/**
 * Flush rewrite rules on (de)activation.
 *
 * The feed has to be registered before flushing, otherwise the
 * new rules do not contain the `tw.txt` endpoint.
 */
function twtxt_activate() {
	twtxt_init();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'twtxt_activate' );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

/**
 * Render the feed.
 *
 * @param bool $for_comments Whether this is a comment feed.
 */
function twtxt_do_feed( $for_comments ) {
	if ( $for_comments ) {
		return;
	}

	load_template( __DIR__ . '/templates/feed-twtxt.php' );
}

/**
 * Serve the feed as plain text.
 *
 * @param string $content_type The default content-type.
 * @param string $type         The feed type.
 *
 * @return string The content-type.
 */
function twtxt_feed_content_type( $content_type, $type ) {
	if ( in_array( $type, array( 'tw.txt', 'twtxt', 'twtxt.txt' ), true ) ) {
		return 'text/plain';
	}

	return $content_type;
}
add_filter( 'feed_content_type', 'twtxt_feed_content_type', 10, 2 );

/**
 * Redirect the old `twtxt` and `twtxt.txt` feed URLs to `tw.txt`.
 *
 * The old names are not registered as feeds, so nothing is added to
 * the feed types. Only the feed name in the request URI is swapped,
 * the context (author, tag, ...) stays intact.
 */
function twtxt_redirect_legacy_feed() {
	if ( ! is_404() && ! is_feed() ) {
		return;
	}

	$uri = wp_unslash( $_SERVER['REQUEST_URI'] );
	$new = preg_replace(
		array( '#/feed/twtxt(\.txt)?(/?)(\?|$)#', '#([?&])feed=twtxt(\.txt)?(&|$)#' ),
		array( '/feed/tw.txt$2$3', '$1feed=tw.txt$3' ),
		$uri,
		1
	);

	if ( $new === $uri ) {
		return;
	}

	wp_safe_redirect( set_url_scheme( sprintf( 'http://%s%s', $_SERVER['HTTP_HOST'], $new ) ), 301 );
	exit;
}
add_action( 'template_redirect', 'twtxt_redirect_legacy_feed', 1 );

/**
 * Raise the post limit for the twtxt feed.
 *
 * twtxt clients read the whole file, so return more posts than the
 * usual RSS limit.
 *
 * @param WP_Query $query The main query.
 */
function twtxt_pre_get_posts( $query ) {
	if ( ! $query->is_main_query() || ! $query->is_feed( 'tw.txt' ) ) {
		return;
	}

	/**
	 * Filter the number of posts in the twtxt feed.
	 *
	 * @param int $posts_per_feed The number of posts. Default 200.
	 */
	$query->set( 'posts_per_rss', (int) apply_filters( 'twtxt_posts_per_feed', 200 ) );
}
add_action( 'pre_get_posts', 'twtxt_pre_get_posts' );

/**
 * Return the text of a twtxt line for the current post.
 *
 * Uses the title and falls back to the excerpt for title-less posts.
 * Tags are stripped and control characters are replaced, since a line
 * must not contain tabs, line breaks or other control characters.
 *
 * @param int $length The maximum number of words.
 *
 * @return string The text.
 */
function twtxt_get_the_excerpt( $length = 100 ) {
	$text = get_the_title();

	if ( ! $text ) {
		$text = get_the_excerpt();
	}

	$text = wp_trim_words( $text, $length, '…' );
	$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );

	// Decoding can bring back tabs and line breaks (`&#9;`, `&#10;`).
	return trim( preg_replace( '/\p{Cc}+/u', ' ', $text ) );
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
 * Return the feed URL to advertise on the current page.
 *
 * The feed itself works on every archive (`/tag/abc/feed/tw.txt`),
 * this is only used for discovery on the blog index and author pages.
 *
 * @return string The feed URL.
 */
function twtxt_get_feed_url() {
	if ( is_author() ) {
		// `get_author_feed_link()` HTML-encodes the ampersand for plain permalinks.
		return str_replace( '&amp;', '&', get_author_feed_link( get_queried_object_id(), 'tw.txt' ) );
	}

	return get_feed_link( 'tw.txt' );
}

/**
 * Whether the current page should advertise the feed.
 *
 * @return bool True on the blog index and author archives.
 */
function twtxt_is_discoverable() {
	return ! is_feed() && ( is_home() || is_author() );
}

/**
 * Send the discovery `Link` header.
 *
 * Has to run before any output, `wp_head` is too late for headers.
 */
function twtxt_send_headers() {
	if ( ! twtxt_is_discoverable() || headers_sent() ) {
		return;
	}

	header( sprintf( 'Link: <%s>; rel="alternate"; type="text/plain"; title="%s"', esc_url_raw( twtxt_get_feed_url() ), __( 'twtxt', 'twtxt' ) ), false );
}
add_action( 'template_redirect', 'twtxt_send_headers' );

/**
 * Add the discovery `<link>` to the HTML head.
 */
function twtxt_add_discovery_link() {
	if ( ! twtxt_is_discoverable() ) {
		return;
	}

	printf( '<link rel="alternate" type="text/plain" title="%s" href="%s" />' . PHP_EOL, esc_attr__( 'twtxt', 'twtxt' ), esc_url( twtxt_get_feed_url() ) );
}
add_action( 'wp_head', 'twtxt_add_discovery_link' );
