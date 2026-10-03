# | |___      _| |___  _| |_
# | __\ \ /\ / / __\ \/ / __|
# | |_ \ V  V /| |_ >  <| |_
#  \__| \_/\_/  \__/_/\_\\__|
#
# Twtxt is an open, distributed
# microblogging platform that
# uses human-readable text files,
# common transport protocols, and
# free software.
#
# Learn more about twtxt at
#   https://github.com/buckket/twtxt
#
# Learn more about the WordPress plugin
#   https://github.com/pfefferle/wordpress-twtxt
#
# ------------------------------------------
#
# nick = <?php echo twtxt_get_nick() . "\n"; ?>
# url = <?php echo esc_url_raw( apply_filters( 'self_link', get_self_link() ) ) . "\n"; ?>
# lang = <?php echo get_locale() . "\n"; ?>
# generator = https://github.com/pfefferle/wordpress-twtxt

<?php
$avatar  = twtxt_get_avatar();
$links   = twtxt_get_links();
$follows = twtxt_get_follows();

if ( $avatar ) {
	printf( "# avatar = %s\n", $avatar );
}

printf( "# following = %d\n", count( $follows ) );

foreach ( $links as $label => $url ) {
	printf( "# link = %s %s\n", $label, $url );
}

foreach ( $follows as $nick => $url ) {
	printf( "# follow = %s %s\n", $nick, $url );
}

echo "\n";

// Newest posts first in the query, oldest first in the file.
foreach ( array_reverse( $posts ) as $post ) {
	setup_postdata( $post );

	$url = wp_get_shortlink();

	// A status should not be longer than 140 characters, the link included.
	printf( "%s\t%s ⌘ %s\n", get_post_time( 'c', true ), twtxt_get_the_excerpt( 140 - mb_strlen( ' ⌘ ' . $url ) ), $url );
}

wp_reset_postdata();
