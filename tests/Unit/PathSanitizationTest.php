<?php
/**
 * Unit tests for path sanitization functionality.
 *
 * Tests the sanitize_invalidation_paths_array() method which validates and
 * sanitizes paths for CloudFront invalidation requests.
 * This method is located at lines 1067-1117 in the main class.
 *
 * @package CloudFrontCacheInvalidator
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;

/**
 * Test path sanitization methods.
 */
class PathSanitizationTest extends TestCase {

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

		// Mock WordPress functions.
		\Brain\Monkey\Functions\when( 'get_option' )->justReturn( array() );

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
	 * Test that valid paths array is processed correctly.
	 */
	public function test_valid_paths_array() {
		$paths = array( '/*', '/blog/*', '/images/logo.png' );

		$result = $this->call_private_method( $this->plugin, 'sanitize_invalidation_paths_array', array( $paths ) );

		$this->assertIsArray( $result, 'Valid paths should return an array' );
		$this->assertEquals( $paths, $result, 'Valid paths should be returned unchanged' );
	}

	/**
	 * Test that empty array returns WP_Error.
	 */
	public function test_empty_array_returns_error() {
		$result = $this->call_private_method( $this->plugin, 'sanitize_invalidation_paths_array', array( array() ) );

		$this->assertInstanceOf( WP_Error::class, $result, 'Empty array should return WP_Error' );
		$this->assertEquals( 'invalid_paths', $result->get_error_code() );
	}

	/**
	 * Test that non-array input returns WP_Error.
	 */
	public function test_non_array_input_returns_error() {
		$invalid_inputs = array(
			'string',
			123,
			true,
			null,
			(object) array( 'path' => '/*' ),
		);

		foreach ( $invalid_inputs as $input ) {
			$result = $this->call_private_method( $this->plugin, 'sanitize_invalidation_paths_array', array( $input ) );

			$this->assertInstanceOf(
				WP_Error::class,
				$result,
				'Non-array input should return WP_Error'
			);
		}
	}

	/**
	 * Test that paths without leading slash are auto-corrected.
	 */
	public function test_paths_without_leading_slash_are_corrected() {
		$paths = array( 'blog/*', 'images/logo.png', 'category/news/' );

		$result = $this->call_private_method( $this->plugin, 'sanitize_invalidation_paths_array', array( $paths ) );

		$expected = array( '/blog/*', '/images/logo.png', '/category/news/' );

		$this->assertIsArray( $result );
		$this->assertEquals( $expected, $result, 'Paths should have leading slash added' );
	}

	/**
	 * Test that duplicate paths are removed.
	 */
	public function test_duplicate_paths_are_removed() {
		$paths = array( '/*', '/blog/*', '/*', '/images/*', '/blog/*' );

		$result = $this->call_private_method( $this->plugin, 'sanitize_invalidation_paths_array', array( $paths ) );

		$expected = array( '/*', '/blog/*', '/images/*' );

		$this->assertIsArray( $result );
		$this->assertCount( 3, $result, 'Duplicates should be removed' );
		$this->assertEquals( array_values( $expected ), array_values( $result ) );
	}

	/**
	 * Test that 3000 path limit is enforced.
	 */
	public function test_3000_path_limit_enforced() {
		$paths = array();
		for ( $i = 0; $i < 3001; $i++ ) {
			$paths[] = '/path-' . $i;
		}

		$result = $this->call_private_method( $this->plugin, 'sanitize_invalidation_paths_array', array( $paths ) );

		$this->assertInstanceOf( WP_Error::class, $result, 'More than 3000 paths should return WP_Error' );
		$this->assertEquals( 'too_many_paths', $result->get_error_code() );
	}

	/**
	 * Test that exactly 3000 paths is allowed.
	 */
	public function test_exactly_3000_paths_allowed() {
		$paths = array();
		for ( $i = 0; $i < 3000; $i++ ) {
			$paths[] = '/path-' . $i;
		}

		$result = $this->call_private_method( $this->plugin, 'sanitize_invalidation_paths_array', array( $paths ) );

		$this->assertIsArray( $result, 'Exactly 3000 paths should be allowed' );
		$this->assertCount( 3000, $result );
	}

	/**
	 * Test that mixed valid and invalid paths are handled.
	 */
	public function test_mixed_valid_invalid_paths() {
		$paths = array(
			'/*',               // Missing slash - will be added.
			'/valid-path',      // Valid.
			'',                 // Empty - will be filtered out.
			'/another-path',    // Valid.
			'   ',              // Whitespace only - will be filtered out.
			123,                // Non-string - will be skipped.
			null,               // Null - will be skipped.
		);

		$result = $this->call_private_method( $this->plugin, 'sanitize_invalidation_paths_array', array( $paths ) );

		$this->assertIsArray( $result );
		$this->assertContains( '/*', $result );
		$this->assertContains( '/valid-path', $result );
		$this->assertContains( '/another-path', $result );
	}

	/**
	 * Test that whitespace is trimmed from paths.
	 */
	public function test_whitespace_trimming() {
		$paths = array(
			'  /*  ',
			'  /blog/*  ',
			"\t/images/*\t",
		);

		$result = $this->call_private_method( $this->plugin, 'sanitize_invalidation_paths_array', array( $paths ) );

		$expected = array( '/*', '/blog/*', '/images/*' );

		$this->assertEquals( $expected, $result, 'Whitespace should be trimmed' );
	}

	/**
	 * Test that empty strings are filtered out.
	 */
	public function test_empty_strings_filtered() {
		$paths = array(
			'/*',
			'',
			'/blog/*',
			'',
			'/images/*',
		);

		$result = $this->call_private_method( $this->plugin, 'sanitize_invalidation_paths_array', array( $paths ) );

		$expected = array( '/*', '/blog/*', '/images/*' );

		$this->assertEquals( $expected, $result, 'Empty strings should be filtered out' );
	}

	/**
	 * Test that non-string values are skipped.
	 */
	public function test_non_string_values_skipped() {
		$paths = array(
			'/*',
			123,
			'/blog/*',
			true,
			'/images/*',
			array( '/nested' ),
			null,
		);

		$result = $this->call_private_method( $this->plugin, 'sanitize_invalidation_paths_array', array( $paths ) );

		$expected = array( '/*', '/blog/*', '/images/*' );

		$this->assertEquals( $expected, $result, 'Non-string values should be skipped' );
	}

	/**
	 * Test that array with only invalid values returns error.
	 */
	public function test_only_invalid_values_returns_error() {
		$paths = array(
			'',
			123,
			null,
			'   ',
			array( 'nested' ),
		);

		$result = $this->call_private_method( $this->plugin, 'sanitize_invalidation_paths_array', array( $paths ) );

		$this->assertInstanceOf( WP_Error::class, $result, 'Array with only invalid values should return error' );
		$this->assertEquals( 'no_valid_paths', $result->get_error_code() );
	}

	/**
	 * Test special characters in paths.
	 */
	public function test_special_characters_in_paths() {
		$paths = array(
			'/path-with-dashes',
			'/path_with_underscores',
			'/path.with.dots',
			'/path/with/slashes',
			'/path?with=query',
			'/path#with-hash',
		);

		$result = $this->call_private_method( $this->plugin, 'sanitize_invalidation_paths_array', array( $paths ) );

		$this->assertIsArray( $result, 'Paths with special characters should be accepted' );
		$this->assertEquals( $paths, $result, 'Special characters should be preserved' );
	}

	/**
	 * Test wildcard paths.
	 */
	public function test_wildcard_paths() {
		$paths = array(
			'/*',
			'/blog/*',
			'/images/*.jpg',
			'/category/*/posts',
		);

		$result = $this->call_private_method( $this->plugin, 'sanitize_invalidation_paths_array', array( $paths ) );

		$this->assertIsArray( $result );
		$this->assertEquals( $paths, $result, 'Wildcard paths should be preserved' );
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
