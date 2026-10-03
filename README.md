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

The feed contains the latest 200 posts in chronological order. Use the `twtxt_posts_per_feed` filter to change the number.

## Feed metadata

The feed supports the [twtxt metadata extension](https://twtxt.dev/exts/metadata.html) with `avatar`, `link`, `follow` and `following` fields.

The blog feed uses the WordPress site icon as its avatar. Author feeds use the author's WordPress avatar. Use `twtxt_avatar` to override the URL, or return an empty string to omit the field. If the blog has no site icon, its avatar field is omitted.

```php
add_filter( 'twtxt_avatar', function ( $avatar ) {
	return 'https://example.com/avatar.png';
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

## Example

Run this to follow me in the app:

```shell
twtxt follow pfefferle https://notiz.blog/author/matthias-pfefferle/feed/twtxt
```

...or my blog:

```shell
twtxt follow notizblog https://notiz.blog/feed/twtxt
```
