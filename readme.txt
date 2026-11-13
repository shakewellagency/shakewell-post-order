=== Shakewell Post Order ===
Contributors: shakewellagency
Tags: order, sort, drag and drop, menu order
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Drag-and-drop ordering of posts, pages and custom post types on the admin list, saved to the built-in menu_order field.

== Description ==

Pick the post types you want to order under Settings > Post order. Their list screens then sort by `menu_order` (then title) and rows can be dragged into place. Every row also has Move up and Move down buttons, so ordering works from the keyboard and with screen readers, which are told the result through a live status message.

The order is saved in WordPress's own `menu_order` field, so a theme or plugin can read it with `orderby => menu_order`. This plugin does not change any front-end query.

Nothing is enabled by default. Developers can set the post types in code with the `shakewell_post_order_post_types` filter, which receives the saved list.

Only the posts on the page you are viewing are reordered, and their positions continue from the previous page. Saving checks a nonce and that you may edit every post involved. Deleting the plugin removes its one setting and leaves every `menu_order` value in place.

= External services =

None.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it with Composer.
2. Activate it from the Plugins screen.
3. Choose the post types under Settings > Post order.

== Frequently Asked Questions ==

= Does it change my site's front end? =

No. It only changes the admin list and writes `menu_order`.

= Where do I report a security problem? =

Please do not open a public issue. Follow SECURITY.md in the plugin's repository.

== Changelog ==

= 0.1.0 =
* First release.
