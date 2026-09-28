<?php
/**
 * Tests for the sanitize callback registered with register_setting().
 *
 * These exercise NotGlossy_CloudFront_Settings_Manager::validate_settings()
 * directly, which is the callable WordPress actually runs on save, and
 * simulate the way WordPress calls it: with the stored option available via
 * get_option(), and twice on the very first save (update_option() falls
 * through to add_option(), which sanitizes the already-sanitized value again).
 *
 * @package CloudFrontCacheInvalidator
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class SettingsValidationTest extends TestCase {
	/**
	 * Settings manager under test (owns the registered sanitize callback).
	 *
	 * @var NotGlossy_CloudFront_Settings_Manager
	 */
	private $settings_manager;

	/**
	 * Credential manager attached to the settings manager.
	 *
	 * @var NotGlossy_CloudFront_Credential_Manager
	 */
	private $credential_manager;

	/**
	 * Value get_option() returns for the plugin option ("the database").
	 *
	 * @var mixed
	 */
	private $stored;

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
		$this->stored          = array();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
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
				$lines = array_map( 'trim', explode( "\n", $value ) );
				return implode( "\n", $lines );
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return 'cloudfront_cache_invalidator_options' === $name ? $this->stored : $fallback;
			}
		);
		Functions\when( 'add_settings_error' )->alias(
			function ( $option, $code, $message, $type = 'error' ) {
				$this->settings_errors[] = compact( 'option', 'code', 'message', 'type' );
			}
		);
		Functions\when( 'is_ssl' )->justReturn( true );

		$this->settings_manager   = new NotGlossy_CloudFront_Settings_Manager();
		$this->credential_manager = new NotGlossy_CloudFront_Credential_Manager( $this->settings_manager );
		$this->settings_manager->set_credential_manager( $this->credential_manager );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Run the sanitize callback exactly as WordPress does.
	 *
	 * @param array $input     Submitted form data.
	 * @param bool  $first_save Simulate the add_option() double run.
	 * @return array
	 */
	private function sanitize( array $input, bool $first_save = false ): array {
		$result = $this->settings_manager->validate_settings( $input );
		if ( $first_save ) {
			$result = $this->settings_manager->validate_settings( $result );
		}
		return $result;
	}

	private function decrypt( string $payload ) {
		$method = ( new ReflectionClass( $this->credential_manager ) )->getMethod( 'decrypt_value' );
		return $method->invoke( $this->credential_manager, $payload );
	}

	private function encrypt( string $plaintext ): string {
		$method = ( new ReflectionClass( $this->credential_manager ) )->getMethod( 'encrypt_value' );
		return $method->invoke( $this->credential_manager, $plaintext );
	}

	private function error_codes(): array {
		return array_column( $this->settings_errors, 'code' );
	}

	private function form( array $overrides = array() ): array {
		return array_merge(
			array(
				'aws_access_key'     => '',
				'aws_secret_key'     => '',
				'aws_region'         => 'us-east-1',
				'distribution_id'    => 'E1234567890AB',
				'invalidation_paths' => '/*',
			),
			$overrides
		);
	}

	/* ---------------------------------------------------------------
	 * Registration
	 * ------------------------------------------------------------- */

	public function test_register_setting_uses_settings_manager_callback() {
		$registered = null;
		Functions\when( 'register_setting' )->alias(
			function ( $group, $option, $args ) use ( &$registered ) {
				$registered = compact( 'group', 'option', 'args' );
			}
		);
		Functions\when( 'add_settings_section' )->justReturn( null );
		Functions\when( 'add_settings_field' )->justReturn( null );

		$this->settings_manager->register_settings();

		$this->assertSame( 'cloudfront_cache_invalidator_options', $registered['option'] );
		$this->assertSame( array( $this->settings_manager, 'validate_settings' ), $registered['args'], 'The tests below must exercise the callable WordPress actually runs' );
	}

	/* ---------------------------------------------------------------
	 * First save (double sanitize)
	 * ------------------------------------------------------------- */

	public function test_first_save_keeps_credentials_and_iam_off() {
		$this->stored = false; // Option does not exist yet.

		$result = $this->sanitize(
			$this->form(
				array(
					'aws_access_key' => 'AKIAIOSFODNN7EXAMPLE',
					'aws_secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
				)
			),
			true
		);

		$this->assertSame( '0', $result['use_iam_role'], 'Unchecked checkbox must stay off after the second sanitize pass' );
		$this->assertTrue( $result['credentials_stored'] );
		$this->assertSame( 'AKIAIOSFODNN7EXAMPLE', $this->decrypt( $result['aws_access_key_enc'] ) );
		$this->assertSame( 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', $this->decrypt( $result['aws_secret_key_enc'] ) );
		$this->assertArrayNotHasKey( 'aws_access_key', $result );
		$this->assertArrayNotHasKey( 'aws_secret_key', $result );
		$this->assertSame( 'E1234567890AB', $result['distribution_id'] );
		$this->assertEmpty( $this->settings_errors );
	}

	public function test_first_save_with_iam_checked_keeps_it_on() {
		$this->stored = false;

		$result = $this->sanitize( $this->form( array( 'use_iam_role' => '1' ) ), true );

		$this->assertSame( '1', $result['use_iam_role'] );
	}

	public function test_posted_ciphertext_is_not_trusted() {
		$stored_access = $this->encrypt( 'AKIASTOREDSTOREDSTO1' );
		$stored_secret = $this->encrypt( 'storedstoredstoredstoredstoredstoredsto1' );
		$this->stored  = array(
			'aws_access_key_enc' => $stored_access,
			'aws_secret_key_enc' => $stored_secret,
			'credentials_stored' => true,
		);

		// Well-formed ciphertext injected into the form must not replace the stored pair.
		$result = $this->sanitize(
			$this->form(
				array(
					'aws_access_key_enc' => $this->encrypt( 'AKIAINJECTEDINJECTE1' ),
					'aws_secret_key_enc' => $this->encrypt( 'injectedinjectedinjectedinjectedinject1' ),
				)
			)
		);

		$this->assertSame( $stored_access, $result['aws_access_key_enc'] );
		$this->assertSame( $stored_secret, $result['aws_secret_key_enc'] );
	}

	public function test_sanitizer_is_a_fixed_point_on_its_own_output() {
		$this->stored = array();

		$once  = $this->sanitize(
			$this->form(
				array(
					'aws_access_key' => 'AKIAIOSFODNN7EXAMPLE',
					'aws_secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
				)
			)
		);
		$twice = $this->sanitize( $once );

		$this->assertSame( $once, $twice );
	}

	/* ---------------------------------------------------------------
	 * Rotation, preservation and removal
	 * ------------------------------------------------------------- */

	public function test_new_keys_replace_stored_keys() {
		$this->stored = array(
			'aws_access_key_enc' => $this->encrypt( 'AKIAOLDOLDOLDOLDOLD1' ),
			'aws_secret_key_enc' => $this->encrypt( 'oldoldoldoldoldoldoldoldoldoldoldoldold1' ),
			'credentials_stored' => true,
			'distribution_id'    => 'E1234567890AB',
		);

		$result = $this->sanitize(
			$this->form(
				array(
					'aws_access_key' => 'AKIANEWNEWNEWNEWNEW1',
					'aws_secret_key' => 'newnewnewnewnewnewnewnewnewnewnewnewnew1',
				)
			)
		);

		$this->assertSame( 'AKIANEWNEWNEWNEWNEW1', $this->decrypt( $result['aws_access_key_enc'] ) );
		$this->assertSame( 'newnewnewnewnewnewnewnewnewnewnewnewnew1', $this->decrypt( $result['aws_secret_key_enc'] ) );
		$this->assertEmpty( $this->settings_errors );
	}

	public function test_blank_fields_preserve_stored_keys() {
		$this->stored = array(
			'aws_access_key_enc' => 'enc-access',
			'aws_secret_key_enc' => 'enc-secret',
			'credentials_stored' => true,
		);

		$result = $this->sanitize( $this->form() );

		$this->assertSame( 'enc-access', $result['aws_access_key_enc'] );
		$this->assertSame( 'enc-secret', $result['aws_secret_key_enc'] );
		$this->assertTrue( $result['credentials_stored'] );
		$this->assertEmpty( $this->settings_errors );
	}

	public function test_clear_credentials_removes_stored_keys() {
		$this->stored = array(
			'aws_access_key_enc' => 'enc-access',
			'aws_secret_key_enc' => 'enc-secret',
			'credentials_stored' => true,
			'distribution_id'    => 'E1234567890AB',
		);

		$result = $this->sanitize( $this->form( array( 'clear_credentials' => '1' ) ) );

		$this->assertArrayNotHasKey( 'aws_access_key_enc', $result );
		$this->assertArrayNotHasKey( 'aws_secret_key_enc', $result );
		$this->assertArrayNotHasKey( 'credentials_stored', $result );
		$this->assertSame( 'E1234567890AB', $result['distribution_id'], 'Other settings are untouched' );
	}

	public function test_half_submitted_pair_is_rejected_and_stored_pair_kept() {
		$this->stored = array(
			'aws_access_key_enc' => 'enc-access',
			'aws_secret_key_enc' => 'enc-secret',
			'credentials_stored' => true,
		);

		$result = $this->sanitize( $this->form( array( 'aws_access_key' => 'AKIANEWNEWNEWNEWNEW1' ) ) );

		$this->assertSame( 'enc-access', $result['aws_access_key_enc'] );
		$this->assertSame( 'enc-secret', $result['aws_secret_key_enc'] );
		$this->assertContains( 'cloudfront_credentials_incomplete', $this->error_codes() );
	}

	public function test_half_submitted_pair_with_nothing_stored_stores_nothing() {
		$this->stored = array();

		$result = $this->sanitize( $this->form( array( 'aws_secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY' ) ) );

		$this->assertArrayNotHasKey( 'aws_access_key_enc', $result );
		$this->assertArrayNotHasKey( 'aws_secret_key_enc', $result );
		$this->assertArrayNotHasKey( 'credentials_stored', $result );
		$this->assertContains( 'cloudfront_credentials_incomplete', $this->error_codes() );
	}

	public function test_stale_half_pair_in_storage_is_dropped() {
		$this->stored = array(
			'aws_access_key_enc' => 'only-access',
			'credentials_stored' => true,
		);

		$result = $this->sanitize( $this->form() );

		$this->assertArrayNotHasKey( 'aws_access_key_enc', $result );
		$this->assertArrayNotHasKey( 'credentials_stored', $result );
	}

	public function test_invalid_key_formats_are_rejected() {
		$this->stored = array();

		$result = $this->sanitize(
			$this->form(
				array(
					'aws_access_key' => 'not a key',
					'aws_secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
				)
			)
		);

		$this->assertArrayNotHasKey( 'aws_access_key_enc', $result );
		$this->assertContains( 'cloudfront_invalid_access_key', $this->error_codes() );
	}

	public function test_array_valued_credential_fields_do_not_fatal() {
		$this->stored = array();

		$result = $this->sanitize(
			$this->form(
				array(
					'aws_access_key'  => array( 'x' ),
					'aws_secret_key'  => array( 'y' ),
					'aws_region'      => array( 'z' ),
					'distribution_id' => array( 'w' ),
				)
			)
		);

		$this->assertArrayNotHasKey( 'aws_access_key_enc', $result );
		$this->assertSame( 'us-east-1', $result['aws_region'] );
		$this->assertSame( '', $result['distribution_id'] );
	}

	public function test_plaintext_keys_never_survive_sanitization() {
		$this->stored = array(
			'aws_access_key' => 'AKIALEGACYPLAINTEXT1',
			'aws_secret_key' => 'legacyplaintextsecretlegacyplaintextsec1',
		);

		$result = $this->sanitize( $this->form() );

		$this->assertArrayNotHasKey( 'aws_access_key', $result );
		$this->assertArrayNotHasKey( 'aws_secret_key', $result );
	}

	/* ---------------------------------------------------------------
	 * HTTPS
	 * ------------------------------------------------------------- */

	public function test_https_blocks_plaintext_credentials_on_http() {
		Functions\when( 'is_ssl' )->justReturn( false );
		$this->stored = array();

		$result = $this->sanitize(
			$this->form(
				array(
					'aws_access_key' => 'AKIAIOSFODNN7EXAMPLE',
					'aws_secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
				)
			)
		);

		$this->assertArrayNotHasKey( 'aws_access_key_enc', $result );
		$this->assertArrayNotHasKey( 'aws_secret_key_enc', $result );
		$this->assertArrayNotHasKey( 'credentials_stored', $result );
		$this->assertSame( 'cloudfront_cache_invalidator_options', $this->settings_errors[0]['option'] );
		$this->assertSame( 'cloudfront_https_required', $this->settings_errors[0]['code'] );
	}

	public function test_http_submission_preserves_existing_credentials() {
		Functions\when( 'is_ssl' )->justReturn( false );
		$this->stored = array(
			'aws_access_key_enc' => 'enc-access',
			'aws_secret_key_enc' => 'enc-secret',
			'credentials_stored' => true,
		);

		$result = $this->sanitize(
			$this->form(
				array(
					'aws_access_key' => 'AKIANEWNEWNEWNEWNEW1',
					'aws_secret_key' => 'newnewnewnewnewnewnewnewnewnewnewnewnew1',
				)
			)
		);

		$this->assertSame( 'enc-access', $result['aws_access_key_enc'] );
		$this->assertSame( 'enc-secret', $result['aws_secret_key_enc'] );
		$this->assertTrue( $result['credentials_stored'] );
		$this->assertSame( 'cloudfront_https_required', $this->settings_errors[0]['code'] );
	}

	/* ---------------------------------------------------------------
	 * IAM checkbox
	 * ------------------------------------------------------------- */

	public function test_iam_role_checkbox_toggles_and_does_not_clear_creds() {
		$this->stored = array(
			'aws_access_key_enc' => 'enc-access',
			'aws_secret_key_enc' => 'enc-secret',
			'credentials_stored' => true,
		);

		$checked = $this->sanitize( array( 'use_iam_role' => '1' ) );
		$this->assertSame( '1', $checked['use_iam_role'] );
		$this->assertSame( 'enc-access', $checked['aws_access_key_enc'] );
		$this->assertSame( 'enc-secret', $checked['aws_secret_key_enc'] );

		$unchecked = $this->sanitize( array() );
		$this->assertSame( '0', $unchecked['use_iam_role'] );

		$zero = $this->sanitize( array( 'use_iam_role' => '0' ) );
		$this->assertSame( '0', $zero['use_iam_role'], 'A submitted "0" is not "checked"' );
	}

	/* ---------------------------------------------------------------
	 * Region, distribution ID and paths
	 * ------------------------------------------------------------- */

	public function test_invalid_region_adds_error_and_preserves_previous() {
		$this->stored = array( 'aws_region' => 'eu-west-2' );

		$result = $this->sanitize( array( 'aws_region' => 'bad_region' ) );

		$this->assertSame( 'eu-west-2', $result['aws_region'] );
		$this->assertSame( 'invalid_aws_region', $this->settings_errors[0]['code'] );
	}

	public function test_valid_region_updates_and_normalizes() {
		$result = $this->sanitize( array( 'aws_region' => ' EU-West-2 ' ) );

		$this->assertSame( 'eu-west-2', $result['aws_region'] );
	}

	public function test_multi_segment_partition_regions_are_accepted() {
		$result = $this->sanitize( array( 'aws_region' => 'us-gov-west-1' ) );

		$this->assertSame( 'us-gov-west-1', $result['aws_region'] );
		$this->assertEmpty( $this->settings_errors );
	}

	public function test_blank_region_falls_back_to_default() {
		$this->stored = array( 'aws_region' => 'eu-west-2' );

		$result = $this->sanitize( array( 'aws_region' => '' ) );

		$this->assertSame( 'us-east-1', $result['aws_region'] );
		$this->assertEmpty( $this->settings_errors );
	}

	public function test_invalid_distribution_id_adds_error_and_preserves_previous() {
		$this->stored = array( 'distribution_id' => 'OLDID123456789' );

		$result = $this->sanitize( array( 'distribution_id' => 'bad' ) );

		$this->assertSame( 'OLDID123456789', $result['distribution_id'] );
		$this->assertSame( 'invalid_distribution_id', $this->settings_errors[0]['code'] );
	}

	public function test_valid_distribution_id_updates_and_normalizes() {
		$result = $this->sanitize( array( 'distribution_id' => ' e1234567890abc ' ) );

		$this->assertSame( 'E1234567890ABC', $result['distribution_id'] );
	}

	public function test_invalid_invalidation_paths_adds_error_and_preserves_previous() {
		$this->stored = array( 'invalidation_paths' => '/*' );

		$result = $this->sanitize( array( 'invalidation_paths' => "blog/*\n/images/*" ) );

		$this->assertSame( '/*', $result['invalidation_paths'] );
		$this->assertSame( 'invalid_invalidation_paths', $this->settings_errors[0]['code'] );
	}

	public function test_valid_invalidation_paths_updates() {
		$result = $this->sanitize( array( 'invalidation_paths' => "  /*  \n  /blog/*  \n\n  /images/*  " ) );

		$this->assertSame( "/*\n/blog/*\n/images/*", $result['invalidation_paths'] );
		$this->assertEmpty( $this->settings_errors );
	}

	/* ---------------------------------------------------------------
	 * Legacy wrapper on the main class shares the same code path
	 * ------------------------------------------------------------- */

	public function test_plugin_wrapper_delegates_to_registered_callback() {
		$this->stored = array(
			'aws_access_key_enc' => 'enc-access',
			'aws_secret_key_enc' => 'enc-secret',
			'credentials_stored' => true,
		);
		$plugin = new NotGlossy_CloudFront_Cache_Invalidator();

		$result = $plugin->validate_settings( $this->form( array( 'aws_access_key' => 'AKIANEWNEWNEWNEWNEW1' ) ) );

		$this->assertSame( 'enc-access', $result['aws_access_key_enc'] );
		$this->assertContains( 'cloudfront_credentials_incomplete', $this->error_codes() );
	}
}
