<?php
/**
 * Known-answer tests for credentials written by earlier releases.
 *
 * v1.1.0 and v1.2.0 stored AWS keys as AES-256-CBC payloads ({"iv","value"})
 * encrypted with hash('sha256', AUTH_KEY . SECURE_AUTH_KEY, true). For one
 * day before the HKDF change, libsodium payloads were sealed with that same
 * key. Both must still decrypt, and must be re-encrypted with the current key.
 *
 * The payloads below were produced with the salts defined in tests/bootstrap.php.
 *
 * @package CloudFrontCacheInvalidator
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class LegacyCredentialMigrationTest extends TestCase {

	const ACCESS_KEY = 'AKIAIOSFODNN7EXAMPLE';
	const SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

	// AES-256-CBC, legacy key (v1.1.0 / v1.2.0 release format).
	const CBC_ACCESS = '{"iv":"oiMwBCrvzayH5f3zMOaBbA==","value":"HxF71Vh0AOa\/cXuzjO4TCWxLA\/tfJbSxdjL5msUklSM="}';
	const CBC_SECRET = '{"iv":"oiMwBCrvzayH5f3zMOaBbA==","value":"UXtnD3D9XltON7pkzYSlXKb3KCeVrWWiQbPbTH4EvPXv5vfN6d17RjM8tpctGQrg"}';

	// libsodium secretbox, legacy key (commit 6e48b4e, before HKDF).
	const SODIUM_LEGACY_ACCESS = '{"nonce":"TkoybDO9IvbRbDhdSgOdIKLDG16UGkHF","value":"zfGr1NrIxA+bauF4MF8+KgzbiXEQmlDJR9Dboby0rRlUwf\/k"}';
	const SODIUM_LEGACY_SECRET = '{"nonce":"GBkqM3E5+T\/Ej+2akZeehQ3Iu8LEdXNG","value":"2rLQAsuB85CcelFyp0XMOGJ95aQzbp+Zthy10bnfX5NgMGTxYTASPSVnfkK1wZX6GpsUHTvISVE="}';

	/**
	 * @var NotGlossy_CloudFront_Settings_Manager
	 */
	private $settings_manager;

	/**
	 * @var NotGlossy_CloudFront_Credential_Manager
	 */
	private $credential_manager;

	/**
	 * Captured update_option() writes.
	 *
	 * @var array
	 */
	private $writes;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->writes = array();

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_salt' )->justReturn( 'fallback-salt-value' );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->writes[] = compact( 'name', 'value' );
				return true;
			}
		);

		putenv( 'CLOUDFRONT_AWS_ACCESS_KEY' );
		putenv( 'CLOUDFRONT_AWS_SECRET_KEY' );

		$this->settings_manager   = new NotGlossy_CloudFront_Settings_Manager();
		$this->credential_manager = new NotGlossy_CloudFront_Credential_Manager( $this->settings_manager );
		$this->settings_manager->set_credential_manager( $this->credential_manager );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function decrypt( $payload ) {
		$method = ( new ReflectionClass( $this->credential_manager ) )->getMethod( 'decrypt_value' );
		return $method->invoke( $this->credential_manager, $payload );
	}

	private function payload_uses_current_key( string $payload ): bool {
		$method = ( new ReflectionClass( $this->credential_manager ) )->getMethod( 'decrypt_payload' );
		$result = $method->invoke( $this->credential_manager, $payload );
		return is_array( $result ) && false === $result['legacy'];
	}

	public function test_release_cbc_payloads_decrypt() {
		$this->assertSame( self::ACCESS_KEY, $this->decrypt( self::CBC_ACCESS ) );
		$this->assertSame( self::SECRET_KEY, $this->decrypt( self::CBC_SECRET ) );
	}

	public function test_sodium_payloads_sealed_with_legacy_key_decrypt() {
		$this->assertSame( self::ACCESS_KEY, $this->decrypt( self::SODIUM_LEGACY_ACCESS ) );
		$this->assertSame( self::SECRET_KEY, $this->decrypt( self::SODIUM_LEGACY_SECRET ) );
	}

	public function test_legacy_payloads_resolve_as_credentials() {
		$this->settings_manager->set_settings(
			array(
				'aws_access_key_enc' => self::CBC_ACCESS,
				'aws_secret_key_enc' => self::CBC_SECRET,
				'credentials_stored' => true,
			)
		);

		$this->assertSame(
			array(
				'key'    => self::ACCESS_KEY,
				'secret' => self::SECRET_KEY,
			),
			$this->credential_manager->resolve_credentials()
		);
		$this->assertSame( NotGlossy_CloudFront_Credential_Manager::STATUS_STORED, $this->credential_manager->get_credential_status() );
	}

	public function test_migration_reencrypts_cbc_payloads_with_current_key() {
		$settings = array(
			'aws_access_key_enc' => self::CBC_ACCESS,
			'aws_secret_key_enc' => self::CBC_SECRET,
			'credentials_stored' => true,
			'distribution_id'    => 'E1234567890AB',
		);

		$migrated = $this->credential_manager->migrate_legacy_credentials( $settings );

		$this->assertNotSame( self::CBC_ACCESS, $migrated['aws_access_key_enc'] );
		$this->assertTrue( $this->payload_uses_current_key( $migrated['aws_access_key_enc'] ) );
		$this->assertTrue( $this->payload_uses_current_key( $migrated['aws_secret_key_enc'] ) );
		$this->assertSame( self::ACCESS_KEY, $this->decrypt( $migrated['aws_access_key_enc'] ) );
		$this->assertSame( self::SECRET_KEY, $this->decrypt( $migrated['aws_secret_key_enc'] ) );
		$this->assertTrue( $migrated['credentials_stored'] );
		$this->assertSame( 'E1234567890AB', $migrated['distribution_id'] );

		$this->assertCount( 1, $this->writes, 'Re-encrypted payloads are persisted once' );
		$this->assertSame( 'cloudfront_cache_invalidator_options', $this->writes[0]['name'] );
		$this->assertSame( $migrated, $this->writes[0]['value'] );
	}

	public function test_migration_reencrypts_legacy_sodium_payloads() {
		$migrated = $this->credential_manager->migrate_legacy_credentials(
			array(
				'aws_access_key_enc' => self::SODIUM_LEGACY_ACCESS,
				'aws_secret_key_enc' => self::SODIUM_LEGACY_SECRET,
			)
		);

		$this->assertTrue( $this->payload_uses_current_key( $migrated['aws_access_key_enc'] ) );
		$this->assertSame( self::SECRET_KEY, $this->decrypt( $migrated['aws_secret_key_enc'] ) );
		$this->assertCount( 1, $this->writes );
	}

	public function test_migration_encrypts_v1_0_plaintext_values() {
		$migrated = $this->credential_manager->migrate_legacy_credentials(
			array(
				'aws_access_key' => self::ACCESS_KEY,
				'aws_secret_key' => self::SECRET_KEY,
			)
		);

		$this->assertArrayNotHasKey( 'aws_access_key', $migrated );
		$this->assertArrayNotHasKey( 'aws_secret_key', $migrated );
		$this->assertSame( self::ACCESS_KEY, $this->decrypt( $migrated['aws_access_key_enc'] ) );
		$this->assertSame( self::SECRET_KEY, $this->decrypt( $migrated['aws_secret_key_enc'] ) );
		$this->assertTrue( $migrated['credentials_stored'] );
	}

	public function test_migration_leaves_current_payloads_alone() {
		$encrypt = ( new ReflectionClass( $this->credential_manager ) )->getMethod( 'encrypt_value' );
		$settings = array(
			'aws_access_key_enc' => $encrypt->invoke( $this->credential_manager, self::ACCESS_KEY ),
			'aws_secret_key_enc' => $encrypt->invoke( $this->credential_manager, self::SECRET_KEY ),
			'credentials_stored' => true,
		);

		$migrated = $this->credential_manager->migrate_legacy_credentials( $settings );

		$this->assertSame( $settings, $migrated );
		$this->assertCount( 0, $this->writes, 'Nothing is written when nothing changed' );
	}

	public function test_undecryptable_payloads_are_reported_not_used() {
		// A CBC payload made with different salts: wrong key, so it must fail cleanly.
		$other_key = hash( 'sha256', 'other-salts', true );
		$iv        = str_repeat( 'a', 16 );
		$payload   = json_encode(
			array(
				'iv'    => base64_encode( $iv ),
				'value' => base64_encode( openssl_encrypt( self::ACCESS_KEY, 'AES-256-CBC', $other_key, OPENSSL_RAW_DATA, $iv ) ),
			)
		);

		$this->settings_manager->set_settings(
			array(
				'aws_access_key_enc' => $payload,
				'aws_secret_key_enc' => $payload,
				'credentials_stored' => true,
			)
		);

		$this->assertNull( $this->credential_manager->resolve_credentials() );
		$this->assertFalse( $this->credential_manager->has_credentials() );
		$this->assertSame( NotGlossy_CloudFront_Credential_Manager::STATUS_UNDECRYPTABLE, $this->credential_manager->get_credential_status() );

		$migrated = $this->credential_manager->migrate_legacy_credentials( $this->settings_manager->get_settings() );
		$this->assertSame( $payload, $migrated['aws_access_key_enc'], 'Undecryptable payloads are left in place for the admin to replace' );
		$this->assertCount( 0, $this->writes );
	}
}
