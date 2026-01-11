<?php
/**
 * Test fixtures and data for unit tests.
 *
 * @package CloudFrontCacheInvalidator
 */

return array(
	'aws_credentials'          => array(
		'access_key' => 'AKIAIOSFODNN7EXAMPLE',
		'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
	),

	'distribution_ids'         => array(
		'valid'   => array(
			'E1234567890AB',
			'EABCDEFGHIJKL',
			'E1A2B3C4D5E6F',
			'EXAMPLEID1234',
		),
		'invalid' => array(
			'E123',                    // Too short.
			'E123456789012345',        // Too long.
			'E123-456-789',            // Special characters.
			'e1234567890ab',           // Lowercase (should be normalized).
			'',                        // Empty.
			'INVALID ID',              // Spaces.
		),
	),

	'aws_regions'              => array(
		'valid'   => array(
			'us-east-1',
			'us-east-2',
			'us-west-1',
			'us-west-2',
			'eu-west-1',
			'eu-west-2',
			'eu-west-3',
			'eu-central-1',
			'ap-southeast-1',
			'ap-southeast-2',
			'ap-northeast-1',
			'ap-northeast-2',
			'ap-south-1',
			'sa-east-1',
			'ca-central-1',
		),
		'invalid' => array(
			'invalid',
			'us_east_1',              // Underscores instead of hyphens.
			'US-EAST-1',              // Uppercase (should be normalized).
			'123-region',             // Invalid format.
			'',                       // Empty.
		),
	),

	'invalidation_paths'       => array(
		'valid'           => array(
			'/*',
			'/blog/*',
			'/images/logo.png',
			'/category/news/',
			'/wp-content/themes/theme-name/*',
			'/page/about/',
		),
		'invalid'         => array(
			'blog/*',                 // Missing leading slash.
			'images/logo.png',        // Missing leading slash.
			'',                       // Empty.
		),
		'with_newlines'   => "/*\n/blog/*\n/images/*",
		'with_whitespace' => "  /*  \n  /blog/*  \n  /images/*  ",
		'mixed'           => "/*\nblog/*\n/images/*\n\n/valid-path/",
	),

	'encryption_test_strings'  => array(
		'simple'             => 'test-string',
		'with_special_chars' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
		'empty'              => '',
		'unicode'            => 'Test with émojis 🔒 and üñïçödé',
		'long'               => str_repeat( 'a', 1000 ),
		'json'               => '{"key":"value","nested":{"foo":"bar"}}',
	),

	'malformed_encrypted_data' => array(
		'not_base64'      => 'not-valid-base64!!!',
		'invalid_format'  => base64_encode( 'invalid' ),
		'wrong_separator' => base64_encode( 'wrong' ) . '|' . base64_encode( 'separator' ),
		'missing_iv'      => base64_encode( 'data' ) . '::',
		'empty'           => '',
	),
);
