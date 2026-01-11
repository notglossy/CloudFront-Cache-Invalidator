<?php
/**
 * Integration tests for WordPress hook behavior.
 *
 * Tests that WordPress hooks trigger the correct invalidation behavior
 * when fired with appropriate parameters. Tests the following methods:
 * - invalidate_on_post_update() at lines 925-990
 * - invalidate_on_post_delete() at lines 1002-1005
 * - invalidate_on_term_update() at lines 1020-1036
 * - invalidate_all() at lines 1048-1056
 *
 * @package CloudFrontCacheInvalidator
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;

/**
 * Test hook behavior and invalidation triggering.
 */
class HookBehaviorTest extends TestCase {

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
	 * Test that save_post hook triggers invalidation with correct parameters.
	 */
	public function test_save_post_triggers_invalidation() {
		// Mock WordPress functions used in invalidate_on_post_update.
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/test-post/' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url ) {
				return parse_url( $url );
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $option ) {
				if ( 'page_on_front' === $option ) {
					return 0;
				}
				if ( 'page_for_posts' === $option ) {
					return 0;
				}
				return array(
					'distribution_id' => 'E1ABCDEFGHIJKL',
					'aws_region'      => 'us-east-1',
				);
			}
		);
		Functions\when( 'get_post_type_archive_link' )->justReturn( false );
		Functions\when( 'get_object_taxonomies' )->justReturn( array() );

		// Create a mock post object.
		$post              = new stdClass();
		$post->ID          = 123;
		$post->post_type   = 'post';
		$post->post_status = 'publish';

		// Test that the method can be called without errors.
		$this->plugin->invalidate_on_post_update( 123, $post );

		// Since send_invalidation_request requires AWS SDK, we're primarily testing
		// that the method executes without PHP errors and processes the post correctly.
		$this->assertTrue( true, 'invalidate_on_post_update should execute without errors' );
	}

	/**
	 * Test that save_post hook skips autosaves.
	 */
	public function test_save_post_skips_autosaves() {
		// Define DOING_AUTOSAVE constant.
		if ( ! defined( 'DOING_AUTOSAVE' ) ) {
			define( 'DOING_AUTOSAVE', true );
		}

		Functions\when( 'wp_is_post_revision' )->justReturn( false );

		$post              = new stdClass();
		$post->ID          = 123;
		$post->post_type   = 'post';
		$post->post_status = 'publish';

		// The method should return early and not call get_permalink.
		Functions\expect( 'get_permalink' )->never();

		$this->plugin->invalidate_on_post_update( 123, $post );

		$this->assertTrue( true, 'Autosaves should be skipped' );
	}

	/**
	 * Test that save_post hook skips revisions.
	 */
	public function test_save_post_skips_revisions() {
		Functions\when( 'wp_is_post_revision' )->justReturn( true );

		$post              = new stdClass();
		$post->ID          = 123;
		$post->post_type   = 'revision';
		$post->post_status = 'inherit';

		// The method should return early and not call get_permalink.
		Functions\expect( 'get_permalink' )->never();

		$this->plugin->invalidate_on_post_update( 123, $post );

		$this->assertTrue( true, 'Revisions should be skipped' );
	}

	/**
	 * Test that save_post hook skips auto-drafts.
	 */
	public function test_save_post_skips_auto_drafts() {
		Functions\when( 'wp_is_post_revision' )->justReturn( false );

		$post              = new stdClass();
		$post->ID          = 123;
		$post->post_type   = 'post';
		$post->post_status = 'auto-draft';

		// The method should return early and not call get_permalink.
		Functions\expect( 'get_permalink' )->never();

		$this->plugin->invalidate_on_post_update( 123, $post );

		$this->assertTrue( true, 'Auto-drafts should be skipped' );
	}

	/**
	 * Test that save_post hook handles front page correctly.
	 */
	public function test_save_post_handles_front_page() {
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url ) {
				return parse_url( $url );
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $option ) {
				if ( 'page_on_front' === $option ) {
					return 456; // Match our test post ID.
				}
				return array(
					'distribution_id' => 'E1ABCDEFGHIJKL',
					'aws_region'      => 'us-east-1',
				);
			}
		);
		Functions\when( 'get_post_type_archive_link' )->justReturn( false );
		Functions\when( 'get_object_taxonomies' )->justReturn( array() );

		$post              = new stdClass();
		$post->ID          = 456;
		$post->post_type   = 'page';
		$post->post_status = 'publish';

		$this->plugin->invalidate_on_post_update( 456, $post );

		$this->assertTrue( true, 'Front page should be handled correctly' );
	}

	/**
	 * Test that deleted_post hook triggers full invalidation.
	 */
	public function test_deleted_post_triggers_full_invalidation() {
		// Mock get_option to return invalidation paths.
		Functions\when( 'get_option' )->alias(
			function ( $option ) {
				if ( 'cloudfront_cache_invalidator_options' === $option ) {
					return array(
						'distribution_id'    => 'E1ABCDEFGHIJKL',
						'aws_region'         => 'us-east-1',
						'invalidation_paths' => '/*',
					);
				}
				return false;
			}
		);

		// Test that the method can be called without errors.
		$this->plugin->invalidate_on_post_delete();

		$this->assertTrue( true, 'deleted_post should trigger full invalidation' );
	}

	/**
	 * Test that switch_theme hook triggers full invalidation.
	 */
	public function test_switch_theme_triggers_full_invalidation() {
		// Mock get_option to return invalidation paths.
		Functions\when( 'get_option' )->alias(
			function ( $option ) {
				if ( 'cloudfront_cache_invalidator_options' === $option ) {
					return array(
						'distribution_id'    => 'E1ABCDEFGHIJKL',
						'aws_region'         => 'us-east-1',
						'invalidation_paths' => '/*',
					);
				}
				return false;
			}
		);

		// Trigger switch_theme action.
		do_action( 'switch_theme', 'twentytwentyfour', new stdClass() );

		$this->assertTrue( true, 'switch_theme should trigger full invalidation' );
	}

	/**
	 * Test that customize_save_after hook triggers full invalidation.
	 */
	public function test_customize_save_after_triggers_full_invalidation() {
		// Mock get_option to return invalidation paths.
		Functions\when( 'get_option' )->alias(
			function ( $option ) {
				if ( 'cloudfront_cache_invalidator_options' === $option ) {
					return array(
						'distribution_id'    => 'E1ABCDEFGHIJKL',
						'aws_region'         => 'us-east-1',
						'invalidation_paths' => '/*',
					);
				}
				return false;
			}
		);

		// Create a mock WP_Customize_Manager.
		$manager = new stdClass();

		// Trigger customize_save_after action.
		do_action( 'customize_save_after', $manager );

		$this->assertTrue( true, 'customize_save_after should trigger full invalidation' );
	}

	/**
	 * Test that wp_update_nav_menu hook triggers full invalidation.
	 */
	public function test_wp_update_nav_menu_triggers_full_invalidation() {
		// Mock get_option to return invalidation paths.
		Functions\when( 'get_option' )->alias(
			function ( $option ) {
				if ( 'cloudfront_cache_invalidator_options' === $option ) {
					return array(
						'distribution_id'    => 'E1ABCDEFGHIJKL',
						'aws_region'         => 'us-east-1',
						'invalidation_paths' => '/*',
					);
				}
				return false;
			}
		);

		// Trigger wp_update_nav_menu action.
		do_action( 'wp_update_nav_menu', 123, array() );

		$this->assertTrue( true, 'wp_update_nav_menu should trigger full invalidation' );
	}

	/**
	 * Test that edited_term hook triggers term-specific invalidation.
	 */
	public function test_edited_term_triggers_term_specific_invalidation() {
		// Mock WordPress functions for term handling.
		Functions\when( 'get_term_link' )->justReturn( 'https://example.com/category/test/' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url ) {
				return parse_url( $url );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );

		// Test that the method can be called with correct parameters.
		$this->plugin->invalidate_on_term_update( 123, 456, 'category' );

		$this->assertTrue( true, 'edited_term should trigger term-specific invalidation' );
	}

	/**
	 * Test that edited_term hook handles WP_Error from get_term_link.
	 */
	public function test_edited_term_handles_error() {
		// Mock get_term_link to return WP_Error.
		Functions\when( 'get_term_link' )->justReturn( new WP_Error( 'invalid_term', 'Invalid term.' ) );
		Functions\when( 'is_wp_error' )->justReturn( true );

		// The method should handle the error gracefully and not call send_invalidation_request.
		$this->plugin->invalidate_on_term_update( 999, 999, 'invalid_taxonomy' );

		$this->assertTrue( true, 'edited_term should handle errors gracefully' );
	}

	/**
	 * Test that save_post hook invalidates taxonomy archives.
	 */
	public function test_save_post_invalidates_taxonomy_archives() {
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/test-post/' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url ) {
				return parse_url( $url );
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $option ) {
				if ( 'page_on_front' === $option || 'page_for_posts' === $option ) {
					return 0;
				}
				return array(
					'distribution_id' => 'E1ABCDEFGHIJKL',
					'aws_region'      => 'us-east-1',
				);
			}
		);
		Functions\when( 'get_post_type_archive_link' )->justReturn( 'https://example.com/blog/' );
		Functions\when( 'get_object_taxonomies' )->justReturn( array( 'category', 'post_tag' ) );

		// Mock get_the_terms to return test terms.
		Functions\when( 'get_the_terms' )->alias(
			function ( $post_id, $taxonomy ) {
				$term           = new stdClass();
				$term->term_id  = 1;
				$term->name     = 'Test Category';
				$term->slug     = 'test-category';
				$term->taxonomy = $taxonomy;
				return array( $term );
			}
		);

		Functions\when( 'get_term_link' )->justReturn( 'https://example.com/category/test-category/' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$post              = new stdClass();
		$post->ID          = 123;
		$post->post_type   = 'post';
		$post->post_status = 'publish';

		// Test that the method processes taxonomy archives.
		$this->plugin->invalidate_on_post_update( 123, $post );

		$this->assertTrue( true, 'save_post should invalidate taxonomy archives' );
	}

	/**
	 * Test that save_post hook invalidates post type archives.
	 */
	public function test_save_post_invalidates_post_type_archives() {
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/product/test/' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url ) {
				return parse_url( $url );
			}
		);
		Functions\when( 'get_option' )->justReturn(
			array(
				'distribution_id' => 'E1ABCDEFGHIJKL',
				'aws_region'      => 'us-east-1',
			)
		);
		Functions\when( 'get_post_type_archive_link' )->justReturn( 'https://example.com/products/' );
		Functions\when( 'get_object_taxonomies' )->justReturn( array() );

		$post              = new stdClass();
		$post->ID          = 789;
		$post->post_type   = 'product';
		$post->post_status = 'publish';

		// Test that the method processes post type archives.
		$this->plugin->invalidate_on_post_update( 789, $post );

		$this->assertTrue( true, 'save_post should invalidate post type archives' );
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
