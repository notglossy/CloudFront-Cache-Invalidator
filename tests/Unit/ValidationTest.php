<?php
/**
 * Unit tests for validation functionality.
 *
 * Tests validation methods for AWS regions, distribution IDs, and invalidation paths.
 * These methods are located at lines 530-623 in the main class.
 *
 * @package CloudFrontCacheInvalidator
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Test validation methods.
 */
class ValidationTest extends TestCase {

	/**
	 * The plugin instance under test.
	 *
	 * @var NotGlossy_CloudFront_Cache_Invalidator
	 */
	private $plugin;

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

		// Mock WordPress translation function.
		Functions\when( '__' )->returnArg( 1 );

		// Mock esc_html.
		Functions\when( 'esc_html' )->returnArg( 1 );

		// Mock get_option.
		Functions\when( 'get_option' )->justReturn( array() );

		// Create plugin instance.
		$this->plugin = new NotGlossy_CloudFront_Cache_Invalidator();
	}

	/**
	 * Tear down the test environment after each test.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Test valid AWS regions are accepted.
	 */
	public function test_valid_aws_regions() {
		foreach ( $this->fixtures['aws_regions']['valid'] as $region ) {
			$result = $this->call_private_method( $this->plugin, 'validate_aws_region', array( $region ) );

			$this->assertNotInstanceOf(
				WP_Error::class,
				$result,
				"Region '{$region}' should be valid"
			);
			$this->assertEquals(
				strtolower( $region ),
				$result,
				'Region should be normalized to lowercase'
			);
		}
	}

	/**
	 * Test invalid AWS regions are rejected.
	 */
	public function test_invalid_aws_regions() {
		$invalid_regions = array(
			'invalid',
			'us_east_1',
			'123-region',
			'not-a-region',
		);

		foreach ( $invalid_regions as $region ) {
			$result = $this->call_private_method( $this->plugin, 'validate_aws_region', array( $region ) );

			$this->assertInstanceOf(
				WP_Error::class,
				$result,
				"Region '{$region}' should be invalid"
			);
			$this->assertEquals( 'invalid_aws_region', $result->get_error_code() );
		}
	}

	/**
	 * Test empty AWS region is allowed.
	 */
	public function test_empty_aws_region_allowed() {
		$result = $this->call_private_method( $this->plugin, 'validate_aws_region', array( '' ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertEquals( '', $result, 'Empty region should be allowed (will use default)' );
	}

	/**
	 * Test AWS region case normalization.
	 */
	public function test_aws_region_case_normalization() {
		$test_cases = array(
			'US-EAST-1'      => 'us-east-1',
			'Us-EaSt-1'      => 'us-east-1',
			'EU-WEST-2'      => 'eu-west-2',
			'ap-southeast-1' => 'ap-southeast-1',
		);

		foreach ( $test_cases as $input => $expected ) {
			$result = $this->call_private_method( $this->plugin, 'validate_aws_region', array( $input ) );

			$this->assertEquals(
				$expected,
				$result,
				"Region '{$input}' should be normalized to '{$expected}'"
			);
		}
	}

	/**
	 * Test AWS region whitespace trimming.
	 */
	public function test_aws_region_whitespace_trimming() {
		$result = $this->call_private_method( $this->plugin, 'validate_aws_region', array( '  us-east-1  ' ) );

		$this->assertEquals( 'us-east-1', $result, 'Whitespace should be trimmed' );
	}

	/**
	 * Test valid CloudFront distribution IDs are accepted.
	 */
	public function test_valid_distribution_ids() {
		foreach ( $this->fixtures['distribution_ids']['valid'] as $id ) {
			$result = $this->call_private_method( $this->plugin, 'validate_distribution_id', array( $id ) );

			$this->assertNotInstanceOf(
				WP_Error::class,
				$result,
				"Distribution ID '{$id}' should be valid"
			);
			$this->assertEquals(
				strtoupper( $id ),
				$result,
				'Distribution ID should be normalized to uppercase'
			);
		}
	}

	/**
	 * Test distribution IDs that are too short are rejected.
	 */
	public function test_distribution_id_too_short() {
		$short_ids = array( 'E123', 'E12345', 'E123456789' );

		foreach ( $short_ids as $id ) {
			$result = $this->call_private_method( $this->plugin, 'validate_distribution_id', array( $id ) );

			$this->assertInstanceOf(
				WP_Error::class,
				$result,
				"Distribution ID '{$id}' (too short) should be invalid"
			);
			$this->assertEquals( 'invalid_distribution_id', $result->get_error_code() );
		}
	}

	/**
	 * Test distribution IDs that are too long are rejected.
	 */
	public function test_distribution_id_too_long() {
		$long_id = 'E123456789012345';

		$result = $this->call_private_method( $this->plugin, 'validate_distribution_id', array( $long_id ) );

		$this->assertInstanceOf( WP_Error::class, $result, 'Distribution ID that is too long should be invalid' );
		$this->assertEquals( 'invalid_distribution_id', $result->get_error_code() );
	}

	/**
	 * Test distribution IDs with special characters are rejected.
	 */
	public function test_distribution_id_special_characters() {
		$invalid_ids = array(
			'E123-456-789',
			'E123 456 789',
			'E123.456.789',
			'E123_456_789',
		);

		foreach ( $invalid_ids as $id ) {
			$result = $this->call_private_method( $this->plugin, 'validate_distribution_id', array( $id ) );

			$this->assertInstanceOf(
				WP_Error::class,
				$result,
				"Distribution ID '{$id}' with special characters should be invalid"
			);
		}
	}

	/**
	 * Test distribution ID case normalization to uppercase.
	 */
	public function test_distribution_id_case_normalization() {
		$test_cases = array(
			'e1234567890ab' => 'E1234567890AB',
			'E1234567890ab' => 'E1234567890AB',
			'e1234567890AB' => 'E1234567890AB',
		);

		foreach ( $test_cases as $input => $expected ) {
			$result = $this->call_private_method( $this->plugin, 'validate_distribution_id', array( $input ) );

			$this->assertEquals(
				$expected,
				$result,
				"Distribution ID '{$input}' should be normalized to '{$expected}'"
			);
		}
	}

	/**
	 * Test empty distribution ID is rejected.
	 */
	public function test_empty_distribution_id_rejected() {
		$result = $this->call_private_method( $this->plugin, 'validate_distribution_id', array( '' ) );

		$this->assertInstanceOf( WP_Error::class, $result, 'Empty distribution ID should be invalid' );
		$this->assertEquals( 'invalid_distribution_id', $result->get_error_code() );
	}

	/**
	 * Test distribution ID whitespace trimming.
	 */
	public function test_distribution_id_whitespace_trimming() {
		$result = $this->call_private_method( $this->plugin, 'validate_distribution_id', array( '  E1234567890AB  ' ) );

		$this->assertEquals( 'E1234567890AB', $result, 'Whitespace should be trimmed' );
	}

	/**
	 * Test valid invalidation paths are accepted.
	 */
	public function test_valid_invalidation_paths() {
		foreach ( $this->fixtures['invalidation_paths']['valid'] as $path ) {
			$result = $this->call_private_method( $this->plugin, 'validate_invalidation_paths', array( $path ) );

			$this->assertNotInstanceOf(
				WP_Error::class,
				$result,
				"Path '{$path}' should be valid"
			);
		}
	}

	/**
	 * Test paths missing leading slash are rejected.
	 */
	public function test_paths_missing_leading_slash_rejected() {
		$invalid_paths = array(
			'blog/*',
			'images/logo.png',
			'category/news/',
		);

		foreach ( $invalid_paths as $path ) {
			$result = $this->call_private_method( $this->plugin, 'validate_invalidation_paths', array( $path ) );

			$this->assertInstanceOf(
				WP_Error::class,
				$result,
				"Path '{$path}' without leading slash should be invalid"
			);
			$this->assertEquals( 'invalid_invalidation_path', $result->get_error_code() );
		}
	}

	/**
	 * Test empty invalidation paths are rejected.
	 */
	public function test_empty_invalidation_paths_rejected() {
		$result = $this->call_private_method( $this->plugin, 'validate_invalidation_paths', array( '' ) );

		$this->assertInstanceOf( WP_Error::class, $result, 'Empty paths should be invalid' );
		$this->assertEquals( 'empty_invalidation_paths', $result->get_error_code() );
	}

	/**
	 * Test multiple paths with newlines.
	 */
	public function test_multiple_paths_with_newlines() {
		$paths  = "/*\n/blog/*\n/images/*";
		$result = $this->call_private_method( $this->plugin, 'validate_invalidation_paths', array( $paths ) );

		$this->assertNotInstanceOf( WP_Error::class, $result, 'Multiple valid paths should be accepted' );
		$this->assertEquals( $paths, $result, 'Valid paths should be preserved' );
	}

	/**
	 * Test paths with whitespace are trimmed.
	 */
	public function test_paths_whitespace_trimming() {
		$paths  = "  /*  \n  /blog/*  \n  /images/*  ";
		$result = $this->call_private_method( $this->plugin, 'validate_invalidation_paths', array( $paths ) );

		$expected = "/*\n/blog/*\n/images/*";

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertEquals( $expected, $result, 'Whitespace should be trimmed from paths' );
	}

	/**
	 * Test empty lines are filtered out.
	 */
	public function test_empty_lines_filtered() {
		$paths  = "/*\n\n/blog/*\n\n\n/images/*";
		$result = $this->call_private_method( $this->plugin, 'validate_invalidation_paths', array( $paths ) );

		$expected = "/*\n/blog/*\n/images/*";

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertEquals( $expected, $result, 'Empty lines should be filtered out' );
	}

	/**
	 * Test mixed valid and invalid paths.
	 */
	public function test_mixed_valid_invalid_paths() {
		$paths  = "/*\nblog/*\n/valid-path";
		$result = $this->call_private_method( $this->plugin, 'validate_invalidation_paths', array( $paths ) );

		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'Mixed valid/invalid paths should be rejected due to invalid path'
		);
		$this->assertEquals( 'invalid_invalidation_path', $result->get_error_code() );
	}

	/**
	 * Test only whitespace lines are filtered out.
	 */
	public function test_only_whitespace_lines_filtered() {
		$paths  = "/*\n   \n/blog/*\n\t\t\n/images/*";
		$result = $this->call_private_method( $this->plugin, 'validate_invalidation_paths', array( $paths ) );

		$expected = "/*\n/blog/*\n/images/*";

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertEquals( $expected, $result, 'Whitespace-only lines should be filtered out' );
	}

	/**
	 * Test that at least one path is required.
	 */
	public function test_at_least_one_path_required() {
		$test_cases = array(
			"\n\n\n",      // Only newlines.
			"   \n   \n   ", // Only whitespace.
		);

		foreach ( $test_cases as $paths ) {
			$result = $this->call_private_method( $this->plugin, 'validate_invalidation_paths', array( $paths ) );

			$this->assertInstanceOf(
				WP_Error::class,
				$result,
				'At least one valid path should be required'
			);
			$this->assertEquals( 'empty_invalidation_paths', $result->get_error_code() );
		}
	}

	/**
	 * Helper method to call private methods on the plugin instance.
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
}
