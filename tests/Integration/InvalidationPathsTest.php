<?php
/**
 * Tests for which paths content changes invalidate, and how they are batched.
 *
 * A small fake site (permalinks, reading settings, post types, taxonomies,
 * terms and comments) is wired up with Brain Monkey. The CloudFront client is
 * mocked and records every batch it is asked to send.
 *
 * @package CloudFrontCacheInvalidator
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class InvalidationPathsTest extends TestCase {

	const HOME = 'https://example.com';

	/**
	 * @var NotGlossy_CloudFront_Invalidation_Manager
	 */
	private $manager;

	/**
	 * @var NotGlossy_CloudFront_Settings_Manager
	 */
	private $settings_manager;

	/**
	 * Batches sent to the client.
	 *
	 * @var array<int,string[]>
	 */
	private $sent = array();

	/**
	 * Posts by ID.
	 *
	 * @var WP_Post[]
	 */
	private $posts = array();

	/**
	 * Terms by term ID.
	 *
	 * @var WP_Term[]
	 */
	private $terms = array();

	/**
	 * Term IDs attached to each post, by taxonomy.
	 *
	 * @var array<int,array<string,int[]>>
	 */
	private $post_terms = array();

	/**
	 * Comments by ID.
	 *
	 * @var WP_Comment[]
	 */
	private $comments = array();

	/**
	 * Options.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Permalink overrides by post ID (e.g. plain permalinks).
	 *
	 * @var array<int,string>
	 */
	private $permalink_overrides = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->sent                = array();
		$this->posts               = array();
		$this->terms               = array();
		$this->post_terms          = array();
		$this->comments            = array();
		$this->permalink_overrides = array();
		$this->options             = array(
			'show_on_front'  => 'posts',
			'page_on_front'  => 0,
			'page_for_posts' => 0,
		);
		unset( $GLOBALS['_wp_suspend_cache_invalidation'] );

		$this->stub_wordpress();

		$this->settings_manager = new NotGlossy_CloudFront_Settings_Manager();
		$this->settings_manager->set_settings( array( 'invalidation_paths' => '/*' ) );

		$client = $this->createMock( NotGlossy_CloudFront_Client::class );
		$client->method( 'send_invalidation_request' )->willReturnCallback(
			function ( $paths ) {
				$this->sent[] = $paths;
				return array( 'Status' => 'InProgress' );
			}
		);

		$this->manager = new NotGlossy_CloudFront_Invalidation_Manager( $this->settings_manager, $client );

		// Default content.
		$this->add_term( 10, 'category', 'news' );
		$this->add_term( 11, 'category', 'sport' );
		$this->add_term( 20, 'product_cat', 'shoes' );
		$this->add_term( 30, 'product_visibility', 'featured' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_wp_suspend_cache_invalidation'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/* ---------------------------------------------------------------
	 * Fake site
	 * ------------------------------------------------------------- */

	private function stub_wordpress(): void {
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'home_url' )->alias(
			function ( $path = '' ) {
				return self::HOME . '/' . ltrim( $path, '/' );
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'current_action' )->justReturn( 'switch_theme' );
		Functions\when( 'wp_is_post_revision' )->alias(
			function ( $post ) {
				return $post instanceof WP_Post && 'revision' === $post->post_type;
			}
		);
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'get_post' )->alias(
			function ( $id ) {
				return $this->posts[ (int) $id ] ?? null;
			}
		);
		Functions\when( 'is_post_type_viewable' )->alias(
			function ( $type ) {
				return in_array( $type, array( 'post', 'page', 'product' ), true );
			}
		);
		Functions\when( 'is_post_status_viewable' )->alias(
			function ( $status ) {
				return 'publish' === $status;
			}
		);
		Functions\when( 'get_permalink' )->alias( array( $this, 'fake_permalink' ) );
		Functions\when( 'get_feed_link' )->justReturn( self::HOME . '/feed/' );
		Functions\when( 'get_author_posts_url' )->justReturn( self::HOME . '/author/admin/' );
		Functions\when( 'get_post_type_archive_link' )->alias(
			function ( $type ) {
				return 'product' === $type ? self::HOME . '/shop/' : false;
			}
		);
		Functions\when( 'get_object_taxonomies' )->alias(
			function ( $type ) {
				$map = array(
					'post'    => array( 'category' ),
					'product' => array( 'product_cat', 'product_visibility' ),
				);
				return $map[ $type ] ?? array();
			}
		);
		Functions\when( 'taxonomy_exists' )->justReturn( true );
		Functions\when( 'is_taxonomy_viewable' )->alias(
			function ( $taxonomy ) {
				return in_array( $taxonomy, array( 'category', 'product_cat' ), true );
			}
		);
		Functions\when( 'get_the_terms' )->alias(
			function ( $post, $taxonomy ) {
				$ids = $this->post_terms[ $post->ID ][ $taxonomy ] ?? array();
				return $ids ? array_map(
					function ( $id ) {
						return $this->terms[ $id ];
					},
					$ids
				) : false;
			}
		);
		Functions\when( 'get_term' )->alias(
			function ( $id ) {
				return $this->terms[ (int) $id ] ?? null;
			}
		);
		Functions\when( 'get_term_by' )->alias(
			function ( $field, $value ) {
				foreach ( $this->terms as $term ) {
					if ( (int) $term->term_taxonomy_id === (int) $value ) {
						return $term;
					}
				}
				return false;
			}
		);
		Functions\when( 'get_term_link' )->alias(
			function ( $term ) {
				$base = array(
					'category'           => '/category/',
					'product_cat'        => '/product-category/',
					'product_visibility' => '/?product_visibility=',
				);
				return self::HOME . $base[ $term->taxonomy ] . $term->slug . ( 'product_visibility' === $term->taxonomy ? '' : '/' );
			}
		);
		Functions\when( 'get_comment' )->alias(
			function ( $id ) {
				return $this->comments[ (int) $id ] ?? null;
			}
		);
	}

	/**
	 * Permalink for a post object or ID, computed from the object's own fields.
	 *
	 * @param WP_Post|int $post Post.
	 * @return string|false
	 */
	public function fake_permalink( $post ) {
		if ( ! $post instanceof WP_Post ) {
			$post = $this->posts[ (int) $post ] ?? null;
		}
		if ( ! $post ) {
			return false;
		}
		if ( isset( $this->permalink_overrides[ $post->ID ] ) ) {
			return $this->permalink_overrides[ $post->ID ];
		}
		if ( 'publish' !== $post->post_status ) {
			return self::HOME . '/?p=' . $post->ID;
		}
		if ( 'page' === $post->post_type && 'page' === $this->options['show_on_front'] && (int) $this->options['page_on_front'] === $post->ID ) {
			return self::HOME . '/';
		}
		$prefix = 'product' === $post->post_type ? '/product/' : '/';
		return self::HOME . $prefix . $post->post_name . '/';
	}

	private function add_post( int $id, string $type, string $slug, string $status = 'publish', array $terms = array() ): WP_Post {
		$post = new WP_Post(
			array(
				'ID'          => $id,
				'post_type'   => $type,
				'post_status' => $status,
				'post_name'   => $slug,
			)
		);

		$this->posts[ $id ]      = $post;
		$this->post_terms[ $id ] = $terms;

		return $post;
	}

	private function add_term( int $id, string $taxonomy, string $slug ): WP_Term {
		$term = new WP_Term(
			array(
				'term_id'          => $id,
				'term_taxonomy_id' => $id,
				'taxonomy'         => $taxonomy,
				'slug'             => $slug,
			)
		);

		$this->terms[ $id ] = $term;
		return $term;
	}

	private function save( WP_Post $post, ?WP_Post $before = null ): void {
		$this->manager->on_post_saved( $post->ID, $post, null !== $before, $before );
	}

	private function assertBatch( array $expected, string $message = '' ): void {
		$this->assertCount( 1, $this->sent, 'Exactly one batch should be sent. ' . $message );
		$actual = $this->sent[0];
		sort( $expected );
		sort( $actual );
		$this->assertSame( $expected, $actual, $message );
	}

	private function assertNothingSent(): void {
		$this->assertNull( $this->manager->flush() );
		$this->assertSame( array(), $this->sent );
	}

	/* ---------------------------------------------------------------
	 * Posts
	 * ------------------------------------------------------------- */

	public function test_publishing_a_post_purges_its_url_and_listings_but_never_everything() {
		$this->save( $this->add_post( 5, 'post', 'hello-world', 'publish', array( 'category' => array( 10 ) ) ) );
		$this->manager->flush();

		$this->assertBatch(
			array(
				'/hello-world/',
				'/hello-world/*',
				'/',
				'/page/*',
				'/feed/',
				'/feed/*',
				'/author/admin/',
				'/author/admin/*',
				'/category/news/',
				'/category/news/*',
			)
		);
		$this->assertNotContains( '/*', $this->sent[0] );
	}

	/**
	 * @dataProvider non_public_statuses
	 */
	public function test_saving_non_public_posts_sends_nothing( string $status ) {
		$this->save( $this->add_post( 5, 'post', 'draft-post', $status, array( 'category' => array( 10 ) ) ) );

		$this->assertNothingSent();
	}

	public function non_public_statuses(): array {
		return array(
			'draft'   => array( 'draft' ),
			'pending' => array( 'pending' ),
			'private' => array( 'private' ),
			'future'  => array( 'future' ),
			'trash'   => array( 'trash' ),
		);
	}

	/**
	 * @dataProvider non_viewable_types
	 */
	public function test_non_viewable_post_types_send_nothing( string $type ) {
		$this->save( $this->add_post( 5, $type, 'entry' ) );

		$this->assertNothingSent();
	}

	public function non_viewable_types(): array {
		return array(
			'menu item'  => array( 'nav_menu_item' ),
			'block'      => array( 'wp_block' ),
			'form entry' => array( 'flamingo_inbound' ),
			'revision'   => array( 'revision' ),
		);
	}

	public function test_unpublishing_purges_the_old_url_and_listings() {
		$before = $this->add_post( 5, 'post', 'hello-world', 'publish', array( 'category' => array( 10 ) ) );
		$after  = clone $before;

		$after->post_status = 'draft';
		$this->posts[5]     = $after;

		$this->save( $after, $before );
		$this->manager->flush();

		$this->assertContains( '/hello-world/', $this->sent[0] );
		$this->assertContains( '/hello-world/*', $this->sent[0] );
		$this->assertContains( '/category/news/', $this->sent[0] );
		$this->assertContains( '/', $this->sent[0] );
		$this->assertNotContains( '/*', $this->sent[0] );
		$this->assertNotContains( '/?p=5', $this->sent[0] );
	}

	public function test_slug_change_purges_old_and_new_urls() {
		$before = $this->add_post( 7, 'page', 'sensitive-page' );
		$after  = clone $before;

		$after->post_name = 'renamed-page';
		$this->posts[7]   = $after;

		$this->save( $after, $before );
		$this->manager->flush();

		$this->assertBatch( array( '/renamed-page/', '/renamed-page/*', '/sensitive-page/', '/sensitive-page/*' ) );
	}

	public function test_front_page_edit_purges_root_without_wildcard() {
		$this->options['show_on_front'] = 'page';
		$this->options['page_on_front'] = 2;

		$this->save( $this->add_post( 2, 'page', 'home' ) );
		$this->manager->flush();

		$this->assertBatch( array( '/' ) );
	}

	public function test_static_front_page_sites_purge_the_posts_page() {
		$this->options['show_on_front']  = 'page';
		$this->options['page_on_front']  = 2;
		$this->options['page_for_posts'] = 3;
		$this->add_post( 3, 'page', 'blog' );

		$this->save( $this->add_post( 5, 'post', 'hello-world' ) );
		$this->manager->flush();

		$this->assertContains( '/blog/', $this->sent[0] );
		$this->assertContains( '/blog/*', $this->sent[0] );
		$this->assertNotContains( '/', $this->sent[0] );
	}

	public function test_custom_post_type_purges_archive_and_skips_private_taxonomies() {
		$this->save( $this->add_post( 8, 'product', 'runner', 'publish', array( 'product_cat' => array( 20 ), 'product_visibility' => array( 30 ) ) ) );
		$this->manager->flush();

		$this->assertBatch(
			array(
				'/product/runner/',
				'/product/runner/*',
				'/shop/',
				'/shop/*',
				'/product-category/shoes/',
				'/product-category/shoes/*',
			)
		);
	}

	public function test_plain_permalinks_purge_the_exact_query_url() {
		$this->permalink_overrides[5] = self::HOME . '/?p=5';

		$this->save( $this->add_post( 5, 'post', 'hello-world' ) );
		$this->manager->flush();

		$this->assertContains( '/?p=5', $this->sent[0] );
		$this->assertNotContains( '/*', $this->sent[0] );
	}

	public function test_permalinks_without_trailing_slash_use_anchored_wildcards() {
		$this->permalink_overrides[5] = self::HOME . '/hello';

		$this->save( $this->add_post( 5, 'post', 'hello' ) );
		$this->manager->flush();

		$this->assertContains( '/hello', $this->sent[0] );
		$this->assertContains( '/hello/*', $this->sent[0] );
		$this->assertNotContains( '/hello*', $this->sent[0] );
	}

	public function test_many_saves_in_one_request_send_one_batch() {
		for ( $i = 1; $i <= 10; $i++ ) {
			$this->save( $this->add_post( 100 + $i, 'page', 'page-' . $i ) );
		}
		$this->manager->flush();

		$this->assertCount( 1, $this->sent );
		$this->assertContains( '/page-1/', $this->sent[0] );
		$this->assertContains( '/page-10/', $this->sent[0] );
	}

	public function test_batches_over_the_wildcard_limit_collapse_to_a_full_purge() {
		for ( $i = 1; $i <= 16; $i++ ) {
			$this->save( $this->add_post( 100 + $i, 'page', 'page-' . $i ) );
		}
		$this->manager->flush();

		$this->assertBatch( array( '/*' ) );
	}

	public function test_removed_terms_are_purged() {
		$post = $this->add_post( 5, 'post', 'hello-world', 'publish', array( 'category' => array( 11 ) ) );

		// Category changed from news (10) to sport (11).
		$this->manager->on_object_terms_set( 5, array( 11 ), array( 11 ), 'category', false, array( 10 ) );
		$this->save( $post );
		$this->manager->flush();

		$this->assertContains( '/category/news/', $this->sent[0] );
		$this->assertContains( '/category/sport/', $this->sent[0] );
	}

	public function test_terms_on_drafts_or_private_taxonomies_are_ignored() {
		$this->add_post( 5, 'post', 'draft', 'draft' );
		$this->manager->on_object_terms_set( 5, array( 11 ), array( 11 ), 'category', false, array( 10 ) );

		$this->add_post( 8, 'product', 'runner' );
		$this->manager->on_object_terms_set( 8, array( 30 ), array( 30 ), 'product_visibility', false, array() );

		$this->assertNothingSent();
	}

	public function test_deleting_a_published_post_purges_it() {
		$this->manager->on_post_deleting( 7, $this->add_post( 7, 'page', 'about' ) );
		$this->manager->flush();

		$this->assertBatch( array( '/about/', '/about/*' ) );
	}

	public function test_deleting_trash_revisions_and_auto_drafts_sends_nothing() {
		$this->manager->on_post_deleting( 7, $this->add_post( 7, 'page', 'about', 'trash' ) );
		$this->manager->on_post_deleting( 8, $this->add_post( 8, 'revision', 'about-rev' ) );
		$this->manager->on_post_deleting( 9, $this->add_post( 9, 'post', '', 'auto-draft' ) );
		$this->manager->on_post_deleting( 10, $this->add_post( 10, 'nav_menu_item', 'item' ) );

		$this->assertNothingSent();
	}

	/* ---------------------------------------------------------------
	 * Terms
	 * ------------------------------------------------------------- */

	public function test_term_slug_change_purges_old_and_new_archive() {
		$this->manager->on_term_editing( 10, 'category' );
		$this->terms[10]->slug = 'headlines';
		$this->manager->invalidate_on_term_update( 10, 10, 'category' );
		$this->manager->flush();

		$this->assertBatch( array( '/category/news/', '/category/news/*', '/category/headlines/', '/category/headlines/*' ) );
	}

	public function test_term_deletion_purges_its_archive() {
		$this->manager->on_term_deleting( 11, 'category' );
		$this->manager->flush();

		$this->assertBatch( array( '/category/sport/', '/category/sport/*' ) );
	}

	public function test_private_taxonomy_terms_send_nothing() {
		$this->manager->on_term_editing( 30, 'product_visibility' );
		$this->manager->invalidate_on_term_update( 30, 30, 'product_visibility' );
		$this->manager->on_term_deleting( 30, 'product_visibility' );

		$this->assertNothingSent();
	}

	/* ---------------------------------------------------------------
	 * Comments
	 * ------------------------------------------------------------- */

	public function test_comment_changes_purge_the_post() {
		$this->add_post( 5, 'post', 'hello-world' );
		$this->comments[1] = new WP_Comment( array( 'comment_ID' => 1, 'comment_post_ID' => 5, 'comment_approved' => '1' ) );

		$this->manager->on_comment_status_transition( 'approved', 'unapproved', $this->comments[1] );
		$this->manager->flush();

		$this->assertBatch( array( '/hello-world/', '/hello-world/*' ) );
	}

	public function test_comment_removed_from_approved_purges_the_post() {
		$this->add_post( 5, 'post', 'hello-world' );
		$comment = new WP_Comment( array( 'comment_ID' => 1, 'comment_post_ID' => 5, 'comment_approved' => 'trash' ) );

		$this->manager->on_comment_status_transition( 'trash', 'approved', $comment );
		$this->manager->flush();

		$this->assertCount( 1, $this->sent );
	}

	public function test_approved_new_and_edited_comments_purge_the_post() {
		$this->add_post( 5, 'post', 'hello-world' );
		$this->comments[1] = new WP_Comment( array( 'comment_ID' => 1, 'comment_post_ID' => 5, 'comment_approved' => '1' ) );

		$this->manager->on_comment_posted( 1, 1 );
		$this->manager->on_comment_edited( 1 );
		$this->manager->flush();

		$this->assertBatch( array( '/hello-world/', '/hello-world/*' ) );
	}

	public function test_unapproved_comments_and_comments_on_drafts_send_nothing() {
		$this->add_post( 5, 'post', 'hello-world' );
		$this->add_post( 6, 'post', 'draft', 'draft' );
		$this->comments[1] = new WP_Comment( array( 'comment_ID' => 1, 'comment_post_ID' => 5, 'comment_approved' => '0' ) );
		$this->comments[2] = new WP_Comment( array( 'comment_ID' => 2, 'comment_post_ID' => 6, 'comment_approved' => '1' ) );

		$this->manager->on_comment_posted( 1, 0 );
		$this->manager->on_comment_posted( 1, 'spam' );
		$this->manager->on_comment_edited( 1 );
		$this->manager->on_comment_status_transition( 'spam', 'unapproved', $this->comments[1] );
		$this->manager->on_comment_edited( 2 );

		$this->assertNothingSent();
	}

	/* ---------------------------------------------------------------
	 * Site-wide changes, suspension and filters
	 * ------------------------------------------------------------- */

	public function test_site_wide_changes_send_the_default_paths_once() {
		$this->settings_manager->set_settings( array( 'invalidation_paths' => "/*\n/blog/*" ) );

		$this->manager->queue_default_paths();
		$this->manager->queue_default_paths();
		$this->save( $this->add_post( 7, 'page', 'about' ) );
		$this->manager->flush();

		$this->assertBatch( array( '/*' ), 'A full purge covers every other path' );
	}

	public function test_custom_default_paths_are_merged_with_content_paths() {
		$this->settings_manager->set_settings( array( 'invalidation_paths' => "/blog/*\n/images/*" ) );

		$this->manager->queue_default_paths();
		$this->save( $this->add_post( 7, 'page', 'about' ) );
		$this->manager->flush();

		$this->assertBatch( array( '/blog/*', '/images/*', '/about/', '/about/*' ) );
	}

	public function test_suspended_cache_invalidation_sends_nothing() {
		$GLOBALS['_wp_suspend_cache_invalidation'] = true;

		$this->save( $this->add_post( 7, 'page', 'about' ) );
		$this->manager->queue_default_paths();

		$this->assertNothingSent();
	}

	public function test_paths_filter_can_rewrite_or_cancel_the_batch() {
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value, ...$args ) {
				if ( 'notglossy_cloudfront_invalidation_paths' === $hook ) {
					TestCase::assertSame( array( 'post_saved' ), $args[0] );
					return array_map(
						function ( $path ) {
							return '/en' . $path;
						},
						$value
					);
				}
				return $value;
			}
		);

		$this->save( $this->add_post( 7, 'page', 'about' ) );
		$this->manager->flush();
		$this->assertBatch( array( '/en/about/', '/en/about/*' ) );

		Functions\when( 'apply_filters' )->justReturn( array() );
		$this->save( $this->add_post( 8, 'page', 'contact' ) );
		$this->assertNull( $this->manager->flush() );
		$this->assertCount( 1, $this->sent );
	}

	public function test_flush_with_nothing_queued_sends_nothing() {
		$this->assertNothingSent();
	}

	public function test_manual_invalidate_all_sends_default_paths_immediately() {
		$this->settings_manager->set_settings( array( 'invalidation_paths' => "/*\n/blog/*" ) );

		$this->manager->invalidate_all();

		$this->assertSame( array( array( '/*', '/blog/*' ) ), $this->sent );
	}

	/* ---------------------------------------------------------------
	 * Hook registration
	 * ------------------------------------------------------------- */

	public function test_hooks_are_registered() {
		$this->manager->register_hooks();

		$expected = array(
			'wp_after_insert_post'      => 'on_post_saved',
			'set_object_terms'          => 'on_object_terms_set',
			'before_delete_post'        => 'on_post_deleting',
			'transition_comment_status' => 'on_comment_status_transition',
			'comment_post'              => 'on_comment_posted',
			'edit_comment'              => 'on_comment_edited',
			'edit_terms'                => 'on_term_editing',
			'edited_term'               => 'invalidate_on_term_update',
			'pre_delete_term'           => 'on_term_deleting',
			'switch_theme'              => 'queue_default_paths',
			'customize_save_after'      => 'queue_default_paths',
			'wp_update_nav_menu'        => 'queue_default_paths',
			'shutdown'                  => 'flush',
		);

		foreach ( $expected as $hook => $method ) {
			$this->assertNotFalse( has_action( $hook, array( $this->manager, $method ) ), "$hook should call $method" );
		}

		$this->assertFalse( has_action( 'save_post', array( $this->manager, 'on_post_saved' ) ) );
		$this->assertFalse( has_action( 'deleted_post', array( $this->manager, 'invalidate_on_post_delete' ) ) );
	}
}
