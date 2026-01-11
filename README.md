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
- **Access Key Support**: Traditional authentication using AWS access keys and secret keys
- **Manual Invalidation**: One-click button to manually invalidate entire cache
- **Customizable Paths**: Configure default invalidation paths for site-wide changes
- **Taxonomy Support**: Invalidates relevant paths when categories, tags, or custom taxonomies are modified

## Requirements

- WordPress 5.0 or higher
- PHP 8.1 or higher
- AWS SDK for PHP (installed via Composer)
- If using access keys: AWS account with CloudFront access
- If using IAM roles: WordPress site hosted on AWS infrastructure with an appropriate IAM role

## Installation

1. Download the plugin and extract it to your `/wp-content/plugins/` directory, or install via the WordPress plugin repository
2. Create a folder called `cloudfront-cache-invalidator` in your `/wp-content/plugins/` directory
3. Upload the plugin file to that folder
4. Install the AWS SDK by running this command in the plugin directory:
   ```
   composer require aws/aws-sdk-php
   ```
5. Activate the plugin through the WordPress admin dashboard
6. Go to Settings → CloudFront Cache to configure the plugin

## Configuration

### Using IAM Roles (Recommended for AWS-hosted sites)

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

### Manual Invalidation

You can manually trigger a cache invalidation by:

1. Go to Settings → CloudFront Cache
2. Scroll to the "Manual Invalidation" section
3. Click the "Invalidate All CloudFront Cache" button

### Default Invalidation Paths

For site-wide changes, the plugin will use the default invalidation paths configured in the settings. The default is `/*` which invalidates the entire cache.

You can customize these paths by entering one path per line in the settings. For example:

```
/*
/wp-content/uploads/*
/wp-content/themes/*
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

**Access Denied Errors**
- Verify that your IAM role or IAM user has the correct permissions
- If using access keys, ensure they are entered correctly
- Check that the distribution ID is correct

**Invalidation Not Working**
- Check your WordPress error logs for any AWS API errors
- Verify the CloudFront distribution is properly configured
- Ensure the paths being invalidated match your URL structure

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

## License

This plugin is licensed under the [GPL v2 or later](https://www.gnu.org/licenses/gpl-2.0.html).

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
- **Encryption/Decryption** (CRITICAL) - AWS credential security
- **Path Sanitization** (HIGH) - Path injection prevention
- **Input Validation** (HIGH) - AWS regions, distribution IDs, paths
- **Credential Resolution** (MEDIUM) - Priority resolution (constants > env > options)

**Test Stats:**
- 60 tests
- 182 assertions
- 4 test suites
- Full coverage of security-critical functions

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

## Support

For support, feature requests, or bug reports, please [create an issue](https://github.com/notglossy/CloudFront-Cache-Invalidator/issues) on GitHub.

## Credits

Developed by Not Glossy, LLC

## Changelog

### 1.0.0
- Initial release
- Support for automatic invalidation on content updates
- Support for IAM roles and access keys
- Manual invalidation feature
- Customizable invalidation paths
