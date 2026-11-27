=== Code Workbench ===
Contributors: vluongo
Tags: code editor, theme editor, file editor, ide, developer
Requires at least: 6.4
Tested up to: 7.2
Requires PHP: 7.4
Stable tag: 1.1.0
License: Apache-2.0
License URI: https://www.apache.org/licenses/LICENSE-2.0

Edit theme and plugin files in a code editor built on the open-source code of Visual Studio Code, and let AI assistants work on them through MCP.

== Description ==

Code Workbench replaces the Theme File Editor and the Plugin File Editor with a full code editor built on Code - OSS, the open-source project behind Visual Studio Code, right inside your dashboard.

The editors stay where you know them: **Appearance → Theme File Editor** and **Plugins → Plugin File Editor**, with the same "Select theme to edit" and "Select plugin to edit" menus. Pick a theme or a plugin and the editor opens on its folder only.

= Features =

* File explorer, tabs, split editors, minimap, multiple cursors and the command palette.
* Quick Open (Ctrl+P) and search across the whole theme or plugin, with regular expressions, case and whole word matching, and include / exclude patterns.
* Syntax highlighting for PHP, HTML, CSS, SCSS, Less, JavaScript, TypeScript, JSON, Markdown, XML, YAML, SQL and shell scripts.
* Completions and error checking for CSS, SCSS, Less and JSON, and Emmet abbreviations for HTML and CSS.
* Create, rename and delete files and folders inside the selected theme or plugin.
* Light and dark color themes and the familiar keyboard shortcuts.

= Scoped to what you select =

The editor sees the selected theme or plugin folder and nothing else. Other plugins, other themes and the WordPress core files cannot be opened, searched or changed from it. A plugin made of a single file shows just that file, as in the built-in editor.

= Safe editing =

* Changes reach your site only when you save them (Ctrl+S), as with the Update File button of the built-in editors.
* Every PHP file is checked for syntax errors before it is saved. A file with an error is not written, and the editor shows the error with its line number.
* For the active theme and active plugins, saving runs the same check as the built-in editor: WordPress loads your site after the change and reverts it if it causes a fatal error. This covers every PHP file, new files and libraries included. A typo cannot lock you out of your dashboard.
* Deleting or renaming a PHP file or a folder of the active theme or of an active plugin is undone if your site no longer loads without it.

= AI assistants (MCP) =

Connect AI assistants such as Claude (Claude Code, claude.ai), ChatGPT, Cursor or VS Code to your plugins and themes through the Model Context Protocol (MCP), right from the editor: open the Theme File Editor or the Plugin File Editor and click the **AI Access (MCP)** icon in the bar on the left, next to the file explorer and search. It shows the buttons to create a token and to see the connection settings, and the list of your tokens.

* Create as many access tokens as you need, each with its own name and access: **read only** (browse, search and read files, load the public pages of your site) or **read and write** (also create, edit, upload, move and delete files, and load pages logged in as you, for example the settings screen of the plugin you are working on).
* Regenerate or revoke a token at any time. The list shows who created each token and when it was last used.
* A new token comes with ready-to-paste settings for Claude Code, for clients configured with a file (Cursor, VS Code, Windsurf) and a secret address for claude.ai and ChatGPT.
* An assistant reaches the folders of your plugins and themes only: never the WordPress files, wp-config.php, your uploads or your database. It can load the pages of your site to check its changes, and no other website.
* The same safety checks as in the editor apply to every change an assistant makes.

= Self-contained =

The editor ships with the plugin and runs entirely in your browser: it downloads nothing and collects no data. The only outside service the plugin contacts is GitHub, a few times a day, to see whether a new version of Code Workbench is out; it sends nothing about your site. The only thing the plugin stores in your database is the list of AI access tokens you create, and never the tokens themselves in readable form: your open tabs and layout are kept by your browser.

= Updates =

Code Workbench is published on GitHub. New versions show up in **Dashboard → Updates** and on the Plugins screen like those of any other plugin, and install with one click (or automatically, if you turn on auto-updates for it).

= Permissions =

Only users who can use the built-in editors can use Code Workbench and create access tokens: by default, administrators (super admins on multisite). An access token acts as the user who created it and stops working if that user loses this permission. When file editing is disabled with `DISALLOW_FILE_EDIT` or `DISALLOW_FILE_MODS`, Code Workbench and its access tokens are disabled too.

= Open source =

Code Workbench is free software released under the Apache License 2.0. Its source code, including the script that builds the bundled editor, is on GitHub: https://github.com/KinG-InFeT/code-workbench

The editor included in Code Workbench is built from the source code of Code - OSS 1.148.0 (MIT License), available at https://github.com/microsoft/vscode/tree/1.148.0, with only the parts needed to edit themes and plugins. Its license and third-party notices are included in the `vscode` folder of the plugin.

Visual Studio Code is a trademark of Microsoft Corporation. Code Workbench is not affiliated with or endorsed by Microsoft.

== Installation ==

1. Download `code-workbench-<version>.zip` from https://github.com/KinG-InFeT/code-workbench/releases, then upload it in **Plugins → Add New Plugin → Upload Plugin** and activate it.
2. Go to **Appearance → Theme File Editor** (**Tools → Theme File Editor** with a block theme) or **Plugins → Plugin File Editor**.
3. Pick a theme or a plugin from the menu at the top right and start editing.
4. To connect an AI assistant, click the **AI Access (MCP)** icon in the bar on the left of the editor, create an access token and copy the settings shown for your assistant.

== Frequently Asked Questions ==

= Can I edit files outside the selected theme or plugin? =

No. Each editor session is limited to the theme or plugin selected in the menu. To edit another one, select it from the menu.

= What happens if I save a change that breaks my site? =

A PHP syntax error is never saved. If the change is valid PHP but makes the active theme or an active plugin fail, WordPress reverts it and the editor shows the error.

= Can I install Visual Studio Code extensions? =

No. The editor comes with the tools for theme and plugin files and has no extension marketplace.

= Can I preview images? =

No. Images open as binary files. You can view them in the Media Library or in your browser.

= Does the editor need an internet connection? =

No. Everything it needs is included in the plugin. Your site only needs to reach GitHub to find out about new versions.

= How do I update Code Workbench? =

Like any other plugin: new versions appear in **Dashboard → Updates** and on the Plugins screen. You can also download the zip of a version from https://github.com/KinG-InFeT/code-workbench/releases and upload it in **Plugins → Add New Plugin → Upload Plugin**: WordPress offers to replace the installed version.

= How do I go back to the built-in editors? =

Deactivate Code Workbench.

= Which AI assistants can I connect? =

Any assistant that supports remote MCP servers. When you create a token, the editor shows the settings for Claude Code, for Cursor, VS Code and Windsurf, and a secret address to add as a custom connector in claude.ai and ChatGPT (in ChatGPT, developer mode must be on).

= Is it safe to give an AI assistant access to my files? =

A read and write token can change code that runs on your site, so give it only to assistants you trust, and prefer a read only token when no change is needed. Every change goes through the same checks as the editor: PHP syntax errors are never saved, and a change that breaks the active theme or an active plugin is reverted. Revoke a token as soon as you no longer use it, and regenerate it if its settings or its secret address may have leaked.

= Can an assistant read my database, my uploads or wp-config.php? =

No. Access tokens reach only the folders of your plugins and themes. To check its work, an assistant can load the pages of your own site, as a visitor, or logged in as you with a read and write token.

= Why does the editor not open? =

The editor must be loaded from your own site. If a CDN or optimization plugin serves the files of other plugins from another domain, exclude `wp-content/plugins/code-workbench/` from it.

== Changelog ==

= 1.1.0 =
* New: connect AI assistants (Claude, ChatGPT, Cursor, VS Code…) to your plugins and themes through MCP, with read only or read and write access tokens you can create, regenerate and revoke in the editor, from the AI Access (MCP) icon of its left bar.
* New: updates from the GitHub releases, shown and installed like those of any other plugin.
* The editor is now built on Code - OSS 1.148.0 (was 1.141.0).
* Saving now checks every PHP file of the active theme and active plugins for fatal errors, new files and libraries (such as vendor folders) included.
* Deleting or renaming a PHP file or a folder that the active theme or an active plugin needs is undone instead of breaking the site.
* Plugins whose folder holds more than one plugin are checked against the active one.
* Large files open faster and use less server memory.
* Search: case-insensitive matching of accented letters, regular expressions across lines, and files with Windows line endings.
* After an update, browsers no longer keep using a cached copy of the editor's file access component.

= 1.0.0 =
* First release.
