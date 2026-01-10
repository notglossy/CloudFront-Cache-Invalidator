# Security Audit: CloudFront Cache Invalidator WordPress Plugin

**Audit Date:** January 9, 2026
**Plugin Version:** 1.0.1
**Auditor:** Claude Code Security Review

## Executive Summary

This plugin automatically invalidates CloudFront CDN cache when WordPress content is updated. It supports both IAM role authentication and AWS access key authentication. The audit identified **3 high-severity**, **4 medium-severity**, and **3 low-severity** security issues, plus several architectural concerns.

---

## Security Issues

### HIGH SEVERITY

#### 1. Plaintext AWS Credentials Storage
**Location:** `includes/class-notglossy-cloudfront-cache-invalidator.php:364-368, 645-648`

**Issue:** AWS access keys and secret keys are stored in plaintext in the WordPress `wp_options` table.

**Risk:**
- Database breaches expose credentials directly
- WordPress backups include unencrypted credentials
- Other plugins with database access can read them
- Site exports may include credentials
- Shared hosting environments may have database access issues

**Recommendation:**
- Encrypt credentials before storing using WordPress salts or a dedicated encryption library
- Support environment variables (`$_ENV` or `getenv()`) as an alternative
- Support `wp-config.php` constants (e.g., `CLOUDFRONT_AWS_ACCESS_KEY`)
- Add admin notice recommending IAM roles over stored credentials

#### 2. Secret Key Exposed in Hidden Form Fields
**Location:** `includes/class-notglossy-cloudfront-cache-invalidator.php:290-296`

**Issue:** When IAM role is enabled, the AWS secret key is still output in a hidden form field to preserve its value:
```php
echo '<input type="hidden" name="..." value="' . esc_attr( $value ) . '" />';
```

**Risk:**
- Secret key visible in HTML source even when IAM role is enabled
- Browser extensions, dev tools, or network proxies can capture it
- Browser autofill or password managers may store it

**Recommendation:**
- Don't output secret key in hidden fields
- Store a flag indicating "credentials exist" rather than the actual values
- Only display masked placeholder (e.g., `********`) for existing secrets
- Require re-entry of secret key when saving changes

#### 3. No HTTPS Enforcement for Settings Page
**Location:** `includes/class-notglossy-cloudfront-cache-invalidator.php:396-457`

**Issue:** The settings page accepts and displays AWS credentials without verifying the connection is over HTTPS.

**Risk:**
- Credentials transmitted in plaintext over HTTP
- Man-in-the-middle attacks can capture credentials

**Recommendation:**
- Add warning notice if `!is_ssl()` returns true
- Consider blocking credential entry on non-HTTPS connections

---

### MEDIUM SEVERITY

#### 4. Nonce Verification Timing Issue
**Location:** `includes/class-notglossy-cloudfront-cache-invalidator.php:444-453`

**Issue:** The manual invalidation form's POST handling occurs AFTER the form is rendered, inside `render_settings_page()`.

**Risk:**
- Page refresh can re-trigger invalidation (no POST-Redirect-GET pattern)
- Nonce check happens late in page lifecycle

**Recommendation:**
- Move POST handling to a separate action hook or to the beginning of the function
- Implement POST-Redirect-GET pattern with admin notices via transients
- Use `admin_post_{action}` hook for form processing

#### 5. No Input Validation for AWS-Specific Formats
**Location:** `includes/class-notglossy-cloudfront-cache-invalidator.php:357-384`

**Issue:** Settings are sanitized but not validated for correct formats:
- AWS Region: No check against valid region names
- Distribution ID: No check for alphanumeric format (e.g., `E1ABCDEFGHIJKL`)
- Invalidation Paths: No check that paths start with `/`

**Risk:**
- Invalid configurations cause runtime API errors
- Poor user experience with delayed error feedback
- Potential for unexpected API behavior

**Recommendation:**
- Validate AWS region against known list or pattern `/^[a-z]{2}-[a-z]+-\d+$/`
- Validate Distribution ID with pattern `/^[A-Z0-9]+$/`
- Validate paths start with `/`
- Return validation errors with `add_settings_error()`

#### 6. Missing Explicit Capability Check
**Location:** `includes/class-notglossy-cloudfront-cache-invalidator.php:396`

**Issue:** `render_settings_page()` doesn't explicitly check `current_user_can('manage_options')` at the start.

**Risk:**
- Defense-in-depth principle not followed
- If WordPress core has a bug in menu capability checking, page could be accessible

**Recommendation:**
```php
public function render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
    }
    // ... rest of function
}
```

#### 7. Invalidation Paths Not Sanitized for AWS API
**Location:** `includes/class-notglossy-cloudfront-cache-invalidator.php:658`

**Issue:** User-provided invalidation paths are passed directly to AWS API without validation.

**Risk:**
- Malformed paths cause API errors
- Special characters could cause unexpected behavior
- No length limits on path arrays (AWS has limits)

**Recommendation:**
- Validate each path starts with `/`
- Limit number of paths per request (AWS limit: 3000 per invalidation)
- Sanitize/escape special characters appropriately for CloudFront

---

### LOW SEVERITY

#### 8. Information Disclosure in Error Logs
**Location:** `includes/class-notglossy-cloudfront-cache-invalidator.php:675, 680`

**Issue:** Invalidation paths and error messages are logged via `error_log()`.

**Risk:**
- URL structure exposed in logs
- Error messages might contain sensitive details
- Logs may be accessible in shared hosting

**Recommendation:**
- Make logging configurable (enable/disable)
- Use `WP_DEBUG_LOG` constant to control logging
- Sanitize error messages before logging

#### 9. No Rate Limiting or Debouncing
**Location:** `includes/class-notglossy-cloudfront-cache-invalidator.php:71-86`

**Issue:** Every content change triggers an immediate API call.

**Risk:**
- Bulk operations (imports, bulk edits) cause API flooding
- AWS API throttling (typically 3000 invalidations/month free, then $0.005 each)
- Excessive costs for high-traffic sites

**Recommendation:**
- Implement request batching with a short delay
- Queue invalidations and process periodically
- Add rate limiting with admin warnings

#### 10. Overly Broad Invalidation on Post Delete
**Location:** `includes/class-notglossy-cloudfront-cache-invalidator.php:548-551`

**Issue:** `invalidate_on_post_delete()` calls `invalidate_all()`, invalidating the entire cache.

**Risk:**
- Unnecessary cache purging
- Increased CloudFront costs
- Performance impact on CDN

**Recommendation:**
- Use `before_delete_post` hook to capture post URL before deletion
- Invalidate only the specific paths that were affected

---

## Architectural Issues

### 1. Single Monolithic Class
The entire plugin logic (685 lines) is in one class. Consider separating:
- Settings management
- AWS API interaction
- WordPress hooks/triggers
- Admin UI rendering

### 2. Synchronous API Calls
Invalidation requests block the WordPress save operation. Consider:
- Background processing with `wp_schedule_single_event()`
- Action Scheduler library for reliable async processing

### 3. No Retry Logic
Failed API calls are logged but not retried. Consider:
- Exponential backoff retry mechanism
- Queue for failed requests

### 4. No Request Batching
Multiple saves in quick succession create multiple API calls. Consider:
- Batching requests over a short window (e.g., 5 seconds)
- Combining paths from multiple operations

### 5. AWS SDK Bundled in Vendor
The full AWS SDK is included via Composer. Consider:
- Using only the CloudFront-specific package
- Checking for existing AWS SDK from other plugins

---

## Positive Security Findings

The plugin does implement several good security practices:

1. **Input sanitization**: Uses `sanitize_text_field()` and `sanitize_textarea_field()`
2. **Output escaping**: Proper use of `esc_attr()`, `esc_html()`, `esc_textarea()`
3. **CSRF protection**: Nonce verification on manual invalidation form
4. **Settings API**: Uses WordPress Settings API correctly
5. **Capability check**: Menu restricted to `manage_options`
6. **Direct access prevention**: `WPINC` check in main plugin file
7. **No SQL queries**: Uses WordPress functions exclusively
8. **IAM role support**: Provides secure authentication option
9. **No hardcoded credentials**: All secrets come from user input
10. **HTTPS for AWS**: AWS SDK handles secure transport

---

## Recommended Remediation Priority

| Priority | Issue | Effort |
|----------|-------|--------|
| 1 | Encrypt stored credentials | Medium |
| 2 | Remove secret from hidden fields | Low |
| 3 | Add HTTPS warning | Low |
| 4 | Fix nonce timing / PRG pattern | Low |
| 5 | Add input validation | Medium |
| 6 | Add explicit capability check | Low |
| 7 | Validate invalidation paths | Low |
| 8 | Make logging configurable | Low |
| 9 | Add rate limiting | Medium |
| 10 | Fix delete invalidation scope | Low |

---

## Files to Modify

1. **`includes/class-notglossy-cloudfront-cache-invalidator.php`** - All security fixes
2. **`cloudfront-cache-invalidator.php`** - No changes needed (main file is fine)

---

## Verification Plan

After implementing fixes:
1. Test settings save with valid/invalid inputs
2. Verify credentials are encrypted in database
3. Test manual invalidation with nonce verification
4. Check HTML source for credential exposure
5. Test on HTTP connection for warning display
6. Verify error handling and logging behavior
7. Test bulk operations for rate limiting
8. Confirm AWS API calls work correctly
