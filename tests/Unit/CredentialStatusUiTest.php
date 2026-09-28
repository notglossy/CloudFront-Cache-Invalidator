<?php
/**
 * Tests for the admin UI that reports credential status: the persistent
 * admin notice and the "Stored Credentials" settings field.
 *
 * @package CloudFrontCacheInvalidator
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class CredentialStatusUiTest extends TestCase {

	/**
	 * @var NotGlossy_CloudFront_Settings_Manager
	 */
	private $settings_manager;

	/**
	 * @var NotGlossy_CloudFront_Credential_Manager
	 */
	private $credential_manager;

	/**
	 * @var NotGlossy_CloudFront_Admin_Interface
	 */
	private $admin_interface;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'admin_url' )->alias(
			function ( $path = '' ) {
				return 'https://example.com/wp-admin/' . $path;
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'current_user_can' )->justReturn( true );

		putenv( 'CLOUDFRONT_AWS_ACCESS_KEY' );
		putenv( 'CLOUDFRONT_AWS_SECRET_KEY' );

		$this->settings_manager   = new NotGlossy_CloudFront_Settings_Manager();
		$this->credential_manager = new NotGlossy_CloudFront_Credential_Manager( $this->settings_manager );
		$this->settings_manager->set_credential_manager( $this->credential_manager );

		$client                = $this->createMock( NotGlossy_CloudFront_Client::class );
		$invalidation_manager  = new NotGlossy_CloudFront_Invalidation_Manager( $this->settings_manager, $client );
		$this->admin_interface = new NotGlossy_CloudFront_Admin_Interface( $this->settings_manager, $invalidation_manager );
	}

	protected function tearDown(): void {
		putenv( 'CLOUDFRONT_AWS_ACCESS_KEY' );
		putenv( 'CLOUDFRONT_AWS_SECRET_KEY' );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function encrypt( string $plaintext ): string {
		$method = ( new ReflectionClass( $this->credential_manager ) )->getMethod( 'encrypt_value' );
		return $method->invoke( $this->credential_manager, $plaintext );
	}

	private function valid_pair(): array {
		return array(
			'aws_access_key_enc' => $this->encrypt( 'AKIAIOSFODNN7EXAMPLE' ),
			'aws_secret_key_enc' => $this->encrypt( 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY' ),
			'credentials_stored' => true,
		);
	}

	private function undecryptable_pair(): array {
		$payload = json_encode(
			array(
				'nonce' => base64_encode( str_repeat( 'n', SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) ),
				'value' => base64_encode( str_repeat( 'x', 40 ) ),
			)
		);
		return array(
			'aws_access_key_enc' => $payload,
			'aws_secret_key_enc' => $payload,
			'credentials_stored' => true,
		);
	}

	private function notice_output( array $settings ): string {
		$this->settings_manager->set_settings( $settings );
		ob_start();
		$this->admin_interface->display_credential_notice();
		return (string) ob_get_clean();
	}

	private function field_output( array $settings ): string {
		$this->settings_manager->set_settings( $settings );
		ob_start();
		$this->settings_manager->clear_credentials_callback();
		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------
	 * Credential status
	 * ------------------------------------------------------------- */

	public function test_status_values() {
		$this->settings_manager->set_settings( array() );
		$this->assertSame( NotGlossy_CloudFront_Credential_Manager::STATUS_NONE, $this->credential_manager->get_credential_status() );

		$this->settings_manager->set_settings( $this->valid_pair() );
		$this->assertSame( NotGlossy_CloudFront_Credential_Manager::STATUS_STORED, $this->credential_manager->get_credential_status() );

		$this->settings_manager->set_settings( $this->undecryptable_pair() );
		$this->assertSame( NotGlossy_CloudFront_Credential_Manager::STATUS_UNDECRYPTABLE, $this->credential_manager->get_credential_status() );

		putenv( 'CLOUDFRONT_AWS_ACCESS_KEY=AKIAIOSFODNN7EXAMPLE' );
		putenv( 'CLOUDFRONT_AWS_SECRET_KEY=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY' );
		$this->assertSame( NotGlossy_CloudFront_Credential_Manager::STATUS_EXTERNAL, $this->credential_manager->get_credential_status() );
	}

	/* ---------------------------------------------------------------
	 * Admin notice
	 * ------------------------------------------------------------- */

	public function test_notice_when_access_key_mode_has_no_keys() {
		$output = $this->notice_output( array( 'distribution_id' => 'E1234567890AB' ) );

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'no AWS access keys are configured', $output );
		$this->assertStringContainsString( 'options-general.php?page=cloudfront-cache-invalidator', $output );
	}

	public function test_notice_when_stored_keys_cannot_be_decrypted() {
		$output = $this->notice_output( array( 'distribution_id' => 'E1234567890AB' ) + $this->undecryptable_pair() );

		$this->assertStringContainsString( 'cannot be decrypted', $output );
	}

	public function test_no_notice_when_keys_are_usable() {
		$this->assertSame( '', $this->notice_output( array( 'distribution_id' => 'E1234567890AB' ) + $this->valid_pair() ) );
	}

	public function test_no_notice_in_iam_mode() {
		$this->assertSame(
			'',
			$this->notice_output(
				array(
					'distribution_id' => 'E1234567890AB',
					'use_iam_role'    => '1',
				)
			)
		);
	}

	public function test_no_notice_before_distribution_is_configured() {
		$this->assertSame( '', $this->notice_output( array() ) );
	}

	public function test_no_notice_for_users_without_manage_options() {
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->assertSame( '', $this->notice_output( array( 'distribution_id' => 'E1234567890AB' ) ) );
	}

	/* ---------------------------------------------------------------
	 * Stored Credentials field
	 * ------------------------------------------------------------- */

	public function test_field_with_stored_keys_offers_removal() {
		$output = $this->field_output( $this->valid_pair() );

		$this->assertStringContainsString( 'An encrypted access key pair is stored', $output );
		$this->assertStringContainsString( 'name="cloudfront_cache_invalidator_options[clear_credentials]"', $output );
	}

	public function test_field_with_undecryptable_keys_explains_and_offers_removal() {
		$output = $this->field_output( $this->undecryptable_pair() );

		$this->assertStringContainsString( 'cannot be decrypted', $output );
		$this->assertStringContainsString( '[clear_credentials]', $output );
	}

	public function test_field_without_keys_has_no_removal_checkbox() {
		$output = $this->field_output( array() );

		$this->assertStringContainsString( 'No access keys are stored', $output );
		$this->assertStringNotContainsString( 'clear_credentials', $output );
	}

	public function test_field_with_external_credentials() {
		putenv( 'CLOUDFRONT_AWS_ACCESS_KEY=AKIAIOSFODNN7EXAMPLE' );
		putenv( 'CLOUDFRONT_AWS_SECRET_KEY=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY' );

		$output = $this->field_output( array() );

		$this->assertStringContainsString( 'constants or environment variables', $output );
	}

	/* ---------------------------------------------------------------
	 * Key input placeholders
	 * ------------------------------------------------------------- */

	public function test_key_inputs_show_status_and_autocomplete_hints() {
		Functions\when( 'checked' )->justReturn( '' );

		$this->settings_manager->set_settings( $this->undecryptable_pair() );
		ob_start();
		$this->settings_manager->aws_access_key_callback();
		$this->settings_manager->aws_secret_key_callback();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'cannot be decrypted - re-enter', $output );
		$this->assertStringContainsString( 'autocomplete="off"', $output );
		$this->assertStringContainsString( 'autocomplete="new-password"', $output );
		$this->assertStringContainsString( 'value=""', $output );

		$this->settings_manager->set_settings( $this->valid_pair() );
		ob_start();
		$this->settings_manager->aws_access_key_callback();
		$this->assertStringContainsString( '******** (stored)', (string) ob_get_clean() );
	}
}
