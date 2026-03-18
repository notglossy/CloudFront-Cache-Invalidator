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
 * Version: 1.2.0
 * Author: Not Glossy LLC
 * Author URI: https://github.com/notglossy
 * License: GPL3
 * Requires PHP: 8.1
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! defined( 'NOTGLOSSY_CLOUDFRONT_CACHE_INVALIDATOR_VERSION' ) ) {
	define( 'NOTGLOSSY_CLOUDFRONT_CACHE_INVALIDATOR_VERSION', '1.2.0' );
}

// Include AWS SDK via Composer autoloader only if not already loaded by another plugin.
$autoload_path = plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
if ( ! class_exists( 'Aws\CloudFront\CloudFrontClient' ) ) {
	if ( file_exists( $autoload_path ) ) {
		require $autoload_path;
	} else {
		// Show admin notice if AWS SDK is missing and autoload cannot be found.
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>';
				echo '<strong>' . esc_html__( 'CloudFront Cache Invalidator Error:', 'cloudfront-cache-invalidator' ) . '</strong> ';
				echo esc_html__( 'AWS SDK for PHP is not installed. Please run "composer install" in the plugin directory to install dependencies.', 'cloudfront-cache-invalidator' );
				echo '</p></div>';
			}
		);
	}
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-notglossy-cloudfront-settings-manager.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-notglossy-cloudfront-credential-manager.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-notglossy-cloudfront-path-validator.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-notglossy-cloudfront-client.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-notglossy-cloudfront-invalidation-manager.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-notglossy-cloudfront-admin-interface.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-notglossy-cloudfront-cache-invalidator.php';

// Initialize the plugin.
$cloudfront_cache_invalidator = new NotGlossy_CloudFront_Cache_Invalidator();
