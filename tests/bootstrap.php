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

// Single-site WordPress defaults (redefinable by tests).
require_once __DIR__ . '/wp-functions.php';

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
		private $errors     = array();
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

// Minimal WordPress object stubs so instanceof checks work in unit tests.
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID          = 0;
		public $post_type   = 'post';
		public $post_status = 'publish';
		public $post_author = 1;
		public $post_name   = '';
		public $post_parent = 0;

		public function __construct( $props = array() ) {
			foreach ( (array) $props as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}
if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public $term_id          = 0;
		public $term_taxonomy_id = 0;
		public $taxonomy         = 'category';
		public $slug             = '';

		public function __construct( $props = array() ) {
			foreach ( (array) $props as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}
if ( ! class_exists( 'WP_Comment' ) ) {
	class WP_Comment {
		public $comment_ID       = 0;
		public $comment_post_ID  = 0;
		public $comment_approved = '1';

		public function __construct( $props = array() ) {
			foreach ( (array) $props as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

// Load the plugin main class.
require_once dirname( __DIR__ ) . '/includes/class-notglossy-cloudfront-cache-invalidator.php';
