# CloudFront Cache Invalidator

[![CI](https://github.com/notglossy/CloudFront-Cache-Invalidator/workflows/CI/badge.svg)](https://github.com/notglossy/CloudFront-Cache-Invalidator/actions)
[![codecov](https://codecov.io/gh/notglossy/CloudFront-Cache-Invalidator/branch/main/graph/badge.svg)](https://codecov.io/gh/notglossy/CloudFront-Cache-Invalidator)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-8892BF.svg)](https://php.net)

A WordPress plugin that automatically invalidates Amazon CloudFront cache when content is updated on your website.

## Description

CloudFront Cache Invalidator helps WordPress site owners who use Amazon CloudFront as their CDN to efficiently manage their cache. When you update content on your WordPress site, this plugin automatically triggers CloudFront invalidation requests for the relevant URLs, ensuring your visitors always see the most up-to-date content.

### Key Features

- **Automatic Invalidation**: Triggers cache invalidation automatically when posts, pages, or custom post types are updated
- **Smart Path Detection**: Intelligently determines which paths need to be invalidated based on content changes
- **IAM Role Support**: Secure authentication using AWS IAM roles (recommended for EC2 instances)
- **Access Key Support**: Traditional authentication using AWS access keys and secret keys with encryption
- **Manual Invalidation**: One-click button to manually invalidate entire cache
- **Customizable Paths**: Configure default invalidation paths for site-wide changes
- **Taxonomy Support**: Invalidates relevant paths when categories, tags, or custom taxonomies are modified
- **Security-First**: AWS credentials are encrypted with libsodium authenticated encryption (XSalsa20-Poly1305) before storage
- **Input Validation**: Comprehensive validation for AWS regions, distribution IDs, and invalidation paths
- **Error Handling**: Robust error handling with user-friendly messages and logging hooks

## Requirements

- WordPress 5.7 or higher
- PHP 8.1 or higher
- AWS SDK for PHP (installed via Composer)
- If using access keys: AWS account with CloudFront access
- If using IAM roles: WordPress site hosted on AWS infrastructure with an appropriate IAM role

## Installation

### Method 1: WordPress Plugin Repository (Recommended)

1. Log in to your WordPress admin dashboard
2. Navigate to **Plugins → Add New**
3. Search for "CloudFront Cache Invalidator"
4. Click **Install Now** and then **Activate**

### Method 2: Manual Installation

1. Download the plugin zip file from the [releases page](https://github.com/notglossy/CloudFront-Cache-Invalidator/releases)
2. In WordPress admin, go to **Plugins → Add New → Upload Plugin**
3. Choose the downloaded zip file and click **Install Now**
4. Activate the plugin

### Method 3: Git Installation

1. Clone the repository to your `/wp-content/plugins/` directory:
   ```bash
   cd /path/to/wordpress/wp-content/plugins/
   git clone https://github.com/notglossy/CloudFront-Cache-Invalidator.git cloudfront-cache-invalidator
   ```
2. Install dependencies:
   ```bash
   cd cloudfront-cache-invalidator
   composer install
   composer require aws/aws-sdk-php
   ```
3. Activate the plugin through the WordPress admin dashboard

### Post-Installation Setup

1. Go to **Settings → CloudFront Cache** to configure the plugin
2. Follow the configuration instructions below

## Configuration

### Using IAM Roles (Recommended for AWS-hosted sites)

IAM roles provide the most secure method of authentication as credentials are never stored in your database.

1. Create an IAM role with the following permissions:
   ```json
   {
     "Version": "2012-10-17",
     "Statement": [
       {
         "Effect": "Allow",
         "Action": [
           "cloudfront:CreateInvalidation",
           "cloudfront:GetInvalidation",
           "cloudfront:ListInvalidations"
         ],
         "Resource": "arn:aws:cloudfront::*:distribution/*"
       }
     ]
   }
   ```
2. Attach this role to your EC2 instance, ECS task, or other AWS service running WordPress
3. In the plugin settings:
   - Check "Use IAM Role"
   - Enter your CloudFront Distribution ID
   - Configure AWS Region (default is us-east-1)
   - Enter default invalidation paths if you want to customize them
   - Save the settings

### Using AWS Access Keys

If your WordPress site is not hosted on AWS, you can use traditional access keys.

1. Create an IAM user with the following permissions:
   ```json
   {
     "Version": "2012-10-17",
     "Statement": [
       {
         "Effect": "Allow",
         "Action": [
           "cloudfront:CreateInvalidation",
           "cloudfront:GetInvalidation",
           "cloudfront:ListInvalidations"
         ],
         "Resource": "arn:aws:cloudfront::*:distribution/*"
       }
     ]
   }
   ```
2. Generate access key and secret key for this user
3. In the plugin settings:
   - Uncheck "Use IAM Role" (if it's checked)
   - Enter your AWS Access Key
   - Enter your AWS Secret Key
   - Enter your CloudFront Distribution ID
   - Configure AWS Region (default is us-east-1)
   - Enter default invalidation paths if you want to customize them
   - Save the settings

   Access-key mode only ever signs requests with the keys you configured. If no usable key pair is available (none stored, or the stored pair can no longer be decrypted), the plugin skips invalidations, returns a `credentials_missing` error to the logging hooks and shows an admin notice. It never falls back to credentials that happen to exist on the server.

#### Rotating or removing access keys

- **Rotate**: enter both the new Access Key and the new Secret Key and save. Leaving both fields blank keeps the stored pair. Submitting only one of the two is rejected and the stored pair is left unchanged.
- **Remove**: tick "Remove the stored access keys when saving" under *Stored Credentials* and save.
- **Constants / environment variables**: `CLOUDFRONT_AWS_ACCESS_KEY` and `CLOUDFRONT_AWS_SECRET_KEY` take precedence over stored keys.

### Security Features

- **Credential Encryption**: AWS access keys and secret keys are encrypted with libsodium authenticated encryption (`sodium_crypto_secretbox`, XSalsa20-Poly1305) before being stored in the database. The key is derived with HKDF from `AUTH_KEY` and `SECURE_AUTH_KEY`, so changing those salts makes stored keys undecryptable; the settings page reports this and asks you to re-enter them.
- **Legacy Payload Migration**: Credentials stored by v1.1.0 / v1.2.0 (AES-256-CBC) are decrypted with the original key derivation and re-encrypted automatically on the next request.
- **HTTPS Requirement**: Credentials cannot be saved over HTTP connections
- **Migration Support**: Automatically migrates legacy plaintext credentials to encrypted storage
- **Environment Variable Support**: Supports loading credentials from constants or environment variables

### Pinning settings in wp-config.php

These constants override the settings page, which then shows the field as read-only:

```php
define( 'CLOUDFRONT_DISTRIBUTION_ID', 'E1ABCDEFGHIJKL' );
define( 'CLOUDFRONT_AWS_REGION', 'us-east-1' );
define( 'CLOUDFRONT_USE_IAM_ROLE', true ); // or false to force access-key mode
```

### Multisite

- **Who can configure the plugin.** On a multisite network, the settings page, saving settings and the manual "Invalidate All" button require `manage_network_options` (Super Admins). Sub-site administrators cannot change the distribution ID, region, IAM mode or keys. AWS credentials from constants, environment variables or an instance role are shared by every site, so letting a sub-site admin set the distribution ID would let them invalidate any distribution those credentials can reach. Use the `notglossy_cloudfront_settings_capability` filter to change the capability.
- **Per-site settings.** Each site keeps its own settings. Content changed on another site during a request, for example under `switch_to_blog()`, is sent to that site's distribution with that site's settings.
- **Recommended.** Scope the IAM policy to the distributions the network actually uses, rather than `distribution/*`.

## Usage

### Automatic Invalidation

Once configured, the plugin will automatically trigger cache invalidations when:

- Published posts, pages, or public custom post types are updated, unpublished, trashed, or deleted
- A published post's slug, parent, date or terms change (the old URL is purged too)
- Comments on a published post are approved, edited, unapproved, spammed, trashed or deleted
- Public categories, tags, or custom taxonomy terms are updated, renamed or deleted
- Watched post meta changes on a published post (WooCommerce price and stock by default, see below)
- WooCommerce stock quantity or stock status changes, including stock reduced by an order
- The theme is changed
- Permalink structure is updated
- Plugins are activated or deactivated
- Navigation menus are updated
- Widgets are updated

### Smart Path Detection

The plugin intelligently determines which paths to invalidate:

- **Post Updates**: Invalidates the post URL (`/slug/` and `/slug/*`), the blog home or posts page, the feed, the author archive, the post type archive and the post's public term archives
- **Page Updates**: Invalidates the page URL; the static front page purges `/` only
- **Term Updates**: Invalidates the term archive and its pages, including the previous URL after a slug or parent change
- **Site-wide Changes**: Uses default invalidation paths configured in settings

Content changes never purge the whole distribution. Drafts, pending, private and scheduled posts, revisions, auto-drafts, menu items, reusable blocks, form entries and other non-public post types and taxonomies are ignored. The site root is purged as `/` plus `/page/*`, never `/*`, and wildcards are anchored on a path boundary (`/slug/*`). Sites using plain permalinks get the exact `/?p=123` URL.

### Batching

All paths collected during a request (for example a bulk edit) are sent as **one** invalidation when the request ends. A batch that would exceed CloudFront's limits (more than 15 wildcard paths or 3,000 paths) is sent as a single `/*` instead of being rejected.

Automatic invalidation is skipped while `WP_IMPORTING` is set or `wp_suspend_cache_invalidation()` is active.

### Post meta changes

Changes that only touch post meta invalidate the post when the meta key is on an allowlist. By default the list holds the WooCommerce price and stock keys: `_price`, `_regular_price`, `_sale_price`, `_stock`, `_stock_status` and `_backorders`. The plugin uses an allowlist rather than reacting to every meta change, because many plugins write meta on each page view, such as view counters and oEmbed caches. Reacting to those would send a billed invalidation for every visit.

Add the keys your theme renders with the `notglossy_cloudfront_meta_keys` filter:

```php
add_filter( 'notglossy_cloudfront_meta_keys', function ( $keys ) {
    $keys[] = 'subtitle';
    return $keys;
} );
```

### Manual Invalidation

You can manually trigger a cache invalidation by:

1. Go to **Settings → CloudFront Cache**
2. Scroll to the "Manual Invalidation" section
3. Click the "Invalidate All CloudFront Cache" button

### Default Invalidation Paths

For site-wide changes, the plugin will use the default invalidation paths configured in the settings. The default is `/*` which invalidates the entire cache.

You can customize these paths by entering one path per line in the settings. For example:

```
/*
/wp-content/uploads/*
/wp-content/themes/*
/blog/*
```

### Monitoring Invalidations

You can view the status of your invalidation requests in the AWS CloudFront console:

1. Log in to the AWS Management Console
2. Navigate to CloudFront
3. Select your distribution
4. Click the "Invalidations" tab

## Troubleshooting

### Common Issues

**AWS SDK Not Found**
- Ensure you've run `composer require aws/aws-sdk-php` in the plugin directory
- Check that the vendor directory exists and contains the AWS SDK
- Verify the autoload.php file is present in the vendor directory

**Access Denied Errors**
- Verify that your IAM role or IAM user has the correct permissions
- If using access keys, ensure they are entered correctly
- Check that the distribution ID is correct (13-14 uppercase alphanumeric characters)
- Ensure the CloudFront distribution exists and is enabled

**Invalidation Not Working**
- Check your WordPress error logs for any AWS API errors
- Verify the CloudFront distribution is properly configured
- Ensure the paths being invalidated match your URL structure
- Check that invalidation paths start with `/` (CloudFront requirement)

**HTTPS Warning**
- If you see a warning about HTTPS, ensure your WordPress site is using HTTPS
- AWS credentials cannot be saved over HTTP connections for security

**Validation Errors**
- AWS Region must follow format: `xx-xxxx-#` or `xxx-xxxx-#` (e.g., us-east-1, eu-west-2)
- Distribution ID must be 13-14 uppercase alphanumeric characters
- Invalidation paths must start with `/`

### Debug Mode

Enable WordPress debug mode to see detailed error messages:

```php
// In wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Check the debug log at `/wp-content/debug.log` for detailed error information.

### Logging and Monitoring

The plugin provides hooks for logging and monitoring:

- `notglossy_cloudfront_invalidation_sent`: Fired when an invalidation request is successfully sent
- `notglossy_cloudfront_invalidation_error`: Fired when an invalidation request fails
- `notglossy_cloudfront_invalidation_paths` (filter): Receives the paths for the request's batch and the reasons they were queued (`post_saved`, `term_updated`, `comment_changed`, `switch_theme`, ...). Return a modified array to remap paths, or an empty array to skip the invalidation.
- `notglossy_cloudfront_client_config` (filter): Adjust the AWS SDK client configuration
- `notglossy_cloudfront_meta_keys` (filter): Post meta keys that invalidate a post when they change
- `notglossy_cloudfront_settings_capability` (filter): Capability required to manage the plugin

You can use these hooks to implement custom logging or monitoring solutions.

```php
// Example: the distribution serves the site under /en/.
add_filter( 'notglossy_cloudfront_invalidation_paths', function ( $paths ) {
    return array_map( fn( $path ) => '/en' . $path, $paths );
} );
```

## Frequently Asked Questions

**Q: How often can I invalidate my CloudFront cache?**
A: AWS provides 1,000 free path invalidations per month. Beyond that, you'll be charged per invalidation path. This plugin tries to be efficient by only invalidating the necessary paths.

**Q: Is using IAM roles more secure than access keys?**
A: Yes, IAM roles are more secure because:
- No credentials are stored in your database
- Credentials are rotated automatically
- Permissions can be managed centrally in AWS
- No risk of credentials being exposed in code

**Q: Will this plugin work if my WordPress site is not hosted on AWS?**
A: Yes, but you'll need to use the AWS access key method instead of IAM roles.

**Q: How can I tell if the invalidation was successful?**
A: Check the AWS CloudFront console for the status of your invalidation requests. Successful invalidations will show as "Completed" status.

**Q: Does the plugin work with custom post types?**
A: Yes, the plugin supports all post types including custom ones.

**Q: What happens if I exceed AWS invalidation limits?**
A: The plugin validates paths before sending to AWS and will show an error if you exceed the 3000 path limit per request.

**Q: Can I use environment variables for AWS credentials?**
A: Yes, you can set credentials using constants (`CLOUDFRONT_AWS_ACCESS_KEY`, `CLOUDFRONT_AWS_SECRET_KEY`) or environment variables with the same names.

## License

This plugin is licensed under the [GPL v3 or later](https://www.gnu.org/licenses/gpl-3.0.html).

## Development

### Setup

```bash
# Install dependencies (includes dev dependencies)
composer install

# Install AWS SDK
composer require aws/aws-sdk-php
```

### Code Quality

This project follows WordPress coding standards and includes comprehensive unit tests.

```bash
# Auto-fix code style issues
composer phpcbf

# Check for code style violations
composer phpcs

# Run unit tests
composer test

# Run tests for specific suite
composer test:unit

# Generate code coverage report (requires Xdebug)
composer test:coverage

# Check for security vulnerabilities
composer audit
```

### Testing

The plugin includes comprehensive unit tests covering:

- **Encryption/Decryption** (CRITICAL) - libsodium authenticated encryption for stored credentials, plus AES-256-CBC decryption and migration of legacy v1.1.0/v1.2.0 payloads
- **Path Sanitization** (HIGH) - Path injection prevention and validation
- **Input Validation** (HIGH) - AWS regions, distribution IDs, and invalidation paths
- **Credential Resolution** (MEDIUM) - Priority resolution (constants > env > options)
- **Hook Behavior** (MEDIUM) - WordPress hook integration and behavior
- **Settings Validation** (MEDIUM) - Form validation and error handling

**Test Statistics:**
- 60+ tests
- 180+ assertions
- 4 test suites (Unit, Integration)
- Full coverage of security-critical functions
- Continuous integration on PHP 8.1, 8.2, 8.3, 8.4

Run tests before submitting pull requests:
```bash
composer phpcbf && composer phpcs && composer test
```

### Continuous Integration

GitHub Actions automatically runs tests on:
- PHP 8.1, 8.2, 8.3, 8.4
- PHPCS code style checks
- PHPUnit tests
- Security vulnerability scanning
- Code coverage reporting (optional Codecov integration)

Every non-draft pull request from this repository also gets an AI review that posts inline
comments for bugs, security issues and over-engineering. It requires an `AI_API_KEY`
repository secret (OpenRouter).

See `.github/workflows/ci.yml` and `.github/workflows/README.md` for details.

### Security

- All AWS credentials are encrypted with libsodium authenticated encryption before database storage
- Input validation prevents injection attacks
- HTTPS requirement for credential submission
- Follows WordPress security best practices
- Regular security audits with `composer audit`

## Support

For support, feature requests, or bug reports, please [create an issue](https://github.com/notglossy/CloudFront-Cache-Invalidator/issues) on GitHub.

## Credits

Developed by Not Glossy, LLC

## Changelog

### 1.3.0
- Security: on multisite, configuring the plugin and running a manual invalidation require `manage_network_options`, so sub-site administrators can no longer point the network's AWS credentials at another distribution (filterable with `notglossy_cloudfront_settings_capability`)
- Fixed: on multisite, content changed under `switch_to_blog()` is sent to that site's distribution with that site's settings, instead of the site the request started on
- Fixed: publishing a post on default reading settings, saving a draft, private or scheduled post, and saving content from private taxonomies no longer purges the entire distribution (`/*`)
- Fixed: deleting revisions, auto-drafts and menu items no longer purges the entire distribution; deleting a published post purges only its URL and listings
- Fixed: changing a slug, parent or date now purges the old URL; renaming or deleting a term purges its old archive
- Fixed: block editor (REST) saves purge the post's current terms, and removed terms are purged too
- Fixed: wildcards are anchored (`/slug/*`) so they no longer match sibling URLs on permalink structures without a trailing slash
- Fixed: newly entered access keys were silently discarded in favour of the previously stored pair, so keys could not be rotated from the settings page
- Fixed: the first save on a fresh install dropped the entered keys and turned "Use IAM Role" on (the sanitize callback is now safe to run twice)
- Fixed: credentials stored by v1.1.0 / v1.2.0 could not be decrypted after the key-derivation change; they are now migrated and re-encrypted automatically
- Added: `CLOUDFRONT_DISTRIBUTION_ID`, `CLOUDFRONT_AWS_REGION` and `CLOUDFRONT_USE_IAM_ROLE` constants
- Added: post meta changes invalidate the post for allowlisted keys (WooCommerce price and stock by default; `notglossy_cloudfront_meta_keys` filter)
- Added: WooCommerce stock quantity and stock status changes, including stock reduced by orders, invalidate the product (variations purge the parent)
- Added: comments (approve, unapprove, edit, spam, trash, delete) purge the post they belong to
- Added: one batched invalidation per request, with a fallback to `/*` for batches over CloudFront's limits
- Added: `notglossy_cloudfront_invalidation_paths` filter; `WP_IMPORTING` and `wp_suspend_cache_invalidation()` are respected
- Added: "Remove the stored access keys" control and a stored-credentials status on the settings page
- Added: half-submitted key pairs and malformed keys are rejected with a settings error; array-valued fields no longer cause a fatal error
- Added: HTTP connect/request timeouts on the CloudFront client and a `notglossy_cloudfront_client_config` filter
- Changed: requires WordPress 5.7 or higher
- Changed: access-key mode refuses to call AWS when no usable key pair is configured instead of falling back to the SDK's ambient credential chain; an admin notice explains why invalidations are paused
- Changed: credentials are passed to the SDK as a provider object and client-construction errors no longer expose the secret in exception traces
- Changed: a blank AWS Region now defaults to `us-east-1`; multi-segment regions such as `us-gov-west-1` are accepted
- Updated dependencies to clear published advisories (Guzzle 7.15.5, guzzlehttp/psr7 2.13.1, jmespath.php 2.9.2, PHPCS 3.13.6, WPCS 3.4.1)

### 1.2.0
- Added comprehensive input validation for AWS regions, distribution IDs, and invalidation paths
- Enhanced security with AES-256-CBC credential encryption
- Improved error handling with user-friendly messages
- Added support for environment variables and constants for credentials
- Implemented automatic migration of legacy plaintext credentials
- Enhanced path sanitization with CloudFront API compliance
- Added logging hooks for monitoring and debugging
- Improved admin interface with better field validation feedback

### 1.1.1
- Added manual invalidation with POST-Redirect-GET pattern
- Implemented path limit validation (3000 paths per request)
- Enhanced error handling and user feedback
- Added admin notices for invalidation results

### 1.0.0
- Initial release
- Support for automatic invalidation on content updates
- Support for IAM roles and access keys
- Manual invalidation feature
- Customizable invalidation paths