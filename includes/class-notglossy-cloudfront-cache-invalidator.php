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
		$this->settings = get_option( $this->settings_option );

		// Add JavaScript for the settings page.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

		// Initialize settings.
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );

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
		$value    = isset( $this->settings['aws_access_key'] ) ? $this->settings['aws_access_key'] : '';
		$disabled = isset( $this->settings['use_iam_role'] ) && '1' === $this->settings['use_iam_role'] ? 'disabled' : '';
		echo '<input type="text" id="aws_access_key" name="' . esc_attr( $this->settings_option ) . '[aws_access_key]" value="' . esc_attr( $value ) . '" class="regular-text" ' . esc_attr( $disabled ) . '/>';
		echo '<p class="description">Optional when using IAM role</p>';

		// Add a hidden field to preserve the value when disabled.
		if ( $disabled ) {
			echo '<input type="hidden" name="' . esc_attr( $this->settings_option ) . '[aws_access_key]" value="' . esc_attr( $value ) . '" />';
		}
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
		$value    = isset( $this->settings['aws_secret_key'] ) ? $this->settings['aws_secret_key'] : '';
		$disabled = isset( $this->settings['use_iam_role'] ) && '1' === $this->settings['use_iam_role'] ? 'disabled' : '';
		echo '<input type="password" id="aws_secret_key" name="' . esc_attr( $this->settings_option ) . '[aws_secret_key]" value="' . esc_attr( $value ) . '" class="regular-text" ' . esc_attr( $disabled ) . '/>';
		echo '<p class="description">Optional when using IAM role</p>';

		// Add a hidden field to preserve the value when disabled.
		if ( $disabled ) {
			echo '<input type="hidden" name="' . esc_attr( $this->settings_option ) . '[aws_secret_key]" value="' . esc_attr( $value ) . '" />';
		}
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
		echo '<p class="description">Default: us-east-1</p>';
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
		echo '<p class="description">The ID of your CloudFront distribution (e.g., E1ABCDEFGHIJKL)</p>';
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
		echo '<p class="description">Enter paths to invalidate (one per line). Use /* for all files. For specific paths, start with /.</p>';
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
		$new_input = array();

		// IAM role checkbox.
		$new_input['use_iam_role'] = isset( $input['use_iam_role'] ) ? '1' : '0';

		if ( isset( $input['aws_access_key'] ) ) {
			$new_input['aws_access_key'] = sanitize_text_field( $input['aws_access_key'] );
		}

		if ( isset( $input['aws_secret_key'] ) ) {
			$new_input['aws_secret_key'] = sanitize_text_field( $input['aws_secret_key'] );
		}

		if ( isset( $input['aws_region'] ) ) {
			$new_input['aws_region'] = sanitize_text_field( $input['aws_region'] );
		}

		if ( isset( $input['distribution_id'] ) ) {
			$new_input['distribution_id'] = sanitize_text_field( $input['distribution_id'] );
		}

		if ( isset( $input['invalidation_paths'] ) ) {
			$new_input['invalidation_paths'] = sanitize_textarea_field( $input['invalidation_paths'] );
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
		?>
		<div class="wrap">
			<h1>CloudFront Cache Invalidator</h1>
			
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
			<form method="post" action="">
				<?php wp_nonce_field( 'manual_invalidation', 'cloudfront_invalidation_nonce' ); ?>
				<p>
					<input type="submit" name="cloudfront_invalidate_all" class="button button-primary" value="Invalidate All CloudFront Cache">
				</p>
			</form>
			
			<?php
			// Handle manual invalidation.
			if ( isset( $_POST['cloudfront_invalidate_all'] ) && check_admin_referer( 'manual_invalidation', 'cloudfront_invalidation_nonce' ) ) {

				$result = $this->invalidate_all();

				if ( is_wp_error( $result ) ) {
					echo '<div class="notice notice-error"><p>Error: ' . esc_html( $result->get_error_message() ) . '</p></div>';
				} else {
					echo '<div class="notice notice-success"><p>CloudFront invalidation request has been sent successfully!</p></div>';
				}
			}
			?>
		</div>
		<?php
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

		try {
			// Set up AWS CloudFront client config.
			$config = array(
				'version' => 'latest',
				'region'  => isset( $this->settings['aws_region'] ) ? $this->settings['aws_region'] : 'us-east-1',
			);

			// Use IAM role or keys based on settings.
			$use_iam_role = isset( $this->settings['use_iam_role'] ) && '1' === $this->settings['use_iam_role'];

			// Only add credentials if not using IAM role or if keys are provided as fallback.
			if ( ! $use_iam_role &&
				isset( $this->settings['aws_access_key'] ) && ! empty( $this->settings['aws_access_key'] ) &&
				isset( $this->settings['aws_secret_key'] ) && ! empty( $this->settings['aws_secret_key'] )
			) {
				$config['credentials'] = array(
					'key'    => $this->settings['aws_access_key'],
					'secret' => $this->settings['aws_secret_key'],
				);
			}

			// Set up AWS CloudFront client.
			$client = new Aws\CloudFront\CloudFrontClient( $config );

			// Create a unique reference ID for this invalidation.
			$caller_reference = 'wp-' . time() . '-' . wp_generate_password( 6, false );

			// Make sure paths are unique and properly formatted.
			$unique_paths = array_unique( $paths );

			// Send invalidation request.
			$result = $client->createInvalidation(
				array(
					'DistributionId'    => $this->settings['distribution_id'],
					'InvalidationBatch' => array(
						'CallerReference' => $caller_reference,
						'Paths'           => array(
							'Quantity' => count( $unique_paths ),
							'Items'    => $unique_paths,
						),
					),
				)
			);

			// Log invalidation request.
			error_log( 'CloudFront Invalidation Sent: ' . implode( ', ', $unique_paths ) );

			return $result;

		} catch ( Exception $e ) {
			error_log( 'CloudFront Invalidation Error: ' . $e->getMessage() );
			return new WP_Error( 'invalidation_failed', $e->getMessage() );
		}
	}
}
