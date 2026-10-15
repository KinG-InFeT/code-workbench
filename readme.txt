=== Code Workbench ===
Contributors: vluongo
Tags: code editor, theme editor, file editor, ide, developer
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: Apache-2.0
License URI: https://www.apache.org/licenses/LICENSE-2.0

Edit theme and plugin files in a full code editor built on the open-source code of Visual Studio Code, scoped to the theme or plugin you select.

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
* For the active theme and active plugins, saving runs the same check as the built-in editor: WordPress loads your site after the change and reverts it if it causes a fatal error. A typo cannot lock you out of your dashboard.

= Self-contained =

The editor ships with the plugin and runs entirely in your browser. It downloads nothing, contacts no external service, collects no data and stores nothing in your database: your open tabs and layout are kept by your browser.

= Permissions =

Only users who can use the built-in editors can use Code Workbench: by default, administrators (super admins on multisite). When file editing is disabled with `DISALLOW_FILE_EDIT` or `DISALLOW_FILE_MODS`, Code Workbench is disabled too.

= Open source =

Code Workbench is free software released under the Apache License 2.0. Its source code, including the script that builds the bundled editor, is on GitHub: https://github.com/KinG-InFeT/code-workbench

The editor included in Code Workbench is built from the source code of Code - OSS 1.141.0 (MIT License), available at https://github.com/microsoft/vscode/tree/1.141.0, with only the parts needed to edit themes and plugins. Its license and third-party notices are included in the `vscode` folder of the plugin.

Visual Studio Code is a trademark of Microsoft Corporation. Code Workbench is not affiliated with or endorsed by Microsoft.

== Installation ==

1. Install and activate Code Workbench.
2. Go to **Appearance → Theme File Editor** or **Plugins → Plugin File Editor**.
3. Pick a theme or a plugin from the menu at the top right and start editing.

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

No. Everything it needs is included in the plugin.

= How do I go back to the built-in editors? =

Deactivate Code Workbench.

= Why does the editor not open? =

The editor must be loaded from your own site. If a CDN or optimization plugin serves the files of other plugins from another domain, exclude `wp-content/plugins/code-workbench/` from it.

== Screenshots ==

1. The Theme File Editor opens the selected theme in a full code editor, with file explorer, tabs and syntax highlighting.
2. The Plugin File Editor does the same for the selected plugin. Light and dark color themes are included.
3. Search across the whole plugin, with regular expressions and include / exclude patterns.
4. Quick Open (Ctrl+P) finds any file of the theme or plugin by name.
5. Completions and error checking for CSS, SCSS, Less and JSON.
6. A PHP file with a syntax error is never saved: the editor shows the error and its line.

== Changelog ==

= 1.0.0 =
* First release.
