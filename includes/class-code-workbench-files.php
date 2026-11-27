<?php
/**
 * REST API behind the VS Code file system provider.
 *
 * @package Code_Workbench
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exposes the files of one plugin or one theme to VS Code.
 *
 * Every request names its scope, as picked in the editor select menu: "type" (plugin or
 * theme) and "item" (the plugin folder, the file of a single file plugin, or the theme
 * stylesheet). Workspace paths start with the item ("/akismet/akismet.php"), and anything
 * outside it does not exist. A single file plugin ("hello.php") is a virtual folder holding
 * that file only: "/hello.php/hello.php".
 */
class Code_Workbench_Files {

	const REST_NAMESPACE = 'code-workbench/v1';

	/**
	 * VS Code FileType values.
	 */
	const TYPE_FILE      = 1;
	const TYPE_DIRECTORY = 2;

	/**
	 * Files larger than this are not opened.
	 */
	const MAX_READ_BYTES = 20 * MB_IN_BYTES;

	/**
	 * Text search skips files larger than this (bundles, minified assets, dumps).
	 */
	const MAX_SEARCH_FILE_BYTES = MB_IN_BYTES;

	/**
	 * Upper bound for the quick open file list and for the text search matches.
	 */
	const MAX_FILES   = 50000;
	const MAX_MATCHES = 5000;

	/**
	 * Bytes of context kept before a match in the search preview, and preview length.
	 */
	const PREVIEW_BEFORE = 40;
	const PREVIEW_LENGTH = 250;

	/**
	 * Registers the REST routes.
	 */
	public static function register_routes() {
		$path = array(
			'type'     => 'string',
			'required' => true,
		);
		$flag = array(
			'type'    => 'boolean',
			'default' => false,
		);
		$list = array(
			'type'    => 'array',
			'items'   => array( 'type' => 'string' ),
			'default' => array(),
		);

		$routes = array(
			'stat'    => array( 'GET', array( 'path' => $path ) ),
			'readdir' => array( 'GET', array( 'path' => $path ) ),
			'read'    => array( 'GET', array( 'path' => $path ) ),
			'write'   => array(
				'POST',
				array(
					'path'      => $path,
					'create'    => $flag,
					'overwrite' => $flag,
				),
			),
			'mkdir'   => array( 'POST', array( 'path' => $path ) ),
			'delete'  => array(
				'POST',
				array(
					'path'      => $path,
					'recursive' => $flag,
				),
			),
			'rename'  => array(
				'POST',
				array(
					'from'      => $path,
					'to'        => $path,
					'overwrite' => $flag,
				),
			),
			'find'    => array(
				'POST',
				array(
					'path'     => $path,
					'includes' => $list,
					'excludes' => $list,
				),
			),
			'search'  => array(
				'POST',
				array(
					'path'            => $path,
					'pattern'         => array(
						'type'     => 'string',
						'required' => true,
					),
					'isRegExp'        => $flag,
					'isCaseSensitive' => $flag,
					'isWordMatch'     => $flag,
					'includes'        => $list,
					'excludes'        => $list,
					'maxResults'      => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => self::MAX_MATCHES,
					),
				),
			),
		);

		$scope = array(
			'type' => array(
				'type'     => 'string',
				'enum'     => array( 'plugin', 'theme' ),
				'required' => true,
			),
			'item' => $path,
		);

		foreach ( $routes as $route => list( $method, $args ) ) {
			register_rest_route(
				self::REST_NAMESPACE,
				'/' . $route,
				array(
					'methods'             => $method,
					'callback'            => array( __CLASS__, 'rest_' . $route ),
					'permission_callback' => array( __CLASS__, 'can_edit' ),
					'args'                => $scope + $args,
				)
			);
		}
	}

	/**
	 * Same capabilities as the core editors. Core already denies them under
	 * DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS and to non super admins on multisite.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function can_edit( $request ) {
		return current_user_can( 'theme' === $request['type'] ? 'edit_themes' : 'edit_plugins' );
	}

	/**
	 * GET /stat
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function rest_stat( $request ) {
		$target = self::resolve( $request, $request['path'] );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		if ( $target['virtual'] ) {
			return array(
				'type'  => self::TYPE_DIRECTORY,
				'size'  => 0,
				'mtime' => 0,
				'ctime' => 0,
			);
		}

		return self::stat( $target['absolute'] );
	}

	/**
	 * GET /readdir: [ [ name, type ], ... ]
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function rest_readdir( $request ) {
		$target = self::resolve( $request, $request['path'] );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		if ( $target['virtual'] ) {
			return array( array( $target['scope']['file'], self::TYPE_FILE ) );
		}

		if ( ! is_dir( $target['absolute'] ) ) {
			return file_exists( $target['absolute'] ) ? self::error( 'not_dir', __( 'Not a folder.', 'code-workbench' ), 400 ) : self::not_found();
		}

		$entries = array();
		foreach ( new FilesystemIterator( $target['absolute'], FilesystemIterator::SKIP_DOTS ) as $item ) {
			$entries[] = array( $item->getFilename(), $item->isDir() ? self::TYPE_DIRECTORY : self::TYPE_FILE );
		}

		return $entries;
	}

	/**
	 * GET /read: the raw file content.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_read( $request ) {
		$file = self::readable_file( $request );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		// Sent as is, outside the JSON envelope, so the file is never held in memory.
		add_filter(
			'rest_pre_serve_request',
			static function () use ( $file ) {
				header( 'Content-Type: application/octet-stream' );
				readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
				return true;
			}
		);

		return new WP_REST_Response();
	}

	/**
	 * The file a read request points to: an existing file of the scope, not too large.
	 *
	 * @param WP_REST_Request $request Request with the scope and "path".
	 * @return string|WP_Error Absolute path.
	 */
	public static function readable_file( $request ) {
		$target = self::resolve( $request, $request['path'] );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$file = $target['absolute'];
		if ( $target['virtual'] || is_dir( $file ) ) {
			return self::error( 'is_dir', __( 'This is a folder.', 'code-workbench' ), 400 );
		}
		if ( ! is_file( $file ) ) {
			return self::not_found();
		}
		if ( filesize( $file ) > self::MAX_READ_BYTES ) {
			return self::error( 'too_large', __( 'The file is too large to be opened in the editor.', 'code-workbench' ), 413 );
		}
		if ( ! is_readable( $file ) ) {
			return self::error( 'failed', __( 'The file could not be read.', 'code-workbench' ) );
		}

		return $file;
	}

	/**
	 * POST /write: the raw request body becomes the file content.
	 *
	 * PHP files are syntax checked first, then saved by core (see save_with_core()).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error The new stat.
	 */
	public static function rest_write( $request ) {
		$target = self::resolve( $request, $request['path'] );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$file   = $target['absolute'];
		$exists = file_exists( $file );

		if ( $target['virtual'] || ( $exists && is_dir( $file ) ) ) {
			return self::error( 'is_dir', __( 'This is a folder.', 'code-workbench' ), 400 );
		}
		if ( '' === $target['relative'] ) {
			return self::forbidden();
		}
		if ( ! $exists && ! $request['create'] ) {
			return self::not_found();
		}
		if ( $exists && $request['create'] && ! $request['overwrite'] ) {
			return self::exists();
		}
		if ( ! is_dir( dirname( $file ) ) ) {
			return self::not_found();
		}

		// wp_opcache_invalidate() and wp_edit_theme_plugin_file().
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$content = $request->get_body();
		$saved   = null;

		if ( self::is_php( $file ) ) {
			try {
				if ( function_exists( 'token_get_all' ) ) {
					token_get_all( $content, TOKEN_PARSE );
				}
			} catch ( CompileError $e ) {
				/* translators: 1: line number, 2: PHP error message. */
				return self::error( 'php_error', sprintf( __( 'PHP syntax error on line %1$d: %2$s', 'code-workbench' ), $e->getLine(), $e->getMessage() ), 422 );
			}

			// A new file is created empty and saved by core like the others: a plugin or theme
			// may load every file of a folder.
			if ( ! $exists && false === file_put_contents( $file, '' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				return self::error( 'failed', __( 'The file could not be written.', 'code-workbench' ) );
			}

			$saved = self::save_with_core( $target, $content );
			if ( is_wp_error( $saved ) ) {
				if ( ! $exists ) {
					unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
				return $saved;
			}
		}

		if ( null === $saved ) {
			if ( false === file_put_contents( $file, $content ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				return self::error( 'failed', __( 'The file could not be written.', 'code-workbench' ) );
			}
			wp_opcache_invalidate( $file, true );
		}

		clearstatcache( true, $file );

		return self::stat( $file );
	}

	/**
	 * POST /mkdir
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function rest_mkdir( $request ) {
		$target = self::resolve_entry( $request, $request['path'] );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		if ( file_exists( $target['absolute'] ) ) {
			return self::exists();
		}
		if ( ! is_dir( dirname( $target['absolute'] ) ) ) {
			return self::not_found();
		}
		if ( ! wp_mkdir_p( $target['absolute'] ) ) {
			return self::error( 'failed', __( 'The folder could not be created.', 'code-workbench' ) );
		}

		return array( 'ok' => true );
	}

	/**
	 * POST /delete
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function rest_delete( $request ) {
		$target = self::resolve_entry( $request, $request['path'] );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$path = $target['absolute'];
		if ( ! file_exists( $path ) && ! is_link( $path ) ) {
			return self::not_found();
		}

		self::forget_php( $path );

		// On an active plugin or theme, a PHP file or a folder is first moved aside: if the site
		// no longer loads without it, it is put back.
		if ( self::is_active( $target['scope'] ) && ( is_dir( $path ) || self::is_php( $path ) ) ) {
			$path = self::move_aside( $path );
			if ( false === $path ) {
				return self::error( 'failed', __( 'Could not delete it.', 'code-workbench' ) );
			}

			$checked = self::check_item( $target['scope'] );
			if ( is_wp_error( $checked ) ) {
				rename( $path, $target['absolute'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				return $checked;
			}
		}

		$deleted = $request['recursive'] ? self::delete_tree( $path ) : ( is_dir( $path ) && ! is_link( $path ) ? rmdir( $path ) : unlink( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $deleted ) {
			if ( $path !== $target['absolute'] ) {
				rename( $path, $target['absolute'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			}
			return self::error( 'failed', __( 'Could not delete it.', 'code-workbench' ) );
		}

		return array( 'ok' => true );
	}

	/**
	 * POST /rename
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function rest_rename( $request ) {
		$from = self::resolve_entry( $request, $request['from'] );
		if ( is_wp_error( $from ) ) {
			return $from;
		}
		$to = self::resolve_entry( $request, $request['to'] );
		if ( is_wp_error( $to ) ) {
			return $to;
		}

		if ( ! file_exists( $from['absolute'] ) && ! is_link( $from['absolute'] ) ) {
			return self::not_found();
		}
		if ( ! is_dir( dirname( $to['absolute'] ) ) ) {
			return self::not_found();
		}

		// What is overwritten is moved aside until the rename succeeded (and the site loads).
		$aside = '';
		if ( file_exists( $to['absolute'] ) ) {
			if ( ! $request['overwrite'] ) {
				return self::exists();
			}
			self::forget_php( $to['absolute'] );
			$aside = self::move_aside( $to['absolute'] );
			if ( false === $aside ) {
				return self::error( 'failed', __( 'Could not rename it.', 'code-workbench' ) );
			}
		}

		self::forget_php( $from['absolute'] );

		// phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename
		if ( ! rename( $from['absolute'], $to['absolute'] ) ) {
			if ( $aside ) {
				rename( $aside, $to['absolute'] );
			}
			return self::error( 'failed', __( 'Could not rename it.', 'code-workbench' ) );
		}

		$risky = $aside || is_dir( $to['absolute'] ) || self::is_php( $from['absolute'] ) || self::is_php( $to['absolute'] );
		if ( $risky && self::is_active( $from['scope'] ) ) {
			$checked = self::check_item( $from['scope'] );
			if ( is_wp_error( $checked ) ) {
				rename( $to['absolute'], $from['absolute'] );
				if ( $aside ) {
					rename( $aside, $to['absolute'] );
				}
				return $checked;
			}
		}
		// phpcs:enable

		if ( $aside ) {
			self::delete_tree( $aside );
		}

		return array( 'ok' => true );
	}

	/**
	 * POST /find: every file under a folder, for Quick Open (VS Code filters them itself).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error Array with 'files' (paths relative to the folder) and 'limitHit'.
	 */
	public static function rest_find( $request ) {
		$target = self::resolve( $request, $request['path'] );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$includes = self::globs_to_regexes( $request['includes'] );
		$files    = array();

		foreach ( self::walk( $target, self::globs_to_regexes( $request['excludes'] ) ) as $relative => $absolute ) {
			if ( $includes && ! self::matches_any( $relative, $includes ) ) {
				continue;
			}
			if ( count( $files ) >= self::MAX_FILES ) {
				return array(
					'files'    => $files,
					'limitHit' => true,
				);
			}
			$files[] = $relative;
		}

		return array(
			'files'    => $files,
			'limitHit' => false,
		);
	}

	/**
	 * POST /search: text search under a folder, one entry per match.
	 *
	 * Each file is read whole and matched once, so files without a match cost a single
	 * preg_match_all(), and a regular expression can match across lines.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error Array with 'matches' and 'limitHit'.
	 */
	public static function rest_search( $request ) {
		$target = self::resolve( $request, $request['path'] );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$pattern = $request['isRegExp'] ? $request['pattern'] : preg_quote( $request['pattern'] );
		if ( $request['isWordMatch'] ) {
			$pattern = '\b(?:' . $pattern . ')\b';
		}
		// \x01 as delimiter: it cannot clash with anything typed in the search box. (*ANYCRLF):
		// "^" and "$" work on Windows and old Mac line endings too.
		$regex = "\x01(*ANYCRLF)" . $pattern . "\x01m" . ( $request['isCaseSensitive'] ? '' : 'i' );
		if ( false === @preg_match( $regex, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$error = error_get_last();
			return self::error( 'invalid_regex', $error ? preg_replace( '/^preg_match\(\): /', '', $error['message'] ) : __( 'Invalid regular expression.', 'code-workbench' ), 400 );
		}
		// UTF-8 files are matched as text: "è" then matches "È" and "." a whole character.
		$utf8 = false === @preg_match( $regex . 'u', '' ) ? '' : $regex . 'u'; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$includes = self::globs_to_regexes( $request['includes'] );
		$max      = min( (int) $request['maxResults'], self::MAX_MATCHES );
		$matches  = array();

		foreach ( self::walk( $target, self::globs_to_regexes( $request['excludes'] ) ) as $relative => $absolute ) {
			if ( $includes && ! self::matches_any( $relative, $includes ) ) {
				continue;
			}

			$size = filesize( $absolute );
			if ( ! $size || $size > self::MAX_SEARCH_FILE_BYTES ) {
				continue;
			}

			$content = file_get_contents( $absolute ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $content || false !== strpos( substr( $content, 0, 1024 ), "\0" ) ) {
				continue;
			}
			if ( ! preg_match_all( $utf8 && mb_check_encoding( $content, 'UTF-8' ) ? $utf8 : $regex, $content, $found, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			$lines  = self::line_starts( $content );
			$cursor = array( -1, 0, 0 );
			foreach ( $found[0] as list( $text, $offset ) ) {
				if ( count( $matches ) >= $max ) {
					return array(
						'matches'  => $matches,
						'limitHit' => true,
					);
				}
				$matches[] = self::search_match( $relative, $content, $lines, $cursor, $offset, strlen( $text ) );
			}
		}

		return array(
			'matches'  => $matches,
			'limitHit' => false,
		);
	}

	/**
	 * Deletes a file or a whole folder tree without following symlinks: a symlinked folder
	 * loses its link, never the folder it points to.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	private static function delete_tree( $path ) {
		if ( is_link( $path ) || is_file( $path ) ) {
			return unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		if ( ! is_dir( $path ) ) {
			return true;
		}

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			$deleted = $item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( ! $deleted ) {
				return false;
			}
		}

		return rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	/**
	 * The plugin or theme the request is scoped to.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error Array with 'type', 'item', 'dir' (its folder), 'file' (single
	 *                        file plugins only) and 'plugin' (plugins only, the get_plugins() key).
	 */
	private static function scope( $request ) {
		$item  = (string) $request['item'];
		$scope = array(
			'type'   => $request['type'],
			'item'   => $item,
			'file'   => '',
			'plugin' => '',
		);

		if ( 'theme' === $scope['type'] ) {
			$theme = wp_get_theme( $item );
			if ( 0 === validate_file( $item ) && $theme->exists() ) {
				return $scope + array( 'dir' => $theme->get_stylesheet_directory() );
			}

			return self::not_found();
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		// A folder can hold more than one plugin: the active one decides whether core checks saves.
		$active = (array) get_option( 'active_plugins', array() );
		foreach ( array_keys( get_plugins() ) as $plugin ) {
			if ( $plugin === $item && false === strpos( $item, '/' ) ) {
				return array(
					'file'   => $item,
					'plugin' => $plugin,
					'dir'    => WP_PLUGIN_DIR,
				) + $scope;
			}
			if ( 0 === strpos( $plugin, $item . '/' ) && ( '' === $scope['plugin'] || in_array( $plugin, $active, true ) ) ) {
				$scope['plugin'] = $plugin;
				$scope['dir']    = WP_PLUGIN_DIR . '/' . $item;
			}
		}

		return '' === $scope['plugin'] ? self::not_found() : $scope;
	}

	/**
	 * Whether core checks the PHP saves of this plugin or theme for fatal errors: the test of
	 * wp_edit_theme_plugin_file().
	 *
	 * @param array $scope Scope.
	 * @return bool
	 */
	private static function is_active( $scope ) {
		if ( 'theme' === $scope['type'] ) {
			return in_array( $scope['item'], array( get_stylesheet(), get_template() ), true );
		}

		return in_array( $scope['plugin'], (array) get_option( 'active_plugins', array() ), true );
	}

	/**
	 * Whether a path is a PHP file.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private static function is_php( $path ) {
		return 'php' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	}

	/**
	 * Moves a file or folder aside, under a hidden name next to it, so it can be put back.
	 *
	 * @param string $path Absolute path.
	 * @return string|false The new path.
	 */
	private static function move_aside( $path ) {
		$aside = dirname( $path ) . '/.code-workbench-' . wp_generate_password( 12, false );

		return rename( $path, $aside ) ? $aside : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
	}

	/**
	 * Drops the PHP files of a file or folder from OPcache, before they are deleted or moved:
	 * OPcache can no longer find them afterwards, and would keep running the old code (and
	 * fool the check of check_item()).
	 *
	 * @param string $path Absolute path.
	 */
	private static function forget_php( $path ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( ! is_dir( $path ) ) {
			wp_opcache_invalidate( $path, true );
			return;
		}

		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			wp_opcache_invalidate( $file->getPathname(), true );
		}
	}

	/**
	 * Maps a workspace path to the file system, within the request scope.
	 *
	 * Paths are validated segment by segment, so no "..", "." or backslash can climb out of
	 * the scope. Symlinks inside it are followed: they were put there by the site owner.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $path    Workspace path, e.g. "/akismet/akismet.php".
	 * @return array|WP_Error Array with 'scope', 'relative' (to the item folder), 'absolute'
	 *                        and 'virtual' (the folder of a single file plugin).
	 */
	private static function resolve( $request, $path ) {
		$scope = self::scope( $request );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		$segments = array_values( array_filter( explode( '/', (string) $path ), 'strlen' ) );
		foreach ( $segments as $segment ) {
			if ( '.' === $segment || '..' === $segment || false !== strpbrk( $segment, "\\\0" ) ) {
				return self::error( 'invalid_path', __( 'Invalid path.', 'code-workbench' ), 400 );
			}
		}

		// VS Code probes paths like "/.vscode/settings.json": outside the item nothing exists.
		$path = implode( '/', $segments );
		if ( $path !== $scope['item'] && 0 !== strpos( $path, $scope['item'] . '/' ) ) {
			return self::not_found();
		}

		$relative = (string) substr( $path, strlen( $scope['item'] ) + 1 );
		$target   = array(
			'scope'    => $scope,
			'relative' => $relative,
			'absolute' => '' === $relative ? $scope['dir'] : $scope['dir'] . '/' . $relative,
			'virtual'  => false,
		);

		if ( $scope['file'] ) {
			if ( '' === $relative ) {
				$target['virtual'] = true;
			} elseif ( $relative !== $scope['file'] ) {
				return self::not_found();
			}
		}

		return $target;
	}

	/**
	 * Resolves a path that is going to be created, renamed or removed: never the plugin or
	 * theme folder itself, never in a single file plugin (its file can only be edited).
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $path    Workspace path.
	 * @return array|WP_Error
	 */
	private static function resolve_entry( $request, $path ) {
		$target = self::resolve( $request, $path );
		if ( ! is_wp_error( $target ) && ( '' === $target['relative'] || $target['scope']['file'] ) ) {
			return self::forbidden();
		}

		return $target;
	}

	/**
	 * Saves a PHP file through wp_edit_theme_plugin_file(), the function behind the core
	 * editors. When the plugin or theme is active, core loads the site after the write and
	 * rolls the change back on a fatal error, so a typo in functions.php cannot lock the admin
	 * (and this editor) out.
	 *
	 * @param array  $target  Resolved path.
	 * @param string $content New content.
	 * @return true|WP_Error|null Null when core does not edit that file (e.g. in a hidden
	 *                            folder), so the caller writes it directly.
	 */
	private static function save_with_core( $target, $content ) {
		$result = self::edit_with_core( $target['scope'], $target['relative'], $content );
		if ( true === $result ) {
			return true;
		}

		// Files outside the core editable list are not unsafe, core just does not edit them.
		if ( in_array( $result->get_error_code(), array( 'bad_plugin_file_path', 'disallowed_theme_file', 'illegal_file_type' ), true ) ) {
			return null;
		}

		return self::core_error( $result );
	}

	/**
	 * Whether the site still loads after a PHP file or a folder of an active plugin or theme was
	 * deleted or renamed. Core only checks saves, so the main file of the plugin (functions.php
	 * or index.php of a theme) is saved again, unchanged, through core.
	 *
	 * @param array $scope Scope of an active plugin or theme.
	 * @return true|WP_Error
	 */
	private static function check_item( $scope ) {
		$relative = 'theme' === $scope['type'] ? 'functions.php' : substr( $scope['plugin'], strlen( $scope['item'] ) + 1 );
		if ( 'theme' === $scope['type'] && ! is_file( $scope['dir'] . '/' . $relative ) ) {
			$relative = 'index.php';
		}

		// Without its main file, WordPress deactivates the plugin or the theme by itself.
		$file = $scope['dir'] . '/' . $relative;
		if ( ! is_file( $file ) ) {
			return true;
		}

		$mtime  = filemtime( $file );
		$result = self::edit_with_core( $scope, $relative, (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		// The content did not change: VS Code must not see the file as modified.
		touch( $file, $mtime ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch
		clearstatcache( true, $file );

		return true === $result ? true : self::core_error( $result );
	}

	/**
	 * Writes a file of a plugin or theme with wp_edit_theme_plugin_file().
	 *
	 * @param array  $scope    Scope.
	 * @param string $relative Path relative to the plugin or theme folder.
	 * @param string $content  New content.
	 * @return true|WP_Error
	 */
	private static function edit_with_core( $scope, $relative, $content ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( 'theme' === $scope['type'] ) {
			$args = array(
				'theme' => $scope['item'],
				'file'  => $relative,
				'nonce' => wp_create_nonce( 'edit-theme_' . $scope['item'] . '_' . $relative ),
			);
		} else {
			$file = $scope['file'] ? $scope['file'] : $scope['item'] . '/' . $relative;
			$args = array(
				'plugin' => $scope['plugin'],
				'file'   => $file,
				'nonce'  => wp_create_nonce( 'edit-plugin_' . $file ),
			);
		}

		// Core leaves folders like vendor/ out of the files it edits, but they are loaded like the
		// others: it checks this file too.
		$include = static function ( $exclusions ) use ( $relative ) {
			return array_diff( (array) $exclusions, explode( '/', $relative ) );
		};
		add_filter( 'plugin_files_exclusions', $include );
		add_filter( 'theme_scandir_exclusions', $include );

		// Core checks the file by loading wp-admin with the cookies of this request, but a REST
		// request has no wp-admin auth cookie (its path is /wp-admin): the loopback would land
		// on the login screen and the change would be reverted. Hand it one for this session,
		// or for a short session of its own when the request has none (MCP access tokens).
		$cookie  = is_ssl() ? SECURE_AUTH_COOKIE : AUTH_COOKIE;
		$own     = empty( $_COOKIE[ $cookie ] );
		$session = '';
		if ( $own ) {
			$user  = get_current_user_id();
			$token = wp_get_session_token();
			if ( '' === $token ) {
				$session = WP_Session_Tokens::get_instance( $user )->create( time() + HOUR_IN_SECONDS );
				$token   = $session;
			}
			$_COOKIE[ $cookie ] = wp_generate_auth_cookie( $user, time() + HOUR_IN_SECONDS, is_ssl() ? 'secure_auth' : 'auth', $token );
		}

		$result = wp_edit_theme_plugin_file( $args + array( 'newcontent' => $content ) );

		remove_filter( 'plugin_files_exclusions', $include );
		remove_filter( 'theme_scandir_exclusions', $include );
		if ( $own ) {
			unset( $_COOKIE[ $cookie ] );
		}
		if ( $session ) {
			WP_Session_Tokens::get_instance( get_current_user_id() )->destroy( $session );
		}

		return $result;
	}

	/**
	 * REST error for an error of wp_edit_theme_plugin_file().
	 *
	 * @param WP_Error $result Core error.
	 * @return WP_Error
	 */
	private static function core_error( $result ) {
		$message = $result->get_error_message() ? $result->get_error_message() : $result->get_error_code();
		$data    = $result->get_error_data();
		if ( 'php_error' === $result->get_error_code() && isset( $data['line'] ) ) {
			/* translators: 1: PHP error message, 2: line number. */
			$message = sprintf( __( '%1$s (line %2$d). The change was reverted.', 'code-workbench' ), $message, $data['line'] );
		}

		return self::error( $result->get_error_code(), $message, 422 );
	}

	/**
	 * Stat in the shape VS Code expects (times in milliseconds).
	 *
	 * @param string $path Absolute path.
	 * @return array|WP_Error
	 */
	private static function stat( $path ) {
		$stat = file_exists( $path ) ? stat( $path ) : false;
		if ( ! $stat ) {
			return self::not_found();
		}

		return array(
			'type'  => is_dir( $path ) ? self::TYPE_DIRECTORY : self::TYPE_FILE,
			'size'  => $stat['size'],
			'mtime' => $stat['mtime'] * 1000,
			'ctime' => $stat['ctime'] * 1000,
		);
	}

	/**
	 * Yields every file under a resolved folder, as relative path => absolute path.
	 *
	 * Excluded folders are pruned, not filtered, so "node_modules" is never walked. Symlinked
	 * folders are followed once, which keeps symlink loops from walking forever.
	 *
	 * @param array $target   Resolved folder.
	 * @param array $excludes Regexes of excluded paths, relative to the folder.
	 * @return Generator
	 */
	private static function walk( $target, $excludes ) {
		if ( $target['virtual'] ) {
			yield $target['scope']['file'] => $target['scope']['dir'] . '/' . $target['scope']['file'];
			return;
		}

		$stack   = array( array( $target['absolute'], '' ) );
		$visited = array();

		while ( $stack ) {
			list( $dir, $prefix ) = array_pop( $stack );

			$real = realpath( $dir );
			if ( false === $real || isset( $visited[ $real ] ) ) {
				continue;
			}
			$visited[ $real ] = true;

			try {
				$items = new FilesystemIterator( $dir, FilesystemIterator::SKIP_DOTS );
			} catch ( UnexpectedValueException $e ) {
				continue; // Unreadable folder.
			}

			foreach ( $items as $item ) {
				$relative = '' === $prefix ? $item->getFilename() : $prefix . '/' . $item->getFilename();
				if ( self::matches_any( $relative, $excludes ) ) {
					continue;
				}
				if ( $item->isDir() ) {
					$stack[] = array( $item->getPathname(), $relative );
				} else {
					yield $relative => $item->getPathname();
				}
			}
		}
	}

	/**
	 * Converts VS Code glob patterns ("**\/node_modules", "*.{php,js}") to regexes. A pattern
	 * also matches everything below a matching folder.
	 *
	 * @param string[] $globs Glob patterns.
	 * @return string[]
	 */
	private static function globs_to_regexes( $globs ) {
		$regexes = array();

		foreach ( (array) $globs as $glob ) {
			$glob  = ltrim( (string) $glob, '/' );
			$regex = '';
			$group = false;
			$len   = strlen( $glob );

			for ( $i = 0; $i < $len; $i++ ) {
				$char = $glob[ $i ];
				if ( '*' === $char && isset( $glob[ $i + 1 ] ) && '*' === $glob[ $i + 1 ] ) {
					++$i;
					if ( isset( $glob[ $i + 1 ] ) && '/' === $glob[ $i + 1 ] ) {
						++$i;
						$regex .= '(?:.*/)?';
					} else {
						$regex .= '.*';
					}
				} elseif ( '*' === $char ) {
					$regex .= '[^/]*';
				} elseif ( '?' === $char ) {
					$regex .= '[^/]';
				} elseif ( '{' === $char ) {
					$regex .= '(?:';
					$group  = true;
				} elseif ( '}' === $char && $group ) {
					$regex .= ')';
					$group  = false;
				} elseif ( ',' === $char && $group ) {
					$regex .= '|';
				} else {
					$regex .= preg_quote( $char, '#' );
				}
			}

			if ( '' !== $regex ) {
				$regexes[] = '#^' . $regex . '(?:/.*)?$#';
			}
		}

		return $regexes;
	}

	/**
	 * Whether a path matches one of the regexes.
	 *
	 * @param string   $path    Relative path.
	 * @param string[] $regexes Regexes.
	 * @return bool
	 */
	private static function matches_any( $path, $regexes ) {
		foreach ( $regexes as $regex ) {
			if ( preg_match( $regex, $path ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * One search match, with a one line preview around its start. Positions are counted in
	 * characters (VS Code positions), offsets come from PCRE in bytes.
	 *
	 * @param string $path    Relative path.
	 * @param string $content File content.
	 * @param int[]  $lines   Byte offset of each line start (see line_starts()).
	 * @param array  $cursor  Last position computed in the file (see position()).
	 * @param int    $offset  Match byte offset.
	 * @param int    $length  Match byte length.
	 * @return array
	 */
	private static function search_match( $path, $content, $lines, &$cursor, $offset, $length ) {
		list( $line, $start )   = self::position( $content, $lines, $cursor, $offset );
		list( $end_line, $end ) = self::position( $content, $lines, $cursor, $offset + $length );

		$from = max( $lines[ $line ], $offset - self::PREVIEW_BEFORE );
		// Never cut a UTF-8 character in half.
		while ( $from > $lines[ $line ] && 0x80 === ( ord( $content[ $from ] ) & 0xC0 ) ) {
			--$from;
		}
		$preview = mb_strcut( substr( $content, $from, self::PREVIEW_LENGTH ), 0, self::PREVIEW_LENGTH, 'UTF-8' );
		$preview = substr( $preview, 0, strcspn( $preview, "\r\n" ) );
		$skipped = mb_strlen( substr( $content, $lines[ $line ], $from - $lines[ $line ] ), 'UTF-8' );
		$length  = mb_strlen( $preview, 'UTF-8' );

		return array(
			'path'         => $path,
			'line'         => $line,
			'start'        => $start,
			'endLine'      => $end_line,
			'end'          => $end,
			'preview'      => $preview,
			// The match in the preview: up to its end, or the end of the preview.
			'previewStart' => min( $start - $skipped, $length ),
			'previewEnd'   => $line === $end_line ? min( $end - $skipped, $length ) : $length,
		);
	}

	/**
	 * Byte offset of each line start.
	 *
	 * @param string $content Text.
	 * @return int[]
	 */
	private static function line_starts( $content ) {
		$starts = array( 0 );
		preg_match_all( '/\r\n|\r|\n/', $content, $breaks, PREG_OFFSET_CAPTURE );
		foreach ( $breaks[0] as list( $break, $offset ) ) {
			$starts[] = $offset + strlen( $break );
		}

		return $starts;
	}

	/**
	 * Line and character column of a byte offset. Matches come in order, so the column is
	 * counted from the previous position on the same line: a long minified line with many
	 * matches is read once, not once per match.
	 *
	 * @param string $content Text.
	 * @param int[]  $lines   Line starts.
	 * @param array  $cursor  Line, byte offset and column of the previous position.
	 * @param int    $offset  Byte offset.
	 * @return int[] Line and column, from 0.
	 */
	private static function position( $content, $lines, &$cursor, $offset ) {
		$low  = 0;
		$high = count( $lines ) - 1;
		while ( $low < $high ) {
			$middle = (int) ( ( $low + $high + 1 ) / 2 );
			if ( $lines[ $middle ] <= $offset ) {
				$low = $middle;
			} else {
				$high = $middle - 1;
			}
		}

		if ( $cursor[0] !== $low || $cursor[1] > $offset ) {
			$cursor = array( $low, $lines[ $low ], 0 );
		}
		$cursor[2] += mb_strlen( substr( $content, $cursor[1], $offset - $cursor[1] ), 'UTF-8' );
		$cursor[1]  = $offset;

		return array( $low, $cursor[2] );
	}

	/**
	 * REST error; the code is what the VS Code extension maps to a FileSystemError.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message shown by VS Code.
	 * @param int    $status  HTTP status.
	 * @return WP_Error
	 */
	private static function error( $code, $message, $status = 500 ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Not found error.
	 *
	 * @return WP_Error
	 */
	private static function not_found() {
		return self::error( 'not_found', __( 'File not found.', 'code-workbench' ), 404 );
	}

	/**
	 * Already exists error.
	 *
	 * @return WP_Error
	 */
	private static function exists() {
		return self::error( 'exists', __( 'A file or folder with this name already exists.', 'code-workbench' ), 409 );
	}

	/**
	 * Forbidden change error.
	 *
	 * @return WP_Error
	 */
	private static function forbidden() {
		return self::error( 'forbidden', __( 'This cannot be changed from the editor.', 'code-workbench' ), 403 );
	}
}
