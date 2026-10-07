<?php
/**
 * Settings Manager for CloudFront Cache Invalidator.
 *
 * Handles all WordPress settings functionality including registration,
 * validation, sanitization, and admin form callbacks.
 *
 * @since 1.2.0
 * @package CloudFrontCacheInvalidator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings Manager class.
 *
 * Responsible for all WordPress Settings API integration, settings validation,
 * and admin form field management.
 *
 * @since 1.2.0
 */
class NotGlossy_CloudFront_Settings_Manager {

	/**
	 * Credential manager instance.
	 *
	 * @since 1.2.0
	 * @access private
	 * @var NotGlossy_CloudFront_Credential_Manager|null
	 */
	private $credential_manager = null;

	/**
	 * Set the credential manager instance.
	 *
	 * @since 1.2.0
	 * @access public
	 * @param NotGlossy_CloudFront_Credential_Manager $credential_manager Credential manager instance.
	 * @return void
	 */
	public function set_credential_manager( NotGlossy_CloudFront_Credential_Manager $credential_manager ) {
		$this->credential_manager = $credential_manager;
	}

	/**
	 * Get the credential manager, if one has been attached.
	 *
	 * @since 1.2.1
	 * @access public
	 * @return NotGlossy_CloudFront_Credential_Manager|null
	 */
	public function get_credential_manager() {
		return $this->credential_manager;
	}

	/**
	 * Cached settings for tests/injection.
	 *
	 * @since 1.2.0
	 * @access private
	 * @var array|null
	 */
	private $current_settings = null;

	/**
	 * Blog ID the cached settings belong to (multisite).
	 *
	 * @since 1.3.0
	 * @access private
	 * @var int
	 */
	private $settings_blog_id = 0;

	/**
	 * Settings that can be pinned by a wp-config.php constant, keyed by setting.
	 *
	 * @since 1.3.0
	 * @var array<string,string>
	 */
	const CONSTANT_OVERRIDES = array(
		'distribution_id' => 'CLOUDFRONT_DISTRIBUTION_ID',
		'aws_region'      => 'CLOUDFRONT_AWS_REGION',
		'use_iam_role'    => 'CLOUDFRONT_USE_IAM_ROLE',
	);

	/**
	 * Settings group name.
	 *
	 * @since 1.2.0
	 * @access private
	 * @var string $settings_group WordPress settings group name.
	 */
	private $settings_group = 'cloudfront_cache_invalidator_settings';

	/**
	 * Settings option name.
	 *
	 * @since 1.2.0
	 * @access private
	 * @var string $settings_option WordPress settings option name.
	 */
	private $settings_option = 'cloudfront_cache_invalidator_options';

	/**
	 * Settings section ID.
	 *
	 * @since 1.2.0
	 * @access private
	 * @var string $settings_section WordPress settings section ID.
	 */
	private $settings_section = 'cloudfront_cache_invalidator_section';

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 * @access public
	 */
	public function __construct() {
		// Settings will be initialized when needed.
	}

	/**
	 * Get settings option name.
	 *
	 * @since 1.2.0
	 * @access public
	 * @return string Settings option name.
	 */
	public function get_settings_option() {
		return $this->settings_option;
	}

	/**
	 * Get settings group name.
	 *
	 * @since 1.2.0
	 * @access public
	 * @return string Settings group name.
	 */
	public function get_settings_group() {
		return $this->settings_group;
	}

	/**
	 * Get settings section ID.
	 *
	 * @since 1.2.0
	 * @access public
	 * @return string Settings section ID.
	 */
	public function get_settings_section() {
		return $this->settings_section;
	}

	/**
	 * Get all plugin settings.
	 *
	 * @since 1.2.0
	 * @access public
	 * @return array Plugin settings.
	 */
	public function get_settings() {
		// The cache belongs to one site; after switch_to_blog() read that site's option.
		if ( is_array( $this->current_settings ) && self::current_blog_id() === $this->settings_blog_id ) {
			return $this->current_settings;
		}

		$settings = get_option( $this->settings_option, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		return $settings;
	}

	/**
	 * Get a specific setting value.
	 *
	 * @since 1.2.0
	 * @access public
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Fallback value if setting doesn't exist.
	 * @return mixed Setting value or fallback.
	 */
	public function get_setting( $key, $fallback = null ) {
		$override = $this->get_constant_override( $key );
		if ( null !== $override ) {
			return $override;
		}

		$settings = $this->get_settings();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $fallback;
	}

	/**
	 * Update a specific setting value.
	 *
	 * @since 1.2.0
	 * @access public
	 * @param string $key Setting key.
	 * @param mixed  $value Setting value.
	 * @return bool True on success, false on failure.
	 */
	public function update_setting( $key, $value ) {
		$settings         = $this->get_settings();
		$settings[ $key ] = $value;
		$this->set_settings( $settings );
		return update_option( $this->settings_option, $settings );
	}

	/**
	 * Inject settings (used by plugin to sync legacy property and tests).
	 *
	 * @since 1.2.0
	 * @access public
	 * @param array $settings Settings array to use for subsequent reads.
	 * @return void
	 */
	public function set_settings( array $settings ) {
		$this->current_settings = $settings;
		$this->settings_blog_id = self::current_blog_id();
	}

	/**
	 * Current blog ID (1 outside multisite).
	 *
	 * @since 1.3.0
	 * @access public
	 * @return int
	 */
	public static function current_blog_id() {
		return function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
	}

	/**
	 * Whether this is a multisite network.
	 *
	 * @since 1.3.0
	 * @access public
	 * @return bool
	 */
	public static function is_network() {
		return function_exists( 'is_multisite' ) && is_multisite();
	}

	/**
	 * Value of a setting pinned by a wp-config.php constant, or null.
	 *
	 * Supported: CLOUDFRONT_DISTRIBUTION_ID, CLOUDFRONT_AWS_REGION and
	 * CLOUDFRONT_USE_IAM_ROLE (true/false).
	 *
	 * @since 1.3.0
	 * @access public
	 * @param string $key Setting key.
	 * @return string|null
	 */
	public function get_constant_override( $key ) {
		if ( ! isset( self::CONSTANT_OVERRIDES[ $key ] ) || ! defined( self::CONSTANT_OVERRIDES[ $key ] ) ) {
			return null;
		}

		$value = constant( self::CONSTANT_OVERRIDES[ $key ] );

		if ( 'use_iam_role' === $key ) {
			return filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ? '1' : '0';
		}

		if ( 'distribution_id' === $key ) {
			return strtoupper( trim( (string) $value ) );
		}

		return strtolower( trim( (string) $value ) );
	}

	/**
	 * Capability required to view and change the plugin settings and to run
	 * a manual invalidation.
	 *
	 * On multisite this defaults to manage_network_options, because the AWS
	 * credentials (constants, environment variables, instance role) are shared
	 * by every site and a sub-site administrator must not be able to point
	 * them at an arbitrary distribution.
	 *
	 * @since 1.3.0
	 * @access public
	 * @return string
	 */
	public function get_required_capability() {
		$capability = self::is_network() ? 'manage_network_options' : 'manage_options';

		/**
		 * Filter the capability required to manage CloudFront Cache Invalidator.
		 *
		 * @since 1.3.0
		 * @param string $capability Capability name.
		 */
		return (string) apply_filters( 'notglossy_cloudfront_settings_capability', $capability );
	}

	/**
	 * Capability for options.php saves of this settings group.
	 *
	 * Hooked to option_page_capability_{group}.
	 *
	 * @since 1.3.0
	 * @access public
	 * @return string
	 */
	public function filter_option_page_capability() {
		return $this->get_required_capability();
	}

	/**
	 * Register plugin settings.
	 *
	 * Sets up the WordPress settings API fields, sections, and validations
	 * for the plugin's configuration page.
	 *
	 * @since 1.2.0
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
			'clear_credentials',
			'Stored Credentials',
			array( $this, 'clear_credentials_callback' ),
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
	 * @since 1.2.0
	 * @access public
	 * @return void
	 */
	public function add_settings_page() {
		add_options_page(
			'CloudFront Cache Invalidator',
			'CloudFront Cache',
			$this->get_required_capability(),
			'cloudfront-cache-invalidator',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Settings section description.
	 *
	 * Outputs the HTML for the settings section description on the admin page.
	 *
	 * @since 1.2.0
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
	 * @since 1.2.0
	 * @access public
	 * @return void
	 */
	public function use_iam_role_callback() {
		$value  = $this->get_setting( 'use_iam_role', '0' );
		$pinned = null !== $this->get_constant_override( 'use_iam_role' );
		echo '<input type="checkbox" id="use_iam_role" name="' . esc_attr( $this->settings_option ) . '[use_iam_role]" value="1" ' . checked( '1', $value, false ) . ( $pinned ? ' disabled' : '' ) . '/>';
		echo '<label for="use_iam_role"> Use instance IAM role (recommended if your WordPress server is running on AWS)</label>';
		echo '<p class="description">When enabled, the plugin uses the AWS SDK default credential chain (instance profile, container credentials, environment variables). Stored access keys are ignored while this is on.</p>';
		$this->render_constant_notice( 'use_iam_role' );
	}

	/**
	 * Note that a field is pinned by a constant.
	 *
	 * @since 1.3.0
	 * @access private
	 * @param string $key Setting key.
	 * @return void
	 */
	private function render_constant_notice( $key ) {
		if ( null === $this->get_constant_override( $key ) ) {
			return;
		}

		printf(
			'<p class="description"><strong>%s</strong></p>',
			esc_html(
				sprintf(
					/* translators: %s: constant name */
					__( 'Set by the %s constant in wp-config.php.', 'cloudfront-cache-invalidator' ),
					self::CONSTANT_OVERRIDES[ $key ]
				)
			)
		);
	}

	/**
	 * AWS Access Key field callback.
	 *
	 * Renders the AWS Access Key field for the settings page.
	 * This field can be disabled when using IAM roles.
	 *
	 * @since 1.2.0
	 * @access public
	 * @return void
	 */
	public function aws_access_key_callback() {
		$disabled = $this->get_setting( 'use_iam_role' ) === '1' ? 'disabled' : '';
		echo '<input type="text" id="aws_access_key" name="' . esc_attr( $this->settings_option ) . '[aws_access_key]" value="" placeholder="' . esc_attr( $this->get_credential_placeholder() ) . '" class="regular-text" autocomplete="off" spellcheck="false" ' . esc_attr( $disabled ) . '/>';
		echo '<p class="description">Leave blank to keep the stored key. To rotate, enter both the new Access Key and the new Secret Key.</p>';
	}

	/**
	 * AWS Secret Key field callback.
	 *
	 * Renders the AWS Secret Key field for the settings page.
	 * This field can be disabled when using IAM roles.
	 *
	 * @since 1.2.0
	 * @access public
	 * @return void
	 */
	public function aws_secret_key_callback() {
		$disabled = $this->get_setting( 'use_iam_role' ) === '1' ? 'disabled' : '';
		echo '<input type="password" id="aws_secret_key" name="' . esc_attr( $this->settings_option ) . '[aws_secret_key]" value="" placeholder="' . esc_attr( $this->get_credential_placeholder() ) . '" class="regular-text" autocomplete="new-password" spellcheck="false" ' . esc_attr( $disabled ) . '/>';
		echo '<p class="description">Leave blank to keep the stored secret. To rotate, enter both the new Access Key and the new Secret Key.</p>';
	}

	/**
	 * Placeholder text describing the state of the stored credentials.
	 *
	 * @since 1.2.1
	 * @access private
	 * @return string
	 */
	private function get_credential_placeholder() {
		if ( null === $this->credential_manager ) {
			return '';
		}

		switch ( $this->credential_manager->get_credential_status() ) {
			case NotGlossy_CloudFront_Credential_Manager::STATUS_STORED:
				return '******** (stored)';
			case NotGlossy_CloudFront_Credential_Manager::STATUS_UNDECRYPTABLE:
				return '******** (stored, cannot be decrypted - re-enter)';
			case NotGlossy_CloudFront_Credential_Manager::STATUS_EXTERNAL:
				return '(set by constant or environment variable)';
			default:
				return '';
		}
	}

	/**
	 * Stored credentials status and "remove" checkbox.
	 *
	 * @since 1.2.1
	 * @access public
	 * @return void
	 */
	public function clear_credentials_callback() {
		if ( null === $this->credential_manager ) {
			return;
		}

		$status = $this->credential_manager->get_credential_status();

		switch ( $status ) {
			case NotGlossy_CloudFront_Credential_Manager::STATUS_STORED:
				echo '<p>' . esc_html__( 'An encrypted access key pair is stored in the database.', 'cloudfront-cache-invalidator' ) . '</p>';
				break;
			case NotGlossy_CloudFront_Credential_Manager::STATUS_UNDECRYPTABLE:
				echo '<p><strong>' . esc_html__( 'The stored access key pair cannot be decrypted.', 'cloudfront-cache-invalidator' ) . '</strong> ' . esc_html__( 'This usually means the WordPress salts changed. Enter the keys again, or remove them.', 'cloudfront-cache-invalidator' ) . '</p>';
				break;
			case NotGlossy_CloudFront_Credential_Manager::STATUS_EXTERNAL:
				echo '<p>' . esc_html__( 'Credentials are provided by the CLOUDFRONT_AWS_ACCESS_KEY / CLOUDFRONT_AWS_SECRET_KEY constants or environment variables and take precedence over stored keys.', 'cloudfront-cache-invalidator' ) . '</p>';
				break;
			default:
				echo '<p>' . esc_html__( 'No access keys are stored.', 'cloudfront-cache-invalidator' ) . '</p>';
				break;
		}

		if ( $this->credential_manager->has_stored_credentials() ) {
			echo '<input type="checkbox" id="clear_credentials" name="' . esc_attr( $this->settings_option ) . '[clear_credentials]" value="1" />';
			echo '<label for="clear_credentials"> ' . esc_html__( 'Remove the stored access keys when saving', 'cloudfront-cache-invalidator' ) . '</label>';
		}
	}

	/**
	 * AWS Region field callback.
	 *
	 * Renders the AWS Region field for the settings page.
	 * Defaults to us-east-1 if not specified.
	 *
	 * @since 1.2.0
	 * @access public
	 * @return void
	 */
	public function aws_region_callback() {
		$value    = $this->get_setting( 'aws_region', 'us-east-1' );
		$readonly = null !== $this->get_constant_override( 'aws_region' ) ? ' readonly' : '';
		echo '<input type="text" id="aws_region" name="' . esc_attr( $this->settings_option ) . '[aws_region]" value="' . esc_attr( $value ) . '" class="regular-text"' . esc_attr( $readonly ) . ' />';
		echo '<p class="description">AWS region (e.g., us-east-1, eu-west-2, ap-southeast-1). Default: us-east-1</p>';
		$this->render_constant_notice( 'aws_region' );
	}

	/**
	 * Distribution ID field callback.
	 *
	 * Renders the CloudFront Distribution ID field for the settings page.
	 * This is required for all invalidation requests.
	 *
	 * @since 1.2.0
	 * @access public
	 * @return void
	 */
	public function distribution_id_callback() {
		$value    = $this->get_setting( 'distribution_id', '' );
		$readonly = null !== $this->get_constant_override( 'distribution_id' ) ? ' readonly' : '';
		echo '<input type="text" id="distribution_id" name="' . esc_attr( $this->settings_option ) . '[distribution_id]" value="' . esc_attr( $value ) . '" class="regular-text"' . esc_attr( $readonly ) . ' />';
		echo '<p class="description">CloudFront Distribution ID (13-14 uppercase characters, e.g., E1ABCDEFGHIJKL)</p>';
		$this->render_constant_notice( 'distribution_id' );
	}

	/**
	 * Invalidation Paths field callback.
	 *
	 * Renders the Default Invalidation Paths field for the settings page.
	 * These paths will be used for site-wide invalidations.
	 *
	 * @since 1.2.0
	 * @access public
	 * @return void
	 */
	public function invalidation_paths_callback() {
		$value = $this->get_setting( 'invalidation_paths', '/*' );
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

		// Blank region falls back to the default.
		if ( '' === $region ) {
			return 'us-east-1';
		}

		// Validate region format: xx-xxxx-#, xxx-xxxx-# or xx-xxx-xxxx-# (e.g. us-gov-west-1).
		if ( ! preg_match( '/^[a-z]{2,3}(-[a-z]+)+-\d+$/', $region ) ) {
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
	 * This is the sanitize callback registered with register_setting(). It must
	 * be safe to run more than once on its own output, because WordPress runs
	 * it twice when the option is created (update_option() falls through to
	 * add_option()). It therefore:
	 *
	 *  - starts from the value stored in the database, never a request-start snapshot;
	 *  - treats the IAM checkbox as on only when it is submitted as "1";
	 *  - applies credential changes last, so nothing can overwrite them.
	 *
	 * @since 1.2.0
	 * @access public
	 * @param array $input The raw input from the settings form.
	 * @return array Sanitized settings values.
	 */
	public function validate_settings( $input ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		// Start from what is stored so blank fields keep their existing values.
		$stored = get_option( $this->settings_option, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$new_input = $stored;

		// IAM role checkbox: only an explicit "1" counts as checked.
		$new_input['use_iam_role'] = ( isset( $input['use_iam_role'] ) && '1' === (string) $input['use_iam_role'] ) ? '1' : '0';

		// Region validation.
		if ( isset( $input['aws_region'] ) ) {
			$region = is_string( $input['aws_region'] ) ? sanitize_text_field( $input['aws_region'] ) : '';

			$validated_region = $this->validate_aws_region( $region );
			if ( is_wp_error( $validated_region ) ) {
				add_settings_error(
					$this->settings_option,
					'invalid_aws_region',
					$validated_region->get_error_message(),
					'error'
				);
				// Keep existing or use default.
				$new_input['aws_region'] = ! empty( $stored['aws_region'] ) ? $stored['aws_region'] : 'us-east-1';
			} else {
				$new_input['aws_region'] = $validated_region;
			}
		}

		// Distribution ID validation.
		if ( isset( $input['distribution_id'] ) ) {
			$dist_id = is_string( $input['distribution_id'] ) ? sanitize_text_field( $input['distribution_id'] ) : '';

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
					$new_input['distribution_id'] = isset( $stored['distribution_id'] ) ? $stored['distribution_id'] : '';
				} else {
					$new_input['distribution_id'] = $validated_dist_id;
				}
			}
		}

		// Invalidation paths validation.
		if ( isset( $input['invalidation_paths'] ) ) {
			$paths = is_string( $input['invalidation_paths'] ) ? sanitize_textarea_field( $input['invalidation_paths'] ) : '';

			$validated_paths = $this->validate_invalidation_paths( $paths );
			if ( is_wp_error( $validated_paths ) ) {
				add_settings_error(
					$this->settings_option,
					'invalid_invalidation_paths',
					$validated_paths->get_error_message(),
					'error'
				);
				// Keep existing or use default.
				$new_input['invalidation_paths'] = ! empty( $stored['invalidation_paths'] ) ? $stored['invalidation_paths'] : '/*';
			} else {
				$new_input['invalidation_paths'] = $validated_paths;
			}
		}

		// Settings pinned by a constant are read-only in the form (and a disabled
		// checkbox submits nothing), so keep the stored value for when the
		// constant is removed.
		foreach ( array_keys( self::CONSTANT_OVERRIDES ) as $pinned_key ) {
			if ( null === $this->get_constant_override( $pinned_key ) ) {
				continue;
			}

			if ( array_key_exists( $pinned_key, $stored ) ) {
				$new_input[ $pinned_key ] = $stored[ $pinned_key ];
			} else {
				unset( $new_input[ $pinned_key ] );
			}
		}

		// Credentials last, so the encrypted values can never be overwritten by stale data.
		if ( null !== $this->credential_manager ) {
			$new_input = $this->credential_manager->process_credential_submission( $input, $new_input );
		} else {
			unset( $new_input['aws_access_key'], $new_input['aws_secret_key'] );
		}

		// Keep in-memory reads consistent with what is about to be stored.
		$this->set_settings( $new_input );

		return $new_input;
	}

	/**
	 * Render the settings page.
	 *
	 * Outputs the HTML for the plugin's admin settings page,
	 * including the settings form and manual invalidation button.
	 *
	 * @since 1.2.0
	 * @access public
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( $this->get_required_capability() ) ) {
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
}