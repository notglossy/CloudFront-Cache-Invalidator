# GitHub Actions CI/CD Workflow

This repository uses GitHub Actions for automated testing, code quality checks, and security scanning.

## Workflows

### CI Workflow (`ci.yml`)

Runs on every push to `main` and `develop` branches, and on all pull requests.

#### Jobs

##### 1. Code Style (PHPCS)
- **PHP Version**: 8.1
- **Purpose**: Enforces WordPress coding standards
- **Command**: `composer phpcs`
- **Standards**: WordPress-Core and WordPress-Extra (WPCS)

##### 2. Tests (Matrix)
- **PHP Versions**: 7.4, 8.0, 8.1, 8.2, 8.3
- **Purpose**: Run PHPUnit tests across multiple PHP versions
- **Command**: `composer test`
- **Tests**: 60 unit tests with 182 assertions
- **Coverage**:
  - Encryption/Decryption (CRITICAL)
  - Path Sanitization (HIGH)
  - Input Validation (HIGH)
  - Credential Resolution (MEDIUM)

##### 3. Code Coverage
- **PHP Version**: 8.1 with Xdebug
- **Purpose**: Generate and upload code coverage reports
- **Output**: `coverage.xml` uploaded to Codecov
- **Note**: Requires `CODECOV_TOKEN` secret to be configured

##### 4. Security Check
- **PHP Version**: 8.1
- **Purpose**: Check for known security vulnerabilities in dependencies
- **Command**: `composer audit`

## Configuration Requirements

### Secrets

If you want to upload coverage reports to Codecov, add this secret to your repository:

- `CODECOV_TOKEN`: Your Codecov upload token
  - Get it from: https://codecov.io/gh/YOUR_ORG/YOUR_REPO/settings
  - Add it at: Repository Settings → Secrets and variables → Actions → New repository secret

### Branch Protection (Recommended)

Configure branch protection rules for `main`:

1. Go to: Settings → Branches → Add branch protection rule
2. Branch name pattern: `main`
3. Enable:
   - ✅ Require status checks to pass before merging
   - ✅ Require branches to be up to date before merging
4. Select required status checks:
   - ✅ Code Style (PHPCS)
   - ✅ Tests (PHP 7.4)
   - ✅ Tests (PHP 8.0)
   - ✅ Tests (PHP 8.1)
   - ✅ Tests (PHP 8.2)
   - ✅ Tests (PHP 8.3)
   - ✅ Security Check

## Badges

Add these badges to your README.md:

```markdown
![CI](https://github.com/YOUR_USERNAME/CloudFront-Cache-Invalidator/workflows/CI/badge.svg)
[![codecov](https://codecov.io/gh/YOUR_USERNAME/CloudFront-Cache-Invalidator/branch/main/graph/badge.svg)](https://codecov.io/gh/YOUR_USERNAME/CloudFront-Cache-Invalidator)
```

## Local Development

Before pushing, run these commands locally to catch issues early:

```bash
# Auto-fix code style issues
composer phpcbf

# Check for remaining code style issues
composer phpcs

# Run tests
composer test

# Run tests with coverage (requires Xdebug)
composer test:coverage

# Check for security vulnerabilities
composer audit
```

## Workflow Performance

The CI workflow uses caching to improve performance:

- **Composer cache**: Cached between runs for faster dependency installation
- **Matrix strategy**: Tests run in parallel across PHP versions
- **Fail-fast disabled**: All PHP versions tested even if one fails

Typical run times:
- PHPCS: ~30-45 seconds
- Tests per PHP version: ~45-60 seconds
- Coverage: ~60-90 seconds (with Xdebug)
- Security: ~30-45 seconds

## Troubleshooting

### Tests failing on specific PHP version

Check the test output for the failing version. Common issues:
- Deprecated function usage
- Type hint differences between PHP versions
- Different behavior in openssl functions

### PHPCS failing

Run locally to see specific violations:
```bash
composer phpcs
```

Auto-fix when possible:
```bash
composer phpcbf
```

### Coverage upload failing

This is non-critical (fail_ci_if_error: false). Common causes:
- Missing `CODECOV_TOKEN` secret
- Codecov service outage
- Network issues

The workflow will still pass if coverage upload fails.

### Composer audit failing

This indicates a security vulnerability in a dependency. To fix:

1. Check which package has the vulnerability
2. Update dependencies: `composer update`
3. If still present, check if a patch or newer version is available
4. Consider replacing the vulnerable package if no fix is available

## Extending the Workflow

### Add PHP 8.4 when released

Edit `.github/workflows/ci.yml` and add to the matrix:

```yaml
matrix:
  php: ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4']
```

### Add integration tests

Create a new job in `ci.yml`:

```yaml
integration:
  name: Integration Tests
  runs-on: ubuntu-latest
  steps:
    # ... setup steps ...
    - name: Run integration tests
      run: composer test:integration
```

### Add deployment workflow

Create `.github/workflows/deploy.yml` for automated deployment on releases.
