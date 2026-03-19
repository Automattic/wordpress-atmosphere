#!/usr/bin/env bash
#
# Build a release-ready ZIP of the ATmosphere plugin.
#
# Usage: bin/release.sh [version]
#
# Uses `git archive` to export only distribution files (respecting
# .gitattributes export-ignore rules), then installs production-only
# Composer dependencies with an optimised autoloader.
#
set -euo pipefail

PLUGIN_SLUG="atmosphere"
VERSION="${1:-$(sed -n 's/.*Version:\s*\([0-9.]*\).*/\1/p' atmosphere.php)}"
BUILD_DIR="$(mktemp -d)"
DEST="${BUILD_DIR}/${PLUGIN_SLUG}"

echo "Building ${PLUGIN_SLUG} v${VERSION}…"

# Export source via git archive (honours .gitattributes export-ignore).
mkdir -p "${DEST}"
git archive HEAD | tar -x -C "${DEST}"

# Install production-only dependencies with optimised autoloader.
composer install --no-dev --optimize-autoloader --working-dir="${DEST}" --quiet

# Remove composer files from the archive (vendor/ stays).
rm -f "${DEST}/composer.json" "${DEST}/composer.lock"

# Create the ZIP.
ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
( cd "${BUILD_DIR}" && zip -rq "${ZIP_NAME}" "${PLUGIN_SLUG}" )
mv "${BUILD_DIR}/${ZIP_NAME}" .

# Clean up.
rm -rf "${BUILD_DIR}"

echo "Created ${ZIP_NAME}"
echo "Size: $(du -h "${ZIP_NAME}" | cut -f1)"
