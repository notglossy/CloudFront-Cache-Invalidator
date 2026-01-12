<?php
/**
 * Credential Manager for CloudFront Cache Invalidator.
 *
 * Handles secure credential management including AES-256-CBC encryption,
 * environment variable resolution, and IAM role vs access key logic.
 *
 * @since 1.2.0
 * @package CloudFrontCacheInvalidator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Credential Manager class.
 *
 * Responsible for secure credential handling, encryption/decryption,
 * environment variable resolution, and IAM role management.
 *
 * @since 1.2.0
 */
class NotGlossy_CloudFront_Credential_Manager {

	/**
	 * Encryption cipher.
	 *
	 * @since 1.2.0
	 * @access private
	 * @var string CIPHER Encryption algorithm.
	 */
	const CIPHER = 'AES-256-CBC';

	/**
	 * Settings manager instance.
	 *
	 * @since 1.2.0
	 * @access private
	 * @var NotGlossy_CloudFront_Settings_Manager $settings_manager Settings manager instance.
	 */
	private $settings_manager;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 * @access public
	 * @param NotGlossy_CloudFront_Settings_Manager $settings_manager Settings manager instance.
	 */
	public function __construct( NotGlossy_CloudFront_Settings_Manager $settings_manager ) {
		$this->settings_manager = $settings_manager;
	}

	/**
	 * Migrate legacy plaintext credentials to encrypted storage.
	 *
	 * @since 1.2.0
	 * @access public
	 * @param array $settings Current settings array.
	 * @return array Updated settings.
	 */
	public function migrate_legacy_credentials( $settings ) {
		$updated = false;

		if ( isset( $settings['aws_access_key'] ) && ! empty( $settings['aws_access_key'] ) ) {
			$encrypted = $this->encrypt_value( $settings['aws_access_key'] );
			if ( false !== $encrypted ) {
				$settings['aws_access_key_enc'] = $encrypted;
				$settings['credentials_stored'] = true;
				$updated                        = true;
			}
			unset( $settings['aws_access_key'] );
		}

		if ( isset( $settings['aws_secret_key'] ) && ! empty( $settings['aws_secret_key'] ) ) {
			$encrypted = $this->encrypt_value( $settings['aws_secret_key'] );
			if ( false !== $encrypted ) {
				$settings['aws_secret_key_enc'] = $encrypted;
				$settings['credentials_stored'] = true;
				$updated                        = true;
			}
			unset( $settings['aws_secret_key'] );
		}

		if ( $updated ) {
			update_option( $this->settings_manager->get_settings_option(), $settings );
		}

		return $settings;
	}

	/**
	 * Get derived encryption key from WordPress salts.
	 *
	 * @since 1.2.0
	 * @access private
	 * @return string Binary encryption key.
	 */
	private function get_encryption_key() {
		$parts = array();

		if ( defined( 'AUTH_KEY' ) ) {
			$parts[] = AUTH_KEY;
		}

		if ( defined( 'SECURE_AUTH_KEY' ) ) {
			$parts[] = SECURE_AUTH_KEY;
		}

		if ( empty( $parts ) ) {
			$parts[] = wp_salt( 'auth' );
		}

		return hash( 'sha256', implode( '', $parts ), true );
	}

	/**
	 * Encrypt a value using AES-256-CBC.
	 *
	 * @since 1.2.0
	 * @access private
	 * @param string $plaintext Plaintext to encrypt.
	 * @return string|false JSON encoded ciphertext payload or false on failure.
	 */
	private function encrypt_value( $plaintext ) {
		if ( '' === $plaintext ) {
			return false;
		}

		$key = $this->get_encryption_key();
		$iv  = random_bytes( 16 );

		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $ciphertext ) {
			return false;
		}

		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Used for encryption, not obfuscation.
		return wp_json_encode(
			array(
				'iv'    => base64_encode( $iv ),
				'value' => base64_encode( $ciphertext ),
			)
		);
		// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a value previously encrypted with encrypt_value().
	 *
	 * @since 1.2.0
	 * @access private
	 * @param string $encoded JSON encoded payload from encrypt_value().
	 * @return string|false Plaintext or false on failure.
	 */
	private function decrypt_value( $encoded ) {
		if ( empty( $encoded ) ) {
			return false;
		}

		$data = json_decode( $encoded, true );
		if ( ! is_array( $data ) || empty( $data['iv'] ) || empty( $data['value'] ) ) {
			return false;
		}

		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Used for decryption, not obfuscation.
		$iv         = base64_decode( $data['iv'], true );
		$ciphertext = base64_decode( $data['value'], true );
		// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $iv || false === $ciphertext ) {
			return false;
		}

		$plaintext = openssl_decrypt( $ciphertext, self::CIPHER, $this->get_encryption_key(), OPENSSL_RAW_DATA, $iv );

		return false === $plaintext ? false : $plaintext;
	}

	/**
	 * Resolve value from constant/env or encrypted option.
	 *
	 * @since 1.2.0
	 * @access private
	 * @param string $constant_name Constant name.
	 * @param string $env_name      Environment variable name.
	 * @param string $option_key    Option key for encrypted value.
	 * @return string|null
	 */
	private function get_env_or_option( $constant_name, $env_name, $option_key ) {
		if ( defined( $constant_name ) && constant( $constant_name ) ) {
			return constant( $constant_name );
		}

		$env_value = getenv( $env_name );
		if ( $env_value ) {
			return $env_value;
		}

		$encrypted_value = $this->settings_manager->get_setting( $option_key );
		if ( $encrypted_value ) {
			return $this->decrypt_value( $encrypted_value );
		}

		return null;
	}

	/**
	 * Resolve AWS credentials honoring constants/env first.
	 *
	 * @since 1.2.0
	 * @access public
	 * @return array|null Array with key/secret or null.
	 */
	public function resolve_credentials() {
		$access_key = $this->get_env_or_option( 'CLOUDFRONT_AWS_ACCESS_KEY', 'CLOUDFRONT_AWS_ACCESS_KEY', 'aws_access_key_enc' );
		$secret_key = $this->get_env_or_option( 'CLOUDFRONT_AWS_SECRET_KEY', 'CLOUDFRONT_AWS_SECRET_KEY', 'aws_secret_key_enc' );

		if ( $access_key && $secret_key ) {
			return array(
				'key'    => $access_key,
				'secret' => $secret_key,
			);
		}

		return null;
	}

	/**
	 * Check if credentials are available.
	 *
	 * @since 1.2.0
	 * @access public
	 * @return bool True if credentials are available, false otherwise.
	 */
	public function has_credentials() {
		$credentials = $this->resolve_credentials();
		return null !== $credentials && ! empty( $credentials['key'] ) && ! empty( $credentials['secret'] );
	}

	/**
	 * Check if using IAM role.
	 *
	 * @since 1.2.0
	 * @access public
	 * @return bool True if IAM role is enabled, false otherwise.
	 */
	public function is_using_iam_role() {
		return $this->settings_manager->get_setting( 'use_iam_role' ) === '1';
	}

	/**
	 * Get AWS region.
	 *
	 * @since 1.2.0
	 * @access public
	 * @return string AWS region.
	 */
	public function get_aws_region() {
		return $this->settings_manager->get_setting( 'aws_region', 'us-east-1' );
	}

	/**
	 * Get CloudFront distribution ID.
	 *
	 * @since 1.2.0
	 * @access public
	 * @return string CloudFront distribution ID.
	 */
	public function get_distribution_id() {
		return $this->settings_manager->get_setting( 'distribution_id', '' );
	}

	/**
	 * Get default invalidation paths.
	 *
	 * @since 1.2.0
	 * @access public
	 * @return string Default invalidation paths.
	 */
	public function get_default_invalidation_paths() {
		return $this->settings_manager->get_setting( 'invalidation_paths', '/*' );
	}

	/**
	 * Process credential submission from settings form.
	 *
	 * @since 1.2.0
	 * @access public
	 * @param array $input Raw input from settings form.
	 * @return array Updated settings with encrypted credentials.
	 */
	public function process_credential_submission( $input ) {
		$settings = $this->settings_manager->get_settings();

		// Enforce HTTPS for credential submission.
		$is_ssl = is_ssl();

		$submitted_access = isset( $input['aws_access_key'] ) ? trim( $input['aws_access_key'] ) : '';
		$submitted_secret = isset( $input['aws_secret_key'] ) ? trim( $input['aws_secret_key'] ) : '';

		if ( ! $is_ssl && ( '' !== $submitted_access || '' !== $submitted_secret ) ) {
			add_settings_error( $this->settings_manager->get_settings_option(), 'cloudfront_https_required', __( 'AWS credentials cannot be saved over an insecure (HTTP) connection. Please use HTTPS.', 'cloudfront-cache-invalidator' ), 'error' );
			// Do not modify stored credentials if submitted over HTTP.
			return $settings;
		}

		// Access key handling.
		if ( '' !== $submitted_access ) {
			$encrypted = $this->encrypt_value( sanitize_text_field( $submitted_access ) );
			if ( false !== $encrypted ) {
				$settings['aws_access_key_enc'] = $encrypted;
				$settings['credentials_stored'] = true;
			}
		}

		// Secret key handling.
		if ( '' !== $submitted_secret ) {
			$encrypted = $this->encrypt_value( sanitize_text_field( $submitted_secret ) );
			if ( false !== $encrypted ) {
				$settings['aws_secret_key_enc'] = $encrypted;
				$settings['credentials_stored'] = true;
			}
		}

		// Never persist plaintext fields.
		unset( $settings['aws_access_key'], $settings['aws_secret_key'] );

		// If neither encrypted value exists, clear the stored flag.
		if ( empty( $settings['aws_access_key_enc'] ) || empty( $settings['aws_secret_key_enc'] ) ) {
			unset( $settings['aws_access_key_enc'], $settings['aws_secret_key_enc'] );
			unset( $settings['credentials_stored'] );
		}

		return $settings;
	}
}
