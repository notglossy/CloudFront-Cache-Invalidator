<?php
/**
 * Invalidation Manager for CloudFront Cache Invalidator.
 *
 * Handles WordPress hook registration and invalidation logic for posts,
 * terms, and site-wide events.
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
 * @since 1.2.0
 */
class NotGlossy_CloudFront_Invalidation_Manager {

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
		// Content updates.
		add_action( 'save_post', array( $this, 'invalidate_on_post_update' ), 10, 3 );
		add_action( 'deleted_post', array( $this, 'invalidate_on_post_delete' ) );

		// Theme and customizer changes.
		add_action( 'switch_theme', array( $this, 'invalidate_all' ) );
		add_action( 'customize_save_after', array( $this, 'invalidate_all' ) );
		add_action( 'update_option_permalink_structure', array( $this, 'invalidate_all' ) );

		// Plugin activation/deactivation can affect URLs.
		add_action( 'activated_plugin', array( $this, 'invalidate_all' ) );
		add_action( 'deactivated_plugin', array( $this, 'invalidate_all' ) );

		// Menus and widgets.
		add_action( 'wp_update_nav_menu', array( $this, 'invalidate_all' ) );
		add_action( 'update_option_sidebars_widgets', array( $this, 'invalidate_all' ) );

		// Terms.
		add_action( 'edited_term', array( $this, 'invalidate_on_term_update' ), 10, 3 );
	}

	/**
	 * Invalidate cache when a post is updated.
	 *
	 * @since 1.2.0
	 * @access public
	 * @param int     $post_id The ID of the post being saved.
	 * @param WP_Post $post    The post object.
	 * @return void
	 */
	public function invalidate_on_post_update( $post_id, $post ) {
		// Skip if this is an autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Skip for revisions.
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Skip for auto drafts.
		if ( 'auto-draft' === $post->post_status ) {
			return;
		}

		$permalink = get_permalink( $post_id );
		if ( ! $permalink ) {
			return;
		}

		$url_parts = wp_parse_url( $permalink );
		$path      = isset( $url_parts['path'] ) ? $url_parts['path'] : '/';

		$paths = array( $path, $path . '*' );

		// If it's a front page or posts page, also invalidate root.
		if ( 'page' === $post->post_type && ( get_option( 'page_on_front' ) === $post_id || get_option( 'page_for_posts' ) === $post_id ) ) {
			$paths[] = '/';
			$paths[] = '/*';
		}

		// Archive URLs for non-page post types.
		if ( 'page' !== $post->post_type ) {
			$archive_url = get_post_type_archive_link( $post->post_type );
			if ( $archive_url ) {
				$archive_parts = wp_parse_url( $archive_url );
				$archive_path  = isset( $archive_parts['path'] ) ? $archive_parts['path'] : '/';
				$paths[]       = $archive_path;
				$paths[]       = $archive_path . '*';
			}

			// Term archives.
			$taxonomies = get_object_taxonomies( $post->post_type );
			foreach ( $taxonomies as $taxonomy ) {
				$terms = get_the_terms( $post_id, $taxonomy );
				if ( $terms && ! is_wp_error( $terms ) ) {
					foreach ( $terms as $term ) {
						$term_link = get_term_link( $term );
						if ( ! is_wp_error( $term_link ) ) {
							$term_parts = wp_parse_url( $term_link );
							$term_path  = isset( $term_parts['path'] ) ? $term_parts['path'] : '/';
							$paths[]    = $term_path;
							$paths[]    = $term_path . '*';
						}
					}
				}
			}
		}

		$this->cloudfront_client->send_invalidation_request( array_unique( $paths ) );
	}

	/**
	 * Invalidate cache when a post is deleted.
	 *
	 * @since 1.2.0
	 * @access public
	 * @return void
	 */
	public function invalidate_on_post_delete() {
		// Invalidate broadly since specific URL is removed.
		$this->invalidate_all();
	}

	/**
	 * Invalidate cache when a term is updated.
	 *
	 * @since 1.2.0
	 * @access public
	 * @param int    $term_id  The term ID.
	 * @param int    $tt_id    The term taxonomy ID.
	 * @param string $taxonomy The taxonomy slug.
	 * @return void
	 */
	public function invalidate_on_term_update( $term_id, $tt_id, $taxonomy ) {
		$term_link = get_term_link( $term_id, $taxonomy );
		if ( is_wp_error( $term_link ) ) {
			return;
		}

		$url_parts = wp_parse_url( $term_link );
		$path      = isset( $url_parts['path'] ) ? $url_parts['path'] : '/';

		$paths = array( $path, $path . '*' );

		$this->cloudfront_client->send_invalidation_request( $paths );
	}

	/**
	 * Invalidate all cache using default paths.
	 *
	 * @since 1.2.0
	 * @access public
	 * @return mixed WP_Error on failure, AWS result object on success.
	 */
	public function invalidate_all() {
		$default_paths = $this->settings_manager->get_setting( 'invalidation_paths', '/*' );
		$paths         = array_map( 'trim', explode( "\n", $default_paths ) );

		return $this->cloudfront_client->send_invalidation_request( $paths );
	}
}
