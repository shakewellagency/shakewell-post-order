# Shakewell Post Order

> **Made by [Shakewell](https://shakewell.agency).** Free and open source, built and maintained by the Shakewell team.

Drag-and-drop ordering of posts, pages and custom post types on the admin list, saved to WordPress's built-in `menu_order` field. It replaces the Post Types Order plugin with a much smaller one.

## Use

1. Activate the plugin and open **Settings > Post order**.
2. Tick the post types you want to order. None are enabled by default.
3. On each type's list screen, drag rows into place, or use the **Move up** and **Move down** buttons in the Order column. A status message announces each save to screen readers.

Hierarchical types (with `page-attributes`) work too: an item moves only among items with the same parent, children travel with it, and the order is renumbered within that parent only. If a parent's children are split across pages of the list, those items are locked with a notice until you show more items per page.

Enabled flat lists sort by `menu_order`, then title, until you pick another column to sort by. Only the current page is reordered and positions continue from the previous page. The front end is untouched: read the order yourself with `orderby => menu_order`.

Set the post types from code instead with the filter, which receives the saved list:

```php
add_filter( 'shakewell_post_order_post_types', fn() => array( 'page', 'book' ) );
```

Saving checks a nonce, that the post type is enabled, and `edit_post` for every post sent. Uninstalling removes only the plugin's option, never a `menu_order` value.

## Front end ordering (optional)

Off by default. For sites moving from Post Types Order, whose "Auto Sort" and "Apply to navigation" settings changed public lists, switch it on so nothing changes order:

```php
add_filter( 'shakewell_post_order_front_end', '__return_true' );
```

It puts `menu_order` in front of the `ORDER BY` of every query outside the admin list, for every post type, REST and WP-CLI queries and `get_posts()` included, and makes the previous and next post links follow `menu_order`, then newest first. A query opts out with `ignore_custom_sort => true`. It does not touch searches, queries ordered by a fixed ID list or at random, queries that already sort by `menu_order`, or an `orderby` chosen in the URL.

Side effect to know about: `get_posts()` ignores `posts_*` filters by default (`suppress_filters`). To order it, this feature lifts that for the queries it covers, so other plugins' `posts_*` filters (multilingual, membership and similar) also run on those calls, as they do with Post Types Order. If the previous or next link's WHERE clause was changed by another plugin first, core's own order is kept for that call.

## Work on it

```sh
composer install
npm ci
npm run env:start      # needs Docker
npm test               # PHPUnit inside the WordPress test container
composer lint          # WordPress coding standards
npm run plugin-check   # the official Plugin Check
```

## Release

Bump the version in the plugin header, `readme.txt` (`Stable tag`) and the `VERSION` constant, add a changelog line, then tag `vX.Y.Z`. The release workflow checks the three match and attaches `shakewell-post-order.zip` to a GitHub release.

## Install with Composer

Until a plugin is listed, install the zip from its GitHub release, pinned to a version with a checksum:

```json
"repositories": [
  {
    "type": "package",
    "package": {
      "name": "shakewellagency/shakewell-post-order",
      "version": "0.1.0",
      "type": "wordpress-plugin",
      "dist": {
        "url": "https://github.com/shakewellagency/shakewell-post-order/releases/download/v0.1.0/shakewell-post-order.zip",
        "type": "zip",
        "shasum": "<sha1 of the zip>"
      },
      "require": { "php": ">=8.1", "composer/installers": "^2.3" }
    }
  }
],
"require": { "shakewellagency/shakewell-post-order": "0.1.0" }
```

Get the checksum with `shasum -a 1 shakewell-post-order.zip`.

Use the zip, not a `vcs` repository. A source install puts tests and repository files under the web root, while the zip leaves them out. The build needs no GitHub token and makes no GitHub API calls. Copy the plugin's PHP requirement into `require`, because Composer doesn't read the plugin's own `composer.json` here.

A plugin with runtime Composer libraries must ship `vendor/` in its zip. The template's release workflow uses `git archive`, which leaves `vendor/` out, so add `composer install --no-dev` and build the zip from `.distignore`, as `shakewell-saml-sso` does.

Once a plugin is listed, switch to `wpackagist-plugin/shakewell-post-order` and drop this entry.

## Made by Shakewell

This plugin is part of a family of free WordPress plugins from [Shakewell](https://shakewell.agency). Each one does one job, keeps its dependencies small and is released under GPL-2.0-or-later.

Use it, fork it and send fixes. To have it set up on your site, or to have a plugin built, [talk to Shakewell](https://shakewell.agency).

See them all: [Shakewell plugins on GitHub](https://github.com/orgs/shakewellagency/repositories?q=shakewell-).
