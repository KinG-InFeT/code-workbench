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
	 * GET /read: the file content, base64 encoded.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function rest_read( $request ) {
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

		$content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $content ) {
			return self::error( 'failed', __( 'The file could not be read.', 'code-workbench' ) );
		}

		return array( 'content' => base64_encode( $content ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * POST /write: the raw request body becomes the file content.
	 *
	 * PHP files are syntax checked first. Existing PHP files are then saved by core (see
	 * save_with_core()).
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

		if ( 'php' === strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) ) {
			try {
				if ( function_exists( 'token_get_all' ) ) {
					token_get_all( $content, TOKEN_PARSE );
				}
			} catch ( CompileError $e ) {
				/* translators: 1: line number, 2: PHP error message. */
				return self::error( 'php_error', sprintf( __( 'PHP syntax error on line %1$d: %2$s', 'code-workbench' ), $e->getLine(), $e->getMessage() ), 422 );
			}

			if ( $exists ) {
				$saved = self::save_with_core( $target, $content );
				if ( is_wp_error( $saved ) ) {
					return $saved;
				}
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

		$deleted = $request['recursive'] ? self::delete_tree( $path ) : ( is_dir( $path ) && ! is_link( $path ) ? rmdir( $path ) : unlink( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $deleted ) {
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
		if ( file_exists( $to['absolute'] ) ) {
			if ( ! $request['overwrite'] ) {
				return self::exists();
			}
			self::delete_tree( $to['absolute'] );
		}

		if ( ! rename( $from['absolute'], $to['absolute'] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			return self::error( 'failed', __( 'Could not rename it.', 'code-workbench' ) );
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
	 * Each file is read whole and matched once before being split into lines, so files
	 * without a match cost a single preg_match().
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
		// \x01 as delimiter: it cannot clash with anything typed in the search box.
		$regex = "\x01" . $pattern . "\x01m" . ( $request['isCaseSensitive'] ? '' : 'i' );
		if ( false === @preg_match( $regex, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$error = error_get_last();
			return self::error( 'invalid_regex', $error ? preg_replace( '/^preg_match\(\): /', '', $error['message'] ) : __( 'Invalid regular expression.', 'code-workbench' ), 400 );
		}

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
			if ( false === $content || false !== strpos( substr( $content, 0, 1024 ), "\0" ) || ! preg_match( $regex, $content ) ) {
				continue;
			}

			foreach ( preg_split( '/\r\n|\r|\n/', $content ) as $line_number => $line ) {
				if ( ! preg_match_all( $regex, $line, $found, PREG_OFFSET_CAPTURE ) ) {
					continue;
				}

				foreach ( $found[0] as list( $text, $offset ) ) {
					if ( count( $matches ) >= $max ) {
						return array(
							'matches'  => $matches,
							'limitHit' => true,
						);
					}
					$matches[] = self::search_match( $relative, $line_number, $line, $offset, strlen( $text ) );
				}
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

		foreach ( array_keys( get_plugins() ) as $plugin ) {
			if ( $plugin === $item && false === strpos( $item, '/' ) ) {
				return array(
					'file'   => $item,
					'plugin' => $plugin,
					'dir'    => WP_PLUGIN_DIR,
				) + $scope;
			}
			if ( 0 === strpos( $plugin, $item . '/' ) ) {
				return array(
					'plugin' => $plugin,
					'dir'    => WP_PLUGIN_DIR . '/' . $item,
				) + $scope;
			}
		}

		return self::not_found();
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
	 * Saves an existing PHP file through wp_edit_theme_plugin_file(), the function behind the
	 * core editors. When the plugin or theme is active, core loads the site after the write
	 * and rolls the change back on a fatal error, so a typo in functions.php cannot lock the
	 * admin (and this editor) out.
	 *
	 * @param array  $target  Resolved path.
	 * @param string $content New content.
	 * @return true|WP_Error|null Null when core does not edit that file (e.g. under vendor/),
	 *                            so the caller writes it directly.
	 */
	private static function save_with_core( $target, $content ) {
		$scope = $target['scope'];

		if ( 'theme' === $scope['type'] ) {
			$args = array(
				'theme' => $scope['item'],
				'file'  => $target['relative'],
				'nonce' => wp_create_nonce( 'edit-theme_' . $scope['item'] . '_' . $target['relative'] ),
			);
		} else {
			$file = $scope['file'] ? $scope['file'] : $scope['item'] . '/' . $target['relative'];
			$args = array(
				'plugin' => $scope['plugin'],
				'file'   => $file,
				'nonce'  => wp_create_nonce( 'edit-plugin_' . $file ),
			);
		}

		// Core checks the file by loading wp-admin with the cookies of this request, but a REST
		// request has no wp-admin auth cookie (its path is /wp-admin): the loopback would land
		// on the login screen and the change would be reverted. Hand it one for this session.
		$cookie = is_ssl() ? SECURE_AUTH_COOKIE : AUTH_COOKIE;
		if ( empty( $_COOKIE[ $cookie ] ) ) {
			$_COOKIE[ $cookie ] = wp_generate_auth_cookie( get_current_user_id(), time() + HOUR_IN_SECONDS, is_ssl() ? 'secure_auth' : 'auth', wp_get_session_token() );
		}

		$result = wp_edit_theme_plugin_file( $args + array( 'newcontent' => $content ) );
		if ( true === $result ) {
			return true;
		}

		// Files outside the core editable list are not unsafe, core just does not edit them.
		if ( in_array( $result->get_error_code(), array( 'bad_plugin_file_path', 'disallowed_theme_file', 'illegal_file_type' ), true ) ) {
			return null;
		}

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
	 * One search match, with a short preview around it. Columns are counted in characters
	 * (VS Code positions), offsets come from PCRE in bytes.
	 *
	 * @param string $path   Relative path.
	 * @param int    $number Line number, from 0.
	 * @param string $line   Line text.
	 * @param int    $offset Match byte offset.
	 * @param int    $length Match byte length.
	 * @return array
	 */
	private static function search_match( $path, $number, $line, $offset, $length ) {
		$start = max( 0, $offset - self::PREVIEW_BEFORE );
		// Never cut a UTF-8 character in half.
		while ( $start > 0 && 0x80 === ( ord( $line[ $start ] ) & 0xC0 ) ) {
			--$start;
		}
		$preview = mb_strcut( $line, $start, self::PREVIEW_LENGTH, 'UTF-8' );
		$column  = mb_strlen( substr( $line, 0, $offset ), 'UTF-8' );

		return array(
			'path'         => $path,
			'line'         => $number,
			'start'        => $column,
			'end'          => $column + mb_strlen( substr( $line, $offset, $length ), 'UTF-8' ),
			'preview'      => $preview,
			'previewStart' => mb_strlen( substr( $line, 0, $start ), 'UTF-8' ),
		);
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
