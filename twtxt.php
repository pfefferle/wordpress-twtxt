<?php
/**
 * Plugin Name: TWTXT
 * Plugin URI: https://github.com/pfefferle/wordpress-twtxt
 * Description: twtxt is a decentralised, minimalist microblogging service for hackers.
 * Author: Matthias Pfefferle
 * Author URI: https://notiz.blog
 * Version: 1.0.1
 * Requires at least: 5.5
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: twtxt
 * Update URI: https://github.com/pfefferle/wordpress-twtxt
 */

/**
 * Register the feed.
 *
 * `add_feed()` already hooks the callback to `do_feed_twtxt`.
 */
function twtxt_init() {
	add_feed( 'twtxt', 'twtxt_do_feed' );
}
add_action( 'init', 'twtxt_init' );

/**
 * Flush rewrite rules on (de)activation.
 *
 * The feed has to be registered before flushing, otherwise the
 * new rules do not contain the `twtxt` endpoint.
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

	$metadata = twtxt_get_metadata();
	$entries  = array_map( 'twtxt_get_post_line', array_reverse( $GLOBALS['wp_query']->posts ) );

	if ( twtxt_send_feed_headers( $metadata, $entries ) ) {
		load_template(
			__DIR__ . '/templates/feed-twtxt.php',
			false,
			array(
				'metadata' => $metadata,
				'entries'  => $entries,
			)
		);
	}
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
 * Redirect the old `tw.txt` and `twtxt.txt` feed URLs to `twtxt`.
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
		array( '#/feed/(tw|twtxt)\.txt(/?)(\?|$)#', '#([?&])feed=(tw|twtxt)\.txt(&|$)#' ),
		array( '/feed/twtxt$2$3', '$1feed=twtxt$3' ),
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
	if ( ! $query->is_main_query() || ! $query->is_feed( 'twtxt' ) ) {
		return;
	}

	/**
	 * Filter the number of posts in the twtxt feed.
 *
 * With yearly archives, this is the minimum number retained in the main feed.
 *
 * @param int $posts_per_feed The number of posts. Default 200.
	 */
	$query->set( 'posts_per_rss', (int) apply_filters( 'twtxt_posts_per_feed', 200 ) );
	$query->set(
		'orderby',
		array(
			'date' => 'DESC',
			'ID'   => 'DESC',
		)
	);
	twtxt_set_archive_year( $query );
}
add_action( 'pre_get_posts', 'twtxt_pre_get_posts' );

/**
 * Return the text of a twtxt line for the current post.
 *
 * Uses the title and falls back to the excerpt for title-less posts.
 * Tags are stripped and control characters are replaced, since a line
 * must not contain tabs, line breaks or other control characters.
 * Longer texts are shortened at the last space and end with "…".
 *
 * @param int         $length The maximum number of characters.
 * @param WP_Post|int $post   The post. Default is the current post.
 *
 * @return string The text.
 */
function twtxt_get_the_excerpt( $length = 140, $post = null ) {
	$text = get_the_title( $post );

	if ( ! $text ) {
		$text = get_the_excerpt( $post );
	}

	$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );

	/**
	 * Filter whether excerpts preserve paragraph breaks as Unicode separators.
	 *
	 * @param bool        $multiline Whether to enable multiline output. Default false.
	 * @param WP_Post|int $post      The post being rendered.
	 */
	if ( apply_filters( 'twtxt_multiline', false, $post ) ) {
		$text = str_replace( array( "\r\n", "\r", "\n" ), "\xE2\x80\xA8", $text );
		$text = trim( preg_replace( '/[^\S\x{2028}]+|[\p{Cc}]+/u', ' ', $text ) );
	} else {
		$text = trim( preg_replace( '/[\p{Cc}\s]+/u', ' ', $text ) );
	}

	$length = max( $length, 1 );

	if ( mb_strlen( $text ) > $length ) {
		$text = mb_substr( $text, 0, $length - 1 );

		// Cut at the last space, so no word is split.
		$cut = preg_replace( '/ [^ ]*$/u', '', $text );

		if ( $cut ) {
			$text = $cut;
		}

		$text .= '…';
	}

	return $text;
}

/**
 * Return a complete, newline-terminated twtxt post line.
 *
 * @param WP_Post $post The post to render.
 *
 * @return string The timestamp and text separated by a tab.
 */
function twtxt_get_post_line( $post ) {
	$url = wp_get_shortlink( $post->ID );

	if ( ! $url ) {
		$url = get_permalink( $post );
	}

	/**
	 * Filter the maximum length of a post, including its permalink.
	 *
	 * @param int     $length Maximum number of characters. Default 140.
	 * @param WP_Post $post   The post being rendered.
	 */
	$length = max( 1, (int) apply_filters( 'twtxt_max_length', 140, $post ) );
	$text   = twtxt_get_the_excerpt( $length - mb_strlen( ' ⌘ ' . $url ), $post ) . ' ⌘ ' . $url;

	return get_post_time( 'c', true, $post ) . "\t" . $text . "\n";
}

/**
 * Build the ordered metadata used by the template and cache validator.
 *
 * @return array Metadata field names mapped to values or lists of values.
 */
function twtxt_get_metadata() {
	$metadata    = array(
		'nick'      => twtxt_get_nick(),
		'url'       => twtxt_get_canonical_url(),
		'lang'      => get_locale(),
		'generator' => 'https://github.com/pfefferle/wordpress-twtxt',
	);
	$avatar      = twtxt_get_avatar();
	$description = twtxt_get_description();
	$refresh     = twtxt_get_refresh();
	$prev        = twtxt_get_prev();
	$follows     = twtxt_get_follows();

	if ( $avatar ) {
		$metadata['avatar'] = $avatar;
	}
	if ( '' !== $description ) {
		$metadata['description'] = $description;
	}
	if ( $refresh ) {
		$metadata['refresh'] = (string) $refresh;
	}
	if ( $prev ) {
		$metadata['prev'] = $prev;
	}

	$metadata['following'] = (string) count( $follows );
	$metadata['link']      = array();
	$metadata['follow']    = array();

	foreach ( twtxt_get_links() as $label => $url ) {
		$metadata['link'][] = $label . ' ' . $url;
	}
	foreach ( $follows as $nick => $url ) {
		$metadata['follow'][] = $nick . ' ' . $url;
	}

	return $metadata;
}

/**
 * Return the nickname of the feed owner.
 *
 * @return string The nickname.
 */
function twtxt_get_nick() {
	if ( is_author() ) {
		return sanitize_title( get_the_author_meta( 'user_nicename', get_queried_object_id() ) );
	}

	return sanitize_title( get_bloginfo( 'name' ) );
}

/**
 * Return the avatar URL advertised in the feed metadata.
 *
 * @return string The avatar URL, or an empty string if none is available.
 */
function twtxt_get_avatar() {
	if ( is_author() ) {
		$avatar = get_avatar_url( get_queried_object_id(), array( 'size' => 512 ) );
	} else {
		$avatar = get_site_icon_url( 512 );
	}

	/**
	 * Filter the feed avatar URL.
	 *
	 * @param string $avatar The author avatar or site icon URL. Return an empty
	 *                       string to omit the avatar metadata.
	 */
	$avatar = apply_filters( 'twtxt_avatar', $avatar );

	if ( ! is_string( $avatar ) ) {
		return '';
	}

	$avatar = trim( $avatar );

	if ( preg_match( '/[\p{Cc}\s]/u', $avatar ) ) {
		return '';
	}

	$avatar = esc_url_raw( $avatar );

	// An explicit fragment supplied by an avatar provider already versions it.
	if ( ! $avatar || false !== strpos( $avatar, '#' ) ) {
		return $avatar;
	}

	if ( is_author() ) {
		$version = get_user_meta( get_queried_object_id(), 'twtxt_avatar_version', true );
	} else {
		$icon_id = (int) get_option( 'site_icon' );
		$version = $icon_id ? get_post_field( 'post_modified_gmt', $icon_id ) : '';
	}

	/**
	 * Filter the avatar version used to invalidate client image caches.
	 *
	 * @param string $version The site icon modification date or author profile version.
	 * @param string $avatar  The avatar URL without a fragment.
	 */
	$version = apply_filters( 'twtxt_avatar_version', $version, $avatar );

	return $avatar . '#' . substr( md5( $avatar . ':' . (string) $version ), 0, 12 );
}

/**
 * Invalidate cached author avatars when their WordPress profile changes.
 *
 * @param int $user_id The updated user ID.
 */
function twtxt_update_avatar_version( $user_id ) {
	update_user_meta( $user_id, 'twtxt_avatar_version', microtime( true ) );
}
add_action( 'profile_update', 'twtxt_update_avatar_version' );

/**
 * Return the suggested feed refresh interval in seconds.
 *
 * @return int The interval, or zero to omit the refresh hint.
 */
function twtxt_get_refresh() {
	/**
	 * Filter the suggested interval between client fetches.
	 *
	 * @param int $seconds Refresh interval in seconds. Default 300. Zero disables it.
	 */
	return max( 0, (int) apply_filters( 'twtxt_refresh', 300 ) );
}

/**
 * Return the description advertised in the feed metadata.
 *
 * @return string The description, or an empty string if none is available.
 */
function twtxt_get_description() {
	if ( is_author() ) {
		$description = get_the_author_meta( 'description', get_queried_object_id() );
	} else {
		$description = get_bloginfo( 'description' );
	}

	/**
	 * Filter the feed description.
	 *
	 * @param string $description The author bio or site tagline. Return an empty
	 *                            string to omit the description metadata.
	 */
	$description = apply_filters( 'twtxt_description', $description );

	if ( ! is_string( $description ) ) {
		return '';
	}

	$description = html_entity_decode( wp_strip_all_tags( $description ), ENT_QUOTES, 'UTF-8' );

	return trim( (string) preg_replace( '/[\p{Cc}\s]+/u', ' ', $description ) );
}

/**
 * Return the links advertised in the feed metadata.
 *
 * @return array Link labels mapped to URLs.
 */
function twtxt_get_links() {
	$links = array( 'Blog' => home_url( '/' ) );

	if ( is_author() ) {
		$website = get_the_author_meta( 'user_url', get_queried_object_id() );

		if ( $website ) {
			$links['Website'] = $website;
		}
	}

	/**
	 * Filter the links advertised in the feed metadata.
	 *
	 * @param array $links Link labels mapped to URLs. Labels may contain spaces.
	 */
	return twtxt_sanitize_metadata_links( apply_filters( 'twtxt_links', $links ) );
}

/**
 * Return the followed feeds advertised in the feed metadata.
 *
 * @return array Nicknames mapped to feed URLs.
 */
function twtxt_get_follows() {
	$follows = array();

	if ( is_author() ) {
		$follows[ sanitize_title( get_bloginfo( 'name' ) ) ] = get_feed_link( 'twtxt' );
	} else {
		$authors = get_users(
			array(
				'has_published_posts' => true,
				'fields'              => array( 'ID', 'user_nicename' ),
			)
		);

		foreach ( $authors as $author ) {
			$follows[ sanitize_title( $author->user_nicename ) ] = str_replace( '&amp;', '&', get_author_feed_link( $author->ID, 'twtxt' ) );
		}
	}

	/**
	 * Filter the publicly advertised followed feeds.
	 *
	 * @param array $follows Nicknames mapped to feed URLs. Defaults to the blog
	 *                       on author feeds and published authors on other feeds.
	 */
	return twtxt_sanitize_metadata_links( apply_filters( 'twtxt_follows', $follows ) );
}

/**
 * Keep metadata labels and URLs on a single physical line.
 *
 * URLs are plain text, so preserve URI schemes such as gopher and im.
 * Skip empty labels and URLs without a scheme or containing whitespace.
 *
 * @param array $links Labels or nicknames mapped to URLs.
 *
 * @return array Sanitized labels mapped to URLs.
 */
function twtxt_sanitize_metadata_links( $links ) {
	$sanitized = array();

	foreach ( (array) $links as $label => $url ) {
		if ( ! is_string( $url ) ) {
			continue;
		}

		$label = html_entity_decode( wp_strip_all_tags( (string) $label ), ENT_QUOTES, 'UTF-8' );
		$label = trim( preg_replace( '/[\p{Cc}\s]+/u', ' ', $label ) );
		$url   = trim( $url );

		if ( '' === $label || ! preg_match( '/^[a-z][a-z0-9+.-]*:[^\p{Cc}\s]+$/iu', $url ) ) {
			continue;
		}

		$sanitized[ $label ] = $url;
	}

	return $sanitized;
}

/**
 * Return the feed URL to advertise on the current page.
 *
 * The feed itself works on every archive (`/tag/abc/feed/twtxt`),
 * this is only used for discovery on the blog index and author pages.
 *
 * @return string The feed URL.
 */
function twtxt_get_feed_url() {
	if ( is_author() ) {
		// `get_author_feed_link()` HTML-encodes the ampersand for plain permalinks.
		return str_replace( '&amp;', '&', get_author_feed_link( get_queried_object_id(), 'twtxt' ) );
	}

	return get_feed_link( 'twtxt' );
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

require_once __DIR__ . '/includes/cache.php';
require_once __DIR__ . '/includes/archives.php';
