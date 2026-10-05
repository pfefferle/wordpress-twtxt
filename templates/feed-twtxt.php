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
<?php
foreach ( $args['metadata'] as $field => $values ) {
	foreach ( (array) $values as $value ) {
		printf( "# %s = %s\n", $field, $value );
	}
}

echo "\n";

foreach ( $args['entries'] as $entry ) {
	echo $entry;
}
