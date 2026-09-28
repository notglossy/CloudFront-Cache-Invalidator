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
		Functions\when( '__' )->returnArg( 1 );

		// Access-key mode needs a resolvable key pair; provide one via the environment.
		putenv( 'CLOUDFRONT_AWS_ACCESS_KEY=AKIAIOSFODNN7EXAMPLE' );
		putenv( 'CLOUDFRONT_AWS_SECRET_KEY=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY' );

		$this->plugin = new NotGlossy_CloudFront_Cache_Invalidator();
	}

	protected function tearDown(): void {
		putenv( 'CLOUDFRONT_AWS_ACCESS_KEY' );
		putenv( 'CLOUDFRONT_AWS_SECRET_KEY' );
		\Mockery::close();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Access-key mode without a usable key pair must refuse to call AWS instead of
	 * falling through to the SDK's default credential chain.
	 */
	public function test_access_key_mode_without_credentials_refuses_to_call_aws(): void {
		putenv( 'CLOUDFRONT_AWS_ACCESS_KEY' );
		putenv( 'CLOUDFRONT_AWS_SECRET_KEY' );

		$client_mock = \Mockery::mock( 'overload:Aws\\CloudFront\\CloudFrontClient' );
		$client_mock->shouldNotReceive( 'createInvalidation' );

		$result = $this->plugin->send_invalidation_request( array( '/*' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'credentials_missing', $result->get_error_code() );
	}

	/**
	 * The client is configured with explicit credentials (as a provider object, not a
	 * raw array) and HTTP timeouts.
	 */
	public function test_client_config_has_credentials_object_and_timeouts(): void {
		$captured = null;
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) use ( &$captured ) {
				if ( 'notglossy_cloudfront_client_config' === $hook ) {
					$captured = $value;
				}
				return $value;
			}
		);
		Functions\when( 'wp_generate_password' )->justReturn( 'abcd12' );

		$client_mock = \Mockery::mock( 'overload:Aws\\CloudFront\\CloudFrontClient' );
		$client_mock->shouldReceive( 'createInvalidation' )->once()->andReturn( array( 'Status' => 'InProgress' ) );

		$this->plugin->send_invalidation_request( array( '/foo' ) );

		$this->assertIsArray( $captured );
		$this->assertSame( 'us-east-1', $captured['region'] );
		$this->assertInstanceOf( \Aws\Credentials\Credentials::class, $captured['credentials'] );
		$this->assertSame( 'AKIAIOSFODNN7EXAMPLE', $captured['credentials']->getAccessKeyId() );
		$this->assertSame( 5, $captured['http']['connect_timeout'] );
		$this->assertSame( 15, $captured['http']['timeout'] );
	}

	/**
	 * IAM-role mode passes no credentials so the SDK default chain is used.
	 */
	public function test_iam_role_mode_passes_no_credentials(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'distribution_id' => 'E1234567890AB',
				'aws_region'      => 'us-east-1',
				'use_iam_role'    => '1',
			)
		);
		$plugin = new NotGlossy_CloudFront_Cache_Invalidator();

		$captured = null;
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) use ( &$captured ) {
				if ( 'notglossy_cloudfront_client_config' === $hook ) {
					$captured = $value;
				}
				return $value;
			}
		);
		Functions\when( 'wp_generate_password' )->justReturn( 'abcd12' );

		$client_mock = \Mockery::mock( 'overload:Aws\\CloudFront\\CloudFrontClient' );
		$client_mock->shouldReceive( 'createInvalidation' )->once()->andReturn( array( 'Status' => 'InProgress' ) );

		$plugin->send_invalidation_request( array( '/foo' ) );

		$this->assertIsArray( $captured );
		$this->assertArrayNotHasKey( 'credentials', $captured );
	}

	/**
	 * Ensure send_invalidation_request issues createInvalidation with normalized paths and caller reference.
	 */
	public function test_send_invalidation_request_calls_cloudfront_with_expected_args(): void {
		// Seed plugin settings via reflection.
		$reflection = new ReflectionClass( $this->plugin );
		$property   = $reflection->getProperty( 'settings' );
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
		$plugin_property->setValue( $this->plugin, array() );

		// Also update settings_manager's current_settings so it doesn't use old values.
		$settings_manager_reflection = new ReflectionClass( $this->plugin );
		$settings_manager_property   = $settings_manager_reflection->getProperty( 'settings_manager' );
		$settings_manager = $settings_manager_property->getValue( $this->plugin );

		$sm_reflection = new ReflectionClass( $settings_manager );
		$sm_property   = $sm_reflection->getProperty( 'current_settings' );
		$sm_property->setValue( $settings_manager, array() );

		$result = $this->plugin->send_invalidation_request( array( '/*' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'settings_missing', $result->get_error_code() );
	}
}
