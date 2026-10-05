<?php
/**
 * Exercise feed output and HTTP caching in the isolated wp-env test site.
 *
 * Run: wp-env run tests-cli wp eval-file wp-content/plugins/twtxt/tests/integration.php
 */

if ( 8830 !== (int) wp_parse_url( home_url(), PHP_URL_PORT ) ) {
	WP_CLI::error( 'Run this only in the wp-env test site on port 8830.' );
}

/**
 * Assert an integration check.
 *
 * @param bool   $condition Whether the check passed.
 * @param string $message   The failure message.
 */
function twtxt_test_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Request a feed from the test WordPress container.
 *
 * @param string $path    The request path.
 * @param array  $headers Optional conditional request headers.
 *
 * @return array The HTTP response.
 */
function twtxt_test_fetch( $path, $headers = array() ) {
	$headers['Host'] = wp_parse_url( home_url(), PHP_URL_HOST ) . ':8830';
	$response        = wp_remote_get(
		'http://tests-wordpress' . $path,
		array(
			'headers' => $headers,
			'timeout' => 30,
		)
	);
	twtxt_test_assert( ! is_wp_error( $response ), 'HTTP request failed: ' . ( is_wp_error( $response ) ? $response->get_error_message() : '' ) );
	return $response;
}

/**
 * Extract post lines from a feed.
 *
 * @param array $response The HTTP response.
 *
 * @return array The physical post lines.
 */
function twtxt_test_entries( $response ) {
	return array_values( preg_grep( '/^\d{4}-/', explode( "\n", wp_remote_retrieve_body( $response ) ) ) );
}

/**
 * Extract a metadata field from a feed.
 *
 * @param array  $response The HTTP response.
 * @param string $field   The metadata field.
 *
 * @return string The field value, or an empty string if absent.
 */
function twtxt_test_metadata( $response, $field ) {
	preg_match( '/^# ' . preg_quote( $field, '/' ) . ' = (.+)$/m', wp_remote_retrieve_body( $response ), $matches );
	return isset( $matches[1] ) ? $matches[1] : '';
}

$mu_file = WP_CONTENT_DIR . '/mu-plugins/twtxt-integration-filters.php';
if ( file_exists( $mu_file ) ) {
	WP_CLI::error( 'The integration filter fixture already exists; inspect it before rerunning.' );
}

wp_mkdir_p( dirname( $mu_file ) );
file_put_contents(
	$mu_file,
	'<?php
foreach ( array( "twtxt_archives", "twtxt_posts_per_feed", "twtxt_refresh", "twtxt_avatar_version", "twtxt_multiline", "twtxt_max_length" ) as $filter ) {
    add_filter( $filter, function ( $default ) use ( $filter ) {
        $options = get_option( "twtxt_integration_filters", array() );
        return array_key_exists( $filter, $options ) ? $options[ $filter ] : $default;
    } );
}
'
);

$post_ids      = array();
$user_id       = 0;
$tag_id        = 0;
$original_urls = get_option( 'twtxt_feed_urls', array() );
$failure       = '';

try {
	$user_id = wp_insert_user(
		array(
			'user_login'  => 'twtxt-integration-' . wp_generate_password( 8, false ),
			'user_pass'   => wp_generate_password(),
			'role'        => 'author',
			'description' => 'Initial author bio.',
		)
	);
	twtxt_test_assert( ! is_wp_error( $user_id ), 'Could not create the fixture author.' );
	$year = (int) current_time( 'Y' );

	// More than 200 current-year posts must remain in the same complete feed.
	for ( $i = 0; $i < 205; ++$i ) {
		$post_ids[] = wp_insert_post(
			array(
				'post_author'   => $user_id,
				'post_title'    => sprintf( 'Current post %03d', $i ),
				'post_status'   => 'publish',
				'post_date'     => $year . '-01-02 12:00:00',
				'post_date_gmt' => $year . '-01-02 12:00:00',
			)
		);
	}
	foreach ( array( $year - 1, $year - 3 ) as $older_year ) {
		$post_ids[] = wp_insert_post(
			array(
				'post_author'   => $user_id,
				'post_title'    => 'Archived post ' . $older_year,
				'post_status'   => 'publish',
				'post_date'     => $older_year . '-12-31 23:59:59',
				'post_date_gmt' => $older_year . '-12-31 23:59:59',
			)
		);
	}
	foreach ( array( 'draft', 'private' ) as $status ) {
		$post_ids[] = wp_insert_post(
			array(
				'post_author' => $user_id,
				'post_title'  => 'Hidden ' . $status,
				'post_status' => $status,
				'post_date'   => ( $year - 2 ) . '-06-01 12:00:00',
			)
		);
	}

	$path     = '/?feed=twtxt&author=' . $user_id;
	$response = twtxt_test_fetch( $path );
	$etag     = wp_remote_retrieve_header( $response, 'etag' );
	$entries  = twtxt_test_entries( $response );
	twtxt_test_assert( 200 === wp_remote_retrieve_response_code( $response ), 'Main feed returned an error.' );
	twtxt_test_assert( 205 === count( $entries ), 'Yearly feeds must include all 205 posts.' );
	twtxt_test_assert( false !== strpos( $entries[0], 'Current post 000' ) && false !== strpos( end( $entries ), 'Current post 204' ), 'Equal-date posts need stable chronological ID order.' );
	twtxt_test_assert( '300' === twtxt_test_metadata( $response, 'refresh' ), 'Default refresh hint is missing.' );
	twtxt_test_assert( 'Initial author bio.' === twtxt_test_metadata( $response, 'description' ), 'Author bio is missing.' );
	twtxt_test_assert( 'no-cache' === wp_remote_retrieve_header( $response, 'cache-control' ) && '' === wp_remote_retrieve_header( $response, 'last-modified' ), 'Feed must revalidate without a post-only Last-Modified header.' );
	twtxt_test_assert( ! preg_match( '/\n\n# (avatar|description|refresh|prev)/', wp_remote_retrieve_body( $response ) ), 'Metadata must stay in one block.' );

	$conditional = twtxt_test_fetch( $path, array( 'If-None-Match' => $etag ) );
	twtxt_test_assert(
		304 === wp_remote_retrieve_response_code( $conditional ) && '' === wp_remote_retrieve_body( $conditional ),
		'Unchanged feeds must return a bodyless 304: ' . wp_json_encode(
			array(
				'status'      => wp_remote_retrieve_response_code( $conditional ),
				'body'        => substr( wp_remote_retrieve_body( $conditional ), 0, 100 ),
				'before_etag' => $etag,
				'after_etag'  => wp_remote_retrieve_header( $conditional, 'etag' ),
			)
		)
	);
	$conditional = twtxt_test_fetch( $path, array( 'If-None-Match' => '"different", W/' . $etag ) );
	twtxt_test_assert( 304 === wp_remote_retrieve_response_code( $conditional ), 'Weak ETags and ETag lists must work.' );
	$conditional = twtxt_test_fetch( $path, array( 'If-Modified-Since' => gmdate( 'D, d M Y H:i:s', time() + DAY_IN_SECONDS ) . ' GMT' ) );
	twtxt_test_assert( 200 === wp_remote_retrieve_response_code( $conditional ), 'A post-only date validator must not hide metadata changes.' );

	$avatar = twtxt_test_metadata( $response, 'avatar' );
	wp_update_user(
		array(
			'ID'          => $user_id,
			'description' => 'Updated author bio.',
		)
	);
	$changed = twtxt_test_fetch( $path, array( 'If-None-Match' => $etag ) );
	twtxt_test_assert( 200 === wp_remote_retrieve_response_code( $changed ) && wp_remote_retrieve_header( $changed, 'etag' ) !== $etag, 'Metadata-only updates must invalidate the ETag.' );
	twtxt_test_assert( 'Updated author bio.' === twtxt_test_metadata( $changed, 'description' ), 'Changed bio was not served.' );
	twtxt_test_assert( twtxt_test_metadata( $changed, 'avatar' ) !== $avatar, 'Profile updates must version the avatar URL.' );
	$stable = twtxt_test_fetch( $path );
	twtxt_test_assert( twtxt_test_metadata( $stable, 'avatar' ) === twtxt_test_metadata( $changed, 'avatar' ), 'Avatar versions must remain stable between changes.' );

	$alias = twtxt_test_fetch( $path . '&utm_source=test&paged=2' );
	twtxt_test_assert( twtxt_test_metadata( $alias, 'url' ) === twtxt_test_metadata( $response, 'url' ), 'Tracking and pagination must not change feed identity.' );
	twtxt_test_assert( twtxt_test_entries( $alias ) === $entries, 'Pagination must not split a yearly feed.' );

	list( $prev_hash, $prev_path ) = explode( ' ', twtxt_test_metadata( $response, 'prev' ), 2 );
	$archive                       = twtxt_test_fetch( $prev_path );
	$archive_entries               = twtxt_test_entries( $archive );
	twtxt_test_assert( 1 === count( $archive_entries ) && 0 === strpos( $archive_entries[0], (string) ( $year - 1 ) ), 'First archive must contain exactly the previous year.' );
	twtxt_test_assert( twtxt_test_metadata( $archive, 'url' ) === twtxt_test_metadata( $response, 'url' ), 'Archives must retain the main feed identity.' );
	list( $created, $text ) = explode( "\t", end( $archive_entries ), 2 );
	twtxt_test_assert( twtxt_get_twt_hash( twtxt_test_metadata( $archive, 'url' ), $created, $text ) === $prev_hash, 'prev hash must identify the last twt in the linked archive.' );
	list( $older_hash, $older_path ) = explode( ' ', twtxt_test_metadata( $archive, 'prev' ), 2 );
	$oldest                          = twtxt_test_fetch( $older_path );
	twtxt_test_assert( 1 === count( twtxt_test_entries( $oldest ) ) && '' === twtxt_test_metadata( $oldest, 'prev' ), 'Archive links must skip empty years and stop at the oldest year.' );
	twtxt_test_assert( ! array_intersect( $entries, $archive_entries ), 'Main and archive feeds must not duplicate posts.' );
	$post_ids[] = wp_insert_post(
		array(
			'post_author'   => $user_id,
			'post_title'    => 'New current post',
			'post_status'   => 'publish',
			'post_date'     => $year . '-01-03 12:00:00',
			'post_date_gmt' => $year . '-01-03 12:00:00',
		)
	);
	twtxt_test_assert( twtxt_test_entries( twtxt_test_fetch( $prev_path ) ) === $archive_entries, 'Adding a new post must not shift archive contents.' );

	$tag = wp_insert_term( 'twtxt-integration-' . $user_id, 'post_tag' );
	twtxt_test_assert( ! is_wp_error( $tag ), 'Could not create the fixture tag.' );
	$tag_id = $tag['term_id'];
	wp_set_post_terms( $post_ids[0], array( $tag_id ), 'post_tag' );
	wp_set_post_terms( $post_ids[205], array( $tag_id ), 'post_tag' );
	update_option( 'twtxt_integration_filters', array( 'twtxt_posts_per_feed' => 1 ) );
	$tagged = twtxt_test_fetch( '/?feed=twtxt&tag=twtxt-integration-' . $user_id );
	twtxt_test_assert( 1 === count( twtxt_test_entries( $tagged ) ), 'Tag feed must retain its scope.' );
	list( $tag_hash, $tag_path ) = explode( ' ', twtxt_test_metadata( $tagged, 'prev' ), 2 );
	$tag_archive                 = twtxt_test_fetch( $tag_path );
	twtxt_test_assert( 1 === count( twtxt_test_entries( $tag_archive ) ) && '' === twtxt_test_metadata( $tag_archive, 'prev' ), 'Tag archives must exclude posts from other tags and years.' );
	twtxt_test_assert( twtxt_test_metadata( $tag_archive, 'url' ) === twtxt_test_metadata( $tagged, 'url' ), 'Tag archives must share their main feed identity.' );

	// Default feeds on inactive blogs must expose posts without archive support.
	wp_remove_object_terms( $post_ids[0], $tag_id, 'post_tag' );
	delete_option( 'twtxt_integration_filters' );
	$empty_year = twtxt_test_fetch( '/?feed=twtxt&tag=twtxt-integration-' . $user_id );
	twtxt_test_assert( 200 === wp_remote_retrieve_response_code( $empty_year ) && twtxt_test_entries( $empty_year ) === twtxt_test_entries( $tag_archive ), 'An inactive feed must include older posts directly.' );
	twtxt_test_assert( '' === twtxt_test_metadata( $empty_year, 'prev' ), 'A feed containing all posts must not link to duplicate archives.' );
	twtxt_test_assert( twtxt_test_metadata( $empty_year, 'url' ) === twtxt_test_metadata( $tagged, 'url' ), 'Extending the main feed across years must preserve its identity.' );

	wp_update_post(
		array(
			'ID'           => $post_ids[0],
			'post_title'   => '',
			'post_excerpt' => "First paragraph.\n\nSecond paragraph.",
		)
	);
	update_option(
		'twtxt_integration_filters',
		array(
			'twtxt_multiline'  => true,
			'twtxt_max_length' => 500,
			'twtxt_refresh'    => 600,
		)
	);
	$multiline = twtxt_test_fetch( $path );
	twtxt_test_assert( false !== strpos( wp_remote_retrieve_body( $multiline ), "First paragraph.\xE2\x80\xA8\xE2\x80\xA8Second paragraph." ), 'Multiline excerpts must use U+2028.' );
	twtxt_test_assert( 206 === count( twtxt_test_entries( $multiline ) ), 'Multiline text must not add physical post lines.' );
	twtxt_test_assert( '600' === twtxt_test_metadata( $multiline, 'refresh' ), 'Refresh filter must change the advertised interval.' );
	update_option(
		'twtxt_integration_filters',
		array(
			'twtxt_archives' => false,
			'twtxt_refresh'  => 0,
		)
	);
	$legacy = twtxt_test_fetch( $path );
	twtxt_test_assert( 200 === count( twtxt_test_entries( $legacy ) ), 'Disabling archives must restore the latest-200 behavior.' );
	twtxt_test_assert( '' === twtxt_test_metadata( $legacy, 'refresh' ) && '' === twtxt_test_metadata( $legacy, 'prev' ), 'Disabled hints and archives must be omitted.' );
	twtxt_test_assert( twtxt_test_metadata( $legacy, 'url' ) === twtxt_test_metadata( $response, 'url' ), 'Archive configuration must not change feed identity.' );

	// Simulate a new year with only a few recent posts and a busy previous year.
	delete_option( 'twtxt_integration_filters' );
	for ( $i = 0; $i < 200; ++$i ) {
		wp_update_post(
			array(
				'ID'            => $post_ids[ $i ],
				'post_date'     => ( $year - 1 ) . '-06-01 12:00:00',
				'post_date_gmt' => ( $year - 1 ) . '-06-01 12:00:00',
			)
		);
	}
	$recent_years = twtxt_test_fetch( $path );
	$recent_posts = twtxt_test_entries( $recent_years );
	twtxt_test_assert( 207 === count( $recent_posts ), 'The main feed must include the entire boundary year to retain at least 200 posts.' );
	twtxt_test_assert( 0 === strpos( $recent_posts[0], (string) ( $year - 1 ) ) && 0 === strpos( end( $recent_posts ), (string) $year ), 'The main feed must include previous and current years in chronological order.' );
	list( $history_hash, $history_path ) = explode( ' ', twtxt_test_metadata( $recent_years, 'prev' ), 2 );
	$history                             = twtxt_test_fetch( $history_path );
	twtxt_test_assert( twtxt_test_entries( $history ) === twtxt_test_entries( $oldest ), 'prev must skip every year already present in the main feed.' );
	twtxt_test_assert( ! array_intersect( $recent_posts, twtxt_test_entries( $history ) ), 'A multi-year main feed must not duplicate its linked archive.' );
	$explicit_year = twtxt_test_fetch( $prev_path );
	twtxt_test_assert( 201 === count( twtxt_test_entries( $explicit_year ) ), 'An explicitly requested archive must still contain exactly one complete year.' );
	twtxt_test_assert( twtxt_test_metadata( $recent_years, 'url' ) === twtxt_test_metadata( $response, 'url' ), 'The oldest retained year must not change feed identity.' );

	twtxt_test_assert( 'om5qesa' === twtxt_get_twt_hash( 'https://example.com/twtxt.txt', '2025-04-29T12:00:00+00:00', 'Hello World!' ), 'Hash v1 reference vector failed.' );
	twtxt_test_assert( 'myzxbwxktuvs' === twtxt_get_twt_hash( 'https://example.com/twtxt.txt', '2026-07-01T00:00:00Z', 'Hello World!' ), 'Hash v2 epoch reference vector failed.' );
} catch ( Exception $error ) {
	$failure = $error->getMessage();
} finally {
	foreach ( $post_ids as $post_id ) {
		wp_delete_post( $post_id, true );
	}
	if ( $user_id && ! is_wp_error( $user_id ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id );
	}
	if ( $tag_id ) {
		wp_delete_term( $tag_id, 'post_tag' );
	}
	delete_option( 'twtxt_integration_filters' );
	update_option( 'twtxt_feed_urls', $original_urls, false );
	unlink( $mu_file );
}

if ( $failure ) {
	WP_CLI::error( $failure );
}
WP_CLI::success( 'HTTP validators, metadata updates, stable identity, avatar versions, archives, multiline output and hash vectors passed.' );
