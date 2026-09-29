<?php
/**
 * Tests for who may configure the plugin, and for wp-config.php constants
 * that pin settings.
 *
 * @package CloudFrontCacheInvalidator
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class MultisiteAccessTest extends TestCase {

	/**
	 * @var NotGlossy_CloudFront_Settings_Manager
	 */
	private $settings_manager;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn( array() );

		$this->settings_manager = new NotGlossy_CloudFront_Settings_Manager();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_single_site_requires_manage_options() {
		Functions\when( 'is_multisite' )->justReturn( false );

		$this->assertSame( 'manage_options', $this->settings_manager->get_required_capability() );
	}

	public function test_multisite_requires_manage_network_options() {
		Functions\when( 'is_multisite' )->justReturn( true );

		$this->assertSame( 'manage_network_options', $this->settings_manager->get_required_capability() );
		$this->assertSame( 'manage_network_options', $this->settings_manager->filter_option_page_capability() );
	}

	public function test_capability_is_filterable() {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				return 'notglossy_cloudfront_settings_capability' === $hook ? 'manage_cloudfront' : $value;
			}
		);

		$this->assertSame( 'manage_cloudfront', $this->settings_manager->get_required_capability() );
	}

	public function test_settings_page_uses_the_required_capability() {
		Functions\when( 'is_multisite' )->justReturn( true );
		$registered = null;
		Functions\when( 'add_options_page' )->alias(
			function ( $title, $menu, $capability ) use ( &$registered ) {
				$registered = $capability;
			}
		);

		$this->settings_manager->add_settings_page();

		$this->assertSame( 'manage_network_options', $registered );
	}

	public function test_sub_site_admin_cannot_open_settings_or_run_manual_invalidation() {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'current_user_can' )->alias(
			function ( $capability ) {
				return 'manage_options' === $capability; // Sub-site admin.
			}
		);
		Functions\when( 'wp_die' )->alias(
			function () {
				throw new RuntimeException( 'wp_die' );
			}
		);

		try {
			$this->settings_manager->render_settings_page();
			$this->fail( 'Settings page should be refused' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'wp_die', $e->getMessage() );
		}

		$client  = $this->createMock( NotGlossy_CloudFront_Client::class );
		$client->expects( $this->never() )->method( 'send_invalidation_request' );
		$manager = new NotGlossy_CloudFront_Invalidation_Manager( $this->settings_manager, $client );
		$admin   = new NotGlossy_CloudFront_Admin_Interface( $this->settings_manager, $manager );

		$this->expectException( RuntimeException::class );
		$admin->handle_manual_invalidation();
	}

	public function test_option_page_capability_filter_is_registered() {
		$client  = $this->createMock( NotGlossy_CloudFront_Client::class );
		$manager = new NotGlossy_CloudFront_Invalidation_Manager( $this->settings_manager, $client );
		$admin   = new NotGlossy_CloudFront_Admin_Interface( $this->settings_manager, $manager );

		$admin->register_hooks();

		$this->assertNotFalse(
			has_filter( 'option_page_capability_cloudfront_cache_invalidator_settings', array( $this->settings_manager, 'filter_option_page_capability' ) )
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constants_pin_distribution_region_and_iam_mode() {
		define( 'CLOUDFRONT_DISTRIBUTION_ID', ' e1pinnedvalue01 ' );
		define( 'CLOUDFRONT_AWS_REGION', 'EU-WEST-1' );
		define( 'CLOUDFRONT_USE_IAM_ROLE', 'true' );

		$this->settings_manager->set_settings(
			array(
				'distribution_id' => 'E1FROMOPTIONS01',
				'aws_region'      => 'us-east-1',
				'use_iam_role'    => '0',
			)
		);
		$credential_manager = new NotGlossy_CloudFront_Credential_Manager( $this->settings_manager );

		$this->assertSame( 'E1PINNEDVALUE01', $credential_manager->get_distribution_id() );
		$this->assertSame( 'eu-west-1', $credential_manager->get_aws_region() );
		$this->assertTrue( $credential_manager->is_using_iam_role() );

		ob_start();
		$this->settings_manager->distribution_id_callback();
		$this->settings_manager->use_iam_role_callback();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'readonly', $output );
		$this->assertStringContainsString( 'disabled', $output );
		$this->assertStringContainsString( 'CLOUDFRONT_DISTRIBUTION_ID', $output );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_iam_constant_false_forces_access_key_mode() {
		define( 'CLOUDFRONT_USE_IAM_ROLE', false );

		$this->settings_manager->set_settings( array( 'use_iam_role' => '1' ) );
		$credential_manager = new NotGlossy_CloudFront_Credential_Manager( $this->settings_manager );

		$this->assertFalse( $credential_manager->is_using_iam_role() );
	}
}
