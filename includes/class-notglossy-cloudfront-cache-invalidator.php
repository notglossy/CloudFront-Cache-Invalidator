<?php
/**
 * Main plugin class for CloudFront Cache Invalidator.
 *
 * This class is responsible for handling all functionality related to
 * CloudFront cache invalidation triggered by WordPress content changes.
 *
 * @since 1.0.0
 * @package CloudFrontCacheInvalidator
 */
class NotGlossy_CloudFront_Cache_Invalidator {

	const CIPHER = 'AES-256-CBC';

	/**
	 * The plugin settings array.
	 *
	 * @since 1.0.0
	 * @access private
	 * @var array $settings Stores all plugin settings.
	 */
	private $settings;

	/**
	 * Settings group name.
	 *
	 * @since 1.0.0
	 * @access private
	 * @var string $settings_group WordPress settings group name.
	 */
	private $settings_group = 'cloudfront_cache_invalidator_settings';

	/**
	 * Settings option name.
	 *
	 * @since 1.0.0
	 * @access private
	 * @var string $settings_option WordPress settings option name.
	 */
	private $settings_option = 'cloudfront_cache_invalidator_options';

	/**
	 * Settings section ID.
	 *
	 * @since 1.0.0
	 * @access private
	 * @var string $settings_section WordPress settings section ID.
	 */
	private $settings_section = 'cloudfront_cache_invalidator_section';

	/**
	 * Constructor.
	 *
	 * Initializes the plugin by setting up WordPress hooks and loading settings.
	 * Also registers hooks for content updates that should trigger cache invalidation.
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function __construct() {

		// Load settings.
		$this->settings = get_option( $this->settings_option, array() );
		if ( ! is_array( $this->settings ) ) {
			$this->settings = array();
		}
		$this->settings = $this->migrate_legacy_credentials( $this->settings );

		// Add JavaScript for the settings page.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

		// Initialize settings.
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );

		// Handle manual invalidation form submission.
		add_action( 'admin_post_cloudfront_invalidate_all', array( $this, 'handle_manual_invalidation' ) );
		add_action( 'admin_notices', array( $this, 'display_invalidation_notices' ) );

		// Register hooks for content updates.
		add_action( 'save_post', array( $this, 'invalidate_on_post_update' ), 10, 3 );
		add_action( 'deleted_post', array( $this, 'invalidate_on_post_delete' ) );
		add_action( 'switch_theme', array( $this, 'invalidate_all' ) );
		add_action( 'customize_save_after', array( $this, 'invalidate_all' ) );
		add_action( 'update_option_permalink_structure', array( $this, 'invalidate_all' ) );
		add_action( 'activated_plugin', array( $this, 'invalidate_all' ) );
		add_action( 'deactivated_plugin', array( $this, 'invalidate_all' ) );

		// Custom invalidation for menus.
		add_action( 'wp_update_nav_menu', array( $this, 'invalidate_all' ) );

		// Custom invalidation for widgets.
		add_action( 'update_option_sidebars_widgets', array( $this, 'invalidate_all' ) );

		// Custom invalidation for categories/terms.
		add_action( 'edited_term', array( $this, 'invalidate_on_term_update' ), 10, 3 );
	}

	/**
		* Migrate legacy plaintext credentials to encrypted storage.
		*
		* @since 1.0.1
		* @access private
		* @param array $settings Current settings array.
		* @return array Updated settings.
		*/
	private function migrate_legacy_credentials( $settings ) {
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
			update_option( $this->settings_option, $settings );
		}

		return $settings;
	}

	/**
		* Get derived encryption key from WordPress salts.
		*
		* @since 1.0.1
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
		* @since 1.0.1
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
		* @since 1.0.1
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
		* @since 1.0.1
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

		if ( isset( $this->settings[ $option_key ] ) ) {
			return $this->decrypt_value( $this->settings[ $option_key ] );
		}

		return null;
	}

	/**
		* Resolve AWS credentials honoring constants/env first.
		*
		* @since 1.0.1
		* @access private
		* @return array|null Array with key/secret or null.
		*/
	private function resolve_credentials() {
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
	 * Register plugin settings.
	 *
	 * Sets up the WordPress settings API fields, sections, and validations
	 * for the plugin's configuration page.
	 *
	 * @since 1.0.0
	 * @access public
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			$this->settings_group,
			$this->settings_option,
			array( $this, 'validate_settings' )
		);

		add_settings_section(
			$this->settings_section,
			'CloudFront Cache Invalidator Settings',
			array( $this, 'settings_section_callback' ),
			'cloudfront-cache-invalidator'
		);

		add_settings_field(
			'use_iam_role',
			'Use IAM Role',
			array( $this, 'use_iam_role_callback' ),
			'cloudfront-cache-invalidator',
			$this->settings_section
		);

		add_settings_field(
			'aws_access_key',
			'AWS Access Key',
			array( $this, 'aws_access_key_callback' ),
			'cloudfront-cache-invalidator',
			$this->settings_section
		);

		add_settings_field(
			'aws_secret_key',
			'AWS Secret Key',
			array( $this, 'aws_secret_key_callback' ),
			'cloudfront-cache-invalidator',
			$this->settings_section
		);

		add_settings_field(
			'aws_region',
			'AWS Region',
			array( $this, 'aws_region_callback' ),
			'cloudfront-cache-invalidator',
			$this->settings_section
		);

		add_settings_field(
			'distribution_id',
			'CloudFront Distribution ID',
			array( $this, 'distribution_id_callback' ),
			'cloudfront-cache-invalidator',
			$this->settings_section
		);

		add_settings_field(
			'invalidation_paths',
			'Default Invalidation Paths',
			array( $this, 'invalidation_paths_callback' ),
			'cloudfront-cache-invalidator',
			$this->settings_section
		);
	}

	/**
	 * Add settings page to admin menu.
	 *
	 * Creates the admin menu item under Settings for plugin configuration.
	 *
	 * @since 1.0.0
	 * @access public
	 * @return void
	 */
	public function add_settings_page() {
		add_options_page(
			'CloudFront Cache Invalidator',
			'CloudFront Cache',
			'manage_options',
			'cloudfront-cache-invalidator',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Enqueue admin scripts.
	 *
	 * Adds JavaScript to the settings page for dynamic form behavior
	 * like toggling access key fields when IAM role is selected.
	 *
	 * @since 1.0.0
	 * @access public
	 * @param string $hook The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_admin_scripts( $hook ) {

		if ( 'settings_page_cloudfront-cache-invalidator' !== $hook ) {
			return;
		}

		// Register and enqueue inline script.
		wp_register_script( 'cloudfront-cache-invalidator-js', false, array(), '1.0.0', false );
		wp_enqueue_script( 'cloudfront-cache-invalidator-js' );

		$script = '
			jQuery(document).ready(function($) {
				var toggleCredentialFields = function() {
					var usingIamRole = $("#use_iam_role").is(":checked");
					if(usingIamRole) {
						$("#aws_access_key, #aws_secret_key").attr("disabled", "disabled");
					} else {
						$("#aws_access_key, #aws_secret_key").removeAttr("disabled");
					}
				};

				// Initial state
				toggleCredentialFields();

				// On change
				$("#use_iam_role").on("change", toggleCredentialFields);
			});
		';

		wp_add_inline_script( 'cloudfront-cache-invalidator-js', $script );
	}

	/**
	 * Settings section description.
	 *
	 * Outputs the HTML for the settings section description on the admin page.
	 *
	 * @since 1.0.0
	 * @access public
	 * @return void
	 */
	public function settings_section_callback() {
		echo '<p>Configure your AWS credentials and CloudFront distribution settings.</p>';
		echo '<p>If your WordPress site is running on an EC2 instance or other AWS service, you can use IAM roles for secure, key-less authentication.</p>';
	}

	/**
	 * IAM Role field callback.
	 *
	 * Renders the IAM role checkbox field for the settings page.
	 * This enables using AWS IAM roles instead of access keys.
	 *
	 * @since 1.0.0
	 * @access public
	 * @return void
	 */
	public function use_iam_role_callback() {
		$value = isset( $this->settings['use_iam_role'] ) ? $this->settings['use_iam_role'] : '0';
		echo '<input type="checkbox" id="use_iam_role" name="' . esc_attr( $this->settings_option ) . '[use_iam_role]" value="1" ' . checked( '1', $value, false ) . '/>';
		echo '<label for="use_iam_role"> Use instance IAM role (recommended if your WordPress server is running on AWS)</label>';
		echo '<p class="description">When enabled, AWS access keys below are optional and will only be used as a fallback.</p>';
	}

	/**
	 * AWS Access Key field callback.
	 *
	 * Renders the AWS Access Key field for the settings page.
	 * This field can be disabled when using IAM roles.
	 *
	 * @since 1.0.0
	 * @access public
	 * @return void
	 */
	public function aws_access_key_callback() {
		$disabled    = isset( $this->settings['use_iam_role'] ) && '1' === $this->settings['use_iam_role'] ? 'disabled' : '';
		$has_stored  = ! empty( $this->settings['credentials_stored'] ) && ! empty( $this->settings['aws_access_key_enc'] );
		$placeholder = $has_stored ? '******** (stored)' : '';
		echo '<input type="text" id="aws_access_key" name="' . esc_attr( $this->settings_option ) . '[aws_access_key]" value="" placeholder="' . esc_attr( $placeholder ) . '" class="regular-text" ' . esc_attr( $disabled ) . '/>';
		echo '<p class="description">Optional when using IAM role. Leave blank to keep existing; enter a new key to replace.</p>';
	}

	/**
	 * AWS Secret Key field callback.
	 *
	 * Renders the AWS Secret Key field for the settings page.
	 * This field can be disabled when using IAM roles.
	 *
	 * @since 1.0.0
	 * @access public
	 * @return void
	 */
	public function aws_secret_key_callback() {
		$disabled    = isset( $this->settings['use_iam_role'] ) && '1' === $this->settings['use_iam_role'] ? 'disabled' : '';
		$has_stored  = ! empty( $this->settings['credentials_stored'] ) && ! empty( $this->settings['aws_secret_key_enc'] );
		$placeholder = $has_stored ? '******** (stored)' : '';
		echo '<input type="password" id="aws_secret_key" name="' . esc_attr( $this->settings_option ) . '[aws_secret_key]" value="" placeholder="' . esc_attr( $placeholder ) . '" class="regular-text" ' . esc_attr( $disabled ) . '/>';
		echo '<p class="description">Optional when using IAM role. Leave blank to keep existing; enter a new secret to replace.</p>';
	}

	/**
	 * AWS Region field callback.
	 *
	 * Renders the AWS Region field for the settings page.
	 * Defaults to us-east-1 if not specified.
	 *
	 * @since 1.0.0
	 * @access public
	 * @return void
	 */
	public function aws_region_callback() {
		$value = isset( $this->settings['aws_region'] ) ? $this->settings['aws_region'] : 'us-east-1';
		echo '<input type="text" id="aws_region" name="' . esc_attr( $this->settings_option ) . '[aws_region]" value="' . esc_attr( $value ) . '" class="regular-text" />';
		echo '<p class="description">AWS region (e.g., us-east-1, eu-west-2, ap-southeast-1). Default: us-east-1</p>';
	}

	/**
	 * Distribution ID field callback.
	 *
	 * Renders the CloudFront Distribution ID field for the settings page.
	 * This is required for all invalidation requests.
	 *
	 * @since 1.0.0
	 * @access public
	 * @return void
	 */
	public function distribution_id_callback() {
		$value = isset( $this->settings['distribution_id'] ) ? $this->settings['distribution_id'] : '';
		echo '<input type="text" id="distribution_id" name="' . esc_attr( $this->settings_option ) . '[distribution_id]" value="' . esc_attr( $value ) . '" class="regular-text" />';
		echo '<p class="description">CloudFront Distribution ID (13-14 uppercase characters, e.g., E1ABCDEFGHIJKL)</p>';
	}

	/**
	 * Invalidation Paths field callback.
	 *
	 * Renders the Default Invalidation Paths field for the settings page.
	 * These paths will be used for site-wide invalidations.
	 *
	 * @since 1.0.0
	 * @access public
	 * @return void
	 */
	public function invalidation_paths_callback() {
		$value = isset( $this->settings['invalidation_paths'] ) ? $this->settings['invalidation_paths'] : '/*';
		echo '<textarea id="invalidation_paths" name="' . esc_attr( $this->settings_option ) . '[invalidation_paths]" rows="3" class="large-text">' . esc_textarea( $value ) . '</textarea>';
		echo '<p class="description">Enter paths to invalidate (one per line). Each path must start with /. Examples: /*, /blog/*, /images/logo.png</p>';
	}

	/**
	 * Validate AWS region format.
	 *
	 * Validates that the AWS region follows the correct format pattern.
	 * Examples: us-east-1, eu-west-2, ap-southeast-1
	 *
	 * @since 1.2.0
	 * @access private
	 * @param string $region AWS region to validate.
	 * @return string|WP_Error Validated region or WP_Error on failure.
	 */
	private function validate_aws_region( $region ) {
		$region = trim( strtolower( $region ) );

		// Allow empty region (will use default).
		if ( '' === $region ) {
			return $region;
		}

		// Validate region format: xx-xxxx-# or xxx-xxxx-#.
		if ( ! preg_match( '/^[a-z]{2,3}-[a-z]+-\d+$/', $region ) ) {
			return new WP_Error(
				'invalid_aws_region',
				__( 'Invalid AWS region format. Please use format like: us-east-1, eu-west-2, ap-southeast-1', 'cloudfront-cache-invalidator' )
			);
		}

		return $region;
	}

	/**
	 * Validate CloudFront Distribution ID format.
	 *
	 * Validates and normalizes the CloudFront Distribution ID.
	 * Distribution IDs are 13-14 uppercase alphanumeric characters.
	 * Examples: E1ABCDEFGHIJKL, E2XYZ123456789
	 *
	 * @since 1.2.0
	 * @access private
	 * @param string $distribution_id Distribution ID to validate.
	 * @return string|WP_Error Validated (uppercase) distribution ID or WP_Error on failure.
	 */
	private function validate_distribution_id( $distribution_id ) {
		$distribution_id = trim( strtoupper( $distribution_id ) );

		// Validate distribution ID format: 13-14 alphanumeric characters.
		if ( ! preg_match( '/^[A-Z0-9]{13,14}$/', $distribution_id ) ) {
			return new WP_Error(
				'invalid_distribution_id',
				__( 'Invalid CloudFront Distribution ID. Expected 13-14 uppercase alphanumeric characters (e.g., E1ABCDEFGHIJKL)', 'cloudfront-cache-invalidator' )
			);
		}

		return $distribution_id;
	}

	/**
	 * Validate invalidation paths format.
	 *
	 * Validates that each invalidation path starts with a forward slash.
	 * CloudFront requires all paths to begin with /.
	 * Examples: /*, /blog/*, /images/logo.png
	 *
	 * @since 1.2.0
	 * @access private
	 * @param string $paths Newline-separated invalidation paths.
	 * @return string|WP_Error Validated paths or WP_Error on failure.
	 */
	private function validate_invalidation_paths( $paths ) {
		// Split paths by newline and trim each.
		$paths_array = array_map( 'trim', explode( "\n", $paths ) );

		// Filter out empty lines.
		$paths_array = array_filter(
			$paths_array,
			function ( $path ) {
				return '' !== $path;
			}
		);

		// Must have at least one path.
		if ( empty( $paths_array ) ) {
			return new WP_Error(
				'empty_invalidation_paths',
				__( 'At least one invalidation path is required.', 'cloudfront-cache-invalidator' )
			);
		}

		// Validate each path starts with /.
		foreach ( $paths_array as $path ) {
			if ( '/' !== substr( $path, 0, 1 ) ) {
				return new WP_Error(
					'invalid_invalidation_path',
					sprintf(
						/* translators: %s: The invalid path */
						__( 'Invalidation path "%s" must start with /. Example: /*, /blog/*, /images/', 'cloudfront-cache-invalidator' ),
						esc_html( $path )
					)
				);
			}
		}

		// Return validated paths joined by newline.
		return implode( "\n", $paths_array );
	}

	/**
	 * Validate settings.
	 *
	 * Sanitizes and validates user input from the settings form.
	 *
	 * @since 1.0.0
	 * @access public
	 * @param array $input The raw input from the settings form.
	 * @return array Sanitized settings values.
	 */
	public function validate_settings( $input ) {
		// Start with existing settings so we can preserve encrypted values when fields are left blank.
		$new_input = $this->settings;

		// IAM role checkbox.
		$new_input['use_iam_role'] = isset( $input['use_iam_role'] ) ? '1' : '0';

		// Enforce HTTPS for credential submission.
		$is_ssl = is_ssl();

		$submitted_access = isset( $input['aws_access_key'] ) ? trim( $input['aws_access_key'] ) : '';
		$submitted_secret = isset( $input['aws_secret_key'] ) ? trim( $input['aws_secret_key'] ) : '';

		if ( ! $is_ssl && ( '' !== $submitted_access || '' !== $submitted_secret ) ) {
			add_settings_error( $this->settings_option, 'cloudfront_https_required', __( 'AWS credentials cannot be saved over an insecure (HTTP) connection. Please use HTTPS.', 'cloudfront-cache-invalidator' ), 'error' );
			// Do not modify stored credentials if submitted over HTTP.
		} else {
			// Access key handling.
			if ( '' !== $submitted_access ) {
				$encrypted = $this->encrypt_value( sanitize_text_field( $submitted_access ) );
				if ( false !== $encrypted ) {
					$new_input['aws_access_key_enc'] = $encrypted;
					$new_input['credentials_stored'] = true;
				}
			}

			// Secret key handling.
			if ( '' !== $submitted_secret ) {
				$encrypted = $this->encrypt_value( sanitize_text_field( $submitted_secret ) );
				if ( false !== $encrypted ) {
					$new_input['aws_secret_key_enc'] = $encrypted;
					$new_input['credentials_stored'] = true;
				}
			}
		}

		// Never persist plaintext fields.
		unset( $new_input['aws_access_key'], $new_input['aws_secret_key'] );

		// Region validation.
		if ( isset( $input['aws_region'] ) ) {
			$region = sanitize_text_field( $input['aws_region'] );

			$validated_region = $this->validate_aws_region( $region );
			if ( is_wp_error( $validated_region ) ) {
				add_settings_error(
					$this->settings_option,
					'invalid_aws_region',
					$validated_region->get_error_message(),
					'error'
				);
				// Keep existing or use default.
				$new_input['aws_region'] = isset( $this->settings['aws_region'] ) ? $this->settings['aws_region'] : 'us-east-1';
			} else {
				$new_input['aws_region'] = $validated_region;
			}
		}

		// Distribution ID validation.
		if ( isset( $input['distribution_id'] ) ) {
			$dist_id = sanitize_text_field( $input['distribution_id'] );

			// Allow empty (user can clear the field).
			if ( '' === $dist_id ) {
				$new_input['distribution_id'] = '';
			} else {
				$validated_dist_id = $this->validate_distribution_id( $dist_id );
				if ( is_wp_error( $validated_dist_id ) ) {
					add_settings_error(
						$this->settings_option,
						'invalid_distribution_id',
						$validated_dist_id->get_error_message(),
						'error'
					);
					// Keep existing value.
					$new_input['distribution_id'] = isset( $this->settings['distribution_id'] ) ? $this->settings['distribution_id'] : '';
				} else {
					$new_input['distribution_id'] = $validated_dist_id;
				}
			}
		}

		// Invalidation paths validation.
		if ( isset( $input['invalidation_paths'] ) ) {
			$paths = sanitize_textarea_field( $input['invalidation_paths'] );

			$validated_paths = $this->validate_invalidation_paths( $paths );
			if ( is_wp_error( $validated_paths ) ) {
				add_settings_error(
					$this->settings_option,
					'invalid_invalidation_paths',
					$validated_paths->get_error_message(),
					'error'
				);
				// Keep existing or use default.
				$new_input['invalidation_paths'] = isset( $this->settings['invalidation_paths'] ) ? $this->settings['invalidation_paths'] : '/*';
			} else {
				$new_input['invalidation_paths'] = $validated_paths;
			}
		}

		// If neither encrypted value exists, clear the stored flag.
		if ( empty( $new_input['aws_access_key_enc'] ) || empty( $new_input['aws_secret_key_enc'] ) ) {
			unset( $new_input['aws_access_key_enc'], $new_input['aws_secret_key_enc'] );
			unset( $new_input['credentials_stored'] );
		}

		return $new_input;
	}

	/**
	 * Render the settings page.
	 *
	 * Outputs the HTML for the plugin's admin settings page,
	 * including the settings form and manual invalidation button.
	 *
	 * @since 1.0.0
	 * @access public
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( __( 'You do not have sufficient permissions to access this page.', 'cloudfront-cache-invalidator' ) ) );
		}
		?>
		<div class="wrap">
			<h1>CloudFront Cache Invalidator</h1>

			<?php if ( ! is_ssl() ) : ?>
				<div class="notice notice-error">
					<p><strong><?php esc_html_e( 'Warning:', 'cloudfront-cache-invalidator' ); ?></strong> <?php esc_html_e( 'You are not using HTTPS. AWS credentials will not be saved over HTTP. Please switch to HTTPS before entering access keys.', 'cloudfront-cache-invalidator' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="notice notice-info">
				<p><strong>IAM Role Support:</strong> If your WordPress site is running on AWS (EC2, ECS, Elastic Beanstalk, etc.), you can use IAM roles for authentication instead of access keys. This is more secure and easier to manage.</p>
				<p>To use this feature:</p>
				<ol>
					<li>Create an IAM role with CloudFront invalidation permissions</li>
					<li>Attach the role to your EC2 instance or other AWS service</li>
					<li>Check the "Use IAM Role" option below</li>
				</ol>
				<p>Example IAM policy for CloudFront invalidation:</p>
				<pre>{
	"Version": "2012-10-17",
	"Statement": [
	{
		"Effect": "Allow",
		"Action": [
		"cloudfront:CreateInvalidation",
		"cloudfront:GetInvalidation",
		"cloudfront:ListInvalidations"
		],
		"Resource": "arn:aws:cloudfront::*:distribution/*"
	}
	]
	}</pre>
			</div>

			<form method="post" action="options.php">
				<?php
				settings_fields( $this->settings_group );
				do_settings_sections( 'cloudfront-cache-invalidator' );
				submit_button();
				?>
			</form>
			<hr>
			<h2>Manual Invalidation</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cloudfront_invalidate_all">
				<?php wp_nonce_field( 'manual_invalidation', 'cloudfront_invalidation_nonce' ); ?>
				<p>
					<input type="submit" name="cloudfront_invalidate_all" class="button button-primary" value="Invalidate All CloudFront Cache">
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle manual invalidation form submission.
	 *
	 * Processes the manual cache invalidation request via admin-post.php,
	 * implementing POST-Redirect-GET pattern to prevent duplicate submissions.
	 *
	 * @since 1.1.1
	 * @access public
	 * @return void
	 */
	public function handle_manual_invalidation() {
		// Check user permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'cloudfront-cache-invalidator' ) );
		}

		// Verify nonce.
		check_admin_referer( 'manual_invalidation', 'cloudfront_invalidation_nonce' );

		// Perform invalidation.
		$result = $this->invalidate_all();

		// Store result in transient for display after redirect.
		$user_id       = get_current_user_id();
		$transient_key = 'cloudfront_invalidation_notice_' . $user_id;

		if ( is_wp_error( $result ) ) {
			set_transient(
				$transient_key,
				array(
					'type'    => 'error',
					'message' => $result->get_error_message(),
				),
				60
			);
		} else {
			set_transient(
				$transient_key,
				array(
					'type'    => 'success',
					'message' => __( 'CloudFront invalidation request has been sent successfully!', 'cloudfront-cache-invalidator' ),
				),
				60
			);
		}

		// Redirect back to settings page.
		$redirect_url = add_query_arg(
			'page',
			'cloudfront-cache-invalidator',
			admin_url( 'options-general.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Display admin notices for manual invalidation results.
	 *
	 * Retrieves and displays success/error messages stored in transients
	 * after manual invalidation redirects.
	 *
	 * @since 1.1.1
	 * @access public
	 * @return void
	 */
	public function display_invalidation_notices() {
		// Only show on our settings page.
		$screen = get_current_screen();
		if ( ! $screen || 'settings_page_cloudfront-cache-invalidator' !== $screen->id ) {
			return;
		}

		// Check for notice transient.
		$user_id       = get_current_user_id();
		$transient_key = 'cloudfront_invalidation_notice_' . $user_id;
		$notice        = get_transient( $transient_key );

		if ( $notice && is_array( $notice ) && isset( $notice['type'], $notice['message'] ) ) {
			$notice_class   = 'error' === $notice['type'] ? 'notice-error' : 'notice-success';
			$message_prefix = 'error' === $notice['type'] ? __( 'Error: ', 'cloudfront-cache-invalidator' ) : '';

			printf(
				'<div class="notice %s is-dismissible"><p>%s%s</p></div>',
				esc_attr( $notice_class ),
				esc_html( $message_prefix ),
				esc_html( $notice['message'] )
			);

			// Delete transient after displaying (single-use).
			delete_transient( $transient_key );
		}
	}

	/**
	 * Invalidate cache when a post is updated.
	 *
	 * Triggers a CloudFront invalidation when a post is saved,
	 * creating paths based on the post's permalink and related archives.
	 *
	 * @since 1.0.0
	 * @access public
	 * @param int     $post_id The ID of the post being saved.
	 * @param WP_Post $post    The post object.
	 * @return void
	 */
	public function invalidate_on_post_update( $post_id, $post ) {
		// Skip if this is an autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Skip for revisions.
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Skip for auto drafts.
		if ( 'auto-draft' === $post->post_status ) {
			return;
		}

		// Get the permalink.
		$permalink = get_permalink( $post_id );

		if ( $permalink ) {
			// Convert full URL to path.
			$url_parts = wp_parse_url( $permalink );
			$path      = isset( $url_parts['path'] ) ? $url_parts['path'] : '/';

			// Invalidate the specific post URL and potentially related paths.
			$paths = array( $path, $path . '*' );

			// If it's a page that might be the front page, also invalidate the root.
			if ( 'page' === $post->post_type && ( get_option( 'page_on_front' ) === $post_id || get_option( 'page_for_posts' ) === $post_id ) ) {
				$paths[] = '/';
				$paths[] = '/*';
			}

			// If archives might be affected (for posts or custom post types).
			if ( 'page' !== $post->post_type ) {
				// Get archive URL.
				$archive_url = get_post_type_archive_link( $post->post_type );
				if ( $archive_url ) {
					$archive_parts = wp_parse_url( $archive_url );
					$archive_path  = isset( $archive_parts['path'] ) ? $archive_parts['path'] : '/';
					$paths[]       = $archive_path;
					$paths[]       = $archive_path . '*';
				}

				// If categories/tags/terms are affected.
				$taxonomies = get_object_taxonomies( $post->post_type );
				foreach ( $taxonomies as $taxonomy ) {
					$terms = get_the_terms( $post_id, $taxonomy );
					if ( $terms && ! is_wp_error( $terms ) ) {
						foreach ( $terms as $term ) {
							$term_link = get_term_link( $term );
							if ( ! is_wp_error( $term_link ) ) {
								$term_parts = wp_parse_url( $term_link );
								$term_path  = isset( $term_parts['path'] ) ? $term_parts['path'] : '/';
								$paths[]    = $term_path;
								$paths[]    = $term_path . '*';
							}
						}
					}
				}
			}

			// Send invalidation request.
			$this->send_invalidation_request( array_unique( $paths ) );
		}
	}

	/**
	 * Invalidate cache when a post is deleted.
	 *
	 * Triggers a site-wide CloudFront invalidation when a post is deleted,
	 * as determining specific affected paths is difficult after deletion.
	 *
	 * @since 1.0.0
	 * @access public
	 * @return void
	 */
	public function invalidate_on_post_delete() {
		// We need to invalidate more broadly since the post URL is now gone.
		$this->invalidate_all();
	}

	/**
	 * Invalidate cache when a term is updated.
	 *
	 * Triggers a CloudFront invalidation when a taxonomy term
	 * (category, tag, etc.) is updated.
	 *
	 * @since 1.0.0
	 * @access public
	 * @param int    $term_id  The term ID.
	 * @param int    $tt_id    The term taxonomy ID.
	 * @param string $taxonomy The taxonomy slug.
	 * @return void
	 */
	public function invalidate_on_term_update( $term_id, $tt_id, $taxonomy ) {

		// Get the term link.
		$term_link = get_term_link( $term_id, $taxonomy );

		if ( ! is_wp_error( $term_link ) ) {
			// Convert full URL to path.
			$url_parts = wp_parse_url( $term_link );
			$path      = isset( $url_parts['path'] ) ? $url_parts['path'] : '/';

			// Invalidate the specific term URL and potentially related paths.
			$paths = array( $path, $path . '*' );

			// Send invalidation request.
			$this->send_invalidation_request( $paths );
		}
	}

	/**
	 * Invalidate all cache.
	 *
	 * Triggers a CloudFront invalidation for all paths defined
	 * in the default invalidation paths setting.
	 *
	 * @since 1.0.0
	 * @access public
	 * @return mixed WP_Error on failure, AWS result object on success
	 */
	public function invalidate_all() {

		// Get default invalidation paths from settings.
		$default_paths = isset( $this->settings['invalidation_paths'] ) ? $this->settings['invalidation_paths'] : '/*';
		$paths         = array_map( 'trim', explode( "\n", $default_paths ) );

		// Send invalidation request.
		return $this->send_invalidation_request( $paths );
	}

	/**
	 * Validate and sanitize an array of invalidation paths for CloudFront API.
	 *
	 * Ensures paths meet CloudFront requirements:
	 * - Must start with /
	 * - Limited to 3000 paths per invalidation (AWS limit)
	 * - Removes empty or invalid paths
	 *
	 * @since 1.1.1
	 * @access private
	 * @param array $paths Array of paths to validate.
	 * @return array|WP_Error Validated paths array or WP_Error if validation fails.
	 */
	private function sanitize_invalidation_paths_array( $paths ) {
		if ( ! is_array( $paths ) || empty( $paths ) ) {
			return new WP_Error( 'invalid_paths', 'Invalidation paths must be a non-empty array.' );
		}

		$validated_paths = array();

		foreach ( $paths as $path ) {
			// Ensure path is a string.
			if ( ! is_string( $path ) ) {
				continue;
			}

			// Trim whitespace.
			$path = trim( $path );

			// Skip empty paths.
			if ( '' === $path ) {
				continue;
			}

			// CloudFront requires paths to start with /.
			if ( '/' !== substr( $path, 0, 1 ) ) {
				$path = '/' . $path;
			}

			// Add to validated list.
			$validated_paths[] = $path;
		}

		// Check if we have any valid paths after validation.
		if ( empty( $validated_paths ) ) {
			return new WP_Error( 'no_valid_paths', 'No valid invalidation paths provided.' );
		}

		// Remove duplicates.
		$validated_paths = array_unique( $validated_paths );

		// AWS CloudFront limit is 3000 paths per invalidation request.
		if ( count( $validated_paths ) > 3000 ) {
			return new WP_Error(
				'too_many_paths',
				sprintf(
					'CloudFront allows a maximum of 3000 paths per invalidation request. You provided %d paths.',
					count( $validated_paths )
				)
			);
		}

		return $validated_paths;
	}

	/**
	 * Send invalidation request to CloudFront.
	 *
	 * Creates and sends an invalidation request to the CloudFront API
	 * for the specified paths.
	 *
	 * @since 1.0.0
	 * @access public
	 * @param array $paths Array of paths to invalidate (e.g., ['/*', '/blog/*']).
	 * @return mixed WP_Error on failure, AWS result object on success
	 */
	public function send_invalidation_request( $paths = array( '/*' ) ) {

		// Check if AWS SDK is available.
		if ( ! class_exists( 'Aws\CloudFront\CloudFrontClient' ) ) {
			// Try to load AWS SDK via manual path if autoloader didn't work.
			if ( ! file_exists( plugin_dir_path( __FILE__ ) . 'vendor/autoload.php' ) ) {
				return new WP_Error( 'sdk_missing', 'AWS SDK for PHP is not installed. Please run composer require aws/aws-sdk-php in the plugin directory.' );
			}
		}

		// Check if distribution ID is configured.
		if ( ! isset( $this->settings['distribution_id'] ) || empty( $this->settings['distribution_id'] ) ) {
			return new WP_Error( 'settings_missing', 'CloudFront Distribution ID not configured.' );
		}

		// Validate and sanitize paths before sending to AWS API.
		$validated_paths = $this->sanitize_invalidation_paths_array( $paths );
		if ( is_wp_error( $validated_paths ) ) {
			return $validated_paths;
		}

		try {
			// Set up AWS CloudFront client config.
			$config = array(
				'version' => 'latest',
				'region'  => isset( $this->settings['aws_region'] ) ? $this->settings['aws_region'] : 'us-east-1',
			);

			// Use IAM role or keys based on settings.
			$use_iam_role = isset( $this->settings['use_iam_role'] ) && '1' === $this->settings['use_iam_role'];

			// Only add credentials if not using IAM role and credentials are resolved.
			if ( ! $use_iam_role ) {
				$creds = $this->resolve_credentials();
				if ( $creds && ! empty( $creds['key'] ) && ! empty( $creds['secret'] ) ) {
					$config['credentials'] = $creds;
				}
			}

			// Set up AWS CloudFront client.
			$client = new Aws\CloudFront\CloudFrontClient( $config );

			// Create a unique reference ID for this invalidation.
			$caller_reference = 'wp-' . time() . '-' . wp_generate_password( 6, false );

			// Send invalidation request.
			$result = $client->createInvalidation(
				array(
					'DistributionId'    => $this->settings['distribution_id'],
					'InvalidationBatch' => array(
						'CallerReference' => $caller_reference,
						'Paths'           => array(
							'Quantity' => count( $validated_paths ),
							'Items'    => $validated_paths,
						),
					),
				)
			);

			// Expose a hook so sites can optionally log or monitor invalidations without using error_log().
			do_action( 'notglossy_cloudfront_invalidation_sent', $validated_paths, $result );

			return $result;

		} catch ( Exception $e ) {
			do_action( 'notglossy_cloudfront_invalidation_error', $e );
			return new WP_Error( 'invalidation_failed', $e->getMessage() );
		}
	}
}
