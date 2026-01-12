<?php
/**
 * CloudFront Client for Cache Invalidation.
 *
 * Handles AWS CloudFront API integration, invalidation request creation,
 * and error handling.
 *
 * @since 1.2.0
 * @package CloudFrontCacheInvalidator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CloudFront Client class.
 *
 * @since 1.2.0
 */
class NotGlossy_CloudFront_Client {

	/**
	 * Settings manager instance.
	 *
	 * @since 1.2.0
	 * @access private
	 * @var NotGlossy_CloudFront_Settings_Manager
	 */
	private $settings_manager;

	/**
	 * Credential manager instance.
	 *
	 * @since 1.2.0
	 * @access private
	 * @var NotGlossy_CloudFront_Credential_Manager
	 */
	private $credential_manager;

	/**
	 * Path validator instance.
	 *
	 * @since 1.2.0
	 * @access private
	 * @var NotGlossy_CloudFront_Path_Validator
	 */
	private $path_validator;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 * @access public
	 * @param NotGlossy_CloudFront_Settings_Manager   $settings_manager   Settings manager instance.
	 * @param NotGlossy_CloudFront_Credential_Manager $credential_manager Credential manager instance.
	 * @param NotGlossy_CloudFront_Path_Validator     $path_validator     Path validator instance.
	 */
	public function __construct(
		NotGlossy_CloudFront_Settings_Manager $settings_manager,
		NotGlossy_CloudFront_Credential_Manager $credential_manager,
		NotGlossy_CloudFront_Path_Validator $path_validator
	) {
		$this->settings_manager   = $settings_manager;
		$this->credential_manager = $credential_manager;
		$this->path_validator     = $path_validator;
	}

	/**
	 * Send invalidation request to CloudFront.
	 *
	 * Creates and sends an invalidation request to the CloudFront API
	 * for the specified paths.
	 *
	 * @since 1.2.0
	 * @access public
	 * @param array $paths Array of paths to invalidate (e.g., ['/*', '/blog/*']).
	 * @return mixed WP_Error on failure, AWS result object on success.
	 */
	public function send_invalidation_request( $paths = array( '/*' ) ) {

		// Check if AWS SDK is available.
		if ( ! class_exists( 'Aws\CloudFront\CloudFrontClient' ) ) {
			// Try to load AWS SDK via manual path if autoloader didn't work.
			if ( ! file_exists( plugin_dir_path( __FILE__ ) . 'vendor/autoload.php' ) ) {
				return new WP_Error( 'sdk_missing', 'AWS SDK for PHP is not installed. Please run composer require aws/aws-sdk-php in the plugin directory.' );
			}
		}

		// Check if distribution ID is configured.
		$distribution_id = $this->credential_manager->get_distribution_id();
		if ( empty( $distribution_id ) ) {
			return new WP_Error( 'settings_missing', 'CloudFront Distribution ID not configured.' );
		}

		// Validate and sanitize paths before sending to AWS API.
		$validated_paths = $this->path_validator->sanitize_invalidation_paths_array( $paths );
		if ( is_wp_error( $validated_paths ) ) {
			return $validated_paths;
		}

		try {
			// Set up AWS CloudFront client config.
			$config = array(
				'Version' => 'latest',
				'region'  => $this->credential_manager->get_aws_region(),
			);

			// Use IAM role or keys based on settings.
			$use_iam_role = $this->credential_manager->is_using_iam_role();

			// Only add credentials if not using IAM role and credentials are resolved.
			if ( ! $use_iam_role ) {
				$creds = $this->credential_manager->resolve_credentials();
				if ( $creds && ! empty( $creds['key'] ) && ! empty( $creds['secret'] ) ) {
					$config['credentials'] = $creds;
				}
			}

			// Set up AWS CloudFront client.
			$client = new Aws\CloudFront\CloudFrontClient( $config );

			// Create a unique reference ID for this invalidation.
			$caller_reference = 'wp-' . time() . '-' . wp_generate_password( 6, false );

			// Send invalidation request.
			$result = $client->createInvalidation(
				array(
					'DistributionId'    => $distribution_id,
					'InvalidationBatch' => array(
						'CallerReference' => $caller_reference,
						'Paths'           => array(
							'Quantity' => count( $validated_paths ),
							'Items'    => $validated_paths,
						),
					),
				)
			);

			// Expose a hook so sites can optionally log or monitor invalidations without using error_log().
			do_action( 'notglossy_cloudfront_invalidation_sent', $validated_paths, $result );

			return $result;

		} catch ( Exception $e ) {
			do_action( 'notglossy_cloudfront_invalidation_error', $e );
			return new WP_Error( 'invalidation_failed', $e->getMessage() );
		}
	}
}
