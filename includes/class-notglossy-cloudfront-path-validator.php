<?php
/**
 * Path Validator for CloudFront Cache Invalidator.
 *
 * Validates and sanitizes CloudFront invalidation paths according to
 * AWS requirements (leading slash, max 3000 paths, duplicates removed).
 *
 * @since 1.2.0
 * @package CloudFrontCacheInvalidator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Path Validator class.
 *
 * @since 1.2.0
 */
class NotGlossy_CloudFront_Path_Validator {

	/**
	 * Validate and sanitize an array of invalidation paths for CloudFront API.
	 *
	 * Ensures paths meet CloudFront requirements:
	 * - Must start with /
	 * - Limited to 3000 paths per invalidation (AWS limit)
	 * - Removes empty or invalid paths
	 *
	 * @since 1.2.0
	 * @access public
	 * @param array $paths Array of paths to validate.
	 * @return array|WP_Error Validated paths array or WP_Error if validation fails.
	 */
	public function sanitize_invalidation_paths_array( $paths ) {
		if ( ! is_array( $paths ) || empty( $paths ) ) {
			return new WP_Error( 'invalid_paths', 'Invalidation paths must be a non-empty array.' );
		}

		$validated_paths = array();

		foreach ( $paths as $path ) {
			// Ensure path is a string.
			if ( ! is_string( $path ) ) {
				continue;
			}

			// Trim whitespace.
			$path = trim( $path );

			// Skip empty paths.
			if ( '' === $path ) {
				continue;
			}

			// CloudFront requires paths to start with /.
			if ( '/' !== substr( $path, 0, 1 ) ) {
				$path = '/' . $path;
			}

			// Add to validated list.
			$validated_paths[] = $path;
		}

		// Check if we have any valid paths after validation.
		if ( empty( $validated_paths ) ) {
			return new WP_Error( 'no_valid_paths', 'No valid invalidation paths provided.' );
		}

		// Remove duplicates.
		$validated_paths = array_unique( $validated_paths );

		// AWS CloudFront limit is 3000 paths per invalidation request.
		if ( count( $validated_paths ) > 3000 ) {
			return new WP_Error(
				'too_many_paths',
				sprintf(
					'CloudFront allows a maximum of 3000 paths per invalidation request. You provided %d paths.',
					count( $validated_paths )
				)
			);
		}

		return $validated_paths;
	}
}
