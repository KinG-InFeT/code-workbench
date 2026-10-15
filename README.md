# Code Workbench

A WordPress plugin that replaces the **Theme File Editor** (`theme-editor.php`) and the **Plugin File Editor** (`plugin-editor.php`) with VS Code for the Web, running inside the WordPress admin. VS Code is built from its MIT licensed source ([Code - OSS](https://github.com/microsoft/vscode)) and ships inside the plugin: nothing is downloaded and the editor needs no external service.

`readme.txt` is the public description for wordpress.org; this file documents how the plugin works. Source: https://github.com/KinG-InFeT/code-workbench

The screens keep their URLs, menu entries and select menu ("Select theme to edit" / "Select plugin to edit"). VS Code opens on the selected theme or plugin folder only: the Explorer, Quick Open and search see that folder and nothing else, and the server refuses any path outside it.

## Requirements

- WordPress 6.4+, PHP 7.4+.
- Users need `edit_themes` / `edit_plugins`, like the core editors. When `DISALLOW_FILE_EDIT` or `DISALLOW_FILE_MODS` is set, or on multisite for non super admins, core removes those capabilities and the editors stay disabled.

## Installation

Install the release zip built by `bin/package.sh` (see [Development](#development)), or copy its `dist/code-workbench` folder into `wp-content/plugins/`, and activate it. A git checkout needs `bin/build-vscode.sh` first, because `vscode/` is not versioned. **Appearance → Theme File Editor** and **Plugins → Plugin File Editor** open VS Code. The plugin stores nothing in the database; VS Code keeps its state (open tabs, layout) in the browser.

## Bundled VS Code

`vscode/<version>/` holds a VS Code for the Web build made by `bin/build-vscode.sh` from the Code - OSS source at the tag named by `CODE_WORKBENCH_VSCODE` (`code-workbench.php`). The Microsoft download (`web-standalone`) cannot be used: its license forbids hosting or redistributing it.

The script trims the build to what editing a theme or a plugin needs:

- **Built-in extensions**: grammars for PHP, HTML, CSS, SCSS, Less, JavaScript, TypeScript, JSON, Markdown, XML, YAML, SQL and shell scripts; completions and validation for CSS and JSON (language servers running in web workers); Emmet; the default color themes and the Seti file icons. The workbench lists every extension it was built with as built in, so the others are deleted from the source before the build, not after.
- **Left out**, by size:
  - the JS/TS language service (about 5 MB once zipped);
  - the HTML language server (1.8 MB, mostly the TypeScript compiler it embeds for `<script>` blocks). HTML keeps highlighting and Emmet, and PHP templates never used it;
  - the Agents app and its voices, the Welcome page illustrations and the OAuth callback page;
  - Markdown preview, Git, notebooks, Copilot, the image preview (it needs webviews, see the limitations) and the marketplace extensions of the product configuration;
  - source maps and the native modules of the desktop app.

  Of the npm runtime modules only those the editor loads are copied: TextMate (`vscode-textmate`, `vscode-oniguruma`) and the encoding detection (`jschardet`, `@vscode/iconv-lite-umd`). The language detection model is not, and `workbench.editor.languageDetection` is off.
- **wordpress.org rules**: the script renames grammar files with spaces or brackets in their name (`Regular Expressions (JavaScript).tmLanguage`) in the source, together with their manifest, and deletes hidden files (`.npmrc`) from the copy.

The result is 34 MB on disk and 8.5 MB in the release zip, against 200 MB for the Microsoft build, under the 10 MB wordpress.org accepts. Each version has its own folder, so a browser never mixes cached files of two versions after an update.

`vscode/` is generated, so it is not versioned (`.gitignore`): [Development](#development) explains how to build it and how to move to another VS Code version.

## How it works

```
theme-editor.php / plugin-editor.php            (admin screen, core URL)
 ├─ select menu → reloads the screen on another theme / plugin
 └─ <iframe ?code_workbench_frame=1>                     (workbench document)
     ├─ vscode/<version>/out/...                  (VS Code workbench, ESM)
     ├─ assets/workbench.js                       (create() with the workspace folder)
     └─ extension/ (built-in web extension)       (file system for the "wordpress:" scheme)
          └─ fetch → /wp-json/code-workbench/v1/*  (includes/class-code-workbench-files.php)
```

- **No server process.** VS Code for the Web runs entirely in the browser. The `extension/` folder is a plain JavaScript web extension, loaded as a built-in one, that registers a `FileSystemProvider`, a file search provider (Quick Open) and a text search provider (Search view) for the `wordpress:` scheme. Each operation is a call to the plugin REST API.
- **Same-origin extension host.** The Code - OSS product configuration has no `webEndpointUrlTemplate`, so the extension host iframe runs on the site's origin instead of `*.vscode-cdn.net`. The extension then calls the REST API with the admin session cookies and the REST nonce. The workbench hands the nonce over through the `codeWorkbench.config` command. An expired nonce is refreshed through core's `admin-ajax.php?action=rest-nonce`.
- **Explicit saves.** VS Code for the Web saves after a 1 s delay by default, which would publish half-typed code on the site. The workbench sets `files.autoSave` to `off`, so files are written on Ctrl+S, like the Update File button of core.
- **Trimmed UI.** Source Control, Run and Debug and Extensions have nothing to work with on a WordPress site, and there are no accounts to sign in to. `assets/workbench.css` hides their activity bar items, the Accounts item and the AI chat item of the status bar, matched by their icon or entry id (`source-control-view-icon`, `run-view-icon`, `extensions-view-icon`, `accounts-view-bar-icon`, `chat.statusBarEntry`). A stylesheet applies on every load, whatever layout the browser has stored. The workbench API can only seed the layout of a first visit, and these views cannot be dropped from the build because many other parts of VS Code depend on their services.
- **Iframe.** The workbench runs in a full height iframe so the admin CSS does not leak into VS Code. The iframe document is a standalone page: it prints its stylesheets and scripts with `wp_print_styles()`, `wp_print_script_tag()` and `wp_print_inline_script_tag()` instead of enqueueing them for an admin hook. `admin_notices` are removed on these screens so nothing pushes the editor below the fold.
- **Core takeover.** `load-theme-editor.php` / `load-plugin-editor.php` render the screen through `admin-header.php` / `admin-footer.php` and end the request before core renders its own editor. The defaults follow core: the active theme with `style.css`, or the first plugin with its main file, and `?file=` opens a given file.

### REST API (`code-workbench/v1`)

Every route takes the scope: `type` (`plugin` | `theme`) and `item` (the plugin folder, the file of a single file plugin, or the theme stylesheet). Paths are workspace paths that start with the item, e.g. `/akismet/akismet.php`.

| Route | Method | Purpose |
| --- | --- | --- |
| `stat`, `readdir`, `read` | GET | File system reads. `read` returns the content base64 encoded, up to 20 MB. |
| `write` | POST | Raw body as the file content; `create`, `overwrite` flags. |
| `mkdir`, `delete`, `rename` | POST | File system changes. |
| `find` | POST | Every file under a folder, for Quick Open (VS Code filters them itself). |
| `search` | POST | Text search: regex / case / whole word, include and exclude globs. |

Errors use the codes `not_found`, `exists`, `not_dir`, `is_dir`, `forbidden`, `invalid_path`, `php_error`, `invalid_regex`, `too_large`, `failed`. The extension maps them to VS Code `FileSystemError`s.

### Isolation

The scope is validated on every request. A plugin must be installed (`get_plugins()`) and a theme must exist (`wp_get_theme()`, `validate_file()`). The path must then sit inside the item:

- Segments `.`, `..`, backslashes and NUL bytes are rejected. Anything outside the item answers `not_found`: other plugins, the plugins/themes root, `wp-config.php`.
- The plugin or theme folder itself cannot be deleted or renamed.
- A **single file plugin** (`hello.php`) is a virtual folder that holds only its file (`/hello.php/hello.php`). That file can be edited, but nothing can be created, renamed or deleted, as in core.
- Symlinks inside the item are followed; they were put there by the site owner. Deleting a symlinked folder removes the link, never its target. Search and Quick Open follow each symlinked folder once, so a symlink loop cannot hang them.

The scope comes from the select menu, and a user who can edit one plugin can edit them all, like in core. The isolation therefore keeps each editor session on the selected item; it is not a permission boundary between plugins.

### Saving PHP safely

1. Every `.php` file is syntax checked with `token_get_all( $code, TOKEN_PARSE )` before it is written. A parse error is rejected and shown in VS Code with its line, and the file on disk is left untouched.
2. Existing PHP files of a plugin or theme are saved through `wp_edit_theme_plugin_file()`, the function behind the core editors. When the plugin or theme is **active**, core then loads wp-admin and the home page. If either hits a fatal error, core rolls the change back and VS Code shows the error. This keeps a broken `functions.php` from locking the admin, and this editor, out. Files outside the core editable list (e.g. under `vendor/`) and new files are written directly.
3. Core's check loads wp-admin with the cookies of the request, but REST requests do not carry the wp-admin auth cookie, whose path is `/wp-admin`. The write route therefore hands the loopback an auth cookie for the current session (`wp_generate_auth_cookie()` with the session token of the logged-in cookie). Without it the loopback lands on the login screen and every save on an active plugin or theme is reverted.

## Limitations

- No terminal, debugger or tasks, as in any VS Code for the Web. CSS, SCSS, Less and JSON get completions and validation. PHP, HTML and JavaScript get syntax highlighting, snippets and Emmet (HTML) only.
- No marketplace: the Code - OSS product configuration has no extension gallery.
- The hidden views (see Trimmed UI) are hidden, not removed: their shortcuts (Ctrl+Shift+G, Ctrl+Shift+D, Ctrl+Shift+X), the Command Palette, the Run menu and Extensions in the Manage menu still reach them, and they open empty.
- No webviews. VS Code only runs a webview on a dedicated hostname derived from the editor's origin (`*.vscode-cdn.net` in Microsoft's builds), which a WordPress site cannot provide. `webviewEndpoint` points at the bundled copy so nothing is fetched from Microsoft's CDN. The few views that use one, such as an extension's details page, stay blank, and images open as binary files.
- JSON schemas are not downloaded (`json.schemaDownload.enable` is off), so `theme.json` and `block.json` are not validated against `schemas.wp.org`. The browser could not fetch them anyway: the redirect `schemas.wp.org` answers with has no CORS header.
- Changes made on disk by other means are not watched. VS Code still detects them when saving, through the file modification time.
- Each file operation is an HTTP request that boots WordPress (about 250 ms on a site with WooCommerce and Elementor). VS Code probes many paths that do not exist, such as `.vscode/tasks.json`, `.copilot` or `pyproject.toml`, on startup and around every save. The extension answers them without a request:
  - paths outside the folder never exist;
  - paths missing from a folder listing it read in the last 30 seconds do not exist either.

  Only absence is answered this way, so the stat VS Code makes before a save always reaches the server. Any change made from the editor clears the listings. Without this cache, on Local's two PHP-FPM workers a save sat behind the probes and took about 5 s; with it, about 1.4 s.

  The extension reads the folder listing before it registers the file system provider. The probes VS Code queued while the extension loaded therefore find the listing ready, and the startup needs 10 requests instead of 56. The editor appears about 1.3 s sooner.
- The provider fires no change events: VS Code updates editors and explorer after its own operations, and an event would only make it read the saved file back.
- Text search reads files up to 1 MB and skips binary files. Results stop at 5,000 matches, and Quick Open lists up to 50,000 files.
- The plugin folder must be served from the site's own origin (no CDN rewriting `plugins_url()` for `code-workbench`), otherwise the extension host cannot reach the REST API.

## Files

| File | Role |
| --- | --- |
| `code-workbench.php` | Bootstrap: constants (`CODE_WORKBENCH_VSCODE` = bundled VS Code version), includes, hooks. |
| `includes/class-code-workbench-admin.php` | Editor screens, select menu, workbench document. |
| `includes/class-code-workbench-files.php` | REST API, scope and path resolution, PHP checks, search. |
| `assets/workbench.js` | Starts the workbench (`create()`) on the selected folder. |
| `assets/workbench.css` | Hides the views that have nothing to work with (see Trimmed UI). |
| `assets/admin.css` | Layout of the editor screens. |
| `extension/` | Built-in VS Code web extension for the `wordpress:` scheme. |
| `vscode/<version>/` | Bundled VS Code for the Web (generated, with its `LICENSE.txt` and `ThirdPartyNotices.txt`). |
| `bin/build-vscode.sh` | Builds `vscode/` from the Code - OSS source. Not part of the release. |
| `bin/package.sh` | Builds the release zip and the wordpress.org SVN layout into `dist/`. Not part of the release. |
| `.wordpress-org/` | Icon, banners and screenshots of the wordpress.org plugin page (SVN `assets/`). |
| `readme.txt` | wordpress.org readme (end users). |
| `LICENSE` | Apache License 2.0, the license of the plugin. |
| `.gitignore` | Leaves out the generated `vscode/` and `dist/`. |

## Development

### Setup

Tools: git, rsync, zip, [nvm](https://github.com/nvm-sh/nvm) for Node.js, and svn for the wordpress.org releases.

```bash
git clone https://github.com/KinG-InFeT/code-workbench.git && cd code-workbench
nvm install 24                     # the Node.js major of the VS Code tag's .nvmrc
bin/build-vscode.sh                # builds vscode/<CODE_WORKBENCH_VSCODE>
ln -s "$PWD" /path/to/wp-content/plugins/code-workbench
```

`bin/build-vscode.sh`:

1. clones the VS Code tag named by `CODE_WORKBENCH_VSCODE` into `~/.cache/code-workbench/vscode-<version>` (or the folder given as argument) and reuses it on the next runs;
2. stops if the running Node.js major differs from the tag's `.nvmrc`;
3. deletes the built-in extensions not in its `EXTENSIONS` list and makes the source fit the wordpress.org rules (see [Bundled VS Code](#bundled-vs-code));
4. installs the build dependencies without their install scripts, which only compile native modules for the desktop app;
5. runs `gulp vscode-web-min` and replaces `vscode/` with the trimmed result.

A run takes about 3 minutes, and the checkout with its dependencies takes about 3 GB of disk. `~/.cache/code-workbench` can be deleted at any time; the next run downloads it again. Build from a path without UUID-like folder names: npm masks them as `***` in `npm list` output, and the extension packager then finds no files for the grammar-only extensions.

On the Local site the plugin folder is symlinked into `wp-content/plugins/`.

### Updating VS Code

1. Pick the new tag on [github.com/microsoft/vscode/tags](https://github.com/microsoft/vscode/tags), read the Node.js version in its `.nvmrc` and `nvm install` it.
2. Set `CODE_WORKBENCH_VSCODE` in `code-workbench.php` to the tag.
3. Update the version and the link in the "Open source" section of `readme.txt`.
4. Run `bin/build-vscode.sh`. It writes `vscode/<new version>/` and removes the old folder. A new folder name also keeps browsers from mixing cached files of two versions.
5. Check the editor on a theme and on a plugin:
   - opening, saving, the PHP syntax error notice, Quick Open and search;
   - no request leaving the site in the browser's network tab;
   - Source Control, Run and Debug, Extensions and Accounts still hidden. Their selectors rely on the icon ids listed under Trimmed UI;
   - the release zip still under 10 MB (`bin/package.sh` prints its size).

   If the build fails, compare the new source with the edits of the script: the `EXTENSIONS` list, the webview scripts filtered out of `build/lib/extensions.ts`, and the runtime `MODULES` copied from `node_modules`.
6. Release a new version of the plugin.

### Releasing a version

1. Raise the version in the `Version` header and in `CODE_WORKBENCH_VERSION` (`code-workbench.php`), and in the `Stable tag` of `readme.txt`. Add a Changelog entry, and update `Tested up to` when a new WordPress version is out.
2. Run `bin/package.sh`.
3. Run Plugin Check on the release: point the symlink at `dist/code-workbench` and run `wp plugin check code-workbench --include-experimental`, then point it back. It reports nothing. On the development folder it also reports the scripts in `bin/` and the zip in `dist/`, which the release leaves out.
4. Publish:
   - for the first version, submit the zip from wordpress.org → Add your plugin; the SVN repository exists once the plugin is approved. Then add the wordpress.org link to the plugin page on wordpress-plugins.luongovincenzo.it, whose "WordPress Plugin Page" button stays hidden until it has one;
   - for the next ones, commit through SVN:

```bash
svn checkout https://plugins.svn.wordpress.org/code-workbench ~/svn/code-workbench   # once
bin/package.sh ~/svn/code-workbench
cd ~/svn/code-workbench && svn status && svn commit -m 'Release 1.0.1'
```

`bin/package.sh` stops when the three versions differ or when `vscode/<version>/` has not been built. Into `dist/` it writes:

- `code-workbench/`, the plugin without `bin/`, `dist/`, `README.md` and hidden files (wordpress.org rejects them);
- `code-workbench-<version>.zip`, to submit the plugin to wordpress.org or to install it by upload;
- `svn/` with the wordpress.org layout: `trunk/`, `tags/<version>/` and `assets/` (the files of `.wordpress-org/`).

With an SVN checkout as argument, the script syncs `trunk/` and `assets/` into the checkout instead of writing `dist/svn/`. It then:

- schedules the added and deleted files;
- copies `trunk` to `tags/<version>`;
- sets the MIME type of new images (without it wordpress.org serves them as downloads);
- stops before the commit.

It refuses a version whose tag already exists. When `icon.svg` is first added, svn warns that `image/svg+xml` is a binary type for a text file; that is expected.

### Plugin page images

`.wordpress-org/` holds:

- `icon.svg` with its 128 and 256 px PNGs;
- the banners at 772×250 and 1544×500;
- `screenshot-1.png` to `screenshot-6.png`, captioned in the Screenshots section of `readme.txt`.

The screenshots were taken at 1440×900 on Twenty Twenty-Five and Akismet, with the admin in English and only the core admin menu. Take them again when the editor's look changes.

## License

Copyright 2026 Vincenzo Luongo. Licensed under the [Apache License, Version 2.0](https://www.apache.org/licenses/LICENSE-2.0) (`LICENSE`).

wordpress.org accepts any GPL compatible license. Apache 2.0 is compatible with GPLv3, and the plugin directory already hosts plugins under it, such as Site Kit by Google. The bundled VS Code build keeps its own MIT license (`vscode/<version>/LICENSE.txt`, with `ThirdPartyNotices.txt`).

The plugin header names the author (Vincenzo Luongo), the plugin page (`https://wordpress-plugins.luongovincenzo.it/plugin/code-workbench`) and the license. `readme.txt` lists the wordpress.org account (`vluongo`) under Contributors.
