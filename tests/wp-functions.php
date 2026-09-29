<?php
/**
 * Single-site defaults for WordPress functions the plugin calls on every
 * request. Loaded after Patchwork so individual tests can still redefine them
 * with Brain Monkey (e.g. to simulate multisite).
 *
 * @package CloudFrontCacheInvalidator
 */

if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id() {
		return 1;
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite() {
		return false;
	}
}
