<?php
/**
 * CloudFront Cache Invalidator
 *
 * @category  Plugin
 * @package   CloudFrontCacheInvalidator
 * @author    Not Glossy LLC <3867591+notglossy@users.noreply.github.com>
 * @copyright 2025 Not GLossy LLC
 * @license   GPL-3.0-or-later https://github.com/notglossy/CloudFront-Cache-Invalidator?tab=GPL-3.0-1-ov-file#readme
 * @link      https://github.com/notglossy/CloudFront-Cache-Invalidator
 *
 * @wordpress-plugin
 * Plugin Name: CloudFront Cache Invalidator
 * Plugin URI: https://github.com/notglossy/CloudFront-Cache-Invalidator
 * Description: Automatically invalidates CloudFront cache when WordPress content is updated.
 * Version: 1.0.1
 * Author: Not Glossy LLC
 * Author URI: https://github.com/notglossy
 * License: GPL3
 * Requires PHP: 7.2
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! defined( 'NOTGLOSSY_CLOUDFRONT_CACHE_INVALIDATOR_VERSION' ) ) {
	define( 'NOTGLOSSY_CLOUDFRONT_CACHE_INVALIDATOR_VERSION', '1.0.1' );
}

// Include AWS SDK via Composer autoloader.
if ( file_exists( plugin_dir_path( __FILE__ ) . 'vendor/autoload.php' ) ) {
	require plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-notglossy-cloudfront-cache-invalidator.php';

// Initialize the plugin.
$cloudfront_cache_invalidator = new NotGlossy_CloudFront_Cache_Invalidator();
