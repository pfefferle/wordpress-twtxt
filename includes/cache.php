<?php
/**
 * HTTP caching for twtxt feeds.
 */

/**
 * Let twtxt validate the rendered feed instead of WordPress post timestamps.
 *
 * WordPress can otherwise return 304 before the template runs, even when the
 * metadata has changed. Preserve the client's ETag for our own comparison.
 *
 * @param WP $wp The WordPress request.
 */
function twtxt_prepare_feed_headers( $wp ) {
	if ( empty( $wp->query_vars['feed'] ) || 'twtxt' !== $wp->query_vars['feed'] ) {
		return;
	}

	$GLOBALS['twtxt_if_none_match'] = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) : '';
	unset( $_SERVER['HTTP_IF_NONE_MATCH'], $_SERVER['HTTP_IF_MODIFIED_SINCE'] );
}
add_action( 'parse_request', 'twtxt_prepare_feed_headers' );

/**
 * Remove validators that only describe post modification dates.
 *
 * @param array $headers The HTTP response headers.
 * @param WP    $wp      The WordPress request.
 *
 * @return array The updated HTTP response headers.
 */
function twtxt_filter_feed_headers( $headers, $wp ) {
	if ( empty( $wp->query_vars['feed'] ) || 'twtxt' !== $wp->query_vars['feed'] ) {
		return $headers;
	}

	unset( $headers['ETag'], $headers['Expires'] );
	$headers['Last-Modified'] = false;
	$headers['Cache-Control'] = 'no-cache';

	return $headers;
}
add_filter( 'wp_headers', 'twtxt_filter_feed_headers', 10, 2 );

/**
 * Send an ETag for feed metadata and post text and handle conditional requests.
 *
 * Last-Modified is omitted because external filters and profile changes need
 * not change any post timestamp. Clients can revalidate the ETag on every fetch.
 *
 * @param array $metadata The feed metadata.
 * @param array $entries  The rendered post lines.
 *
 * @return bool Whether the response needs a body.
 */
function twtxt_send_feed_headers( $metadata, $entries ) {
	if ( headers_sent() ) {
		return true;
	}

	$hash = hash_init( 'sha256' );
	hash_update( $hash, hash_file( 'sha256', __DIR__ . '/../templates/feed-twtxt.php' ) );
	hash_update( $hash, wp_json_encode( $metadata ) );
	foreach ( $entries as $entry ) {
		hash_update( $hash, $entry );
	}
	$etag = '"' . hash_final( $hash ) . '"';
	header_remove( 'Last-Modified' );
	header_remove( 'Expires' );
	header( 'Cache-Control: no-cache' );
	header( 'ETag: ' . $etag );

	$client_etags = isset( $GLOBALS['twtxt_if_none_match'] ) ? $GLOBALS['twtxt_if_none_match'] : '';

	foreach ( explode( ',', $client_etags ) as $client_etag ) {
		$client_etag = trim( $client_etag );

		if ( 0 === strpos( $client_etag, 'W/' ) ) {
			$client_etag = substr( $client_etag, 2 );
		}

		// Apache appends a compression suffix after PHP has sent the ETag.
		// For GET revalidation, compressed and plain copies have the same text.
		$client_etag = preg_replace( '/-(?:gzip|br)"$/', '"', $client_etag );

		if ( '*' === $client_etag || $etag === $client_etag ) {
			status_header( 304 );
			return false;
		}
	}

	return true;
}
