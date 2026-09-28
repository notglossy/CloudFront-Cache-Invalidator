<?php
/**
 * Credential Manager for CloudFront Cache Invalidator.
 *
 * Handles secure credential management including libsodium authenticated encryption,
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
	 * Credential status: nothing stored or configured.
	 *
	 * @since 1.2.1
	 */
	const STATUS_NONE = 'none';

	/**
	 * Credential status: keys come from constants or environment variables.
	 *
	 * @since 1.2.1
	 */
	const STATUS_EXTERNAL = 'external';

	/**
	 * Credential status: keys are stored in the database and decrypt correctly.
	 *
	 * @since 1.2.1
	 */
	const STATUS_STORED = 'stored';

	/**
	 * Credential status: keys are stored but cannot be decrypted (e.g. salts changed).
	 *
	 * @since 1.2.1
	 */
	const STATUS_UNDECRYPTABLE = 'undecryptable';

	/**
	 * Settings manager instance.
	 *
	 * @since 1.2.0
	 * @access private
	 * @var NotGlossy_CloudFront_Settings_Manager $settings_manager Settings manager instance.
	 */
	private $settings_manager;

	/**
	 * Ciphertexts produced by process_credential_submission() during this request.
	 *
	 * WordPress may run the sanitize callback a second time on its own output.
	 * Only payloads issued here are carried through on that pass, so encrypted
	 * values posted directly in a settings submission are never trusted.
	 *
	 * @since 1.2.1
	 * @access private
	 * @var string[]
	 */
	private $issued_payloads = array();

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
	 * Migrate legacy credentials to current encrypted storage.
	 *
	 * Handles two legacy layouts:
	 *  - plaintext `aws_access_key` / `aws_secret_key` values from v1.0.x;
	 *  - payloads encrypted by v1.1.0 / v1.2.0 with the previous key derivation
	 *    (AES-256-CBC "iv" payloads, or libsodium payloads sealed with the old key).
	 *
	 * Anything that can be recovered is re-encrypted with the current key and
	 * persisted, so the legacy code paths are only ever needed once.
	 *
	 * @since 1.2.0
	 * @access public
	 * @param array $settings Current settings array.
	 * @return array Updated settings.
	 */
	public function migrate_legacy_credentials( $settings ) {
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$updated = false;

		foreach ( array( 'aws_access_key', 'aws_secret_key' ) as $plain_key ) {
			$enc_key = $plain_key . '_enc';

			// Legacy plaintext values.
			if ( isset( $settings[ $plain_key ] ) && is_string( $settings[ $plain_key ] ) && '' !== $settings[ $plain_key ] ) {
				$encrypted = $this->encrypt_value( $settings[ $plain_key ] );
				if ( false !== $encrypted ) {
					$settings[ $enc_key ] = $encrypted;
					$updated              = true;
				}
			}
			unset( $settings[ $plain_key ] );

			// Payloads sealed with a legacy key or cipher.
			if ( ! empty( $settings[ $enc_key ] ) && is_string( $settings[ $enc_key ] ) ) {
				$decrypted = $this->decrypt_payload( $settings[ $enc_key ] );
				if ( false !== $decrypted && $decrypted['legacy'] ) {
					$encrypted = $this->encrypt_value( $decrypted['plaintext'] );
					if ( false !== $encrypted ) {
						$settings[ $enc_key ] = $encrypted;
						$updated              = true;
					}
				}
			}
		}

		if ( $updated ) {
			$settings['credentials_stored'] = ! empty( $settings['aws_access_key_enc'] ) && ! empty( $settings['aws_secret_key_enc'] );
			update_option( $this->settings_manager->get_settings_option(), $settings );
		}

		return $settings;
	}

	/**
	 * Get the salt material shared by all key derivations.
	 *
	 * @since 1.2.1
	 * @access private
	 * @return string[] Salt parts.
	 */
	private function get_key_material() {
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

		return $parts;
	}

	/**
	 * Get derived encryption key from WordPress salts.
	 *
	 * @since 1.2.0
	 * @access private
	 * @return string Binary encryption key.
	 */
	private function get_encryption_key() {
		return hash_hkdf(
			'sha256',
			implode( '|', $this->get_key_material() ),
			SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
			'cloudfront-cache-invalidator-encryption'
		);
	}

	/**
	 * Get the key derivation used by v1.1.0 and v1.2.0 releases.
	 *
	 * Only used to decrypt payloads written before the switch to HKDF, so
	 * they can be re-encrypted with the current key.
	 *
	 * @since 1.2.1
	 * @access private
	 * @return string Binary encryption key.
	 */
	private function get_legacy_encryption_key() {
		return hash( 'sha256', implode( '', $this->get_key_material() ), true );
	}

	/**
	 * Encrypt a value using libsodium authenticated encryption.
	 *
	 * @since 1.2.0
	 * @access private
	 * @param string $plaintext Plaintext to encrypt.
	 * @return string|false JSON encoded ciphertext payload or false on failure.
	 */
	private function encrypt_value( $plaintext ) {
		if ( ! is_string( $plaintext ) || '' === $plaintext ) {
			return false;
		}

		$key   = $this->get_encryption_key();
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $key );

		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Used for encryption, not obfuscation.
		return wp_json_encode(
			array(
				'nonce' => base64_encode( $nonce ),
				'value' => base64_encode( $ciphertext ),
			)
		);
		// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a value previously encrypted with encrypt_value().
	 *
	 * Supports current libsodium payloads (nonce key) and legacy
	 * AES-256-CBC payloads (iv key) for backwards compatibility.
	 *
	 * @since 1.2.0
	 * @access private
	 * @param string $encoded JSON encoded payload from encrypt_value().
	 * @return string|false Plaintext or false on failure.
	 */
	private function decrypt_value( $encoded ) {
		$result = $this->decrypt_payload( $encoded );

		return false === $result ? false : $result['plaintext'];
	}

	/**
	 * Decrypt a payload and report whether a legacy key or cipher was needed.
	 *
	 * @since 1.2.1
	 * @access private
	 * @param string $encoded JSON encoded payload.
	 * @return array|false Array with 'plaintext' and 'legacy' keys, or false on failure.
	 */
	private function decrypt_payload( $encoded ) {
		if ( ! is_string( $encoded ) || '' === $encoded ) {
			return false;
		}

		$data = json_decode( $encoded, true );
		if ( ! is_array( $data ) || empty( $data['value'] ) || ! is_string( $data['value'] ) ) {
			return false;
		}

		// Current libsodium format (nonce key).
		if ( ! empty( $data['nonce'] ) && is_string( $data['nonce'] ) ) {
			return $this->decrypt_sodium( $data );
		}

		// Legacy AES-256-CBC format (iv key).
		if ( ! empty( $data['iv'] ) && is_string( $data['iv'] ) ) {
			return $this->decrypt_legacy_cbc( $data );
		}

		return false;
	}

	/**
	 * Decrypt a libsodium authenticated encryption payload.
	 *
	 * Tries the current key first and falls back to the legacy derivation used
	 * briefly before the switch to HKDF.
	 *
	 * @since 1.2.0
	 * @access private
	 * @param array $data Decoded JSON with nonce and value keys.
	 * @return array|false Array with 'plaintext' and 'legacy' keys, or false on failure.
	 */
	private function decrypt_sodium( $data ) {
		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Used for decryption, not obfuscation.
		$nonce      = base64_decode( $data['nonce'], true );
		$ciphertext = base64_decode( $data['value'], true );
		// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $nonce || false === $ciphertext || SODIUM_CRYPTO_SECRETBOX_NONCEBYTES !== strlen( $nonce ) ) {
			return false;
		}

		$candidates = array(
			array( $this->get_encryption_key(), false ),
			array( $this->get_legacy_encryption_key(), true ),
		);

		foreach ( $candidates as $candidate ) {
			try {
				$plaintext = sodium_crypto_secretbox_open( $ciphertext, $nonce, $candidate[0] );
			} catch ( SodiumException $e ) {
				$plaintext = false;
			}

			if ( false !== $plaintext ) {
				return array(
					'plaintext' => $plaintext,
					'legacy'    => $candidate[1],
				);
			}
		}

		return false;
	}

	/**
	 * Decrypt a legacy AES-256-CBC payload written by v1.1.0 / v1.2.0.
	 *
	 * These releases derived the key with a plain SHA-256 over the salts, so
	 * that derivation is used here. Callers re-encrypt the result with the
	 * current key.
	 *
	 * @since 1.2.0
	 * @access private
	 * @param array $data Decoded JSON with iv and value keys.
	 * @return array|false Array with 'plaintext' and 'legacy' keys, or false on failure.
	 */
	private function decrypt_legacy_cbc( $data ) {
		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Used for decryption, not obfuscation.
		$iv         = base64_decode( $data['iv'], true );
		$ciphertext = base64_decode( $data['value'], true );
		// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $iv || false === $ciphertext || 16 !== strlen( $iv ) ) {
			return false;
		}

		$plaintext = openssl_decrypt( $ciphertext, 'AES-256-CBC', $this->get_legacy_encryption_key(), OPENSSL_RAW_DATA, $iv );

		if ( false === $plaintext ) {
			return false;
		}

		return array(
			'plaintext' => $plaintext,
			'legacy'    => true,
		);
	}

	/**
	 * Check whether a string is a payload produced by encrypt_value() or a
	 * legacy release, without attempting to decrypt it.
	 *
	 * @since 1.2.1
	 * @access private
	 * @param mixed $encoded Candidate payload.
	 * @return bool
	 */
	private function is_encrypted_payload( $encoded ) {
		if ( ! is_string( $encoded ) || '' === $encoded ) {
			return false;
		}

		$data = json_decode( $encoded, true );
		if ( ! is_array( $data ) || empty( $data['value'] ) || ! is_string( $data['value'] ) ) {
			return false;
		}

		$has_nonce = ! empty( $data['nonce'] ) && is_string( $data['nonce'] );
		$has_iv    = ! empty( $data['iv'] ) && is_string( $data['iv'] );

		return $has_nonce || $has_iv;
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
			$decrypted = $this->decrypt_value( $encrypted_value );
			return false === $decrypted ? null : $decrypted;
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
	 * Check whether credentials come from constants or environment variables.
	 *
	 * @since 1.2.1
	 * @access public
	 * @return bool
	 */
	public function has_external_credentials() {
		foreach ( array( 'CLOUDFRONT_AWS_ACCESS_KEY', 'CLOUDFRONT_AWS_SECRET_KEY' ) as $name ) {
			if ( ! ( defined( $name ) && constant( $name ) ) && ! getenv( $name ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check whether an encrypted key pair is stored in the options table.
	 *
	 * @since 1.2.1
	 * @access public
	 * @return bool
	 */
	public function has_stored_credentials() {
		return ! empty( $this->settings_manager->get_setting( 'aws_access_key_enc' ) )
			&& ! empty( $this->settings_manager->get_setting( 'aws_secret_key_enc' ) );
	}

	/**
	 * Describe where credentials come from and whether they are usable.
	 *
	 * @since 1.2.1
	 * @access public
	 * @return string One of the STATUS_* constants.
	 */
	public function get_credential_status() {
		if ( $this->has_external_credentials() ) {
			return self::STATUS_EXTERNAL;
		}

		if ( ! $this->has_stored_credentials() ) {
			return self::STATUS_NONE;
		}

		return $this->has_credentials() ? self::STATUS_STORED : self::STATUS_UNDECRYPTABLE;
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
		$region = $this->settings_manager->get_setting( 'aws_region', 'us-east-1' );

		return ( is_string( $region ) && '' !== trim( $region ) ) ? trim( $region ) : 'us-east-1';
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
	 * Read a submitted credential field as a trimmed string.
	 *
	 * Non-string values (for example an array in a crafted POST) are treated
	 * as empty instead of causing a TypeError.
	 *
	 * @since 1.2.1
	 * @access private
	 * @param array  $input Raw input.
	 * @param string $key   Field name.
	 * @return string
	 */
	private function get_submitted_string( $input, $key ) {
		if ( ! isset( $input[ $key ] ) || ! is_string( $input[ $key ] ) ) {
			return '';
		}

		return trim( $input[ $key ] );
	}

	/**
	 * Validate an AWS access key ID.
	 *
	 * @since 1.2.1
	 * @access private
	 * @param string $value Submitted value.
	 * @return bool
	 */
	private function is_valid_access_key_id( $value ) {
		return 1 === preg_match( '/^[A-Z0-9]{16,128}$/', $value );
	}

	/**
	 * Validate an AWS secret access key.
	 *
	 * Secrets are not sanitized (that would silently alter them); they only
	 * have to be printable ASCII without whitespace.
	 *
	 * @since 1.2.1
	 * @access private
	 * @param string $value Submitted value.
	 * @return bool
	 */
	private function is_valid_secret_key( $value ) {
		return 1 === preg_match( '/^[\x21-\x7E]{16,512}$/', $value );
	}

	/**
	 * Process credential submission from settings form.
	 *
	 * Applies the credential-related parts of a settings submission on top of
	 * `$current` (the settings as stored in the database) and returns the
	 * result. Rules:
	 *
	 *  - Both fields blank: keep whatever is stored.
	 *  - Both fields filled: validate, encrypt and replace the stored pair.
	 *  - Only one field filled: reject with a settings error, keep stored pair.
	 *  - `clear_credentials` checked: remove the stored pair.
	 *  - Encrypted payloads present in `$input` (WordPress runs the sanitize
	 *    callback twice on the first save) are carried through unchanged.
	 *
	 * @since 1.2.0
	 * @access public
	 * @param array      $input   Raw input from settings form.
	 * @param array|null $current Settings to apply the changes to. Defaults to the current settings.
	 * @return array Updated settings with encrypted credentials.
	 */
	public function process_credential_submission( $input, $current = null ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$settings = is_array( $current ) ? $current : $this->settings_manager->get_settings();
		$option   = $this->settings_manager->get_settings_option();

		// Never persist plaintext fields.
		unset( $settings['aws_access_key'], $settings['aws_secret_key'] );

		// Carry through payloads this request encrypted on an earlier sanitize pass.
		foreach ( array( 'aws_access_key_enc', 'aws_secret_key_enc' ) as $enc_key ) {
			if ( isset( $input[ $enc_key ] ) && in_array( $input[ $enc_key ], $this->issued_payloads, true ) ) {
				$settings[ $enc_key ] = $input[ $enc_key ];
			}
		}

		$submitted_access = $this->get_submitted_string( $input, 'aws_access_key' );
		$submitted_secret = $this->get_submitted_string( $input, 'aws_secret_key' );
		$clear_requested  = isset( $input['clear_credentials'] ) && '1' === (string) $input['clear_credentials'];

		if ( $clear_requested ) {
			unset( $settings['aws_access_key_enc'], $settings['aws_secret_key_enc'] );
			$submitted_access = '';
			$submitted_secret = '';
		}

		if ( '' !== $submitted_access || '' !== $submitted_secret ) {
			if ( ! is_ssl() ) {
				add_settings_error( $option, 'cloudfront_https_required', __( 'AWS credentials cannot be saved over an insecure (HTTP) connection. Please use HTTPS.', 'cloudfront-cache-invalidator' ), 'error' );
			} elseif ( '' === $submitted_access || '' === $submitted_secret ) {
				add_settings_error( $option, 'cloudfront_credentials_incomplete', __( 'Enter both the AWS Access Key and the AWS Secret Key to replace the stored credentials. Existing credentials were left unchanged.', 'cloudfront-cache-invalidator' ), 'error' );
			} elseif ( ! $this->is_valid_access_key_id( $submitted_access ) ) {
				add_settings_error( $option, 'cloudfront_invalid_access_key', __( 'The AWS Access Key ID is not in a valid format. Existing credentials were left unchanged.', 'cloudfront-cache-invalidator' ), 'error' );
			} elseif ( ! $this->is_valid_secret_key( $submitted_secret ) ) {
				add_settings_error( $option, 'cloudfront_invalid_secret_key', __( 'The AWS Secret Key is not in a valid format. Existing credentials were left unchanged.', 'cloudfront-cache-invalidator' ), 'error' );
			} else {
				$encrypted_access = $this->encrypt_value( $submitted_access );
				$encrypted_secret = $this->encrypt_value( $submitted_secret );

				if ( false !== $encrypted_access && false !== $encrypted_secret ) {
					$settings['aws_access_key_enc'] = $encrypted_access;
					$settings['aws_secret_key_enc'] = $encrypted_secret;
					$this->issued_payloads[]        = $encrypted_access;
					$this->issued_payloads[]        = $encrypted_secret;
				} else {
					add_settings_error( $option, 'cloudfront_encryption_failed', __( 'AWS credentials could not be encrypted. Existing credentials were left unchanged.', 'cloudfront-cache-invalidator' ), 'error' );
				}
			}
		}

		// Only a complete pair is worth keeping.
		if ( empty( $settings['aws_access_key_enc'] ) || empty( $settings['aws_secret_key_enc'] ) ) {
			unset( $settings['aws_access_key_enc'], $settings['aws_secret_key_enc'], $settings['credentials_stored'] );
		} else {
			$settings['credentials_stored'] = true;
		}

		return $settings;
	}
}
