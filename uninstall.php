<?php
/**
 * Deletes the MCP access tokens and the cached latest release, the only data Code Workbench
 * stores.
 *
 * @package Code_Workbench
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_network_option( null, 'code_workbench_mcp_tokens' );
delete_site_transient( 'code_workbench_release' );
