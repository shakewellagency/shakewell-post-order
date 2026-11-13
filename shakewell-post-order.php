<?php
/**
 * Plugin Name:       Shakewell Post Order
 * Plugin URI:        https://github.com/shakewellagency/shakewell-post-order
 * Description:       Drag-and-drop ordering of posts, pages and custom post types on the admin list, saved to the built-in menu_order field.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Shakewell
 * Author URI:        https://shakewell.agency
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shakewell-post-order
 *
 * @package Shakewell\PostOrder
 */

namespace Shakewell\PostOrder;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.1.0';
const FILE    = __FILE__;

require_once __DIR__ . '/src/Plugin.php';

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
