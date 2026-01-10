<?php
/**
 * PHPUnit bootstrap file for CloudFront Cache Invalidator tests.
 *
 * @package CloudFrontCacheInvalidator
 */

// Load Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Initialize Brain\Monkey for WordPress function mocking.
require_once dirname( __DIR__ ) . '/vendor/antecedent/patchwork/Patchwork.php';

// Define WordPress constants that might be used in the plugin.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

// Define WordPress salts for encryption tests.
if ( ! defined( 'AUTH_KEY' ) ) {
	define( 'AUTH_KEY', 'test-auth-key-for-unit-tests-only' );
}
if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
	define( 'SECURE_AUTH_KEY', 'test-secure-auth-key-for-unit-tests-only' );
}
if ( ! defined( 'LOGGED_IN_KEY' ) ) {
	define( 'LOGGED_IN_KEY', 'test-logged-in-key-for-unit-tests-only' );
}
if ( ! defined( 'NONCE_KEY' ) ) {
	define( 'NONCE_KEY', 'test-nonce-key-for-unit-tests-only' );
}
if ( ! defined( 'AUTH_SALT' ) ) {
	define( 'AUTH_SALT', 'test-auth-salt-for-unit-tests-only' );
}
if ( ! defined( 'SECURE_AUTH_SALT' ) ) {
	define( 'SECURE_AUTH_SALT', 'test-secure-auth-salt-for-unit-tests-only' );
}
if ( ! defined( 'LOGGED_IN_SALT' ) ) {
	define( 'LOGGED_IN_SALT', 'test-logged-in-salt-for-unit-tests-only' );
}
if ( ! defined( 'NONCE_SALT' ) ) {
	define( 'NONCE_SALT', 'test-nonce-salt-for-unit-tests-only' );
}

// Mock WP_Error class for testing.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $errors = array();
		private $error_data = array();

		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( ! empty( $code ) ) {
				$this->errors[ $code ][] = $message;
				if ( ! empty( $data ) ) {
					$this->error_data[ $code ] = $data;
				}
			}
		}

		public function get_error_code() {
			$codes = array_keys( $this->errors );
			return empty( $codes ) ? '' : $codes[0];
		}

		public function get_error_message( $code = '' ) {
			if ( empty( $code ) ) {
				$code = $this->get_error_code();
			}
			if ( isset( $this->errors[ $code ] ) ) {
				return $this->errors[ $code ][0];
			}
			return '';
		}

		public function get_error_data( $code = '' ) {
			if ( empty( $code ) ) {
				$code = $this->get_error_code();
			}
			if ( isset( $this->error_data[ $code ] ) ) {
				return $this->error_data[ $code ];
			}
			return null;
		}

		public function add( $code, $message, $data = '' ) {
			$this->errors[ $code ][] = $message;
			if ( ! empty( $data ) ) {
				$this->error_data[ $code ] = $data;
			}
		}
	}
}

// Load the plugin main class.
require_once dirname( __DIR__ ) . '/includes/class-notglossy-cloudfront-cache-invalidator.php';
