<?php
/**
 * Integration-style tests for validate_settings() behavior.
 *
 * Focus: HTTPS enforcement, credential encryption/preservation, plaintext stripping,
 * IAM role toggle, validation error handling, and fallbacks.
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class SettingsValidationTest extends TestCase {
	/**
	 * Plugin instance under test.
	 *
	 * @var NotGlossy_CloudFront_Cache_Invalidator
	 */
	private $plugin;

	/**
	 * Collected settings errors (simulating add_settings_error/get_settings_errors).
	 *
	 * @var array<int,array>
	 */
	private $settings_errors;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->settings_errors = array();

		// Basic WP function shims.
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->alias(
			function ( $value ) {
				return is_string( $value ) ? trim( $value ) : '';
			}
		);

		Functions\when( 'sanitize_textarea_field' )->alias(
			function ( $value ) {
				if ( ! is_string( $value ) ) {
					return '';
				}
				// Trim each line; preserve newlines.
				$lines = array_map( 'trim', explode( "\n", $value ) );
				return implode( "\n", $lines );
			}
		);

		Functions\when( 'get_option' )->justReturn( array() );

		Functions\when( 'add_settings_error' )->alias(
			function ( $option, $code, $message, $type = 'error' ) {
				$this->settings_errors[] = compact( 'option', 'code', 'message', 'type' );
			}
		);

		// Default to HTTPS; individual tests can override.
		Functions\when( 'is_ssl' )->justReturn( true );

		// Instantiate plugin.
		$this->plugin = new NotGlossy_CloudFront_Cache_Invalidator();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_https_blocks_plaintext_credentials_on_http() {
		Functions\when( 'is_ssl' )->justReturn( false );
		$this->seed_settings( array() );

		$input = array(
			'use_iam_role'   => '0',
			'aws_access_key' => 'AKIA123',
			'aws_secret_key' => 'SECRET123',
		);

		$result = $this->plugin->validate_settings( $input );

		$this->assertArrayNotHasKey( 'aws_access_key_enc', $result );
		$this->assertArrayNotHasKey( 'aws_secret_key_enc', $result );
		$this->assertArrayNotHasKey( 'credentials_stored', $result );
		$this->assertNotEmpty( $this->settings_errors );
		$this->assertSame( 'cloudfront_cache_invalidator_options', $this->settings_errors[0]['option'] );
		$this->assertSame( 'cloudfront_https_required', $this->settings_errors[0]['code'] );
	}

	public function test_https_allows_encrypting_credentials() {
		Functions\when( 'is_ssl' )->justReturn( true );
		$this->seed_settings( array() );

		$input = array(
			'use_iam_role'   => '0',
			'aws_access_key' => 'AKIA123',
			'aws_secret_key' => 'SECRET123',
		);

		$result = $this->plugin->validate_settings( $input );

		$this->assertArrayHasKey( 'aws_access_key_enc', $result );
		$this->assertArrayHasKey( 'aws_secret_key_enc', $result );
		$this->assertEquals( '1', $result['credentials_stored'] );
		$this->assertArrayNotHasKey( 'aws_access_key', $result );
		$this->assertArrayNotHasKey( 'aws_secret_key', $result );
	}

	public function test_blank_submission_preserves_existing_encrypted_credentials() {
		$seed = array(
			'aws_access_key_enc' => 'enc-access',
			'aws_secret_key_enc' => 'enc-secret',
			'credentials_stored' => true,
		);
		$this->seed_settings( $seed );

		$input  = array( 'use_iam_role' => '0' );
		$result = $this->plugin->validate_settings( $input );

		$this->assertSame( $seed['aws_access_key_enc'], $result['aws_access_key_enc'] );
		$this->assertSame( $seed['aws_secret_key_enc'], $result['aws_secret_key_enc'] );
		$this->assertTrue( $result['credentials_stored'] );
	}

	public function test_iam_role_checkbox_toggles_and_does_not_clear_creds() {
		$seed = array(
			'aws_access_key_enc' => 'enc-access',
			'aws_secret_key_enc' => 'enc-secret',
			'credentials_stored' => true,
		);
		$this->seed_settings( $seed );

		// Checkbox checked
		$result_checked = $this->plugin->validate_settings( array( 'use_iam_role' => '1' ) );
		$this->assertSame( '1', $result_checked['use_iam_role'] );
		$this->assertSame( 'enc-access', $result_checked['aws_access_key_enc'] );
		$this->assertSame( 'enc-secret', $result_checked['aws_secret_key_enc'] );

		// Checkbox absent
		$result_unchecked = $this->plugin->validate_settings( array() );
		$this->assertSame( '0', $result_unchecked['use_iam_role'] );
	}

	public function test_invalid_region_adds_error_and_preserves_previous() {
		$seed = array( 'aws_region' => 'us-east-1' );
		$this->seed_settings( $seed );

		$result = $this->plugin->validate_settings( array( 'aws_region' => 'bad_region' ) );

		$this->assertSame( 'us-east-1', $result['aws_region'] );
		$this->assertSame( 'invalid_aws_region', $this->settings_errors[0]['code'] );
	}

	public function test_valid_region_updates_and_normalizes() {
		$this->seed_settings( array( 'aws_region' => 'us-east-1' ) );

		$result = $this->plugin->validate_settings( array( 'aws_region' => 'EU-West-2' ) );

		$this->assertSame( 'eu-west-2', $result['aws_region'] );
	}

	public function test_invalid_distribution_id_adds_error_and_preserves_previous() {
		$seed = array( 'distribution_id' => 'OLDID123456789' );
		$this->seed_settings( $seed );

		$result = $this->plugin->validate_settings( array( 'distribution_id' => 'bad' ) );

		$this->assertSame( 'OLDID123456789', $result['distribution_id'] );
		$this->assertSame( 'invalid_distribution_id', $this->settings_errors[0]['code'] );
	}

	public function test_valid_distribution_id_updates_and_normalizes() {
		$this->seed_settings( array( 'distribution_id' => 'OLDID123456789' ) );

		$result = $this->plugin->validate_settings( array( 'distribution_id' => 'e1234567890123' ) );

		$this->assertSame( 'E1234567890123', $result['distribution_id'] );
	}

	public function test_invalid_invalidation_paths_adds_error_and_preserves_previous() {
		$seed = array( 'invalidation_paths' => '/*' );
		$this->seed_settings( $seed );

		$result = $this->plugin->validate_settings( array( 'invalidation_paths' => "blog/*\n/images/*" ) );

		$this->assertSame( '/*', $result['invalidation_paths'] );
		$this->assertSame( 'invalid_invalidation_paths', $this->settings_errors[0]['code'] );
	}

	public function test_valid_invalidation_paths_updates() {
		$this->seed_settings( array( 'invalidation_paths' => '/*' ) );

		$paths  = "/*\n/blog/*\n/images/*";
		$result = $this->plugin->validate_settings( array( 'invalidation_paths' => $paths ) );

		$this->assertSame( $paths, $result['invalidation_paths'] );
	}

	public function test_credentials_flag_cleared_when_one_side_missing() {
		$seed = array(
			'aws_access_key_enc' => 'only-access',
			'credentials_stored' => true,
		);
		$this->seed_settings( $seed );

		$result = $this->plugin->validate_settings( array() );

		$this->assertArrayNotHasKey( 'aws_access_key_enc', $result );
		$this->assertArrayNotHasKey( 'aws_secret_key_enc', $result );
		$this->assertArrayNotHasKey( 'credentials_stored', $result );
	}

	private function seed_settings( array $settings ): void {
		$reflection = new ReflectionClass( $this->plugin );
		$property   = $reflection->getProperty( 'settings' );
		// Note: setAccessible() is no longer needed in PHP 8.1+, as private properties
		// are accessible via reflection by default.
		$property->setValue( $this->plugin, $settings );
	}
}
