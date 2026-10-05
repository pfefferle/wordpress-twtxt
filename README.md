# This WordPress plugin provides a simple feed, you can follow using twtxt

nothing more... nothing less...

> twtxt is a decentralised, minimalist microblogging service for hackers.

More infos about twtxt here <https://github.com/buckket/twtxt>

## Installation

The plugin is not in the WordPress.org directory, so you have to install it by hand:

1. Download the [ZIP file](https://github.com/pfefferle/wordpress-twtxt/archive/refs/heads/master.zip)
2. Go to *Plugins → Add New → Upload Plugin* in your WordPress admin and upload it (or unzip it into `wp-content/plugins/` via FTP)
3. Activate the plugin

The feed is available right after activation, no need to re-save the permalinks.

Alternatively with WP-CLI:

```shell
wp plugin install https://github.com/pfefferle/wordpress-twtxt/archive/refs/heads/master.zip --activate
```

...or with Composer:

```shell
composer require pfefferle/wordpress-twtxt
```

## Feeds

The plugin adds a `twtxt` feed for the whole blog and one per author:

* `https://example.com/feed/twtxt`
* `https://example.com/author/USERNAME/feed/twtxt`

Both are announced via a `<link rel="alternate">` tag and a `Link` HTTP header on the blog index and on author archives.

The old `/feed/tw.txt` and `/feed/twtxt.txt` URLs redirect to `/feed/twtxt`.

The main feed contains all published posts from the current calendar year, in chronological order. Year boundaries use the site's timezone. Older years are available as linked archive feeds, without duplicating posts between the main feed and its archives.

## Archives

Yearly archives are enabled by default. The `prev` metadata links to the latest earlier year with published posts and includes the hash of that archive's last post. Empty years are skipped, and each archive links to the next older year. New posts in the current year do not shift existing archives.

You can also request a year directly:

* `https://example.com/feed/twtxt?twtxt_year=2025`
* `https://example.com/author/USERNAME/feed/twtxt?twtxt_year=2025`

Archives preserve the author or taxonomy scope of the main feed and advertise the same primary `url`, as required for stable post hashes. Existing date-specific and single-post feeds keep their original scope.

To restore the latest-200-post feed, disable yearly archives:

```php
add_filter( 'twtxt_archives', '__return_false' );
```

The `twtxt_posts_per_feed` filter controls the post limit when yearly archives are disabled. Complete yearly feeds have no post limit.

## Feed identity and HTTP caching

The plugin remembers the first clean URL requested for each feed context, preserving its trailing slash. Tracking parameters, pagination and archive selectors do not change the primary feed identity. The `twtxt_feed_url` filter can preserve a previously advertised URL explicitly; avoid changing an established primary URL, since clients use it to hash posts.

HTTP responses advertise `Cache-Control: no-cache` and an ETag derived from metadata and post text. Unchanged feeds return `304 Not Modified`, while metadata-only changes invalidate the ETag. The template prints the feed directly, without output buffering.

Post-only `Last-Modified` headers are omitted. Clients using only `If-Modified-Since` receive a complete feed on each fetch. Server or proxy caching rules should respect the feed's revalidation policy.

## Feed metadata

The feed supports the [twtxt metadata extension](https://twtxt.dev/exts/metadata.html) with `avatar`, `description`, `refresh`, `prev`, `link`, `follow` and `following` fields.

The blog feed uses the WordPress site icon as its avatar. Author feeds use the author's WordPress avatar. Use `twtxt_avatar` to override the URL, or return an empty string to omit the field. If the blog has no site icon, its avatar field is omitted.

```php
add_filter( 'twtxt_avatar', function ( $avatar ) {
	return 'https://example.com/avatar.png';
} );
```

Avatar URLs receive a stable version fragment based on the site icon's modification date or the author's profile version. Updating a WordPress profile changes its avatar version. Explicit fragments supplied by avatar providers or the `twtxt_avatar` filter are preserved. For an image changed outside WordPress, such as a Gravatar update, use `twtxt_avatar_version` to provide a new version.

The blog feed uses the site tagline as its description, and author feeds use the author's bio from their WordPress profile. HTML is stripped and whitespace is normalized to keep the description on one line. Use `twtxt_description` to customize it, or return an empty string to omit the field. Empty descriptions are omitted by default.

```php
add_filter( 'twtxt_description', function ( $description ) {
	return 'Notes about the open web.';
} );
```

The default `refresh` hint is 300 seconds. Use `twtxt_refresh` to change the interval, or return zero to omit the hint. Clients decide whether to honor it.

```php
add_filter( 'twtxt_refresh', function ( $seconds ) {
	return 600;
} );
```

Links include the blog homepage and, on author feeds, the author's website if set in their WordPress profile. Use `twtxt_links` to add or replace links. It receives an array of link labels mapped to URLs; labels can contain spaces and URLs can use schemes such as `https:` or `im:`.

```php
add_filter( 'twtxt_links', function ( $links ) {
	$links['Github Profile'] = 'https://github.com/pfefferle';
	return $links;
} );
```

The blog feed follows each author with published posts, and each author feed follows the blog. Use `twtxt_follows` to add or replace follows. It receives an array of nicknames mapped to feed URLs. The feed publishes these as `# follow` lines and sets `# following` to the number of valid entries. This advertises follows without fetching their feeds.

```php
add_filter( 'twtxt_follows', function ( $follows ) {
	$follows['pfefferle'] = 'https://twtxt.net/user/pfefferle/twtxt.txt';
	return $follows;
} );
```

These filters run in the current feed context, so callbacks can use `is_author()` and `get_queried_object_id()` to configure each author's metadata separately. Empty labels and invalid URLs are omitted; labels are normalized to one line.

## Post text

Posts use their title, or their excerpt when there is no title, followed by a permalink. The default maximum length is 140 characters, including the link. Use `twtxt_max_length` to change it.

Multiline output is opt-in. With `twtxt_multiline` enabled, paragraph breaks become Unicode line separators (`U+2028`), keeping each post on one physical feed line:

```php
add_filter( 'twtxt_multiline', '__return_true' );
add_filter( 'twtxt_max_length', function ( $length ) {
	return 500;
} );
```

Changing the text formatting of existing posts changes their twt hashes, so choose these settings before publishing a feed or account for the effect on existing replies.

## Development

The plugin requires WordPress 5.5 or newer. Start the wp-env environment, then run the integration checks against the isolated test site on port 8830:

```shell
npm run env:start
npm test
```

The checks create and remove fixture users and posts, and verify conditional HTTP requests, metadata updates, feed identity, yearly archives, multiline output and the twt hash reference vectors.

## Example

Run this to follow me in the app:

```shell
twtxt follow pfefferle https://notiz.blog/author/matthias-pfefferle/feed/twtxt
```

...or my blog:

```shell
twtxt follow notizblog https://notiz.blog/feed/twtxt
```
