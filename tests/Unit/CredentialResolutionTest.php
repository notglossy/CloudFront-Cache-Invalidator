<?php
/**
 * Unit tests for credential resolution functionality.
 *
 * Tests the resolve_credentials() and get_env_or_option() methods which
 * handle priority resolution of AWS credentials from constants, environment
 * variables, and encrypted options.
 * These methods are located at lines 231-267 in the main class.
 *
 * @package CloudFrontCacheInvalidator
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Test credential resolution methods.
 */
class CredentialResolutionTest extends TestCase {

	/**
	 * The plugin instance under test.
	 *
	 * @var NotGlossy_CloudFront_Cache_Invalidator
	 */
	private $plugin;

	/**
	 * Test data fixtures.
	 *
	 * @var array
	 */
	private $fixtures;

	/**
	 * Set up the test environment before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Load test fixtures.
		$this->fixtures = require dirname( __DIR__ ) . '/fixtures/test-data.php';

		// Mock WordPress functions.
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_salt' )->justReturn( 'fallback-salt-value' );
		Functions\when( 'get_option' )->justReturn( array() );

		// Undefine test constants if they exist.
		$this->undefine_constant( 'CLOUDFRONT_AWS_ACCESS_KEY' );
		$this->undefine_constant( 'CLOUDFRONT_AWS_SECRET_KEY' );

		// Clear environment variables.
		putenv( 'CLOUDFRONT_AWS_ACCESS_KEY' );
		putenv( 'CLOUDFRONT_AWS_SECRET_KEY' );
	}

	/**
	 * Tear down the test environment after each test.
	 */
	protected function tearDown(): void {
		// Cleanup.
		$this->undefine_constant( 'CLOUDFRONT_AWS_ACCESS_KEY' );
		$this->undefine_constant( 'CLOUDFRONT_AWS_SECRET_KEY' );
		putenv( 'CLOUDFRONT_AWS_ACCESS_KEY' );
		putenv( 'CLOUDFRONT_AWS_SECRET_KEY' );

		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Test credentials from constants have highest priority.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_credentials_from_constants() {
		// Redefine constants for this test.
		if ( ! defined( 'CLOUDFRONT_AWS_ACCESS_KEY' ) ) {
			define( 'CLOUDFRONT_AWS_ACCESS_KEY', 'CONST_ACCESS_KEY' );
		}
		if ( ! defined( 'CLOUDFRONT_AWS_SECRET_KEY' ) ) {
			define( 'CLOUDFRONT_AWS_SECRET_KEY', 'CONST_SECRET_KEY' );
		}

		// Set environment variables (should be ignored).
		putenv( 'CLOUDFRONT_AWS_ACCESS_KEY=ENV_ACCESS_KEY' );
		putenv( 'CLOUDFRONT_AWS_SECRET_KEY=ENV_SECRET_KEY' );

		// Create plugin with encrypted options (should be ignored).
		$this->plugin = $this->create_plugin_with_encrypted_credentials(
			'OPTION_ACCESS_KEY',
			'OPTION_SECRET_KEY'
		);

		$credentials = $this->call_private_method( $this->plugin, 'resolve_credentials' );

		$this->assertIsArray( $credentials );
		$this->assertEquals( 'CONST_ACCESS_KEY', $credentials['key'] );
		$this->assertEquals( 'CONST_SECRET_KEY', $credentials['secret'] );
	}

	/**
	 * Test credentials from environment variables when constants not defined.
	 *
	 * Note: We test this via get_env_or_option directly since we cannot
	 * undefine constants in PHP, and constants are set in bootstrap.
	 */
	public function test_credentials_from_environment() {
		// Set environment variables.
		putenv( 'CLOUDFRONT_AWS_ACCESS_KEY=ENV_ACCESS_KEY' );
		putenv( 'CLOUDFRONT_AWS_SECRET_KEY=ENV_SECRET_KEY' );

		$this->plugin = new NotGlossy_CloudFront_Cache_Invalidator();

		// Test get_env_or_option with a non-existent constant.
		$result = $this->call_private_method(
			$this->plugin,
			'get_env_or_option',
			array( 'NON_EXISTENT_CONSTANT', 'CLOUDFRONT_AWS_ACCESS_KEY', 'aws_access_key_enc' )
		);

		$this->assertEquals( 'ENV_ACCESS_KEY', $result, 'Environment variable should be used when constant is not defined' );
	}

	/**
	 * Test credentials from encrypted options when no constants or env vars.
	 */
	public function test_credentials_from_encrypted_options() {
		// Create plugin with encrypted options.
		$this->plugin = $this->create_plugin_with_encrypted_credentials(
			'OPTION_ACCESS_KEY',
			'OPTION_SECRET_KEY'
		);

		// Test get_env_or_option with non-existent constant and env var.
		$result = $this->call_private_method(
			$this->plugin,
			'get_env_or_option',
			array( 'NON_EXISTENT_CONSTANT', 'NON_EXISTENT_ENV_VAR', 'aws_access_key_enc' )
		);

		$this->assertEquals( 'OPTION_ACCESS_KEY', $result, 'Encrypted option should be used when no constant or env var' );
	}

	/**
	 * Test that missing credentials return null.
	 */
	public function test_missing_credentials_return_null() {
		$this->plugin = new NotGlossy_CloudFront_Cache_Invalidator();

		// Test with non-existent constant, env var, and option.
		$result = $this->call_private_method(
			$this->plugin,
			'get_env_or_option',
			array( 'NON_EXISTENT_CONSTANT', 'NON_EXISTENT_ENV_VAR', 'non_existent_option' )
		);

		$this->assertNull( $result, 'Missing credentials should return null' );
	}

	/**
	 * Test that partial credentials (only access key) return null.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_partial_credentials_access_key_only() {
		if ( ! defined( 'CLOUDFRONT_AWS_ACCESS_KEY' ) ) {
			define( 'CLOUDFRONT_AWS_ACCESS_KEY', 'ACCESS_KEY_ONLY' );
		}

		$this->plugin = new NotGlossy_CloudFront_Cache_Invalidator();

		$credentials = $this->call_private_method( $this->plugin, 'resolve_credentials' );

		$this->assertNull( $credentials, 'Access key without secret key should return null' );
	}

	/**
	 * Test that partial credentials (only secret key) return null.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_partial_credentials_secret_key_only() {
		if ( ! defined( 'CLOUDFRONT_AWS_SECRET_KEY' ) ) {
			define( 'CLOUDFRONT_AWS_SECRET_KEY', 'SECRET_KEY_ONLY' );
		}

		$this->plugin = new NotGlossy_CloudFront_Cache_Invalidator();

		$credentials = $this->call_private_method( $this->plugin, 'resolve_credentials' );

		$this->assertNull( $credentials, 'Secret key without access key should return null' );
	}

	/**
	 * Test get_env_or_option priority: constant > env > option.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_env_or_option_priority_constant() {
		if ( ! defined( 'CLOUDFRONT_AWS_ACCESS_KEY' ) ) {
			define( 'CLOUDFRONT_AWS_ACCESS_KEY', 'FROM_CONSTANT' );
		}
		putenv( 'CLOUDFRONT_AWS_ACCESS_KEY=FROM_ENV' );

		$this->plugin = $this->create_plugin_with_encrypted_credentials( 'FROM_OPTION', 'secret' );

		$result = $this->call_private_method(
			$this->plugin,
			'get_env_or_option',
			array( 'CLOUDFRONT_AWS_ACCESS_KEY', 'CLOUDFRONT_AWS_ACCESS_KEY', 'aws_access_key_enc' )
		);

		$this->assertEquals( 'FROM_CONSTANT', $result, 'Constant should have highest priority' );
	}

	/**
	 * Test get_env_or_option priority: env > option when no constant.
	 */
	public function test_get_env_or_option_priority_env() {
		putenv( 'CLOUDFRONT_AWS_ACCESS_KEY=FROM_ENV' );

		$this->plugin = $this->create_plugin_with_encrypted_credentials( 'FROM_OPTION', 'secret' );

		$result = $this->call_private_method(
			$this->plugin,
			'get_env_or_option',
			array( 'NON_EXISTENT_CONSTANT', 'CLOUDFRONT_AWS_ACCESS_KEY', 'aws_access_key_enc' )
		);

		$this->assertEquals( 'FROM_ENV', $result, 'Environment variable should have priority over option' );
	}

	/**
	 * Test get_env_or_option returns option when no constant or env.
	 */
	public function test_get_env_or_option_priority_option() {
		$this->plugin = $this->create_plugin_with_encrypted_credentials( 'FROM_OPTION', 'secret' );

		$result = $this->call_private_method(
			$this->plugin,
			'get_env_or_option',
			array( 'NON_EXISTENT_CONSTANT', 'NON_EXISTENT_ENV_VAR', 'aws_access_key_enc' )
		);

		$this->assertEquals( 'FROM_OPTION', $result, 'Option should be used when no constant or env var' );
	}

	/**
	 * Test get_env_or_option returns null when nothing is set.
	 */
	public function test_get_env_or_option_returns_null() {
		$this->plugin = new NotGlossy_CloudFront_Cache_Invalidator();

		$result = $this->call_private_method(
			$this->plugin,
			'get_env_or_option',
			array( 'NON_EXISTENT_CONSTANT', 'NON_EXISTENT_ENV_VAR', 'non_existent_option' )
		);

		$this->assertNull( $result, 'Should return null when no credentials are available' );
	}

	/**
	 * Test that empty string constant is ignored (falls through to env/option).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_empty_constant_ignored() {
		if ( ! defined( 'CLOUDFRONT_AWS_ACCESS_KEY' ) ) {
			define( 'CLOUDFRONT_AWS_ACCESS_KEY', '' );
		}
		putenv( 'CLOUDFRONT_AWS_ACCESS_KEY=FROM_ENV' );

		$this->plugin = new NotGlossy_CloudFront_Cache_Invalidator();

		$result = $this->call_private_method(
			$this->plugin,
			'get_env_or_option',
			array( 'CLOUDFRONT_AWS_ACCESS_KEY', 'CLOUDFRONT_AWS_ACCESS_KEY', 'aws_access_key_enc' )
		);

		$this->assertEquals( 'FROM_ENV', $result, 'Empty constant should be ignored' );
	}

	/**
	 * Test that empty env var is ignored (falls through to option).
	 */
	public function test_empty_env_var_ignored() {
		putenv( 'CLOUDFRONT_AWS_ACCESS_KEY=' );

		$this->plugin = $this->create_plugin_with_encrypted_credentials( 'FROM_OPTION', 'secret' );

		$result = $this->call_private_method(
			$this->plugin,
			'get_env_or_option',
			array( 'NON_EXISTENT_CONSTANT', 'CLOUDFRONT_AWS_ACCESS_KEY', 'aws_access_key_enc' )
		);

		$this->assertEquals( 'FROM_OPTION', $result, 'Empty env var should be ignored' );
	}

	/**
	 * Helper to create plugin instance with encrypted credentials.
	 *
	 * @param string $access_key Access key to encrypt.
	 * @param string $secret_key Secret key to encrypt.
	 * @return NotGlossy_CloudFront_Cache_Invalidator Plugin instance.
	 */
	private function create_plugin_with_encrypted_credentials( $access_key, $secret_key ) {
		// Create a temporary plugin to encrypt the credentials.
		$temp_plugin   = new NotGlossy_CloudFront_Cache_Invalidator();
		$encrypted_key = $this->call_private_method( $temp_plugin, 'encrypt_value', array( $access_key ) );
		$encrypted_sec = $this->call_private_method( $temp_plugin, 'encrypt_value', array( $secret_key ) );

		// Mock get_option to return encrypted credentials.
		Functions\when( 'get_option' )->justReturn(
			array(
				'aws_access_key_enc' => $encrypted_key,
				'aws_secret_key_enc' => $encrypted_sec,
			)
		);

		// Create new plugin instance that will load these options.
		return new NotGlossy_CloudFront_Cache_Invalidator();
	}

	/**
	 * Helper to undefine a constant if it exists.
	 *
	 * @param string $constant_name The constant name.
	 */
	private function undefine_constant( $constant_name ) {
		if ( defined( $constant_name ) ) {
			// Use runkit or uopz if available, otherwise skip.
			// For testing purposes, we'll work around this by testing in isolation.
		}
	}

	/**
	 * Helper method to call private methods on the plugin instance.
	 *
	 * @param object $object     The object instance.
	 * @param string $method     The method name.
	 * @param array  $parameters Method parameters.
	 * @return mixed Method return value.
	 */
	private function call_private_method( $object, $method, $parameters = array() ) {
		$reflection = new ReflectionClass( get_class( $object ) );
		$method     = $reflection->getMethod( $method );

		return $method->invokeArgs( $object, $parameters );
	}
}
