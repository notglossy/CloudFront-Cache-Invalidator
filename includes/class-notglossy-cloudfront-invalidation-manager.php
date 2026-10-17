<?php
/**
 * Invalidation Manager for CloudFront Cache Invalidator.
 *
 * Works out which URLs a content change affects, collects them for the
 * duration of the request, and sends one deduplicated invalidation batch
 * when the request ends.
 *
 * @since 1.2.0
 * @package CloudFrontCacheInvalidator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Invalidation Manager class.
 *
 * Rules the path computation follows:
 *  - only viewable post types, viewable post statuses and viewable taxonomies
 *    produce paths, so drafts, private posts, revisions, menu items and form
 *    entries never trigger an invalidation;
 *  - a URL that resolves to the site root is purged as "/" (plus "/page/*"
 *    for listings), never as "/*";
 *  - wildcards are anchored on a path boundary ("/slug/*"), so they cannot
 *    match sibling URLs;
 *  - URLs that only exist as query strings (plain permalinks) are purged
 *    exactly, without a wildcard.
 *
 * @since 1.2.0
 */
class NotGlossy_CloudFront_Invalidation_Manager {

	/**
	 * CloudFront allows at most this many wildcard paths in progress at once.
	 *
	 * @since 1.3.0
	 */
	const MAX_WILDCARD_PATHS = 15;

	/**
	 * CloudFront allows at most this many paths per invalidation batch.
	 *
	 * @since 1.3.0
	 */
	const MAX_PATHS = 3000;

	/**
	 * Settings manager instance.
	 *
	 * @since 1.2.0
	 * @access private
	 * @var NotGlossy_CloudFront_Settings_Manager
	 */
	private $settings_manager;

	/**
	 * CloudFront client instance.
	 *
	 * @since 1.2.0
	 * @access private
	 * @var NotGlossy_CloudFront_Client
	 */
	private $cloudfront_client;

	/**
	 * Meta keys watched by default: WooCommerce price and stock.
	 *
	 * @since 1.3.0
	 * @var string[]
	 */
	const DEFAULT_META_KEYS = array(
		'_price',
		'_regular_price',
		'_sale_price',
		'_stock',
		'_stock_status',
		'_backorders',
	);

	/**
	 * Paths queued during this request, keyed by blog ID and then by path.
	 *
	 * Multisite: content saved under switch_to_blog() belongs to that site's
	 * distribution, so each site's paths are sent with its own settings.
	 *
	 * @since 1.3.0
	 * @access private
	 * @var array<int,array<string,true>>
	 */
	private $queue = array();

	/**
	 * Blog IDs whose configured default (site-wide) paths are queued.
	 *
	 * @since 1.3.0
	 * @access private
	 * @var array<int,true>
	 */
	private $queue_defaults = array();

	/**
	 * Why paths were queued, keyed by blog ID, for the paths filter.
	 *
	 * @since 1.3.0
	 * @access private
	 * @var array<int,string[]>
	 */
	private $reasons = array();

	/**
	 * Posts whose paths are already queued for a meta or stock change, keyed
	 * by blog ID and then post ID. Cleared when that blog's batch is sent.
	 *
	 * @since 1.3.0
	 * @access private
	 * @var array<int,array<int,true>>
	 */
	private $meta_queued_posts = array();

	/**
	 * Term archive paths captured before a term is edited, keyed by term ID.
	 *
	 * @since 1.3.0
	 * @access private
	 * @var array<int,string[]>
	 */
	private $pending_term_paths = array();

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 * @access public
	 * @param NotGlossy_CloudFront_Settings_Manager $settings_manager Settings manager instance.
	 * @param NotGlossy_CloudFront_Client           $cloudfront_client CloudFront client instance.
	 */
	public function __construct( NotGlossy_CloudFront_Settings_Manager $settings_manager, NotGlossy_CloudFront_Client $cloudfront_client ) {
		$this->settings_manager  = $settings_manager;
		$this->cloudfront_client = $cloudfront_client;
	}

	/**
	 * Register WordPress hooks for invalidation triggers.
	 *
	 * @since 1.2.0
	 * @access public
	 * @return void
	 */
	public function register_hooks() {
		// Posts. wp_after_insert_post runs after terms and meta are saved (including
		// REST / block editor saves) and passes the post as it was before the update.
		add_action( 'wp_after_insert_post', array( $this, 'on_post_saved' ), 10, 4 );
		add_action( 'set_object_terms', array( $this, 'on_object_terms_set' ), 10, 6 );
		add_action( 'before_delete_post', array( $this, 'on_post_deleting' ), 10, 2 );

		// Post meta (allowlisted keys only, see get_watched_meta_keys()).
		add_action( 'added_post_meta', array( $this, 'on_post_meta_changed' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'on_post_meta_changed' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'on_post_meta_changed' ), 10, 3 );

		// WooCommerce writes stock with direct SQL, so post meta hooks do not fire.
		add_action( 'woocommerce_product_set_stock', array( $this, 'on_product_changed' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'on_product_changed' ) );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'on_product_changed' ) );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'on_product_changed' ) );

		// Comments.
		add_action( 'transition_comment_status', array( $this, 'on_comment_status_transition' ), 10, 3 );
		add_action( 'comment_post', array( $this, 'on_comment_posted' ), 10, 2 );
		add_action( 'edit_comment', array( $this, 'on_comment_edited' ), 10, 1 );

		// Terms.
		add_action( 'edit_terms', array( $this, 'on_term_editing' ), 10, 2 );
		add_action( 'edited_term', array( $this, 'invalidate_on_term_update' ), 10, 3 );
		add_action( 'pre_delete_term', array( $this, 'on_term_deleting' ), 10, 2 );

		// Site-wide changes use the configured default paths.
		add_action( 'switch_theme', array( $this, 'queue_default_paths' ) );
		add_action( 'customize_save_after', array( $this, 'queue_default_paths' ) );
		add_action( 'update_option_permalink_structure', array( $this, 'queue_default_paths' ) );
		add_action( 'activated_plugin', array( $this, 'queue_default_paths' ) );
		add_action( 'deactivated_plugin', array( $this, 'queue_default_paths' ) );
		add_action( 'wp_update_nav_menu', array( $this, 'queue_default_paths' ) );
		add_action( 'update_option_sidebars_widgets', array( $this, 'queue_default_paths' ) );

		// Send everything collected during the request as one batch.
		add_action( 'shutdown', array( $this, 'flush' ) );
	}

	/* -------------------------------------------------------------
	 * Hook callbacks
	 * ----------------------------------------------------------- */

	/**
	 * Queue paths after a post is created, updated or trashed.
	 *
	 * Purges the post's current URL and listings when it is publicly viewable,
	 * and its previous URL when that differs (slug, parent or date change) or
	 * the post stopped being viewable (unpublished, made private, trashed).
	 *
	 * @since 1.3.0
	 * @access public
	 * @param int          $post_id     Post ID.
	 * @param WP_Post|null $post        Post object.
	 * @param bool         $update      Whether this is an update.
	 * @param WP_Post|null $post_before Post object before the update, or null for new posts.
	 * @return void
	 */
	public function on_post_saved( $post_id, $post = null, $update = false, $post_before = null ) {
		unset( $update );

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! $post instanceof WP_Post ) {
			$post = get_post( $post_id );
		}
		if ( ! $post instanceof WP_Post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		$paths = array();

		if ( $this->is_post_viewable( $post ) ) {
			$paths = $this->get_post_paths( $post );
		}

		if ( $post_before instanceof WP_Post && $this->is_post_viewable( $post_before ) ) {
			$old_permalink = get_permalink( $post_before );
			$new_permalink = $this->is_post_viewable( $post ) ? get_permalink( $post ) : '';

			if ( $old_permalink && $old_permalink !== $new_permalink ) {
				$paths = array_merge( $paths, $this->url_to_paths( $old_permalink, true ) );
			}
			if ( '' === $new_permalink ) {
				// No longer viewable: its listings must drop it too.
				$paths = array_merge( $paths, $this->get_post_listing_paths( $post_before ) );
			}
		}

		$this->queue_paths( $paths, 'post_saved' );
	}

	/**
	 * Queue archives of terms added to or removed from a viewable post.
	 *
	 * Removed terms are only visible here; wp_after_insert_post handles the
	 * terms the post has after saving.
	 *
	 * @since 1.3.0
	 * @access public
	 * @param int    $object_id  Object ID.
	 * @param array  $terms      Terms passed to wp_set_object_terms().
	 * @param array  $tt_ids     New term taxonomy IDs.
	 * @param string $taxonomy   Taxonomy slug.
	 * @param bool   $append     Whether terms were appended.
	 * @param array  $old_tt_ids Previous term taxonomy IDs.
	 * @return void
	 */
	public function on_object_terms_set( $object_id, $terms, $tt_ids, $taxonomy, $append = false, $old_tt_ids = array() ) {
		unset( $terms, $append );

		if ( ! $this->is_taxonomy_viewable( $taxonomy ) ) {
			return;
		}

		$post = get_post( $object_id );
		if ( ! $post instanceof WP_Post || ! $this->is_post_viewable( $post ) ) {
			return;
		}

		$tt_ids     = array_map( 'intval', (array) $tt_ids );
		$old_tt_ids = array_map( 'intval', (array) $old_tt_ids );
		$changed    = array_unique( array_merge( array_diff( $tt_ids, $old_tt_ids ), array_diff( $old_tt_ids, $tt_ids ) ) );

		$paths = array();
		foreach ( $changed as $tt_id ) {
			$term = get_term_by( 'term_taxonomy_id', $tt_id, $taxonomy );
			if ( $term ) {
				$paths = array_merge( $paths, $this->get_term_paths( $term ) );
			}
		}

		$this->queue_paths( $paths, 'post_terms_changed' );
	}

	/**
	 * Queue a viewable post's URL and listings before it is permanently deleted.
	 *
	 * Trashing is handled by on_post_saved(), so deleting from the trash does nothing.
	 *
	 * @since 1.3.0
	 * @access public
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post object.
	 * @return void
	 */
	public function on_post_deleting( $post_id, $post = null ) {
		if ( ! $post instanceof WP_Post ) {
			$post = get_post( $post_id );
		}
		if ( ! $post instanceof WP_Post || wp_is_post_revision( $post ) || ! $this->is_post_viewable( $post ) ) {
			return;
		}

		$this->queue_paths( $this->get_post_paths( $post ), 'post_deleted' );
	}

	/**
	 * Queue a viewable post's paths when a watched meta key changes.
	 *
	 * Only allowlisted keys count (WooCommerce price and stock by default),
	 * because plugins such as view counters write meta on every page view.
	 *
	 * @since 1.3.0
	 * @access public
	 * @param int|int[] $meta_ids  Meta ID(s).
	 * @param int       $object_id Post ID.
	 * @param string    $meta_key  Meta key.
	 * @return void
	 */
	public function on_post_meta_changed( $meta_ids, $object_id, $meta_key ) {
		unset( $meta_ids );

		if ( ! is_string( $meta_key ) || ! in_array( $meta_key, $this->get_watched_meta_keys(), true ) ) {
			return;
		}

		$this->queue_post_for_data_change( (int) $object_id, 'post_meta_changed' );
	}

	/**
	 * Queue a WooCommerce product's paths after a stock change.
	 *
	 * Variations purge their parent product.
	 *
	 * @since 1.3.0
	 * @access public
	 * @param mixed $product WC_Product object or product ID.
	 * @return void
	 */
	public function on_product_changed( $product ) {
		$product_id = 0;

		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			$product_id = (int) $product->get_id();
			if ( method_exists( $product, 'get_parent_id' ) && (int) $product->get_parent_id() ) {
				$product_id = (int) $product->get_parent_id();
			}
		} elseif ( is_numeric( $product ) ) {
			// Variation IDs are resolved to the parent in queue_post_for_data_change().
			$product_id = (int) $product;
		}

		if ( $product_id ) {
			$this->queue_post_for_data_change( $product_id, 'product_stock_changed' );
		}
	}

	/**
	 * Meta keys whose changes invalidate the post.
	 *
	 * @since 1.3.0
	 * @access public
	 * @return string[]
	 */
	public function get_watched_meta_keys() {
		/**
		 * Filter the post meta keys that invalidate a post when they change.
		 *
		 * Add the keys your theme renders (for example ACF fields). Avoid keys
		 * that change on every page view, such as view counters.
		 *
		 * @since 1.3.0
		 * @param string[] $meta_keys Meta keys. Default WooCommerce price and stock keys.
		 */
		$keys = apply_filters( 'notglossy_cloudfront_meta_keys', self::DEFAULT_META_KEYS );

		return is_array( $keys ) ? array_values( array_filter( $keys, 'is_string' ) ) : array();
	}

	/**
	 * Queue a post's paths once per request for data changes (meta, stock).
	 *
	 * @since 1.3.0
	 * @access private
	 * @param int    $post_id Post ID.
	 * @param string $reason  Reason.
	 * @return void
	 */
	private function queue_post_for_data_change( $post_id, $reason ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || wp_is_post_revision( $post ) ) {
			return;
		}

		// Variations are not viewable themselves; their parent product is.
		if ( 'product_variation' === $post->post_type && $post->post_parent ) {
			$post = get_post( (int) $post->post_parent );
			if ( ! $post instanceof WP_Post ) {
				return;
			}
		}

		$blog_id = $this->current_blog_id();
		if ( isset( $this->meta_queued_posts[ $blog_id ][ $post->ID ] ) || ! $this->is_post_viewable( $post ) ) {
			return;
		}

		$this->meta_queued_posts[ $blog_id ][ $post->ID ] = true;
		$this->queue_paths( $this->get_post_paths( $post ), $reason );
	}

	/**
	 * Queue the post page when a comment is approved or leaves the approved state.
	 *
	 * @since 1.3.0
	 * @access public
	 * @param string     $new_status New comment status.
	 * @param string     $old_status Old comment status.
	 * @param WP_Comment $comment    Comment object.
	 * @return void
	 */
	public function on_comment_status_transition( $new_status, $old_status, $comment ) {
		if ( 'approved' === $new_status || 'approved' === $old_status ) {
			$this->queue_comment_post( $comment );
		}
	}

	/**
	 * Queue the post page when an approved comment is posted.
	 *
	 * @since 1.3.0
	 * @access public
	 * @param int        $comment_id       Comment ID.
	 * @param int|string $comment_approved 1 if approved, 0 if not, 'spam' or 'trash'.
	 * @return void
	 */
	public function on_comment_posted( $comment_id, $comment_approved ) {
		if ( '1' === (string) $comment_approved ) {
			$this->queue_comment_post( get_comment( $comment_id ) );
		}
	}

	/**
	 * Queue the post page when an approved comment is edited.
	 *
	 * @since 1.3.0
	 * @access public
	 * @param int $comment_id Comment ID.
	 * @return void
	 */
	public function on_comment_edited( $comment_id ) {
		$comment = get_comment( $comment_id );
		if ( $comment && '1' === (string) $comment->comment_approved ) {
			$this->queue_comment_post( $comment );
		}
	}

	/**
	 * Capture a term's archive paths before it is edited, so a slug or parent
	 * change also purges the old URL.
	 *
	 * @since 1.3.0
	 * @access public
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public function on_term_editing( $term_id, $taxonomy ) {
		if ( ! $this->is_taxonomy_viewable( $taxonomy ) ) {
			return;
		}

		$term = get_term( $term_id, $taxonomy );
		if ( $term && ! is_wp_error( $term ) ) {
			$this->pending_term_paths[ (int) $term_id ] = $this->get_term_paths( $term );
		}
	}

	/**
	 * Queue a term's archive paths after it is edited (new and previous URL).
	 *
	 * @since 1.2.0
	 * @access public
	 * @param int    $term_id  The term ID.
	 * @param int    $tt_id    The term taxonomy ID.
	 * @param string $taxonomy The taxonomy slug.
	 * @return void
	 */
	public function invalidate_on_term_update( $term_id, $tt_id, $taxonomy ) {
		unset( $tt_id );

		$paths = array();
		if ( isset( $this->pending_term_paths[ (int) $term_id ] ) ) {
			$paths = $this->pending_term_paths[ (int) $term_id ];
			unset( $this->pending_term_paths[ (int) $term_id ] );
		}

		if ( $this->is_taxonomy_viewable( $taxonomy ) ) {
			$term = get_term( $term_id, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				$paths = array_merge( $paths, $this->get_term_paths( $term ) );
			}
		}

		$this->queue_paths( $paths, 'term_updated' );
	}

	/**
	 * Queue a term's archive paths before it is deleted.
	 *
	 * @since 1.3.0
	 * @access public
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public function on_term_deleting( $term_id, $taxonomy ) {
		if ( ! $this->is_taxonomy_viewable( $taxonomy ) ) {
			return;
		}

		$term = get_term( $term_id, $taxonomy );
		if ( $term && ! is_wp_error( $term ) ) {
			$this->queue_paths( $this->get_term_paths( $term ), 'term_deleted' );
		}
	}

	/**
	 * Queue the configured default paths (site-wide changes).
	 *
	 * @since 1.3.0
	 * @access public
	 * @return void
	 */
	public function queue_default_paths() {
		if ( $this->is_suspended() ) {
			return;
		}

		$blog_id                          = $this->current_blog_id();
		$this->queue_defaults[ $blog_id ] = true;
		$this->reasons[ $blog_id ][]      = current_action() ? current_action() : 'site_wide';
	}

	/* -------------------------------------------------------------
	 * Queue
	 * ----------------------------------------------------------- */

	/**
	 * Add paths to this request's batch.
	 *
	 * @since 1.3.0
	 * @access public
	 * @param string[] $paths  Paths to invalidate.
	 * @param string   $reason Why they are queued.
	 * @return void
	 */
	public function queue_paths( array $paths, $reason = 'manual' ) {
		if ( empty( $paths ) || $this->is_suspended() ) {
			return;
		}

		$blog_id = $this->current_blog_id();
		foreach ( $paths as $path ) {
			if ( is_string( $path ) && '' !== $path ) {
				$this->queue[ $blog_id ][ $path ] = true;
			}
		}
		$this->reasons[ $blog_id ][] = $reason;
	}

	/**
	 * Get the paths the next flush would send for a site, before filtering.
	 *
	 * Must be called while that site is the current blog, because the default
	 * paths come from its settings.
	 *
	 * @since 1.3.0
	 * @access public
	 * @param int|null $blog_id Blog ID. Defaults to the current blog.
	 * @return string[]
	 */
	public function get_queued_paths( $blog_id = null ) {
		$blog_id = null === $blog_id ? $this->current_blog_id() : (int) $blog_id;
		$paths   = isset( $this->queue[ $blog_id ] ) ? array_keys( $this->queue[ $blog_id ] ) : array();

		if ( ! empty( $this->queue_defaults[ $blog_id ] ) ) {
			$paths = array_merge( $this->get_default_paths(), $paths );
		}

		$paths = array_values( array_unique( $paths ) );

		// Everything is already covered by a full purge.
		if ( in_array( '/*', $paths, true ) ) {
			return array( '/*' );
		}

		return $paths;
	}

	/**
	 * Send the queued paths, one invalidation batch per site.
	 *
	 * Runs on shutdown. On multisite, each site's batch is sent while that
	 * site is switched in, so it uses that site's distribution and settings.
	 * Batches that exceed CloudFront's wildcard or path limits are collapsed
	 * to a full purge rather than being rejected.
	 *
	 * @since 1.3.0
	 * @access public
	 * @return mixed|null Result for the last batch sent (client result or WP_Error), or null when nothing was sent.
	 */
	public function flush() {
		$blog_ids = array_unique( array_merge( array_keys( $this->queue ), array_keys( $this->queue_defaults ) ) );
		$current  = $this->current_blog_id();
		$result   = null;

		// Send the current site's batch first; it needs no switch.
		usort(
			$blog_ids,
			function ( $a, $b ) use ( $current ) {
				return ( $b === $current ) <=> ( $a === $current );
			}
		);

		foreach ( $blog_ids as $blog_id ) {
			$switched = $blog_id !== $current && function_exists( 'switch_to_blog' );
			if ( $switched ) {
				switch_to_blog( $blog_id );
			}

			$batch_result = $this->flush_blog( $blog_id );
			if ( null !== $batch_result ) {
				$result = $batch_result;
			}

			if ( $switched ) {
				restore_current_blog();
			}
		}

		return $result;
	}

	/**
	 * Send one site's queued paths. The site must be the current blog.
	 *
	 * @since 1.3.0
	 * @access private
	 * @param int $blog_id Blog ID.
	 * @return mixed|null
	 */
	private function flush_blog( $blog_id ) {
		$paths   = $this->get_queued_paths( $blog_id );
		$reasons = isset( $this->reasons[ $blog_id ] ) ? array_values( array_unique( $this->reasons[ $blog_id ] ) ) : array();

		// Reset first so a listener that queues more paths cannot loop.
		unset( $this->queue[ $blog_id ], $this->queue_defaults[ $blog_id ], $this->reasons[ $blog_id ], $this->meta_queued_posts[ $blog_id ] );

		/**
		 * Filter the paths sent to CloudFront for this request.
		 *
		 * Return an empty array to skip the invalidation. Useful for mapping
		 * WordPress URLs to a different CloudFront path layout. On multisite
		 * this runs once per site, with that site switched in.
		 *
		 * @since 1.3.0
		 * @param string[] $paths   Paths to invalidate.
		 * @param string[] $reasons Why the paths were queued (e.g. post_saved, term_updated, switch_theme).
		 */
		$paths = apply_filters( 'notglossy_cloudfront_invalidation_paths', $paths, $reasons );

		if ( ! is_array( $paths ) || empty( $paths ) ) {
			return null;
		}

		$paths     = array_values( array_unique( array_filter( $paths, 'is_string' ) ) );
		$wildcards = count( array_filter( $paths, array( $this, 'is_wildcard_path' ) ) );

		if ( count( $paths ) > self::MAX_PATHS || $wildcards > self::MAX_WILDCARD_PATHS ) {
			$paths = array( '/*' );
		}

		return $this->cloudfront_client->send_invalidation_request( $paths );
	}

	/**
	 * Invalidate all cache using default paths, immediately.
	 *
	 * Used by the manual "Invalidate All" action, which needs the result.
	 *
	 * @since 1.2.0
	 * @access public
	 * @return mixed WP_Error on failure, AWS result object on success.
	 */
	public function invalidate_all() {
		return $this->cloudfront_client->send_invalidation_request( $this->get_default_paths() );
	}

	/* -------------------------------------------------------------
	 * Backward-compatible entry points (send immediately)
	 * ----------------------------------------------------------- */

	/**
	 * Invalidate a post's URLs immediately.
	 *
	 * @since 1.2.0
	 * @access public
	 * @param int          $post_id The ID of the post.
	 * @param WP_Post|null $post    The post object.
	 * @return mixed|null
	 */
	public function invalidate_on_post_update( $post_id, $post = null ) {
		$this->on_post_saved( $post_id, $post, true, null );
		return $this->flush();
	}

	/**
	 * Invalidate a post's URLs immediately before deletion.
	 *
	 * Called without a post, it keeps its pre-1.3.0 behaviour and sends the
	 * configured default paths.
	 *
	 * @since 1.2.0
	 * @access public
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post object.
	 * @return mixed|null
	 */
	public function invalidate_on_post_delete( $post_id = 0, $post = null ) {
		if ( ! $post_id && ! $post ) {
			return $this->invalidate_all();
		}

		$this->on_post_deleting( $post_id, $post );
		return $this->flush();
	}

	/* -------------------------------------------------------------
	 * Path computation
	 * ----------------------------------------------------------- */

	/**
	 * Get the configured default paths.
	 *
	 * @since 1.3.0
	 * @access private
	 * @return string[]
	 */
	private function get_default_paths() {
		$default_paths = $this->settings_manager->get_setting( 'invalidation_paths', '/*' );
		if ( ! is_string( $default_paths ) || '' === trim( $default_paths ) ) {
			$default_paths = '/*';
		}

		return array_values( array_filter( array_map( 'trim', explode( "\n", $default_paths ) ) ) );
	}

	/**
	 * Current blog ID (1 outside multisite).
	 *
	 * @since 1.3.0
	 * @access private
	 * @return int
	 */
	private function current_blog_id() {
		return NotGlossy_CloudFront_Settings_Manager::current_blog_id();
	}

	/**
	 * Whether automatic invalidation is suspended for this request.
	 *
	 * @since 1.3.0
	 * @access private
	 * @return bool
	 */
	private function is_suspended() {
		if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
			return true;
		}

		return ! empty( $GLOBALS['_wp_suspend_cache_invalidation'] );
	}

	/**
	 * Whether a post has a public URL.
	 *
	 * @since 1.3.0
	 * @access private
	 * @param WP_Post $post Post object.
	 * @return bool
	 */
	private function is_post_viewable( $post ) {
		return is_post_type_viewable( $post->post_type ) && is_post_status_viewable( $post->post_status );
	}

	/**
	 * Whether a taxonomy has public term archives.
	 *
	 * @since 1.3.0
	 * @access private
	 * @param string $taxonomy Taxonomy slug.
	 * @return bool
	 */
	private function is_taxonomy_viewable( $taxonomy ) {
		return taxonomy_exists( $taxonomy ) && is_taxonomy_viewable( $taxonomy );
	}

	/**
	 * Whether a path contains a wildcard.
	 *
	 * @since 1.3.0
	 * @access private
	 * @param string $path Path.
	 * @return bool
	 */
	private function is_wildcard_path( $path ) {
		return false !== strpos( $path, '*' );
	}

	/**
	 * Paths for a viewable post: its URL, sub-pages and the listings that show it.
	 *
	 * @since 1.3.0
	 * @access private
	 * @param WP_Post $post Post object.
	 * @return string[]
	 */
	private function get_post_paths( $post ) {
		$paths     = array();
		$permalink = get_permalink( $post );

		if ( $permalink ) {
			$paths = $this->url_to_paths( $permalink, true );
		}

		return array_merge( $paths, $this->get_post_listing_paths( $post ) );
	}

	/**
	 * Paths of listings that include a post: archives, blog home, feed,
	 * author archive and term archives.
	 *
	 * @since 1.3.0
	 * @access private
	 * @param WP_Post $post Post object.
	 * @return string[]
	 */
	private function get_post_listing_paths( $post ) {
		$paths = array();

		if ( 'post' === $post->post_type ) {
			$posts_page = (int) get_option( 'page_for_posts' );
			if ( 'page' === get_option( 'show_on_front' ) && $posts_page ) {
				$paths = array_merge( $paths, $this->listing_url_to_paths( get_permalink( $posts_page ) ) );
			} else {
				$paths = array_merge( $paths, $this->listing_url_to_paths( home_url( '/' ) ) );
			}

			$paths = array_merge( $paths, $this->url_to_paths( get_feed_link(), true ) );
			$paths = array_merge( $paths, $this->listing_url_to_paths( get_author_posts_url( (int) $post->post_author ) ) );
		} elseif ( 'page' !== $post->post_type ) {
			$archive = get_post_type_archive_link( $post->post_type );
			if ( $archive ) {
				$paths = array_merge( $paths, $this->listing_url_to_paths( $archive ) );
			}
		}

		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			if ( ! $this->is_taxonomy_viewable( $taxonomy ) ) {
				continue;
			}

			$terms = get_the_terms( $post, $taxonomy );
			if ( ! $terms || is_wp_error( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$paths = array_merge( $paths, $this->get_term_paths( $term ) );
			}
		}

		return $paths;
	}

	/**
	 * Paths for a term archive (the archive, its pages and its feed).
	 *
	 * @since 1.3.0
	 * @access private
	 * @param WP_Term $term Term object.
	 * @return string[]
	 */
	private function get_term_paths( $term ) {
		$link = get_term_link( $term );
		if ( is_wp_error( $link ) || ! $link ) {
			return array();
		}

		return $this->listing_url_to_paths( $link );
	}

	/**
	 * Paths for a listing URL, including its paginated pages.
	 *
	 * @since 1.3.0
	 * @access private
	 * @param string|false $url Listing URL.
	 * @return string[]
	 */
	private function listing_url_to_paths( $url ) {
		$paths = $this->url_to_paths( $url, true );

		// The site root never gets a wildcard, so add its pagination explicitly.
		if ( array( '/' ) === $paths ) {
			$paths[] = '/page/*';
		}

		return $paths;
	}

	/**
	 * Convert a URL into CloudFront invalidation paths.
	 *
	 * - "/" (site root): exactly "/", never "/*".
	 * - URL with a query string (plain permalinks): the exact path and query.
	 * - Anything else: the exact path, plus "<path>/*" when $with_children.
	 *
	 * @since 1.3.0
	 * @access private
	 * @param string|false $url           URL.
	 * @param bool         $with_children Also purge everything below the path.
	 * @return string[]
	 */
	private function url_to_paths( $url, $with_children ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return array();
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return array();
		}

		$path = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		if ( '/' !== $path[0] ) {
			$path = '/' . $path;
		}

		if ( ! empty( $parts['query'] ) ) {
			return array( $path . '?' . $parts['query'] );
		}

		if ( '/' === $path ) {
			return array( '/' );
		}

		$paths = array( $path );
		if ( $with_children ) {
			$paths[] = rtrim( $path, '/' ) . '/*';
		}

		return $paths;
	}

	/**
	 * Queue the page of the post a comment belongs to.
	 *
	 * @since 1.3.0
	 * @access private
	 * @param WP_Comment|null $comment Comment.
	 * @return void
	 */
	private function queue_comment_post( $comment ) {
		if ( ! $comment || empty( $comment->comment_post_ID ) ) {
			return;
		}

		$post = get_post( (int) $comment->comment_post_ID );
		if ( ! $post instanceof WP_Post || ! $this->is_post_viewable( $post ) ) {
			return;
		}

		$permalink = get_permalink( $post );
		if ( $permalink ) {
			$this->queue_paths( $this->url_to_paths( $permalink, true ), 'comment_changed' );
		}
	}
}
