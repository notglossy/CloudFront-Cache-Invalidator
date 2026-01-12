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
- **Security-First**: AWS credentials are encrypted using AES-256-CBC before storage
- **Input Validation**: Comprehensive validation for AWS regions, distribution IDs, and invalidation paths
- **Error Handling**: Robust error handling with user-friendly messages and logging hooks

## Requirements

- WordPress 5.0 or higher
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

### Security Features

- **Credential Encryption**: AWS access keys and secret keys are encrypted using AES-256-CBC before being stored in the database
- **HTTPS Requirement**: Credentials cannot be saved over HTTP connections
- **Migration Support**: Automatically migrates legacy plaintext credentials to encrypted storage
- **Environment Variable Support**: Supports loading credentials from constants or environment variables

## Usage

### Automatic Invalidation

Once configured, the plugin will automatically trigger cache invalidations when:

- Posts, pages, or custom post types are published, updated, or deleted
- Categories, tags, or custom taxonomies are updated
- The theme is changed
- Permalink structure is updated
- Plugins are activated or deactivated
- Navigation menus are updated
- Widgets are updated

### Smart Path Detection

The plugin intelligently determines which paths to invalidate:

- **Post Updates**: Invalidates the specific post URL, related archive pages, and taxonomy pages
- **Page Updates**: Invalidates the page URL and root paths if it's the front page
- **Term Updates**: Invalidates the specific taxonomy term URL
- **Site-wide Changes**: Uses default invalidation paths configured in settings

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

You can use these hooks to implement custom logging or monitoring solutions.

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

- **Encryption/Decryption** (CRITICAL) - AWS credential security using AES-256-CBC
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

See `.github/workflows/ci.yml` for details.

### Security

- All AWS credentials are encrypted using AES-256-CBC before database storage
- Input validation prevents injection attacks
- HTTPS requirement for credential submission
- Follows WordPress security best practices
- Regular security audits with `composer audit`

## Support

For support, feature requests, or bug reports, please [create an issue](https://github.com/notglossy/CloudFront-Cache-Invalidator/issues) on GitHub.

## Credits

Developed by Not Glossy, LLC

## Changelog

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