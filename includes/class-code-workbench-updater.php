<?php
/**
 * Updates from the GitHub releases of the plugin.
 *
 * @package Code_Workbench
 */

defined( 'ABSPATH' ) || exit;

/**
 * The plugin is not hosted on wordpress.org: its "Update URI" header points to GitHub, and
 * WordPress asks this class (update_plugins_github.com) for the latest version. The answer is
 * the latest GitHub release and the plugin zip attached to it, so updates show up and install
 * like those of any other plugin. The header also keeps WordPress from offering a plugin of
 * wordpress.org that would have the same slug.
 */
class Code_Workbench_Updater {

	const REPOSITORY = 'KinG-InFeT/code-workbench';

	/**
	 * Site transient caching the latest release.
	 */
	const CACHE = 'code_workbench_release';

	/**
	 * Registers the hooks.
	 */
	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'update' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
	}

	/**
	 * The latest version, for wp_update_plugins().
	 *
	 * @param array|false $update      Update found by another filter.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin file.
	 * @return array|false
	 */
	public static function update( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( CODE_WORKBENCH_FILE ) !== $plugin_file ) {
			return $update;
		}

		$release = self::latest_release();
		if ( ! $release ) {
			return $update;
		}

		return array(
			'slug'    => dirname( $plugin_file ),
			'version' => $release['version'],
			'url'     => $release['url'],
			'package' => $release['package'],
		);
	}

	/**
	 * "View details" of the plugin: the GitHub release instead of a wordpress.org page.
	 *
	 * @param false|object|array $result Result of another filter.
	 * @param string             $action plugins_api() action.
	 * @param object             $args   Request arguments.
	 * @return false|object|array
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || dirname( plugin_basename( CODE_WORKBENCH_FILE ) ) !== $args->slug ) {
			return $result;
		}

		$release = self::latest_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Code Workbench',
			'slug'          => $args->slug,
			'version'       => $release['version'],
			'author'        => 'Vincenzo Luongo',
			'homepage'      => 'https://github.com/' . self::REPOSITORY,
			'download_link' => $release['package'],
			'sections'      => array(
				/* translators: %s: Version number. */
				'changelog' => '<h4>' . esc_html( sprintf( __( 'Version %s', 'code-workbench' ), $release['version'] ) ) . '</h4>' . wpautop( esc_html( $release['notes'] ) ),
			),
		);
	}

	/**
	 * Latest release with its plugin zip, from the GitHub API. Cached for 12 hours, and for
	 * an hour after a failure, so the API is not asked at every update check.
	 *
	 * @return array|null Keys: version, url, package, notes.
	 */
	private static function latest_release() {
		$release = get_site_transient( self::CACHE );
		if ( is_array( $release ) ) {
			return $release['version'] ? $release : null;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest',
			array(
				'timeout'    => 10,
				'headers'    => array( 'Accept' => 'application/vnd.github+json' ),
				// No site address in the user agent, unlike the WordPress default.
				'user-agent' => 'Code-Workbench/' . CODE_WORKBENCH_VERSION,
			)
		);
		$data    = json_decode( wp_remote_retrieve_body( $response ), true );
		$release = array(
			'version' => '',
			'url'     => '',
			'package' => '',
			'notes'   => '',
		);

		if ( 200 === wp_remote_retrieve_response_code( $response ) && is_array( $data ) && ! empty( $data['tag_name'] ) && isset( $data['assets'] ) ) {
			$version = ltrim( (string) $data['tag_name'], 'vV' );
			foreach ( (array) $data['assets'] as $asset ) {
				if ( isset( $asset['name'], $asset['browser_download_url'] ) && 'code-workbench-' . $version . '.zip' === $asset['name'] ) {
					$release = array(
						'version' => $version,
						'url'     => isset( $data['html_url'] ) ? (string) $data['html_url'] : '',
						'package' => (string) $asset['browser_download_url'],
						'notes'   => isset( $data['body'] ) ? (string) $data['body'] : '',
					);
				}
			}
		}

		set_site_transient( self::CACHE, $release, $release['version'] ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );

		return $release['version'] ? $release : null;
	}
}
