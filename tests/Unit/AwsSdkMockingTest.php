<?php
/**
 * AWS SDK mocking tests for CloudFront invalidation calls.
 *
 * @package CloudFrontCacheInvalidator
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class AwsSdkMockingTest extends TestCase {
	/**
	 * @var NotGlossy_CloudFront_Cache_Invalidator
	 */
	private $plugin;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Mock WP config access and prevent side-effects.
		Functions\when( 'get_option' )->justReturn(
			array(
				'distribution_id' => 'E1234567890AB',
				'aws_region'      => 'us-east-1',
				'use_iam_role'    => '0',
			)
		);
		Functions\when( 'do_action' )->justReturn( null );

		$this->plugin = new NotGlossy_CloudFront_Cache_Invalidator();
	}

	protected function tearDown(): void {
		\Mockery::close();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Ensure send_invalidation_request issues createInvalidation with normalized paths and caller reference.
	 */
	public function test_send_invalidation_request_calls_cloudfront_with_expected_args(): void {
		// Seed plugin settings via reflection.
		$reflection = new ReflectionClass( $this->plugin );
		$property   = $reflection->getProperty( 'settings' );
		$property->setAccessible( true );
		$property->setValue(
			$this->plugin,
			array(
				'distribution_id' => 'E1234567890AB',
				'aws_region'      => 'us-east-1',
				'use_iam_role'    => '0',
			)
		);

		// Make caller reference deterministic suffix.
		Functions\when( 'wp_generate_password' )->justReturn( 'abcd12' );

		// Mock AWS SDK instantiation and call.
		$client_mock = \Mockery::mock( 'overload:Aws\\CloudFront\\CloudFrontClient' );
		$client_mock
			->shouldReceive( 'createInvalidation' )
			->once()
			->with(
				\Mockery::on(
					function ( $args ) {
						// Assert distribution ID and caller reference format.
						TestCase::assertSame( 'E1234567890AB', $args['DistributionId'] );
						TestCase::assertSame( 2, $args['InvalidationBatch']['Paths']['Quantity'] );
						TestCase::assertSame( array( '/foo', '/bar' ), $args['InvalidationBatch']['Paths']['Items'] );
						TestCase::assertMatchesRegularExpression( '/^wp-\d+-abcd12$/', $args['InvalidationBatch']['CallerReference'] );
						return true;
					}
				)
			)
			->andReturn( array( 'Status' => 'Completed' ) );

		$result = $this->plugin->send_invalidation_request( array( '/foo', 'bar', '/foo' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'Completed', $result['Status'] );
	}

	/**
	 * Ensure missing distribution ID returns WP_Error.
	 */
	public function test_send_invalidation_request_returns_error_when_distribution_missing(): void {
		// Update plugin's settings through reflection.
		$plugin_reflection = new ReflectionClass( $this->plugin );
		$plugin_property   = $plugin_reflection->getProperty( 'settings' );
		$plugin_property->setAccessible( true );
		$plugin_property->setValue( $this->plugin, array() );

		// Also update settings_manager's current_settings so it doesn't use old values.
		$settings_manager_reflection = new ReflectionClass( $this->plugin );
		$settings_manager_property   = $settings_manager_reflection->getProperty( 'settings_manager' );
		$settings_manager_property->setAccessible( true );
		$settings_manager = $settings_manager_property->getValue( $this->plugin );

		$sm_reflection = new ReflectionClass( $settings_manager );
		$sm_property   = $sm_reflection->getProperty( 'current_settings' );
		$sm_property->setAccessible( true );
		$sm_property->setValue( $settings_manager, array() );

		$result = $this->plugin->send_invalidation_request( array( '/*' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'settings_missing', $result->get_error_code() );
	}
}
