<?php
/**
 * Replaces the Theme File Editor and the Plugin File Editor screens.
 *
 * @package Code_Workbench
 */

defined( 'ABSPATH' ) || exit;

/**
 * Takes over theme-editor.php and plugin-editor.php: same URLs, menu entries and select
 * menu, with VS Code opened on the selected theme or plugin folder only.
 */
class Code_Workbench_Admin {

	/**
	 * Registers the hooks.
	 */
	public static function init() {
		add_action( 'load-theme-editor.php', array( __CLASS__, 'theme_editor' ) );
		add_action( 'load-plugin-editor.php', array( __CLASS__, 'plugin_editor' ) );
	}

	/**
	 * Theme File Editor.
	 */
	public static function theme_editor() {
		if ( ! self::takes_over( 'edit_themes' ) ) {
			return;
		}

		$themes = array();
		foreach ( wp_get_themes( array( 'errors' => null ) ) as $stylesheet => $theme ) {
			if ( ! $theme->errors() || 'theme_no_stylesheet' !== $theme->errors()->get_error_code() ) {
				$themes[ $stylesheet ] = $theme->get( 'Name' );
			}
		}

		$stylesheet = self::query( 'theme', get_stylesheet() );
		if ( ! isset( $themes[ $stylesheet ] ) ) {
			$stylesheet = get_stylesheet();
		}

		self::render(
			array(
				'type'     => 'theme',
				'item'     => $stylesheet,
				'file'     => '/' . $stylesheet . '/' . self::query( 'file', 'style.css' ),
				'options'  => $themes,
				'selected' => $stylesheet,
				'active'   => in_array( $stylesheet, array( get_stylesheet(), get_template() ), true ),
			)
		);
	}

	/**
	 * Plugin File Editor.
	 */
	public static function plugin_editor() {
		if ( ! self::takes_over( 'edit_plugins' ) ) {
			return;
		}

		$plugins = wp_list_pluck( get_plugins(), 'Name' );
		if ( ! $plugins ) {
			return; // Core explains there is nothing to edit.
		}

		// Same fallbacks as core: the plugin owning ?file=, else the first plugin.
		$plugin = self::query( 'plugin' );
		$file   = self::query( 'file' );
		if ( ! isset( $plugins[ $plugin ] ) ) {
			$plugin = key( $plugins );
			foreach ( array_keys( $plugins ) as $candidate ) {
				if ( $candidate === $file || ( false !== strpos( $file, '/' ) && strtok( $candidate, '/' ) === strtok( $file, '/' ) ) ) {
					$plugin = $candidate;
					break;
				}
			}
		}

		// "akismet/akismet.php" opens the akismet folder, a single file plugin only its file.
		$item = false === strpos( $plugin, '/' ) ? $plugin : strtok( $plugin, '/' );
		if ( '' === $file || ( $file !== $plugin && 0 !== strpos( $file, $item . '/' ) ) ) {
			$file = $plugin;
		}

		self::render(
			array(
				'type'     => 'plugin',
				'item'     => $item,
				'file'     => '/' . ( $item === $plugin ? $item . '/' : '' ) . $file,
				'options'  => $plugins,
				'selected' => $plugin,
				'active'   => is_plugin_active( $plugin ),
			)
		);
	}

	/**
	 * Whether this screen shows VS Code. The loopback requests core makes to check a saved
	 * PHP file for fatal errors must reach the real screens, and so must multisite site
	 * admins, whom core redirects to the network admin.
	 *
	 * @param string $capability Capability of the screen.
	 * @return bool
	 */
	private static function takes_over( $capability ) {
		return current_user_can( $capability ) && '' === self::query( 'wp_scrape_key' ) && ( ! is_multisite() || is_network_admin() );
	}

	/**
	 * Renders the screen (or, with ?code_workbench_frame=1, the workbench it loads in its iframe)
	 * and ends the request before core renders its own editor.
	 *
	 * @param array $screen Keys: type, item, file (workspace path to open), options (value =>
	 *                      label for the select menu), selected, active.
	 */
	private static function render( $screen ) {
		// menu-header.php sets $self in the including scope: global, it highlights the menu entry.
		global $title, $parent_file, $self;

		if ( '' !== self::query( 'code_workbench_frame' ) ) {
			self::render_workbench( $screen );
			exit;
		}

		$is_theme    = 'theme' === $screen['type'];
		$title       = $is_theme ? __( 'Edit Themes' ) : __( 'Edit Plugins' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.WP.I18n.MissingArgDomain
		$parent_file = $is_theme ? 'themes.php' : 'plugins.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		// The editor fills the screen: notices would push it below the fold.
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );

		wp_enqueue_style( 'code-workbench', plugins_url( 'assets/admin.css', CODE_WORKBENCH_FILE ), array(), CODE_WORKBENCH_VERSION );
		require_once ABSPATH . 'wp-admin/admin-header.php';

		/* translators: %s: Theme or plugin name. */
		$editing = $screen['active'] ? __( 'Editing %s (active)' ) : __( 'Editing %s (inactive)' ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain
		?>
		<div class="wrap code-workbench">
			<div class="code-workbench-bar">
				<h1><?php echo esc_html( $title ); ?></h1>
				<h2><?php printf( esc_html( $editing ), '<strong>' . esc_html( $screen['options'][ $screen['selected'] ] ) . '</strong>' ); ?></h2>
				<form class="code-workbench-select" action="<?php echo $is_theme ? 'theme-editor.php' : 'plugin-editor.php'; ?>" method="get">
					<label for="<?php echo esc_attr( $screen['type'] ); ?>" id="theme-plugin-editor-selector"><?php echo esc_html( $is_theme ? __( 'Select theme to edit:' ) : __( 'Select plugin to edit:' ) ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain ?></label>
					<select name="<?php echo esc_attr( $screen['type'] ); ?>" id="<?php echo esc_attr( $screen['type'] ); ?>">
						<?php foreach ( $screen['options'] as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $screen['selected'] ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php submit_button( __( 'Select' ), '', 'Submit', false ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain ?>
				</form>
			</div>
			<iframe class="code-workbench-frame" src="<?php echo esc_url( add_query_arg( 'code_workbench_frame', '1' ) ); ?>" title="Code Workbench"></iframe>
		</div>
		<?php
		require_once ABSPATH . 'wp-admin/admin-footer.php';
		exit;
	}

	/**
	 * Workbench document. assets/workbench.js reads the JSON configuration and starts VS Code.
	 * It is a standalone page in the iframe, outside the admin page, so its styles and scripts
	 * are printed here rather than enqueued for an admin hook.
	 *
	 * @param array $screen See render().
	 */
	private static function render_workbench( $screen ) {
		$vscode = plugins_url( 'vscode/' . CODE_WORKBENCH_VSCODE, CODE_WORKBENCH_FILE );
		$config = array(
			'workbench' => $vscode . '/out/vs/workbench/workbench.web.main.internal.js',
			// Webviews load from the plugin instead of Microsoft's CDN.
			'webviews'  => $vscode . '/out/vs/workbench/contrib/webview/browser/pre/',
			'extension' => plugins_url( 'extension', CODE_WORKBENCH_FILE ),
			'folder'    => '/' . $screen['item'],
			'siteName'  => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			// Handed to the WordPress extension through the "codeWorkbench.config" command.
			'wordpress' => array(
				'restUrl'  => rest_url( Code_Workbench_Files::REST_NAMESPACE ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'type'     => $screen['type'],
				'item'     => $screen['item'],
				'openFile' => $screen['file'],
			),
		);
		wp_register_style( 'code-workbench-vscode', $vscode . '/out/vs/workbench/workbench.web.main.internal.css', array(), CODE_WORKBENCH_VSCODE );
		wp_register_style( 'code-workbench-workbench', plugins_url( 'assets/workbench.css', CODE_WORKBENCH_FILE ), array( 'code-workbench-vscode' ), CODE_WORKBENCH_VERSION );
		?>
<!DOCTYPE html>
<html>
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<title><?php echo esc_html( $screen['options'][ $screen['selected'] ] ); ?> &lsaquo; Code Workbench</title>
		<?php
		wp_print_styles( 'code-workbench-workbench' );
		wp_print_inline_script_tag( wp_json_encode( $config ), array( 'id' => 'code-workbench-config', 'type' => 'application/json' ) );
		wp_print_inline_script_tag( 'globalThis._VSCODE_FILE_ROOT = ' . wp_json_encode( $vscode . '/out/' ) . ';' );
		wp_print_script_tag( array( 'src' => $vscode . '/out/nls.messages.js' ) );
		wp_print_script_tag(
			array(
				'type' => 'module',
				'src'  => add_query_arg( 'ver', CODE_WORKBENCH_VERSION, plugins_url( 'assets/workbench.js', CODE_WORKBENCH_FILE ) ),
			)
		);
		?>
	</head>
	<body aria-label=""></body>
</html>
		<?php
	}

	/**
	 * Query string value. Paths are validated by the REST API when they are used.
	 *
	 * @param string $key     Key.
	 * @param string $default Value when missing.
	 * @return string
	 */
	private static function query( $key, $default = '' ) {
		return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) && '' !== $_GET[ $key ] ? wp_unslash( $_GET[ $key ] ) : $default; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}
}
