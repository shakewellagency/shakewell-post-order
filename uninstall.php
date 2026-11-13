<?php
/**
 * Removes the plugin's option when it is deleted from the Plugins screen.
 *
 * The saved order lives in each post's menu_order field and is never touched.
 *
 * @package Shakewell\PostOrder
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'shakewell_post_order_post_types' );
