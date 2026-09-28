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

		// Set up AWS CloudFront client config.
		$config = array(
			'version' => 'latest',
			'region'  => $this->credential_manager->get_aws_region(),
			'http'    => array(
				'connect_timeout' => 5,
				'timeout'         => 15,
			),
		);

		// Use IAM role or keys based on settings.
		$use_iam_role = $this->credential_manager->is_using_iam_role();

		if ( ! $use_iam_role ) {
			// Access-key mode: never fall through to the SDK's default credential
			// chain (environment, ~/.aws, instance metadata). Refuse instead.
			$creds = $this->credential_manager->resolve_credentials();
			if ( ! $creds || empty( $creds['key'] ) || empty( $creds['secret'] ) ) {
				$error = new WP_Error(
					'credentials_missing',
					__( 'CloudFront invalidation skipped: no usable AWS access keys are configured. Enter the keys on the CloudFront Cache settings page or enable "Use IAM Role".', 'cloudfront-cache-invalidator' )
				);
				do_action( 'notglossy_cloudfront_invalidation_error', new \RuntimeException( $error->get_error_message() ) );
				return $error;
			}

			// A provider object keeps the secret out of exception stack traces.
			$access_key_credentials = new Aws\Credentials\Credentials( $creds['key'], $creds['secret'] );
			$config['credentials']  = $access_key_credentials;
			unset( $creds );
		}

		/**
		 * Filter the AWS SDK client configuration before the CloudFront client is created.
		 *
		 * @since 1.2.1
		 * @param array $config Client configuration (region, http timeouts, credentials, ...).
		 */
		$config = apply_filters( 'notglossy_cloudfront_client_config', $config );
		if ( ! is_array( $config ) ) {
			$config = array();
		}

		// In access-key mode a filter may replace the credentials, but removing them
		// would re-enable the SDK's ambient credential chain, so restore them.
		if ( ! $use_iam_role && empty( $config['credentials'] ) ) {
			$config['credentials'] = $access_key_credentials;
		}

		try {
			// Set up AWS CloudFront client.
			$client = new Aws\CloudFront\CloudFrontClient( $config );
		} catch ( \Throwable $e ) {
			// Client construction failures carry the config (and credentials) in their
			// trace arguments, so hand listeners a plain exception with the message only.
			do_action( 'notglossy_cloudfront_invalidation_error', new \RuntimeException( $e->getMessage(), (int) $e->getCode() ) );
			return new WP_Error( 'client_config_failed', $e->getMessage() );
		}

		try {
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

		} catch ( \Throwable $e ) {
			do_action( 'notglossy_cloudfront_invalidation_error', $e );
			return new WP_Error( 'invalidation_failed', $e->getMessage() );
		}
	}
}
