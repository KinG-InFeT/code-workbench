#!/usr/bin/env bash
#
# Packages a release of the plugin into dist/:
# - dist/code-workbench/, the plugin without the development files;
# - dist/code-workbench-<version>.zip, to attach to the GitHub release (the updater of the
#   plugin installs the asset with this name) or to install by upload.
#
# Usage: bin/package.sh

set -euo pipefail

SLUG=code-workbench
PLUGIN=$(cd "$(dirname "$0")/.." && pwd)
DIST=$PLUGIN/dist
VERSION=$(sed -n 's/^ \* Version: *//p' "$PLUGIN/$SLUG.php")
VSCODE=$(sed -n "s/.*'CODE_WORKBENCH_VSCODE', '\([^']*\)'.*/\1/p" "$PLUGIN/$SLUG.php")

if [ "$VERSION" != "$(sed -n "s/.*'CODE_WORKBENCH_VERSION', '\([^']*\)'.*/\1/p" "$PLUGIN/$SLUG.php")" ]; then
	echo "CODE_WORKBENCH_VERSION does not match version $VERSION of $SLUG.php." >&2
	exit 1
fi
if [ "$VERSION" != "$(sed -n 's/^Stable tag: *//p' "$PLUGIN/readme.txt")" ]; then
	echo "The Stable tag of readme.txt does not match version $VERSION of $SLUG.php." >&2
	exit 1
fi
if [ ! -d "$PLUGIN/vscode/$VSCODE" ]; then
	echo "vscode/$VSCODE is missing: run bin/build-vscode.sh." >&2
	exit 1
fi

# The plugin without the development files (bin/, dist/, README.md and hidden files such as
# .git and .github).
mkdir -p "$DIST"
rsync -a --delete --exclude '.*' --exclude '/bin/' --exclude '/dist/' --exclude '/README.md' "$PLUGIN/" "$DIST/$SLUG/"
# A folder per version: browsers never run a cached extension of the previous release.
mv "$DIST/$SLUG/extension" "$DIST/$SLUG/extension-$VERSION"

rm -f "$DIST/$SLUG-$VERSION.zip"
(cd "$DIST" && zip -qr "$SLUG-$VERSION.zip" "$SLUG")
echo "dist/$SLUG-$VERSION.zip: $(du -h "$DIST/$SLUG-$VERSION.zip" | cut -f1)"
