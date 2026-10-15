#!/usr/bin/env bash
#
# Builds VS Code for the Web from its MIT licensed source (Code - OSS) with
# only the built-in extensions useful for themes and plugins, and copies the
# result into the plugin's vscode/<version>/ folder. The version is the
# CODE_WORKBENCH_VSCODE constant of code-workbench.php.
#
# Usage: bin/build-vscode.sh [source-dir]
# Needs git, rsync and the Node.js major version named in the source's .nvmrc.

set -euo pipefail

EXTENSIONS=" css css-language-features emmet html javascript json json-language-features less markdown-basics php scss shellscript sql theme-defaults theme-seti typescript-basics xml yaml "
MODULES="vscode-oniguruma vscode-textmate jschardet @vscode/iconv-lite-umd"

PLUGIN=$(cd "$(dirname "$0")/.." && pwd)
VERSION=$(sed -n "s/.*'CODE_WORKBENCH_VSCODE', '\([^']*\)'.*/\1/p" "$PLUGIN/code-workbench.php")
SRC=$(realpath -m "${1:-$HOME/.cache/code-workbench/vscode-$VERSION}")
OUT=$(dirname "$SRC")/vscode-web
DEST=$PLUGIN/vscode/$VERSION

if [ ! -d "$SRC" ]; then
	git clone --depth 1 --branch "$VERSION" https://github.com/microsoft/vscode "$SRC"
fi
cd "$SRC"

if [ "$(node -p 'process.versions.node.split(".")[0]')" != "$(cut -d. -f1 .nvmrc)" ]; then
	echo "Node.js $(cat .nvmrc) is required." >&2
	exit 1
fi

# The workbench lists every extension found here as built in, so the others go.
for dir in extensions/*/; do
	name=$(basename "$dir")
	if [ -f "$dir/package.json" ] && [[ "$EXTENSIONS" != *" $name "* ]]; then
		rm -rf "$dir"
	fi
done
node -e '
const fs = require("fs");
const product = JSON.parse(fs.readFileSync("product.json"));
product.builtInExtensions = [];
product.webBuiltInExtensions = [];
fs.writeFileSync("product.json", JSON.stringify(product, null, "\t"));

// wordpress.org rejects file names with spaces or special characters, such as
// "Regular Expressions (JavaScript).tmLanguage". The workbench embeds the manifests at
// build time, so the grammars are renamed in the source.
for (const name of fs.readdirSync("extensions")) {
	const syntaxes = `extensions/${name}/syntaxes`;
	if (!fs.existsSync(syntaxes)) {
		continue;
	}
	for (const file of fs.readdirSync(syntaxes).filter(file => /[^\w.-]/.test(file))) {
		const safe = file.replace(/[^\w.]+/g, "-").replace(/-\./g, ".");
		const manifest = `extensions/${name}/package.json`;
		fs.renameSync(`${syntaxes}/${file}`, `${syntaxes}/${safe}`);
		fs.writeFileSync(manifest, fs.readFileSync(manifest, "utf8").replace(`syntaxes/${file}`, `syntaxes/${safe}`));
	}
}

// The build also bundles the webview scripts of a fixed list of extensions.
const build = "build/lib/extensions.ts";
fs.writeFileSync(build, fs.readFileSync(build, "utf8").split("\n").filter(line => {
	const media = /^\s*\{ script: \x27([^/]+)\//.exec(line);
	return !media || fs.existsSync("extensions/" + media[1]);
}).join("\n"));
'

# Install scripts only build the native modules of the desktop app and the server.
for dir in . build extensions remote/web extensions/*/ extensions/*/server/; do
	if [ -f "$dir/package-lock.json" ]; then
		(cd "$dir" && npm ci --ignore-scripts --no-audit --no-fund)
	fi
done

npm run gulp vscode-web-min

# Not used: the Agents app and its voices, the Welcome page illustrations, the OAuth
# callback page, source maps and the NLS files other than nls.messages.js.
rm -rf "$PLUGIN/vscode"
mkdir -p "$DEST/node_modules"
cp LICENSE.txt ThirdPartyNotices.txt "$DEST/"
rsync -a --exclude 'vs/sessions/' --exclude 'vs/workbench/contrib/agentsVoice/' --exclude 'vs/workbench/contrib/welcomeGettingStarted/common/media/' \
	--exclude 'vs/code/browser/workbench/callback.html' --exclude '*.map' --exclude 'nls.*.json' "$OUT/out/" "$DEST/out/"
rsync -a --exclude 'dist/node/' "$OUT/extensions/" "$DEST/extensions/"
for module in $MODULES; do
	mkdir -p "$DEST/node_modules/$(dirname "$module")"
	cp -r "$OUT/node_modules/$module" "$DEST/node_modules/$module"
done
# wordpress.org rejects hidden files (.npmrc of the language servers).
find "$DEST" -name '.*' -type f -delete

du -sh "$DEST"
