<?php
/**
 * Plugin Name:       Code Workbench
 * Plugin URI:        https://wordpress-plugins.luongovincenzo.it/plugin/code-workbench
 * Description:       Replaces the Theme File Editor and the Plugin File Editor with a full code editor built on the open-source code of Visual Studio Code, scoped to the selected theme or plugin, and lets AI assistants work on plugin and theme files through MCP.
 * Version:           1.1.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Vincenzo Luongo
 * Author URI:        https://www.luongovincenzo.it/
 * License:           Apache-2.0
 * License URI:       https://www.apache.org/licenses/LICENSE-2.0
 * Text Domain:       code-workbench
 * Update URI:        https://github.com/KinG-InFeT/code-workbench
 */

defined( 'ABSPATH' ) || exit;

define( 'CODE_WORKBENCH_VERSION', '1.1.0' );
define( 'CODE_WORKBENCH_FILE', __FILE__ );
// VS Code version bundled in vscode/<version>/, built by bin/build-vscode.sh.
define( 'CODE_WORKBENCH_VSCODE', '1.148.0' );

require_once __DIR__ . '/includes/class-code-workbench-files.php';
require_once __DIR__ . '/includes/class-code-workbench-admin.php';
require_once __DIR__ . '/includes/class-code-workbench-mcp.php';
require_once __DIR__ . '/includes/class-code-workbench-updater.php';

add_action( 'rest_api_init', array( 'Code_Workbench_Files', 'register_routes' ) );
add_action( 'rest_api_init', array( 'Code_Workbench_MCP', 'register_routes' ) );
// Update checks also run from WP-Cron, outside the admin.
Code_Workbench_Updater::init();

if ( is_admin() ) {
	Code_Workbench_Admin::init();
}
