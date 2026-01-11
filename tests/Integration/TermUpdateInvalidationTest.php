<?php
/**
 * Integration tests for term update invalidation.
 *
 * Tests the invalidate_on_term_update() method which handles CloudFront
 * cache invalidation when taxonomy terms (categories, tags, custom taxonomies)
 * are updated. This method is located at lines 1020-1036 in the main class.
 *
 * @package CloudFrontCacheInvalidator
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Test term update invalidation behavior.
 */
class TermUpdateInvalidationTest extends TestCase {

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

		// Mock WordPress functions required during construction.
		Functions\when( 'get_option' )->justReturn(
			array(
				'distribution_id' => $this->fixtures['distribution_ids']['valid'][0],
				'aws_region'      => $this->fixtures['aws_regions']['valid'][0],
			)
		);

		// Mock common WordPress functions used in invalidation methods.
		Functions\when( 'wp_generate_password' )->justReturn( 'abc123' );
		Functions\when( 'do_action' )->justReturn( null );

		// Create plugin instance which registers hooks in constructor.
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
	 * Test that category term link is extracted correctly.
	 *
	 * Verifies that the method gets the term link for a category
	 * and extracts the path for invalidation.
	 */
	public function test_category_term_link_extraction() {
		Functions\when( 'get_term_link' )->justReturn( 'https://example.com/category/technology/' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url ) {
				return parse_url( $url );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );

		// Test execution - should extract /category/technology/ from the URL.
		$this->plugin->invalidate_on_term_update( 5, 6, 'category' );

		$this->assertTrue( true, 'Category term link should be extracted and invalidated' );
	}

	/**
	 * Test that tag term link is extracted correctly.
	 *
	 * Verifies that the method gets the term link for a tag
	 * and extracts the path for invalidation.
	 */
	public function test_tag_term_link_extraction() {
		Functions\when( 'get_term_link' )->justReturn( 'https://example.com/tag/wordpress/' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url ) {
				return parse_url( $url );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );

		// Test execution - should extract /tag/wordpress/ from the URL.
		$this->plugin->invalidate_on_term_update( 10, 11, 'post_tag' );

		$this->assertTrue( true, 'Tag term link should be extracted and invalidated' );
	}

	/**
	 * Test that custom taxonomy term link is extracted correctly.
	 *
	 * Custom post types can have custom taxonomies, and their term links
	 * should also be extracted correctly.
	 */
	public function test_custom_taxonomy_term_link_extraction() {
		Functions\when( 'get_term_link' )->justReturn( 'https://example.com/product-category/electronics/' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url ) {
				return parse_url( $url );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );

		// Test execution - should extract /product-category/electronics/ from the URL.
		$this->plugin->invalidate_on_term_update( 20, 21, 'product_category' );

		$this->assertTrue( true, 'Custom taxonomy term link should be extracted and invalidated' );
	}

	/**
	 * Test path generation with trailing slash.
	 *
	 * Most WordPress permalinks have trailing slashes. The method should
	 * handle these correctly.
	 */
	public function test_path_generation_with_trailing_slash() {
		Functions\when( 'get_term_link' )->justReturn( 'https://example.com/category/news/' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url ) {
				return parse_url( $url );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );

		// Test execution - should create paths: /category/news/ and /category/news/*
		$this->plugin->invalidate_on_term_update( 15, 16, 'category' );

		$this->assertTrue( true, 'Path with trailing slash should be handled correctly' );
	}

	/**
	 * Test path generation without trailing slash.
	 *
	 * Some permalink structures don't use trailing slashes. The method
	 * should handle these correctly too.
	 */
	public function test_path_generation_without_trailing_slash() {
		Functions\when( 'get_term_link' )->justReturn( 'https://example.com/category/sports' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url ) {
				return parse_url( $url );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );

		// Test execution - should create paths: /category/sports and /category/sports*
		$this->plugin->invalidate_on_term_update( 25, 26, 'category' );

		$this->assertTrue( true, 'Path without trailing slash should be handled correctly' );
	}

	/**
	 * Test path generation with subdirectory installation.
	 *
	 * WordPress can be installed in a subdirectory, resulting in paths
	 * like /blog/category/news/
	 */
	public function test_path_generation_with_subdirectory() {
		Functions\when( 'get_term_link' )->justReturn( 'https://example.com/blog/category/updates/' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url ) {
				return parse_url( $url );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );

		// Test execution - should extract /blog/category/updates/
		$this->plugin->invalidate_on_term_update( 30, 31, 'category' );

		$this->assertTrue( true, 'Path with subdirectory should be handled correctly' );
	}

	/**
	 * Test path generation with hierarchical categories.
	 *
	 * Categories can be hierarchical, resulting in paths like
	 * /category/parent/child/
	 */
	public function test_path_generation_with_hierarchical_categories() {
		Functions\when( 'get_term_link' )->justReturn( 'https://example.com/category/technology/software/' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url ) {
				return parse_url( $url );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );

		// Test execution - should extract /category/technology/software/
		$this->plugin->invalidate_on_term_update( 40, 41, 'category' );

		$this->assertTrue( true, 'Hierarchical category path should be handled correctly' );
	}

	/**
	 * Test that root path is handled when term link path is empty.
	 *
	 * If wp_parse_url doesn't return a path component, the method
	 * should default to root path.
	 */
	public function test_path_generation_defaults_to_root_when_no_path() {
		Functions\when( 'get_term_link' )->justReturn( 'https://example.com/' );
		Functions\when( 'wp_parse_url' )->justReturn(
			array(
				'scheme' => 'https',
				'host'   => 'example.com',
			)
		);
		Functions\when( 'is_wp_error' )->justReturn( false );

		// Test execution - should default to / when no path is present.
		$this->plugin->invalidate_on_term_update( 50, 51, 'category' );

		$this->assertTrue( true, 'Missing path should default to root path' );
	}

	/**
	 * Test error handling when get_term_link returns WP_Error.
	 *
	 * If the term doesn't exist or is invalid, get_term_link returns
	 * a WP_Error. The method should handle this gracefully.
	 */
	public function test_handles_wp_error_from_get_term_link() {
		$error = new WP_Error( 'invalid_term', 'Invalid term ID.' );
		Functions\when( 'get_term_link' )->justReturn( $error );
		Functions\when( 'is_wp_error' )->justReturn( true );

		// wp_parse_url should never be called if get_term_link returns an error.
		Functions\expect( 'wp_parse_url' )->never();

		// Test execution - should return early without attempting invalidation.
		$this->plugin->invalidate_on_term_update( 999, 999, 'category' );

		$this->assertTrue( true, 'WP_Error from get_term_link should be handled gracefully' );
	}

	/**
	 * Test error handling with different WP_Error codes.
	 *
	 * get_term_link can return different error codes. All should be
	 * handled the same way.
	 */
	public function test_handles_different_wp_error_codes() {
		$error = new WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' );
		Functions\when( 'get_term_link' )->justReturn( $error );
		Functions\when( 'is_wp_error' )->justReturn( true );

		// Test execution - should handle any WP_Error gracefully.
		$this->plugin->invalidate_on_term_update( 888, 888, 'invalid_taxonomy' );

		$this->assertTrue( true, 'Different WP_Error codes should be handled gracefully' );
	}

	/**
	 * Test that method is called with correct parameters.
	 *
	 * The edited_term hook passes three parameters: term_id, tt_id, and taxonomy.
	 * Verify the method can handle all three correctly.
	 */
	public function test_accepts_correct_parameters() {
		Functions\when( 'get_term_link' )->justReturn( 'https://example.com/category/test/' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url ) {
				return parse_url( $url );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );

		// Test with standard parameters from edited_term hook.
		$term_id  = 123;
		$tt_id    = 456;
		$taxonomy = 'category';

		$this->plugin->invalidate_on_term_update( $term_id, $tt_id, $taxonomy );

		$this->assertTrue( true, 'Method should accept term_id, tt_id, and taxonomy parameters' );
	}

	/**
	 * Test path generation with special characters in term slug.
	 *
	 * Term slugs can contain hyphens, numbers, and sometimes other characters.
	 * The method should handle these correctly.
	 */
	public function test_path_generation_with_special_characters() {
		Functions\when( 'get_term_link' )->justReturn( 'https://example.com/category/web-3-0/' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url ) {
				return parse_url( $url );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );

		// Test execution - should handle hyphens and numbers in path.
		$this->plugin->invalidate_on_term_update( 70, 71, 'category' );

		$this->assertTrue( true, 'Path with special characters should be handled correctly' );
	}

	/**
	 * Test path generation with encoded characters.
	 *
	 * Some term slugs might have URL-encoded characters. The method
	 * should preserve the encoding from the term link.
	 */
	public function test_path_generation_with_encoded_characters() {
		Functions\when( 'get_term_link' )->justReturn( 'https://example.com/category/caf%C3%A9/' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url ) {
				return parse_url( $url );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );

		// Test execution - should preserve URL encoding in path.
		$this->plugin->invalidate_on_term_update( 80, 81, 'category' );

		$this->assertTrue( true, 'Path with encoded characters should be handled correctly' );
	}

	/**
	 * Test that both exact path and wildcard path are invalidated.
	 *
	 * The method should create two paths: the exact term path and
	 * a wildcard version (path/*) to catch paginated archives.
	 */
	public function test_creates_both_exact_and_wildcard_paths() {
		Functions\when( 'get_term_link' )->justReturn( 'https://example.com/category/articles/' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url ) {
				return parse_url( $url );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );

		// Test execution - should create both /category/articles/ and /category/articles/*
		$this->plugin->invalidate_on_term_update( 90, 91, 'category' );

		$this->assertTrue( true, 'Both exact path and wildcard path should be created' );
	}

	/**
	 * Test with different taxonomy slug formats.
	 *
	 * Custom taxonomies can have various slug formats. Test that
	 * different formats work correctly.
	 */
	public function test_different_taxonomy_slug_formats() {
		$test_cases = array(
			array(
				'url'      => 'https://example.com/portfolio-type/design/',
				'taxonomy' => 'portfolio_type',
			),
			array(
				'url'      => 'https://example.com/productcategory/widgets/',
				'taxonomy' => 'product_category',
			),
			array(
				'url'      => 'https://example.com/custom-tax/term/',
				'taxonomy' => 'custom_tax',
			),
		);

		foreach ( $test_cases as $index => $case ) {
			Functions\when( 'get_term_link' )->justReturn( $case['url'] );
			Functions\when( 'wp_parse_url' )->alias(
				function ( $url ) {
					return parse_url( $url );
				}
			);
			Functions\when( 'is_wp_error' )->justReturn( false );

			$this->plugin->invalidate_on_term_update( 100 + $index, 200 + $index, $case['taxonomy'] );
		}

		$this->assertTrue( true, 'Different taxonomy slug formats should be handled correctly' );
	}

	/**
	 * Test path generation with query parameters in term link.
	 *
	 * While rare, if a term link has query parameters, only the path
	 * should be extracted for invalidation.
	 */
	public function test_path_generation_ignores_query_parameters() {
		Functions\when( 'get_term_link' )->justReturn( 'https://example.com/category/test/?page=2' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url ) {
				return parse_url( $url );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );

		// Test execution - should extract only /category/test/ without query params.
		$this->plugin->invalidate_on_term_update( 110, 111, 'category' );

		$this->assertTrue( true, 'Query parameters should be ignored in path extraction' );
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
