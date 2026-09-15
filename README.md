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

The plugin adds a `tw.txt` feed for the whole blog and one per author:

* `https://example.com/feed/tw.txt`
* `https://example.com/author/USERNAME/feed/tw.txt`

Both are announced via a `<link rel="alternate">` tag and a `Link` HTTP header on the blog index and on author archives.

The old `/feed/twtxt` and `/feed/twtxt.txt` URLs redirect to `/feed/tw.txt`.

The feed contains the latest 200 posts in chronological order. Use the `twtxt_posts_per_feed` filter to change the number.

## Example

Run this to follow me in the app:

```shell
twtxt follow pfefferle https://notiz.blog/author/matthias-pfefferle/feed/tw.txt
```

...or my blog:

```shell
twtxt follow notizblog https://notiz.blog/feed/tw.txt
```
