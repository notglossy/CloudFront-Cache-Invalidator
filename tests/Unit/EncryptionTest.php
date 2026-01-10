<?php
/**
 * Unit tests for encryption/decryption functionality.
 *
 * Tests the critical security functions that handle AWS credential encryption.
 * These methods are located at lines 170-219 in the main class.
 *
 * @package CloudFrontCacheInvalidator
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Test encryption and decryption methods.
 */
class EncryptionTest extends TestCase {

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

		// Mock wp_json_encode to use json_encode.
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		// Mock wp_salt to return a consistent value.
		Functions\when( 'wp_salt' )->justReturn( 'fallback-salt-value' );

		// Mock get_option to return empty array.
		Functions\when( 'get_option' )->justReturn( array() );

		// Create plugin instance.
		$this->plugin = new NotGlossy_CloudFront_Cache_Invalidator();
	}

	/**
	 * Tear down the test environment after each test.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Test that encryption produces different outputs with the same input.
	 *
	 * Due to the random IV, encrypting the same plaintext multiple times
	 * should produce different ciphertext each time.
	 */
	public function test_encryption_produces_different_outputs_with_same_input() {
		$plaintext = 'test-string';

		$encrypted1 = $this->call_private_method( $this->plugin, 'encrypt_value', array( $plaintext ) );
		$encrypted2 = $this->call_private_method( $this->plugin, 'encrypt_value', array( $plaintext ) );

		$this->assertNotFalse( $encrypted1, 'First encryption should not return false' );
		$this->assertNotFalse( $encrypted2, 'Second encryption should not return false' );
		$this->assertNotEquals( $encrypted1, $encrypted2, 'Encrypting the same value twice should produce different outputs due to random IV' );
	}

	/**
	 * Test that decryption successfully recovers the original plaintext.
	 */
	public function test_decryption_recovers_original_plaintext() {
		foreach ( $this->fixtures['encryption_test_strings'] as $name => $plaintext ) {
			if ( '' === $plaintext ) {
				continue; // Empty strings are handled separately.
			}

			$encrypted = $this->call_private_method( $this->plugin, 'encrypt_value', array( $plaintext ) );
			$decrypted = $this->call_private_method( $this->plugin, 'decrypt_value', array( $encrypted ) );

			$this->assertEquals(
				$plaintext,
				$decrypted,
				sprintf( 'Decryption should recover original plaintext for: %s', $name )
			);
		}
	}

	/**
	 * Test round-trip encryption and decryption.
	 */
	public function test_encrypt_decrypt_round_trip() {
		$test_values = array(
			'simple string',
			'AKIAIOSFODNN7EXAMPLE',
			'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
			'special-chars: !@#$%^&*()',
		);

		foreach ( $test_values as $value ) {
			$encrypted = $this->call_private_method( $this->plugin, 'encrypt_value', array( $value ) );
			$this->assertNotFalse( $encrypted, "Encryption should succeed for: {$value}" );

			$decrypted = $this->call_private_method( $this->plugin, 'decrypt_value', array( $encrypted ) );
			$this->assertEquals( $value, $decrypted, "Round trip should preserve value: {$value}" );
		}
	}

	/**
	 * Test that encrypting an empty string returns false.
	 */
	public function test_encrypt_empty_string_returns_false() {
		$result = $this->call_private_method( $this->plugin, 'encrypt_value', array( '' ) );
		$this->assertFalse( $result, 'Encrypting an empty string should return false' );
	}

	/**
	 * Test that decrypting an empty string returns false.
	 */
	public function test_decrypt_empty_string_returns_false() {
		$result = $this->call_private_method( $this->plugin, 'decrypt_value', array( '' ) );
		$this->assertFalse( $result, 'Decrypting an empty string should return false' );
	}

	/**
	 * Test that decrypting null returns false.
	 */
	public function test_decrypt_null_returns_false() {
		$result = $this->call_private_method( $this->plugin, 'decrypt_value', array( null ) );
		$this->assertFalse( $result, 'Decrypting null should return false' );
	}

	/**
	 * Test handling of malformed encrypted data.
	 */
	public function test_decrypt_malformed_data_returns_false() {
		$malformed_data = array(
			'not valid JSON',
			'{"invalid":"structure"}',
			'{"iv":"","value":""}',
			'{"iv":"valid","value":""}',
			'{"iv":"","value":"valid"}',
		);

		foreach ( $malformed_data as $data ) {
			$result = $this->call_private_method( $this->plugin, 'decrypt_value', array( $data ) );
			$this->assertFalse( $result, "Decrypting malformed data should return false: {$data}" );
		}
	}

	/**
	 * Test handling of invalid base64 in encrypted data.
	 */
	public function test_decrypt_invalid_base64_returns_false() {
		$invalid_data = wp_json_encode(
			array(
				'iv'    => 'not!!!valid!!!base64',
				'value' => 'also!!!not!!!valid',
			)
		);

		$result = $this->call_private_method( $this->plugin, 'decrypt_value', array( $invalid_data ) );
		$this->assertFalse( $result, 'Decrypting data with invalid base64 should return false' );
	}

	/**
	 * Test that encrypted output has the expected JSON structure.
	 */
	public function test_encrypted_output_has_correct_structure() {
		$plaintext = 'test-value';
		$encrypted = $this->call_private_method( $this->plugin, 'encrypt_value', array( $plaintext ) );

		$this->assertNotFalse( $encrypted );

		$data = json_decode( $encrypted, true );
		$this->assertIsArray( $data, 'Encrypted output should be a valid JSON object' );
		$this->assertArrayHasKey( 'iv', $data, 'Encrypted output should have an iv key' );
		$this->assertArrayHasKey( 'value', $data, 'Encrypted output should have a value key' );
		$this->assertNotEmpty( $data['iv'], 'IV should not be empty' );
		$this->assertNotEmpty( $data['value'], 'Value should not be empty' );
	}

	/**
	 * Test that IV is random and different each time.
	 */
	public function test_iv_is_random() {
		$plaintext = 'test';

		$encrypted1 = $this->call_private_method( $this->plugin, 'encrypt_value', array( $plaintext ) );
		$encrypted2 = $this->call_private_method( $this->plugin, 'encrypt_value', array( $plaintext ) );

		$data1 = json_decode( $encrypted1, true );
		$data2 = json_decode( $encrypted2, true );

		$this->assertNotEquals( $data1['iv'], $data2['iv'], 'IV should be different for each encryption' );
	}

	/**
	 * Test encryption key derivation from WordPress salts.
	 */
	public function test_encryption_key_derivation() {
		// The encryption key is derived in get_encryption_key() which uses
		// AUTH_KEY and SECURE_AUTH_KEY constants (defined in bootstrap).
		// We verify the encryption works correctly, which implicitly tests
		// the key derivation.
		$plaintext = 'test-key-derivation';

		$encrypted = $this->call_private_method( $this->plugin, 'encrypt_value', array( $plaintext ) );
		$this->assertNotFalse( $encrypted, 'Encryption with derived key should succeed' );

		$decrypted = $this->call_private_method( $this->plugin, 'decrypt_value', array( $encrypted ) );
		$this->assertEquals( $plaintext, $decrypted, 'Decryption with derived key should succeed' );
	}

	/**
	 * Test that long strings can be encrypted and decrypted.
	 */
	public function test_encrypt_decrypt_long_string() {
		$long_string = str_repeat( 'a', 1000 );

		$encrypted = $this->call_private_method( $this->plugin, 'encrypt_value', array( $long_string ) );
		$this->assertNotFalse( $encrypted, 'Long string encryption should succeed' );

		$decrypted = $this->call_private_method( $this->plugin, 'decrypt_value', array( $encrypted ) );
		$this->assertEquals( $long_string, $decrypted, 'Long string decryption should succeed' );
	}

	/**
	 * Test that unicode/special characters are handled correctly.
	 */
	public function test_encrypt_decrypt_unicode() {
		$unicode_string = 'Test with émojis 🔒 and üñïçödé';

		$encrypted = $this->call_private_method( $this->plugin, 'encrypt_value', array( $unicode_string ) );
		$this->assertNotFalse( $encrypted, 'Unicode string encryption should succeed' );

		$decrypted = $this->call_private_method( $this->plugin, 'decrypt_value', array( $encrypted ) );
		$this->assertEquals( $unicode_string, $decrypted, 'Unicode string should be preserved' );
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
