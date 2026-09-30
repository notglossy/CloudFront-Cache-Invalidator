#!/usr/bin/env bash
# Build the installable plugin zip.
#
# Exports the committed tree (git archive honours .gitattributes export-ignore,
# so tests, CI config and dev tooling are left out), installs production
# dependencies only, removes the Composer manifests and writes
#   <out>/cloudfront-cache-invalidator-<version>.zip
#   <out>/cloudfront-cache-invalidator-<version>.zip.sha256
#
# Usage: bin/build-release.sh [output-dir] [git-ref]
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="cloudfront-cache-invalidator"
OUT="${1:-$ROOT/dist}"
REF="${2:-HEAD}"

VERSION="$(git -C "$ROOT" show "$REF:$SLUG.php" | sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' | head -n1 | tr -d '[:space:]')"
if [ -z "$VERSION" ]; then
	echo "Could not read the Version header from $SLUG.php" >&2
	exit 1
fi

BUILD="$(mktemp -d)"
trap 'rm -rf "$BUILD"' EXIT

git -C "$ROOT" archive --format=tar --prefix="$SLUG/" "$REF" | tar -x -C "$BUILD"

(
	cd "$BUILD/$SLUG"
	composer install --no-dev --prefer-dist --no-interaction --no-progress --no-plugins --no-scripts --optimize-autoloader
	rm -f composer.json composer.lock
)

# Guard against dev tooling leaking into the build.
for dev in tests vendor/phpunit vendor/brain vendor/mockery vendor/antecedent vendor/squizlabs vendor/wp-coding-standards; do
	if [ -e "$BUILD/$SLUG/$dev" ]; then
		echo "Build contains development files: $dev" >&2
		exit 1
	fi
done

# Absolute path: zip runs from inside $BUILD, so a relative OUT would resolve there.
mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"
ZIP="$OUT/$SLUG-$VERSION.zip"
rm -f "$ZIP" "$ZIP.sha256"
(cd "$BUILD" && zip -rqX "$ZIP" "$SLUG")
(cd "$OUT" && shasum -a 256 "$(basename "$ZIP")" > "$(basename "$ZIP").sha256")

echo "$ZIP"
