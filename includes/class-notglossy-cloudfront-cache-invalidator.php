<?php
/**
 * Main plugin class for CloudFront Cache Invalidator.
 *
 * Orchestrates settings, credentials, path validation, CloudFront API
 * interactions, invalidation hooks, and admin UI through composed classes.
 * Legacy public APIs are preserved by delegating to the new components.
 *
 * @since 1.0.0
 * @package CloudFrontCacheInvalidator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Ensure dependencies are loaded when this file is included directly (e.g., in tests).
if ( ! class_exists( 'NotGlossy_CloudFront_Settings_Manager' ) ) {
	require_once __DIR__ . '/class-notglossy-cloudfront-settings-manager.php';
}
if ( ! class_exists( 'NotGlossy_CloudFront_Credential_Manager' ) ) {
	require_once __DIR__ . '/class-notglossy-cloudfront-credential-manager.php';
}
if ( ! class_exists( 'NotGlossy_CloudFront_Path_Validator' ) ) {
	require_once __DIR__ . '/class-notglossy-cloudfront-path-validator.php';
}
if ( ! class_exists( 'NotGlossy_CloudFront_Client' ) ) {
	require_once __DIR__ . '/class-notglossy-cloudfront-client.php';
}
if ( ! class_exists( 'NotGlossy_CloudFront_Invalidation_Manager' ) ) {
	require_once __DIR__ . '/class-notglossy-cloudfront-invalidation-manager.php';
}
if ( ! class_exists( 'NotGlossy_CloudFront_Admin_Interface' ) ) {
	require_once __DIR__ . '/class-notglossy-cloudfront-admin-interface.php';
}

class NotGlossy_CloudFront_Cache_Invalidator {

	/**
	 * Backward-compatible settings storage for tests and legacy access.
	 *
	 * @var array
	 */
	private $settings = array();

	/**
	 * Settings manager.
	 *
	 * @var NotGlossy_CloudFront_Settings_Manager
	 */
	private $settings_manager;

	/**
	 * Credential manager.
	 *
	 * @var NotGlossy_CloudFront_Credential_Manager
	 */
	private $credential_manager;

	/**
	 * Path validator.
	 *
	 * @var NotGlossy_CloudFront_Path_Validator
	 */
	private $path_validator;

	/**
	 * CloudFront client.
	 *
	 * @var NotGlossy_CloudFront_Client
	 */
	private $cloudfront_client;

	/**
	 * Invalidation manager.
	 *
	 * @var NotGlossy_CloudFront_Invalidation_Manager
	 */
	private $invalidation_manager;

	/**
	 * Admin interface handler.
	 *
	 * @var NotGlossy_CloudFront_Admin_Interface
	 */
	private $admin_interface;

	/**
	 * Constructor sets up all components and registers hooks.
	 */
	public function __construct() {
		$this->settings_manager     = new NotGlossy_CloudFront_Settings_Manager();
		$this->credential_manager   = new NotGlossy_CloudFront_Credential_Manager( $this->settings_manager );
		$this->path_validator       = new NotGlossy_CloudFront_Path_Validator();
		$this->cloudfront_client    = new NotGlossy_CloudFront_Client( $this->settings_manager, $this->credential_manager, $this->path_validator );
		$this->invalidation_manager = new NotGlossy_CloudFront_Invalidation_Manager( $this->settings_manager, $this->cloudfront_client );
		$this->admin_interface      = new NotGlossy_CloudFront_Admin_Interface( $this->settings_manager, $this->invalidation_manager );

		// Mirror initial settings for backward-compatibility with tests that reflect into $settings.
		$this->settings = $this->settings_manager->get_settings();
		$this->settings_manager->set_settings( $this->settings );

		// Migrate any legacy credentials to encrypted storage and refresh mirrored settings.
		$current_settings = $this->credential_manager->migrate_legacy_credentials( $this->settings );
		$this->settings   = $current_settings;
		$this->settings_manager->set_settings( $this->settings );

		// Register hooks.
		$this->register_hooks();
	}

	/**
	 * Register all WordPress hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		// Content update hooks (delegate to invalidation manager).
		add_action( 'save_post', array( $this, 'invalidate_on_post_update' ), 10, 3 );
		add_action( 'deleted_post', array( $this, 'invalidate_on_post_delete' ) );

		// Theme and customizer hooks.
		add_action( 'switch_theme', array( $this, 'invalidate_all' ) );
		add_action( 'customize_save_after', array( $this, 'invalidate_all' ) );
		add_action( 'update_option_permalink_structure', array( $this, 'invalidate_all' ) );

		// Plugin activation/deactivation hooks.
		add_action( 'activated_plugin', array( $this, 'invalidate_all' ) );
		add_action( 'deactivated_plugin', array( $this, 'invalidate_all' ) );

		// Menu and widget hooks.
		add_action( 'wp_update_nav_menu', array( $this, 'invalidate_all' ) );
		add_action( 'update_option_sidebars_widgets', array( $this, 'invalidate_all' ) );

		// Term hooks.
		add_action( 'edited_term', array( $this, 'invalidate_on_term_update' ), 10, 3 );

		// Admin hooks.
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

		// Manual invalidation hooks.
		add_action( 'admin_post_cloudfront_invalidate_all', array( $this, 'handle_manual_invalidation' ) );
		add_action( 'admin_notices', array( $this, 'display_invalidation_notices' ) );
	}

	/* -------------------------------------------------------------
	 * Wrapper methods (maintain public API compatibility)
	 * ----------------------------------------------------------- */

	// Settings API wrappers.
	public function register_settings() {
		return $this->settings_manager->register_settings();
	}

	public function add_settings_page() {
		return $this->settings_manager->add_settings_page();
	}

	public function settings_section_callback() {
		return $this->settings_manager->settings_section_callback();
	}

	public function use_iam_role_callback() {
		return $this->settings_manager->use_iam_role_callback();
	}

	public function aws_access_key_callback() {
		return $this->settings_manager->aws_access_key_callback();
	}

	public function aws_secret_key_callback() {
		return $this->settings_manager->aws_secret_key_callback();
	}

	public function aws_region_callback() {
		return $this->settings_manager->aws_region_callback();
	}

	public function distribution_id_callback() {
		return $this->settings_manager->distribution_id_callback();
	}

	public function invalidation_paths_callback() {
		return $this->settings_manager->invalidation_paths_callback();
	}

	public function validate_settings( $input ) {
		// First process credentials through the credential manager to preserve encryption handling.
		// This encrypts keys and removes plaintext versions from the result.
		$settings = $this->credential_manager->process_credential_submission( $input );

		// Ensure settings manager sees the updated snapshot for validation fallbacks.
		$this->settings_manager->set_settings( $settings );

		// Remove plaintext credential keys from input before passing to settings validation.
		// The credential manager has already handled encryption and removed these keys.
		$input_for_validation = array_diff_key(
			$input,
			array_flip( array( 'aws_access_key', 'aws_secret_key' ) )
		);

		// For checkbox fields like use_iam_role, only include in merged data if it's in the original input.
		// This ensures WordPress form behavior where unchecked checkboxes don't appear in form data.
		$merged = array_merge( $settings, $input_for_validation );

		// Remove use_iam_role from merged if it wasn't in the original input.
		// This preserves the behavior that unchecked checkboxes don't override previous values
		// in the validation flow - the settings_manager will default to '0' if not present.
		if ( ! isset( $input['use_iam_role'] ) && isset( $merged['use_iam_role'] ) ) {
			unset( $merged['use_iam_role'] );
		}

		// Validate non-credential fields. The credential manager has already processed credentials.
		$validated = $this->settings_manager->validate_settings( $merged );

		// Sync mirrored settings for legacy property and downstream uses.
		$this->settings = $validated;
		$this->settings_manager->set_settings( $validated );

		return $validated;
	}

	public function render_settings_page() {
		return $this->settings_manager->render_settings_page();
	}

	public function enqueue_admin_scripts( $hook ) {
		return $this->admin_interface->enqueue_admin_scripts( $hook );
	}

	public function handle_manual_invalidation() {
		return $this->admin_interface->handle_manual_invalidation();
	}

	public function display_invalidation_notices() {
		return $this->admin_interface->display_invalidation_notices();
	}

	// Invalidation wrappers.
	public function invalidate_on_post_update( $post_id, $post ) {
		return $this->invalidation_manager->invalidate_on_post_update( $post_id, $post );
	}

	public function invalidate_on_post_delete() {
		return $this->invalidation_manager->invalidate_on_post_delete();
	}

	public function invalidate_on_term_update( $term_id, $tt_id, $taxonomy ) {
		return $this->invalidation_manager->invalidate_on_term_update( $term_id, $tt_id, $taxonomy );
	}

	public function invalidate_all() {
		return $this->invalidation_manager->invalidate_all();
	}

	// CloudFront client wrapper.
	public function send_invalidation_request( $paths = array( '/*' ) ) {
		return $this->cloudfront_client->send_invalidation_request( $paths );
	}

	/* -------------------------------------------------------------
	 * Private compatibility wrappers for legacy tests (encryption & validation)
	 * ----------------------------------------------------------- */

	private function migrate_legacy_credentials( $settings ) {
		return $this->credential_manager->migrate_legacy_credentials( $settings );
	}

	private function get_encryption_key() {
		$reflector = new ReflectionClass( $this->credential_manager );
		$method    = $reflector->getMethod( 'get_encryption_key' );
		$method->setAccessible( true );
		return $method->invoke( $this->credential_manager );
	}

	private function encrypt_value( $plaintext ) {
		$reflector = new ReflectionClass( $this->credential_manager );
		$method    = $reflector->getMethod( 'encrypt_value' );
		$method->setAccessible( true );
		return $method->invoke( $this->credential_manager, $plaintext );
	}

	private function decrypt_value( $encoded ) {
		$reflector = new ReflectionClass( $this->credential_manager );
		$method    = $reflector->getMethod( 'decrypt_value' );
		$method->setAccessible( true );
		return $method->invoke( $this->credential_manager, $encoded );
	}

	private function get_env_or_option( $constant_name, $env_name, $option_key ) {
		$reflector = new ReflectionClass( $this->credential_manager );
		$method    = $reflector->getMethod( 'get_env_or_option' );
		$method->setAccessible( true );
		return $method->invoke( $this->credential_manager, $constant_name, $env_name, $option_key );
	}

	private function resolve_credentials() {
		return $this->credential_manager->resolve_credentials();
	}

	private function validate_aws_region( $region ) {
		$reflector = new ReflectionClass( $this->settings_manager );
		$method    = $reflector->getMethod( 'validate_aws_region' );
		$method->setAccessible( true );
		return $method->invoke( $this->settings_manager, $region );
	}

	private function validate_distribution_id( $distribution_id ) {
		$reflector = new ReflectionClass( $this->settings_manager );
		$method    = $reflector->getMethod( 'validate_distribution_id' );
		$method->setAccessible( true );
		return $method->invoke( $this->settings_manager, $distribution_id );
	}

	private function validate_invalidation_paths( $paths ) {
		$reflector = new ReflectionClass( $this->settings_manager );
		$method    = $reflector->getMethod( 'validate_invalidation_paths' );
		$method->setAccessible( true );
		return $method->invoke( $this->settings_manager, $paths );
	}

	private function sanitize_invalidation_paths_array( $paths ) {
		return $this->path_validator->sanitize_invalidation_paths_array( $paths );
	}
}
