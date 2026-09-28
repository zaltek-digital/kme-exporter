#!/bin/bash
#
# Zips the plugin for distribution, excluding dev files.
# Output goes to the versions/ folder (not committed). Upload the zip in
# wp-admin → Plugins → Add New → Upload Plugin on the KME site.
#
# There is no build step: the plugin is PHP only.
#

set -e

PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_NAME="$(basename "$PLUGIN_DIR")"

# Read version from the plugin header
VERSION=$(grep -m1 "Version:" "$PLUGIN_DIR/kme-exporter.php" | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')

# Create versions folder if it doesn't exist
mkdir -p "$PLUGIN_DIR/versions"

ZIP_FILE="$PLUGIN_DIR/versions/$PLUGIN_NAME-v$VERSION.zip"

# Remove old zip for this version if it exists
rm -f "$ZIP_FILE"

cd "$PLUGIN_DIR/.." || exit 1

zip -r "$ZIP_FILE" "$PLUGIN_NAME" \
    -x "$PLUGIN_NAME/.git/*" \
    -x "$PLUGIN_NAME/.gitignore" \
    -x "$PLUGIN_NAME/.gitattributes" \
    -x "$PLUGIN_NAME/.editorconfig" \
    -x "$PLUGIN_NAME/vendor/*" \
    -x "$PLUGIN_NAME/tests/*" \
    -x "$PLUGIN_NAME/tools/*" \
    -x "$PLUGIN_NAME/fixtures/*" \
    -x "$PLUGIN_NAME/schema/*" \
    -x "$PLUGIN_NAME/docs/*" \
    -x "$PLUGIN_NAME/bundles/*" \
    -x "$PLUGIN_NAME/composer.json" \
    -x "$PLUGIN_NAME/composer.lock" \
    -x "$PLUGIN_NAME/phpcs.xml.dist" \
    -x "$PLUGIN_NAME/phpstan.neon.dist" \
    -x "$PLUGIN_NAME/phpunit.xml.dist" \
    -x "$PLUGIN_NAME/zip-plugin.sh" \
    -x "$PLUGIN_NAME/.claude/*" \
    -x "$PLUGIN_NAME/CLAUDE.md" \
    -x "$PLUGIN_NAME/versions/*"

echo ""
echo "Created: $ZIP_FILE"
