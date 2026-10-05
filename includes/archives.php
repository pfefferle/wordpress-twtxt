<?php
/**
 * Stable feed identities and yearly archives.
 */

/**
 * Register the public archive selector.
 *
 * @param array $vars The public query variables.
 *
 * @return array The updated query variables.
 */
function twtxt_query_vars( $vars ) {
	$vars[] = 'twtxt_year';
	return $vars;
}
add_filter( 'query_vars', 'twtxt_query_vars' );

/**
 * Select complete calendar years for a main twtxt query.
 *
 * Date and singular feeds keep their original scope. Archive feeds retain the
 * same author and taxonomy constraints as their main feed. The main feed keeps
 * at least the configured post count, extending back through complete years.
 *
 * @param WP_Query $query The main query.
 */
function twtxt_set_archive_year( $query ) {
	/**
	 * Filter whether the feed uses yearly archives.
	 *
	 * @param bool $enabled Whether to enable archives. Default true.
	 */
	if ( ! apply_filters( 'twtxt_archives', true ) || $query->is_date() || $query->is_singular() ) {
		return;
	}

	$current_year = (int) current_time( 'Y' );
	$requested    = $query->get( 'twtxt_year' );
	$year         = is_scalar( $requested ) ? (int) $requested : 0;

	if ( $year >= 1 && $year <= $current_year ) {
		$query->set( 'year', $year );
	} else {
		$recent                   = $query->query_vars;
		$recent['posts_per_rss']  = max( 1, (int) $query->get( 'posts_per_rss' ) );
		$recent['posts_per_page'] = $recent['posts_per_rss'];
		$recent['nopaging']       = false;
		$recent['paged']          = 1;
		$recent['no_found_rows']  = true;
		$recent['fields']         = 'ids';
		$recent                   = new WP_Query( $recent );
		$oldest                   = $recent->posts ? get_post( end( $recent->posts ) ) : null;
		$year                     = $oldest ? min( $current_year, (int) substr( $oldest->post_date, 0, 4 ) ) : $current_year;
		$date_query               = $query->get( 'date_query' );
		$date_query               = is_array( $date_query ) ? $date_query : array();
		$date_query[]             = array(
			'after'     => array(
				'year'  => $year,
				'month' => 1,
				'day'   => 1,
			),
			'inclusive' => true,
		);
		$query->set( 'date_query', $date_query );
		$query->set( 'twtxt_main_years', true );
	}

	$query->set( 'twtxt_archive_year', $year );
	$query->set( 'posts_per_rss', -1 );
	$query->set( 'posts_per_page', -1 );
	$query->set( 'nopaging', true );
	$query->set( 'paged', 1 );
	$query->set( 'no_found_rows', true );
}

/**
 * Remove WordPress's forced RSS query limit for complete yearly feeds.
 *
 * @param string   $limits The SQL LIMIT clause.
 * @param WP_Query $query  The post query.
 *
 * @return string An empty clause for yearly feeds, otherwise the original limit.
 */
function twtxt_archive_limits( $limits, $query ) {
	return $query->get( 'twtxt_archive_year' ) ? '' : $limits;
}
add_filter( 'post_limits', 'twtxt_archive_limits', 10, 2 );

/**
 * Return a stable identity shared by the main feed and its archives.
 *
 * Remember the first clean URL for each feed context, including its trailing
 * slash. Later request aliases and archive selectors must not change twt hashes.
 *
 * @return string The primary feed URL.
 */
function twtxt_get_canonical_url() {
	global $wp_query;

	$context = array();
	$fields  = array( 'post_type', 'cat', 'tag', 'tag_id', 'taxonomy', 'term', 'p', 'name', 'page_id', 'pagename', 's', 'attachment_id' );

	if ( is_author() ) {
		$context['author'] = get_queried_object_id();
	} else {
		$fields[] = 'author';
	}

	if ( ! $wp_query->get( 'twtxt_archive_year' ) ) {
		$fields = array_merge( $fields, array( 'year', 'monthnum', 'day', 'm', 'w' ) );
	}

	foreach ( $fields as $field ) {
		$value = $wp_query->get( $field );
		if ( ! empty( $value ) ) {
			$context[ $field ] = $value;
		}
	}

	$key  = md5( wp_json_encode( $context ) );
	$urls = get_option( 'twtxt_feed_urls', array() );

	if ( empty( $urls[ $key ] ) ) {
		$url   = get_self_link();
		$query = wp_parse_url( $url, PHP_URL_QUERY );
		parse_str( (string) $query, $params );
		$keep = array_merge( $fields, array( 'feed', 'author', 'author_name' ) );
		$url  = remove_query_arg( array_diff( array_keys( $params ), $keep ), $url );
		$url  = esc_url_raw( apply_filters( 'self_link', $url ) );

		$urls[ $key ] = $url;
		update_option( 'twtxt_feed_urls', $urls, false );
	}

	/**
	 * Filter the stable primary URL used for feed identity and twt hashes.
	 *
	 * @param string $url The remembered primary feed URL. Keep existing identities
	 *                    when customizing this value.
	 */
	return esc_url_raw( apply_filters( 'twtxt_feed_url', $urls[ $key ] ) );
}

/**
 * Return the previous nonempty year's hash and relative archive URL.
 *
 * @return string The prev field value, or an empty string at the end of history.
 */
function twtxt_get_prev() {
	global $wp_query;

	$year = (int) $wp_query->get( 'twtxt_archive_year' );
	if ( ! $year ) {
		return '';
	}

	$query = $wp_query->query_vars;
	unset( $query['year'], $query['twtxt_year'], $query['twtxt_archive_year'] );
	if ( ! empty( $query['twtxt_main_years'] ) ) {
		// Remove the main feed's lower date bound before looking for older posts.
		array_pop( $query['date_query'] );
		unset( $query['twtxt_main_years'] );
	}
	$query['date_query'][]   = array(
		'before' => array(
			'year'  => $year,
			'month' => 1,
			'day'   => 1,
		),
	);
	$query['posts_per_rss']  = 1;
	$query['posts_per_page'] = 1;
	$query['nopaging']       = false;
	$query['no_found_rows']  = true;
	$query['paged']          = 1;
	$query['orderby']        = array(
		'date' => 'DESC',
		'ID'   => 'DESC',
	);
	$previous                = new WP_Query( $query );

	if ( ! $previous->posts ) {
		return '';
	}

	$post                   = $previous->posts[0];
	$url                    = twtxt_get_canonical_url();
	$line                   = twtxt_get_post_line( $post );
	list( $created, $text ) = explode( "\t", rtrim( $line, "\n" ), 2 );
	$hash                   = twtxt_get_twt_hash( $url, $created, $text );

	if ( ! $hash ) {
		return '';
	}

	$archive_url = add_query_arg( 'twtxt_year', substr( $post->post_date, 0, 4 ), $url );

	return $hash . ' ' . wp_make_link_relative( $archive_url );
}

/**
 * Compute a twt hash using the v1/v2 timestamp epoch from the specification.
 *
 * @param string $url     The primary feed URL.
 * @param string $created The RFC 3339 timestamp.
 * @param string $text    The exact twt text.
 *
 * @return string The twt hash, or an empty string if BLAKE2b is unavailable.
 */
function twtxt_get_twt_hash( $url, $created, $text ) {
	$created = preg_replace( '/[+-]00:00$/', 'Z', $created );
	$payload = $url . "\n" . $created . "\n" . $text;

	if ( function_exists( 'sodium_crypto_generichash' ) ) {
		$bytes = sodium_crypto_generichash( $payload, '', 32 );
	} elseif ( class_exists( 'ParagonIE_Sodium_Compat' ) ) {
		$bytes = ParagonIE_Sodium_Compat::crypto_generichash( $payload, '', 32 );
	} else {
		return '';
	}

	$alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
	$buffer   = 0;
	$bits     = 0;
	$hash     = '';

	foreach ( unpack( 'C*', $bytes ) as $byte ) {
		$buffer = ( $buffer << 8 ) | $byte;
		$bits  += 8;
		while ( $bits >= 5 ) {
			$bits -= 5;
			$hash .= $alphabet[ ( $buffer >> $bits ) & 31 ];
		}
		$buffer &= ( 1 << $bits ) - 1;
	}
	if ( $bits ) {
		$hash .= $alphabet[ ( $buffer << ( 5 - $bits ) ) & 31 ];
	}

	return strtotime( $created ) < strtotime( '2026-07-01T00:00:00Z' ) ? substr( $hash, -7 ) : substr( $hash, 0, 12 );
}
