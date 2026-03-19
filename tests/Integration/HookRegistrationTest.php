<?php
/**
 * Integration tests for WordPress hook registration.
 *
 * Tests that all WordPress hooks are properly registered by the plugin
 * and that the correct callbacks are attached. Hooks are registered in
 * the constructor at lines 60-96 in the main class.
 *
 * @package CloudFrontCacheInvalidator
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Test hook registration.
 */
class HookRegistrationTest extends TestCase {

	/**
	 * The plugin instance under test.
	 *
	 * @var NotGlossy_CloudFront_Cache_Invalidator
	 */
	private $plugin;

	/**
	 * The invalidation manager instance.
	 *
	 * @var NotGlossy_CloudFront_Invalidation_Manager
	 */
	private $invalidation_manager;

	/**
	 * The admin interface instance.
	 *
	 * @var NotGlossy_CloudFront_Admin_Interface
	 */
	private $admin_interface;

	/**
	 * The settings manager instance.
	 *
	 * @var NotGlossy_CloudFront_Settings_Manager
	 */
	private $settings_manager;

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

		// Mock WordPress functions required during construction.
		Functions\when( 'get_option' )->justReturn( array() );

		// Create plugin instance which registers hooks in constructor.
		$this->plugin = new NotGlossy_CloudFront_Cache_Invalidator();

		// Extract sub-class instances via reflection for hook assertions.
		$reflection                 = new ReflectionClass( $this->plugin );
		$inv_prop                   = $reflection->getProperty( 'invalidation_manager' );
		$this->invalidation_manager = $inv_prop->getValue( $this->plugin );

		$admin_prop           = $reflection->getProperty( 'admin_interface' );
		$this->admin_interface = $admin_prop->getValue( $this->plugin );

		$settings_prop         = $reflection->getProperty( 'settings_manager' );
		$this->settings_manager = $settings_prop->getValue( $this->plugin );

		// Ensure the test helper can still set legacy $settings via reflection.
		$property = $reflection->getProperty( 'settings' );
		$property->setValue( $this->plugin, array() );
	}

	/**
	 * Tear down the test environment after each test.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Test that save_post hook is registered.
	 */
	public function test_save_post_hook_is_registered() {
		$this->assertTrue(
			has_action( 'save_post', array( $this->invalidation_manager, 'invalidate_on_post_update' ) ) !== false,
			'save_post hook should be registered with invalidate_on_post_update callback'
		);
	}

	/**
	 * Test that save_post hook has correct priority.
	 */
	public function test_save_post_hook_has_correct_priority() {
		$priority = has_action( 'save_post', array( $this->invalidation_manager, 'invalidate_on_post_update' ) );
		$this->assertEquals(
			10,
			$priority,
			'save_post hook should be registered with priority 10'
		);
	}

	/**
	 * Test that deleted_post hook is registered.
	 */
	public function test_deleted_post_hook_is_registered() {
		$this->assertTrue(
			has_action( 'deleted_post', array( $this->invalidation_manager, 'invalidate_on_post_delete' ) ) !== false,
			'deleted_post hook should be registered with invalidate_on_post_delete callback'
		);
	}

	/**
	 * Test that switch_theme hook is registered.
	 */
	public function test_switch_theme_hook_is_registered() {
		$this->assertTrue(
			has_action( 'switch_theme', array( $this->invalidation_manager, 'invalidate_all' ) ) !== false,
			'switch_theme hook should be registered with invalidate_all callback'
		);
	}

	/**
	 * Test that customize_save_after hook is registered.
	 */
	public function test_customize_save_after_hook_is_registered() {
		$this->assertTrue(
			has_action( 'customize_save_after', array( $this->invalidation_manager, 'invalidate_all' ) ) !== false,
			'customize_save_after hook should be registered with invalidate_all callback'
		);
	}

	/**
	 * Test that wp_update_nav_menu hook is registered.
	 */
	public function test_wp_update_nav_menu_hook_is_registered() {
		$this->assertTrue(
			has_action( 'wp_update_nav_menu', array( $this->invalidation_manager, 'invalidate_all' ) ) !== false,
			'wp_update_nav_menu hook should be registered with invalidate_all callback'
		);
	}

	/**
	 * Test that edited_term hook is registered.
	 */
	public function test_edited_term_hook_is_registered() {
		$this->assertTrue(
			has_action( 'edited_term', array( $this->invalidation_manager, 'invalidate_on_term_update' ) ) !== false,
			'edited_term hook should be registered with invalidate_on_term_update callback'
		);
	}

	/**
	 * Test that edited_term hook has correct priority.
	 */
	public function test_edited_term_hook_has_correct_priority() {
		$priority = has_action( 'edited_term', array( $this->invalidation_manager, 'invalidate_on_term_update' ) );
		$this->assertEquals(
			10,
			$priority,
			'edited_term hook should be registered with priority 10'
		);
	}

	/**
	 * Test that all admin hooks are registered.
	 */
	public function test_admin_hooks_are_registered() {
		$this->assertTrue(
			has_action( 'admin_init', array( $this->settings_manager, 'register_settings' ) ) !== false,
			'admin_init hook should be registered with register_settings callback'
		);

		$this->assertTrue(
			has_action( 'admin_menu', array( $this->settings_manager, 'add_settings_page' ) ) !== false,
			'admin_menu hook should be registered with add_settings_page callback'
		);

		$this->assertTrue(
			has_action( 'admin_enqueue_scripts', array( $this->admin_interface, 'enqueue_admin_scripts' ) ) !== false,
			'admin_enqueue_scripts hook should be registered with enqueue_admin_scripts callback'
		);
	}

	/**
	 * Test that manual invalidation hooks are registered.
	 */
	public function test_manual_invalidation_hooks_are_registered() {
		$this->assertTrue(
			has_action( 'admin_post_cloudfront_invalidate_all', array( $this->admin_interface, 'handle_manual_invalidation' ) ) !== false,
			'admin_post_cloudfront_invalidate_all hook should be registered'
		);

		$this->assertTrue(
			has_action( 'admin_notices', array( $this->admin_interface, 'display_invalidation_notices' ) ) !== false,
			'admin_notices hook should be registered'
		);
	}

	/**
	 * Helper method to call private methods via reflection.
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

	/**
	 * Helper method to set private properties via reflection.
	 *
	 * @param array $settings Settings array to inject.
	 * @return void
	 */
	private function seed_settings( array $settings ): void {
		$reflection = new ReflectionClass( $this->plugin );
		$property   = $reflection->getProperty( 'settings' );
		$property->setValue( $this->plugin, $settings );
	}
}
