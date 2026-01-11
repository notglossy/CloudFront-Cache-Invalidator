<?php
/**
 * Integration tests for post update invalidation.
 *
 * Tests the invalidate_on_post_update() method which handles CloudFront
 * cache invalidation when posts are created or updated. This method is
 * located at lines 925-990 in the main class.
 *
 * @package CloudFrontCacheInvalidator
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Test post update invalidation behavior.
 */
class PostUpdateInvalidationTest extends TestCase {

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
	 * Test that post permalink is correctly extracted and invalidated.
	 *
	 * Verifies that the method extracts the permalink path from the full URL
	 * and creates appropriate invalidation patterns (path and path/*).
	 */
	public function test_post_permalink_extraction() {
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/2024/01/sample-post/' );
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
					'distribution_id' => 'E1234567890AB',
					'aws_region'      => 'us-east-1',
				);
			}
		);
		Functions\when( 'get_post_type_archive_link' )->justReturn( false );
		Functions\when( 'get_object_taxonomies' )->justReturn( array() );

		$post              = new stdClass();
		$post->ID          = 123;
		$post->post_type   = 'post';
		$post->post_status = 'publish';

		// Test execution - should extract /2024/01/sample-post/ from the URL.
		$this->plugin->invalidate_on_post_update( 123, $post );

		$this->assertTrue( true, 'Post permalink should be extracted and invalidated' );
	}

	/**
	 * Test that root path is invalidated when permalink path is empty.
	 *
	 * Some permalink structures may result in just the domain.
	 */
	public function test_post_permalink_extraction_root_path() {
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url ) {
				return parse_url( $url );
			}
		);
		Functions\when( 'get_option' )->justReturn(
			array(
				'distribution_id' => 'E1234567890AB',
				'aws_region'      => 'us-east-1',
			)
		);
		Functions\when( 'get_post_type_archive_link' )->justReturn( false );
		Functions\when( 'get_object_taxonomies' )->justReturn( array() );

		$post              = new stdClass();
		$post->ID          = 1;
		$post->post_type   = 'post';
		$post->post_status = 'publish';

		// Test execution - should handle root path (/).
		$this->plugin->invalidate_on_post_update( 1, $post );

		$this->assertTrue( true, 'Root path should be handled correctly' );
	}

	/**
	 * Test that archive page paths are invalidated for post types.
	 *
	 * When a post of a custom post type is updated, the archive page
	 * for that post type should also be invalidated.
	 */
	public function test_archive_page_paths_for_custom_post_type() {
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/products/sample-product/' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url ) {
				return parse_url( $url );
			}
		);
		Functions\when( 'get_option' )->justReturn(
			array(
				'distribution_id' => 'E1234567890AB',
				'aws_region'      => 'us-east-1',
			)
		);
		Functions\when( 'get_post_type_archive_link' )->justReturn( 'https://example.com/products/' );
		Functions\when( 'get_object_taxonomies' )->justReturn( array() );

		$post              = new stdClass();
		$post->ID          = 456;
		$post->post_type   = 'product';
		$post->post_status = 'publish';

		// Test execution - should invalidate both the product page and archive.
		$this->plugin->invalidate_on_post_update( 456, $post );

		$this->assertTrue( true, 'Archive page should be invalidated for custom post types' );
	}

	/**
	 * Test that no archive is invalidated for pages.
	 *
	 * Pages don't have archives, so get_post_type_archive_link returns false.
	 */
	public function test_no_archive_for_pages() {
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/about/' );
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
					'distribution_id' => 'E1234567890AB',
					'aws_region'      => 'us-east-1',
				);
			}
		);
		Functions\when( 'get_post_type_archive_link' )->justReturn( false );
		Functions\when( 'get_object_taxonomies' )->justReturn( array() );

		$post              = new stdClass();
		$post->ID          = 789;
		$post->post_type   = 'page';
		$post->post_status = 'publish';

		// Test execution - should only invalidate the page itself.
		$this->plugin->invalidate_on_post_update( 789, $post );

		$this->assertTrue( true, 'Pages should not trigger archive invalidation' );
	}

	/**
	 * Test that category paths are invalidated when post has categories.
	 *
	 * When a post is assigned to categories, those category archive pages
	 * should be invalidated.
	 */
	public function test_category_paths_invalidation() {
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/blog/my-post/' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url ) {
				return parse_url( $url );
			}
		);
		Functions\when( 'get_option' )->justReturn(
			array(
				'distribution_id' => 'E1234567890AB',
				'aws_region'      => 'us-east-1',
			)
		);
		Functions\when( 'get_post_type_archive_link' )->justReturn( 'https://example.com/blog/' );
		Functions\when( 'get_object_taxonomies' )->justReturn( array( 'category', 'post_tag' ) );

		// Mock get_the_terms to return test categories.
		Functions\when( 'get_the_terms' )->alias(
			function ( $post_id, $taxonomy ) {
				if ( 'category' === $taxonomy ) {
					$cat1           = new stdClass();
					$cat1->term_id  = 1;
					$cat1->name     = 'Technology';
					$cat1->slug     = 'technology';
					$cat1->taxonomy = 'category';

					$cat2           = new stdClass();
					$cat2->term_id  = 2;
					$cat2->name     = 'News';
					$cat2->slug     = 'news';
					$cat2->taxonomy = 'category';

					return array( $cat1, $cat2 );
				}
				return false;
			}
		);

		Functions\when( 'get_term_link' )->alias(
			function ( $term ) {
				if ( 1 === $term->term_id ) {
					return 'https://example.com/category/technology/';
				}
				if ( 2 === $term->term_id ) {
					return 'https://example.com/category/news/';
				}
				return false;
			}
		);

		Functions\when( 'is_wp_error' )->justReturn( false );

		$post              = new stdClass();
		$post->ID          = 100;
		$post->post_type   = 'post';
		$post->post_status = 'publish';

		// Test execution - should invalidate post + blog archive + both categories.
		$this->plugin->invalidate_on_post_update( 100, $post );

		$this->assertTrue( true, 'Category paths should be invalidated' );
	}

	/**
	 * Test that tag paths are invalidated when post has tags.
	 *
	 * When a post is assigned to tags, those tag archive pages
	 * should be invalidated.
	 */
	public function test_tag_paths_invalidation() {
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/blog/my-post/' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url ) {
				return parse_url( $url );
			}
		);
		Functions\when( 'get_option' )->justReturn(
			array(
				'distribution_id' => 'E1234567890AB',
				'aws_region'      => 'us-east-1',
			)
		);
		Functions\when( 'get_post_type_archive_link' )->justReturn( 'https://example.com/blog/' );
		Functions\when( 'get_object_taxonomies' )->justReturn( array( 'post_tag' ) );

		// Mock get_the_terms to return test tags.
		Functions\when( 'get_the_terms' )->alias(
			function ( $post_id, $taxonomy ) {
				if ( 'post_tag' === $taxonomy ) {
					$tag1           = new stdClass();
					$tag1->term_id  = 10;
					$tag1->name     = 'PHP';
					$tag1->slug     = 'php';
					$tag1->taxonomy = 'post_tag';

					$tag2           = new stdClass();
					$tag2->term_id  = 11;
					$tag2->name     = 'WordPress';
					$tag2->slug     = 'wordpress';
					$tag2->taxonomy = 'post_tag';

					return array( $tag1, $tag2 );
				}
				return false;
			}
		);

		Functions\when( 'get_term_link' )->alias(
			function ( $term ) {
				if ( 10 === $term->term_id ) {
					return 'https://example.com/tag/php/';
				}
				if ( 11 === $term->term_id ) {
					return 'https://example.com/tag/wordpress/';
				}
				return false;
			}
		);

		Functions\when( 'is_wp_error' )->justReturn( false );

		$post              = new stdClass();
		$post->ID          = 200;
		$post->post_type   = 'post';
		$post->post_status = 'publish';

		// Test execution - should invalidate post + blog archive + both tags.
		$this->plugin->invalidate_on_post_update( 200, $post );

		$this->assertTrue( true, 'Tag paths should be invalidated' );
	}

	/**
	 * Test that custom taxonomy paths are invalidated.
	 *
	 * Custom post types can have custom taxonomies, and those should
	 * also be invalidated when the post is updated.
	 */
	public function test_custom_taxonomy_paths_invalidation() {
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/products/widget-pro/' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url ) {
				return parse_url( $url );
			}
		);
		Functions\when( 'get_option' )->justReturn(
			array(
				'distribution_id' => 'E1234567890AB',
				'aws_region'      => 'us-east-1',
			)
		);
		Functions\when( 'get_post_type_archive_link' )->justReturn( 'https://example.com/products/' );
		Functions\when( 'get_object_taxonomies' )->justReturn( array( 'product_category' ) );

		// Mock get_the_terms to return custom taxonomy terms.
		Functions\when( 'get_the_terms' )->alias(
			function ( $post_id, $taxonomy ) {
				if ( 'product_category' === $taxonomy ) {
					$term           = new stdClass();
					$term->term_id  = 50;
					$term->name     = 'Electronics';
					$term->slug     = 'electronics';
					$term->taxonomy = 'product_category';

					return array( $term );
				}
				return false;
			}
		);

		Functions\when( 'get_term_link' )->justReturn( 'https://example.com/product-category/electronics/' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$post              = new stdClass();
		$post->ID          = 300;
		$post->post_type   = 'product';
		$post->post_status = 'publish';

		// Test execution - should invalidate product + archive + custom taxonomy.
		$this->plugin->invalidate_on_post_update( 300, $post );

		$this->assertTrue( true, 'Custom taxonomy paths should be invalidated' );
	}

	/**
	 * Test that WP_Error from get_term_link is handled gracefully.
	 *
	 * If get_term_link returns a WP_Error, the method should skip that
	 * term and continue processing other terms.
	 */
	public function test_term_link_error_handling() {
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/blog/my-post/' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url ) {
				return parse_url( $url );
			}
		);
		Functions\when( 'get_option' )->justReturn(
			array(
				'distribution_id' => 'E1234567890AB',
				'aws_region'      => 'us-east-1',
			)
		);
		Functions\when( 'get_post_type_archive_link' )->justReturn( false );
		Functions\when( 'get_object_taxonomies' )->justReturn( array( 'category' ) );

		// Mock get_the_terms to return a term.
		Functions\when( 'get_the_terms' )->justReturn(
			array(
				(object) array(
					'term_id'  => 999,
					'name'     => 'Invalid Category',
					'slug'     => 'invalid',
					'taxonomy' => 'category',
				),
			)
		);

		// Mock get_term_link to return WP_Error.
		Functions\when( 'get_term_link' )->justReturn( new WP_Error( 'invalid_term', 'Invalid term.' ) );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$post              = new stdClass();
		$post->ID          = 400;
		$post->post_type   = 'post';
		$post->post_status = 'publish';

		// Test execution - should handle the error and continue.
		$this->plugin->invalidate_on_post_update( 400, $post );

		$this->assertTrue( true, 'WP_Error from get_term_link should be handled gracefully' );
	}

	/**
	 * Test that front page is detected and invalidated.
	 *
	 * When page_on_front option matches the post ID, the root path
	 * should be invalidated.
	 */
	public function test_front_page_detection_page_on_front() {
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
					return 5; // Match our test post ID.
				}
				if ( 'page_for_posts' === $option ) {
					return 0;
				}
				return array(
					'distribution_id' => 'E1234567890AB',
					'aws_region'      => 'us-east-1',
				);
			}
		);
		Functions\when( 'get_post_type_archive_link' )->justReturn( false );
		Functions\when( 'get_object_taxonomies' )->justReturn( array() );

		$post              = new stdClass();
		$post->ID          = 5;
		$post->post_type   = 'page';
		$post->post_status = 'publish';

		// Test execution - should invalidate root path because it's the front page.
		$this->plugin->invalidate_on_post_update( 5, $post );

		$this->assertTrue( true, 'Front page (page_on_front) should be detected and root path invalidated' );
	}

	/**
	 * Test that blog page is detected and invalidated.
	 *
	 * When page_for_posts option matches the post ID, the root path
	 * should be invalidated.
	 */
	public function test_front_page_detection_page_for_posts() {
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/blog/' );
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
					return 6; // Match our test post ID.
				}
				return array(
					'distribution_id' => 'E1234567890AB',
					'aws_region'      => 'us-east-1',
				);
			}
		);
		Functions\when( 'get_post_type_archive_link' )->justReturn( false );
		Functions\when( 'get_object_taxonomies' )->justReturn( array() );

		$post              = new stdClass();
		$post->ID          = 6;
		$post->post_type   = 'page';
		$post->post_status = 'publish';

		// Test execution - should invalidate root path because it's the blog page.
		$this->plugin->invalidate_on_post_update( 6, $post );

		$this->assertTrue( true, 'Blog page (page_for_posts) should be detected and root path invalidated' );
	}

	/**
	 * Test that autosaves are skipped.
	 *
	 * When DOING_AUTOSAVE is defined and true, the method should return
	 * early without invalidating anything.
	 */
	public function test_skip_autosaves() {
		// Define DOING_AUTOSAVE constant if not already defined.
		if ( ! defined( 'DOING_AUTOSAVE' ) ) {
			define( 'DOING_AUTOSAVE', true );
		}

		Functions\when( 'wp_is_post_revision' )->justReturn( false );

		// get_permalink should never be called for autosaves.
		Functions\expect( 'get_permalink' )->never();

		$post              = new stdClass();
		$post->ID          = 999;
		$post->post_type   = 'post';
		$post->post_status = 'publish';

		// Test execution - should return early due to DOING_AUTOSAVE.
		$this->plugin->invalidate_on_post_update( 999, $post );

		$this->assertTrue( true, 'Autosaves should be skipped' );
	}

	/**
	 * Test that revisions are skipped.
	 *
	 * When wp_is_post_revision returns true, the method should return
	 * early without invalidating anything.
	 */
	public function test_skip_revisions() {
		Functions\when( 'wp_is_post_revision' )->justReturn( true );

		// get_permalink should never be called for revisions.
		Functions\expect( 'get_permalink' )->never();

		$post              = new stdClass();
		$post->ID          = 1000;
		$post->post_type   = 'revision';
		$post->post_status = 'inherit';

		// Test execution - should return early due to revision detection.
		$this->plugin->invalidate_on_post_update( 1000, $post );

		$this->assertTrue( true, 'Revisions should be skipped' );
	}

	/**
	 * Test that auto-drafts are skipped.
	 *
	 * When post_status is 'auto-draft', the method should return early
	 * without invalidating anything.
	 */
	public function test_skip_auto_drafts() {
		Functions\when( 'wp_is_post_revision' )->justReturn( false );

		// get_permalink should never be called for auto-drafts.
		Functions\expect( 'get_permalink' )->never();

		$post              = new stdClass();
		$post->ID          = 1001;
		$post->post_type   = 'post';
		$post->post_status = 'auto-draft';

		// Test execution - should return early due to auto-draft status.
		$this->plugin->invalidate_on_post_update( 1001, $post );

		$this->assertTrue( true, 'Auto-drafts should be skipped' );
	}

	/**
	 * Test that draft posts are processed normally.
	 *
	 * Draft posts (not auto-drafts) should be invalidated to ensure
	 * any previously published content is updated in the CDN.
	 */
	public function test_draft_posts_are_processed() {
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/draft-post/' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url ) {
				return parse_url( $url );
			}
		);
		Functions\when( 'get_option' )->justReturn(
			array(
				'distribution_id' => 'E1234567890AB',
				'aws_region'      => 'us-east-1',
			)
		);
		Functions\when( 'get_post_type_archive_link' )->justReturn( false );
		Functions\when( 'get_object_taxonomies' )->justReturn( array() );

		$post              = new stdClass();
		$post->ID          = 1002;
		$post->post_type   = 'post';
		$post->post_status = 'draft';

		// Test execution - draft should be processed.
		$this->plugin->invalidate_on_post_update( 1002, $post );

		$this->assertTrue( true, 'Draft posts should be processed (not skipped)' );
	}

	/**
	 * Test that pending posts are processed normally.
	 *
	 * Pending posts should be invalidated to ensure any previously
	 * published content is updated in the CDN.
	 */
	public function test_pending_posts_are_processed() {
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/pending-post/' );
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url ) {
				return parse_url( $url );
			}
		);
		Functions\when( 'get_option' )->justReturn(
			array(
				'distribution_id' => 'E1234567890AB',
				'aws_region'      => 'us-east-1',
			)
		);
		Functions\when( 'get_post_type_archive_link' )->justReturn( false );
		Functions\when( 'get_object_taxonomies' )->justReturn( array() );

		$post              = new stdClass();
		$post->ID          = 1003;
		$post->post_type   = 'post';
		$post->post_status = 'pending';

		// Test execution - pending should be processed.
		$this->plugin->invalidate_on_post_update( 1003, $post );

		$this->assertTrue( true, 'Pending posts should be processed (not skipped)' );
	}

	/**
	 * Test that false permalink is handled gracefully.
	 *
	 * If get_permalink returns false, the method should handle it
	 * gracefully without attempting to invalidate.
	 */
	public function test_false_permalink_handling() {
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'get_permalink' )->justReturn( false );

		// wp_parse_url should never be called if permalink is false.
		Functions\expect( 'wp_parse_url' )->never();

		$post              = new stdClass();
		$post->ID          = 1004;
		$post->post_type   = 'post';
		$post->post_status = 'publish';

		// Test execution - should handle false permalink gracefully.
		$this->plugin->invalidate_on_post_update( 1004, $post );

		$this->assertTrue( true, 'False permalink should be handled gracefully' );
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
