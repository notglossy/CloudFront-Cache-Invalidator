<?php
/**
 * Admin Interface for CloudFront Cache Invalidator.
 *
 * Handles admin UI rendering, manual invalidation submission, notices,
 * and admin page scripts.
 *
 * @since 1.2.0
 * @package CloudFrontCacheInvalidator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Interface class.
 *
 * @since 1.2.0
 */
class NotGlossy_CloudFront_Admin_Interface {

	/**
	 * Settings manager instance.
	 *
	 * @since 1.2.0
	 * @access private
	 * @var NotGlossy_CloudFront_Settings_Manager
	 */
	private $settings_manager;

	/**
	 * Invalidation manager instance.
	 *
	 * @since 1.2.0
	 * @access private
	 * @var NotGlossy_CloudFront_Invalidation_Manager
	 */
	private $invalidation_manager;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 * @access public
	 * @param NotGlossy_CloudFront_Settings_Manager   $settings_manager    Settings manager instance.
	 * @param NotGlossy_CloudFront_Invalidation_Manager $invalidation_manager Invalidation manager instance.
	 */
	public function __construct( NotGlossy_CloudFront_Settings_Manager $settings_manager, NotGlossy_CloudFront_Invalidation_Manager $invalidation_manager ) {
		$this->settings_manager     = $settings_manager;
		$this->invalidation_manager = $invalidation_manager;
	}

	/**
	 * Register admin-related hooks.
	 *
	 * @since 1.2.0
	 * @access public
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'admin_init', array( $this->settings_manager, 'register_settings' ) );
		add_action( 'admin_menu', array( $this->settings_manager, 'add_settings_page' ) );
		add_action( 'admin_post_cloudfront_invalidate_all', array( $this, 'handle_manual_invalidation' ) );
		add_action( 'admin_notices', array( $this, 'display_invalidation_notices' ) );
	}

	/**
	 * Enqueue admin scripts for the settings page.
	 *
	 * Adds JavaScript to toggle credential fields when IAM role is selected.
	 *
	 * @since 1.2.0
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
		$this->settings_manager->render_settings_page();
	}

	/**
	 * Handle manual invalidation form submission.
	 *
	 * Processes the manual cache invalidation request via admin-post.php,
	 * implementing POST-Redirect-GET pattern to prevent duplicate submissions.
	 *
	 * @since 1.2.0
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
		$result = $this->invalidation_manager->invalidate_all();

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
	 * @since 1.2.0
	 * @access public
	 * @return void
	 */
	public function display_invalidation_notices() {
		$screen = get_current_screen();
		if ( ! $screen || 'settings_page_cloudfront-cache-invalidator' !== $screen->id ) {
			return;
		}

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

			delete_transient( $transient_key );
		}
	}
}
