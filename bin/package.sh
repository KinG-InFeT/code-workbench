#!/usr/bin/env bash
#
# Packages a release of the plugin into dist/:
# - dist/code-workbench-<version>.zip, to submit to wordpress.org or install by upload;
# - the wordpress.org SVN layout: trunk/ and tags/<version>/ with the plugin, and
#   assets/ with the images of the plugin page (from .wordpress-org/).
#
# Usage: bin/package.sh [svn-checkout]
# Without arguments the SVN layout is written to dist/svn. With a checkout of
# https://plugins.svn.wordpress.org/code-workbench it is synced into the checkout
# and the changes are scheduled (add, delete, tag), ready to review and commit.

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
if [ $# -gt 0 ] && [ ! -d "$1/.svn" ]; then
	echo "$1 is not an SVN checkout." >&2
	exit 1
fi
if [ $# -gt 0 ] && [ -e "$1/tags/$VERSION" ]; then
	echo "tags/$VERSION already exists: raise the version first." >&2
	exit 1
fi

# The plugin without the development files. wordpress.org rejects hidden files.
mkdir -p "$DIST"
rsync -a --delete --exclude '.*' --exclude '/bin/' --exclude '/dist/' --exclude '/README.md' "$PLUGIN/" "$DIST/$SLUG/"

rm -f "$DIST/$SLUG-$VERSION.zip"
(cd "$DIST" && zip -qr "$SLUG-$VERSION.zip" "$SLUG")
echo "dist/$SLUG-$VERSION.zip: $(du -h "$DIST/$SLUG-$VERSION.zip" | cut -f1)"

if [ $# -eq 0 ]; then
	rm -rf "$DIST/svn"
	mkdir -p "$DIST/svn/tags"
	cp -r "$DIST/$SLUG" "$DIST/svn/trunk"
	cp -r "$DIST/$SLUG" "$DIST/svn/tags/$VERSION"
	cp -r "$PLUGIN/.wordpress-org" "$DIST/svn/assets"
	echo "dist/svn: trunk, tags/$VERSION and assets."
	exit 0
fi

SVN=$(cd "$1" && pwd)
mkdir -p "$SVN/trunk" "$SVN/assets" "$SVN/tags"
rsync -a --delete "$DIST/$SLUG/" "$SVN/trunk/"
rsync -a --delete "$PLUGIN/.wordpress-org/" "$SVN/assets/"

cd "$SVN"
svn add --force --quiet trunk assets tags
svn status | sed -n 's/^! *//p' | while read -r file; do
	svn delete --quiet "$file@"
done
svn copy --quiet trunk "tags/$VERSION"
# Without a MIME type wordpress.org serves the images as downloads.
svn status assets | sed -n 's/^A *//p' | while read -r image; do
	case $image in
		*.png) svn propset --quiet svn:mime-type image/png "$image" ;;
		*.svg) svn propset --quiet svn:mime-type image/svg+xml "$image" ;;
	esac
done

svn status --quiet | cut -c1 | sort | uniq -c
echo "Review with svn status / svn diff in $SVN, then: svn commit -m 'Release $VERSION'"
