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
#    nick = <?php echo twtxt_get_nick() . PHP_EOL; ?>
#    url = <?php self_link(); echo PHP_EOL; ?>
#    lang = <?php echo get_locale() . PHP_EOL; ?>

<?php
query_posts( 'posts_per_page=100' );

while ( have_posts() ) :
	the_post();

	echo mysql2date( 'c', get_post_time( 'Y-m-d H:i:s', true ), false );
	echo "\t";
	echo twtxt_get_the_excerpt();
	echo ' ⌘ ';
	echo wp_get_shortlink();
	echo PHP_EOL;
endwhile;
