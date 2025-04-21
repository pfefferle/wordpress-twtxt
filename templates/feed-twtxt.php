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
# nick = <?php echo twtxt_get_nick() . PHP_EOL; ?>
# url = <?php self_link(); echo PHP_EOL; ?>
# lang = <?php echo get_locale() . PHP_EOL; ?>
# generator = <?php echo twtxt_get_generator() . PHP_EOL; ?>

<?php
query_posts( 'posts_per_page=200' );

$lines = array();

while ( have_posts() ) :
	the_post();

	$line  = mysql2date( 'c', get_post_time( 'Y-m-d H:i:s', true ), false );
	$line .= "\t";
	$line .= twtxt_get_the_excerpt();
	$line .= ' ?~L~X ';
	$line .= wp_get_shortlink();

	$lines[] = $line;
endwhile;

wp_reset_postdata();

// Now reverse and output.
$lines = array_reverse( $lines );

foreach ( $lines as $line ) {
	echo $line . PHP_EOL;
}
