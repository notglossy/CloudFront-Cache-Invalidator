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
	 * Cached settings for tests/injection.
	 *
	 * @since 1.2.0
	 * @access private
	 * @var array|null
	 */
	private $current_settings = null;

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
		if ( is_array( $this->current_settings ) ) {
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
		$settings               = $this->get_settings();
		$settings[ $key ]       = $value;
		$this->current_settings = $settings;
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
			'manage_options',
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
		$value = $this->get_setting( 'use_iam_role', '0' );
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
	 * @since 1.2.0
	 * @access public
	 * @return void
	 */
	public function aws_access_key_callback() {
		$disabled    = $this->get_setting( 'use_iam_role' ) === '1' ? 'disabled' : '';
		$has_stored  = ! empty( $this->get_setting( 'credentials_stored' ) ) && ! empty( $this->get_setting( 'aws_access_key_enc' ) );
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
	 * @since 1.2.0
	 * @access public
	 * @return void
	 */
	public function aws_secret_key_callback() {
		$disabled    = $this->get_setting( 'use_iam_role' ) === '1' ? 'disabled' : '';
		$has_stored  = ! empty( $this->get_setting( 'credentials_stored' ) ) && ! empty( $this->get_setting( 'aws_secret_key_enc' ) );
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
	 * @since 1.2.0
	 * @access public
	 * @return void
	 */
	public function aws_region_callback() {
		$value = $this->get_setting( 'aws_region', 'us-east-1' );
		echo '<input type="text" id="aws_region" name="' . esc_attr( $this->settings_option ) . '[aws_region]" value="' . esc_attr( $value ) . '" class="regular-text" />';
		echo '<p class="description">AWS region (e.g., us-east-1, eu-west-2, ap-southeast-1). Default: us-east-1</p>';
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
		$value = $this->get_setting( 'distribution_id', '' );
		echo '<input type="text" id="distribution_id" name="' . esc_attr( $this->settings_option ) . '[distribution_id]" value="' . esc_attr( $value ) . '" class="regular-text" />';
		echo '<p class="description">CloudFront Distribution ID (13-14 uppercase characters, e.g., E1ABCDEFGHIJKL)</p>';
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
	 * @since 1.2.0
	 * @access public
	 * @param array $input The raw input from the settings form.
	 * @return array Sanitized settings values.
	 */
	public function validate_settings( $input ) {
		// Start with existing settings so we can preserve encrypted values when fields are left blank.
		$new_input = $this->get_settings();

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
				$new_input['aws_access_key'] = sanitize_text_field( $submitted_access );
			}

			// Secret key handling.
			if ( '' !== $submitted_secret ) {
				$new_input['aws_secret_key'] = sanitize_text_field( $submitted_secret );
			}
		}

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
				$new_input['aws_region'] = $this->get_setting( 'aws_region', 'us-east-1' );
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
					$new_input['distribution_id'] = $this->get_setting( 'distribution_id', '' );
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
				$new_input['invalidation_paths'] = $this->get_setting( 'invalidation_paths', '/*' );
			} else {
				$new_input['invalidation_paths'] = $validated_paths;
			}
		}

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
}