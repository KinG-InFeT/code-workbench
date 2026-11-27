<?php
/**
 * MCP server: AI assistants work on the files of the plugins and themes.
 *
 * @package Code_Workbench
 */

defined( 'ABSPATH' ) || exit;

/**
 * Model Context Protocol server (Streamable HTTP, JSON answers only) at
 * /wp-json/code-workbench/v1/mcp, authenticated by access tokens.
 *
 * A token is sent as "Authorization: Bearer <token>", or in the URL (/mcp/<token>) for the
 * clients that cannot set headers (claude.ai, ChatGPT). Requests run as the user who created
 * the token, so they need the capabilities of the core editors, and a read token gets the
 * read tools only. The file tools go through the REST routes of Code_Workbench_Files: same
 * scope isolation, PHP syntax check and rollback of the changes that break the site.
 */
class Code_Workbench_MCP {

	/**
	 * Option holding the tokens. Network wide on multisite, like the files they give access to.
	 */
	const OPTION = 'code_workbench_mcp_tokens';

	/**
	 * Protocol versions, newest first. A client asking for another one gets the newest.
	 */
	const PROTOCOL_VERSIONS = array( '2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05' );

	/**
	 * Limits of what a tool returns to the assistant.
	 */
	const MAX_TEXT_BYTES  = 200 * KB_IN_BYTES;
	const MAX_LINES       = 2000;
	const MAX_LIST        = 1000;
	const MAX_IMAGE_BYTES = 5 * MB_IN_BYTES;

	/**
	 * Token of the current request.
	 *
	 * @var array
	 */
	private static $token;

	/**
	 * Registers the MCP endpoint and the routes the editor manages the tokens with.
	 */
	public static function register_routes() {
		register_rest_route(
			Code_Workbench_Files::REST_NAMESPACE,
			'/mcp(?:/(?P<key>[A-Za-z0-9_]+))?',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'handle' ),
					'permission_callback' => array( __CLASS__, 'authenticate' ),
				),
				array(
					// No server-sent events stream and no sessions: each answer comes with its request.
					'methods'             => array( 'GET', 'DELETE' ),
					'callback'            => array( __CLASS__, 'not_allowed' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		// Token management, for the VS Code extension: cookie and nonce, like the file routes.
		$manage = array( __CLASS__, 'can_manage' );
		$id     = '/mcp-tokens/(?P<id>[a-z0-9]{8})';
		register_rest_route(
			Code_Workbench_Files::REST_NAMESPACE,
			'/mcp-tokens',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'rest_list_tokens' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'rest_create_token' ),
					'permission_callback' => $manage,
					'args'                => array(
						'name'   => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'access' => array(
							'type'     => 'string',
							'enum'     => array( 'read', 'write' ),
							'required' => true,
						),
					),
				),
			)
		);
		register_rest_route(
			Code_Workbench_Files::REST_NAMESPACE,
			$id,
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'rest_revoke_token' ),
				'permission_callback' => $manage,
			)
		);
		register_rest_route(
			Code_Workbench_Files::REST_NAMESPACE,
			$id . '/regenerate',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_regenerate_token' ),
				'permission_callback' => $manage,
			)
		);
	}

	/**
	 * Who manages the tokens: the users of the file editors.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'edit_plugins' ) || current_user_can( 'edit_themes' );
	}

	/**
	 * GET /mcp-tokens: the tokens (never their secret) and what the clients need to connect.
	 *
	 * @return array
	 */
	public static function rest_list_tokens() {
		$tokens = array();
		foreach ( self::tokens() as $id => $token ) {
			$owner    = get_userdata( $token['user'] );
			$tokens[] = array(
				'id'      => $id,
				'name'    => $token['name'],
				'access'  => $token['access'],
				'owner'   => $owner ? $owner->display_name : '',
				'mine'    => get_current_user_id() === $token['user'],
				'created' => $token['created'],
				'used'    => $token['used'],
			);
		}

		return array(
			'endpoint' => rest_url( Code_Workbench_Files::REST_NAMESPACE . '/mcp' ),
			'server'   => sanitize_title( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			'site'     => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'tokens'   => $tokens,
		);
	}

	/**
	 * POST /mcp-tokens: a new token, shown once.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function rest_create_token( $request ) {
		$name = mb_substr( trim( $request['name'] ), 0, 100 );
		$name = '' !== $name ? $name : __( 'AI assistant', 'code-workbench' );

		return array(
			'name'  => $name,
			'token' => self::create_token( $name, $request['access'] ),
		);
	}

	/**
	 * POST /mcp-tokens/<id>/regenerate: a new secret for a token of the current user (the token
	 * acts as its owner, so nobody else can get one).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function rest_regenerate_token( $request ) {
		$tokens = self::tokens();
		if ( ! isset( $tokens[ $request['id'] ] ) ) {
			return new WP_Error( 'not_found', __( 'This access token no longer exists.', 'code-workbench' ), array( 'status' => 404 ) );
		}
		$token = $tokens[ $request['id'] ];
		if ( get_current_user_id() !== $token['user'] ) {
			return new WP_Error( 'forbidden', __( 'Only the user who created an access token can regenerate it.', 'code-workbench' ), array( 'status' => 403 ) );
		}

		return array(
			'name'  => $token['name'],
			'token' => self::create_token( $token['name'], $token['access'], $request['id'] ),
		);
	}

	/**
	 * DELETE /mcp-tokens/<id>: revokes a token. Any user of the editors can, so that a leaked
	 * token never waits for its owner.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function rest_revoke_token( $request ) {
		$tokens = self::tokens();
		if ( ! isset( $tokens[ $request['id'] ] ) ) {
			return new WP_Error( 'not_found', __( 'This access token no longer exists.', 'code-workbench' ), array( 'status' => 404 ) );
		}

		unset( $tokens[ $request['id'] ] );
		update_network_option( null, self::OPTION, $tokens );

		return array( 'ok' => true );
	}

	/**
	 * Access tokens, by id.
	 *
	 * @return array[] Each with name, access ("read" or "write"), user, hash, created, used.
	 */
	private static function tokens() {
		return (array) get_network_option( null, self::OPTION, array() );
	}

	/**
	 * Creates a token, or gives a new secret to an existing one. Only its hash is stored.
	 *
	 * @param string $name   Label.
	 * @param string $access "read" or "write".
	 * @param string $id     Id of the token to regenerate.
	 * @return string The token, to show once.
	 */
	private static function create_token( $name, $access, $id = '' ) {
		$tokens = self::tokens();
		$id     = '' !== $id ? $id : strtolower( wp_generate_password( 8, false ) );
		$key    = 'cw_' . $id . wp_generate_password( 40, false );

		$tokens[ $id ] = array(
			'name'    => $name,
			'access'  => 'write' === $access ? 'write' : 'read',
			'user'    => get_current_user_id(),
			'hash'    => hash( 'sha256', $key ),
			'created' => time(),
			'used'    => 0,
		);
		update_network_option( null, self::OPTION, $tokens );

		return $key;
	}

	/**
	 * Permission callback: a valid token whose owner can still edit plugins or themes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function authenticate( $request ) {
		$key = (string) $request['key'];
		if ( '' === $key && preg_match( '/^Bearer\s+(\S+)$/i', (string) $request->get_header( 'authorization' ), $match ) ) {
			$key = $match[1];
		}

		$tokens = self::tokens();
		$id     = 0 === strpos( $key, 'cw_' ) ? substr( $key, 3, 8 ) : '';
		if ( '' === $id || ! isset( $tokens[ $id ] ) || ! hash_equals( $tokens[ $id ]['hash'], hash( 'sha256', $key ) ) ) {
			return new WP_Error( 'code_workbench_token', __( 'Invalid or revoked access token.', 'code-workbench' ), array( 'status' => 401 ) );
		}

		wp_set_current_user( $tokens[ $id ]['user'] );
		if ( ! current_user_can( 'edit_plugins' ) && ! current_user_can( 'edit_themes' ) ) {
			return new WP_Error( 'code_workbench_token', __( 'The owner of this access token cannot edit plugins or themes.', 'code-workbench' ), array( 'status' => 403 ) );
		}

		self::$token = $tokens[ $id ];

		// "Last used" is precise to the minute: no database write on every call.
		if ( time() - $tokens[ $id ]['used'] > MINUTE_IN_SECONDS ) {
			$tokens[ $id ]['used'] = time();
			update_network_option( null, self::OPTION, $tokens );
		}

		return true;
	}

	/**
	 * GET and DELETE: this server keeps no stream nor session.
	 *
	 * @return WP_REST_Response
	 */
	public static function not_allowed() {
		return new WP_REST_Response( null, 405, array( 'Allow' => 'POST' ) );
	}

	/**
	 * POST: one JSON-RPC message.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_REST_Response
	 */
	public static function handle( $request ) {
		$message = json_decode( $request->get_body(), true );
		if ( ! is_array( $message ) || ! isset( $message['method'] ) ) {
			return array(
				'jsonrpc' => '2.0',
				'id'      => null,
				'error'   => array(
					'code'    => -32600,
					'message' => 'Invalid request',
				),
			);
		}

		// Notifications (no id) get no answer.
		if ( ! array_key_exists( 'id', $message ) ) {
			return new WP_REST_Response( null, 202 );
		}

		$params = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : array();
		switch ( $message['method'] ) {
			case 'initialize':
				$requested = isset( $params['protocolVersion'] ) ? $params['protocolVersion'] : '';
				$result    = array(
					'protocolVersion' => in_array( $requested, self::PROTOCOL_VERSIONS, true ) ? $requested : self::PROTOCOL_VERSIONS[0],
					'capabilities'    => array( 'tools' => new stdClass() ),
					'serverInfo'      => array(
						'name'    => 'code-workbench',
						'title'   => 'Code Workbench',
						'version' => CODE_WORKBENCH_VERSION,
					),
					'instructions'    => sprintf(
						'Files of the WordPress plugins and themes of %s (%s). Start with list_projects: the other tools take the type and slug of one plugin or theme, and paths inside its folder. Nothing outside these folders can be reached. PHP files are checked for syntax errors before they are saved, and on an active plugin or theme a change that breaks the site is reverted. %s',
						wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
						home_url( '/' ),
						self::can_write() ? 'Check the result on the site with fetch_page.' : 'This access token is read only.'
					),
				);
				break;

			case 'ping':
				$result = new stdClass();
				break;

			case 'tools/list':
				$result = array( 'tools' => array() );
				foreach ( self::tools() as $name => $tool ) {
					$result['tools'][] = array(
						'name'        => $name,
						'description' => $tool['description'],
						'inputSchema' => array(
							'type'       => 'object',
							'properties' => $tool['properties'] ? $tool['properties'] : new stdClass(),
							'required'   => $tool['required'],
						),
						'annotations' => array(
							'readOnlyHint'    => ! $tool['write'],
							'destructiveHint' => ! empty( $tool['destructive'] ),
							'openWorldHint'   => false,
						),
					);
				}
				break;

			case 'tools/call':
				$result = self::call_tool( isset( $params['name'] ) ? (string) $params['name'] : '', isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array() );
				break;

			default:
				return array(
					'jsonrpc' => '2.0',
					'id'      => $message['id'],
					'error'   => array(
						'code'    => -32601,
						'message' => 'Method not found',
					),
				);
		}

		return array(
			'jsonrpc' => '2.0',
			'id'      => $message['id'],
			'result'  => $result,
		);
	}

	/**
	 * Whether the token of the request can change files.
	 *
	 * @return bool
	 */
	private static function can_write() {
		return 'write' === self::$token['access'];
	}

	/**
	 * Tools the token can use.
	 *
	 * @return array[] By name: description, properties, required, write, destructive.
	 */
	private static function tools() {
		$type = array(
			'type'        => 'string',
			'enum'        => array( 'plugin', 'theme' ),
			'description' => 'Whether slug is a plugin or a theme.',
		);
		$slug = array(
			'type'        => 'string',
			'description' => 'Folder of the plugin or theme, as listed by list_projects. A plugin made of a single file is that file name, e.g. "hello.php".',
		);
		$path = array(
			'type'        => 'string',
			'description' => 'Path inside the plugin or theme folder, e.g. "includes/class-main.php".',
		);

		$tools = array(
			'list_projects' => array(
				'description' => 'Lists the plugins and themes installed on the site, with their slug, version and whether they are active.',
				'properties'  => array(),
				'required'    => array(),
			),
			'list_files'    => array(
				'description' => 'Lists a folder of a plugin or theme ("/" marks the subfolders), or with recursive or pattern every file below it.',
				'properties'  => array(
					'type'      => $type,
					'slug'      => $slug,
					'path'      => array(
						'type'        => 'string',
						'description' => 'Folder inside the plugin or theme. Empty for its root folder.',
					),
					'recursive' => array(
						'type'        => 'boolean',
						'description' => 'List every file below the folder.',
					),
					'pattern'   => array(
						'type'        => 'string',
						'description' => 'Glob of the files to list, relative to path, e.g. "**/*.php". Implies recursive.',
					),
				),
				'required'    => array( 'type', 'slug' ),
			),
			'read_file'     => array(
				'description' => sprintf( 'Reads a file of a plugin or theme: up to %d lines of text from offset, or an image.', self::MAX_LINES ),
				'properties'  => array(
					'type'   => $type,
					'slug'   => $slug,
					'path'   => $path,
					'offset' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'First line to read, from 1.',
					),
					'limit'  => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Number of lines to read.',
					),
				),
				'required'    => array( 'type', 'slug', 'path' ),
			),
			'search'        => array(
				'description' => 'Searches text in the files of a plugin or theme. Answers "path:line: text" for each match.',
				'properties'  => array(
					'type'           => $type,
					'slug'           => $slug,
					'pattern'        => array(
						'type'        => 'string',
						'description' => 'Text to find, or a PCRE regular expression with regex.',
					),
					'regex'          => array( 'type' => 'boolean' ),
					'case_sensitive' => array( 'type' => 'boolean' ),
					'path'           => array(
						'type'        => 'string',
						'description' => 'Folder to search in. Empty for the whole plugin or theme.',
					),
					'include'        => array(
						'type'        => 'string',
						'description' => 'Glob of the files to search, relative to path, e.g. "**/*.php".',
					),
					'max_results'    => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 500,
					),
				),
				'required'    => array( 'type', 'slug', 'pattern' ),
			),
			'fetch_page'    => array(
				'description' => 'Loads a page of this site, to check a change: HTTP status, time, redirects, PHP errors shown on the page, title and text (or HTML). Only URLs of this site, as an anonymous visitor.',
				'properties'  => array(
					'url'    => array(
						'type'        => 'string',
						'description' => 'URL of a page of this site, or a path relative to its home page, e.g. "/shop/".',
					),
					'format' => array(
						'type'        => 'string',
						'enum'        => array( 'text', 'html' ),
						'description' => 'Body as readable text (default) or as HTML.',
					),
				),
				'required'    => array( 'url' ),
			),
		);

		if ( ! self::can_write() ) {
			return array_map( array( __CLASS__, 'read_only' ), $tools );
		}

		$tools['fetch_page']['description']           = str_replace( 'as an anonymous visitor.', 'as an anonymous visitor or logged in as the owner of the access token (as_user), e.g. for admin screens.', $tools['fetch_page']['description'] );
		$tools['fetch_page']['properties']['as_user'] = array(
			'type'        => 'boolean',
			'description' => 'Load the page logged in as the owner of the access token.',
		);

		return array_map( array( __CLASS__, 'read_only' ), $tools ) + array(
			'write_file'    => array(
				'description' => 'Creates or overwrites a file of a plugin or theme; missing folders are created. A PHP file with a syntax error is not saved, and a change that breaks an active plugin or theme is reverted.',
				'properties'  => array(
					'type'     => $type,
					'slug'     => $slug,
					'path'     => $path,
					'content'  => array(
						'type'        => 'string',
						'description' => 'The whole new content.',
					),
					'encoding' => array(
						'type'        => 'string',
						'enum'        => array( 'text', 'base64' ),
						'description' => 'base64 for binary files such as images. Default text.',
					),
				),
				'required'    => array( 'type', 'slug', 'path', 'content' ),
				'write'       => true,
			),
			'edit_file'     => array(
				'description' => 'Replaces an exact text in a file of a plugin or theme, with the same checks as write_file. old_string must occur once, unless replace_all.',
				'properties'  => array(
					'type'        => $type,
					'slug'        => $slug,
					'path'        => $path,
					'old_string'  => array( 'type' => 'string' ),
					'new_string'  => array( 'type' => 'string' ),
					'replace_all' => array( 'type' => 'boolean' ),
				),
				'required'    => array( 'type', 'slug', 'path', 'old_string', 'new_string' ),
				'write'       => true,
			),
			'create_folder' => array(
				'description' => 'Creates a folder (and its missing parents) in a plugin or theme.',
				'properties'  => array(
					'type' => $type,
					'slug' => $slug,
					'path' => $path,
				),
				'required'    => array( 'type', 'slug', 'path' ),
				'write'       => true,
			),
			'delete'        => array(
				'description' => 'Deletes a file or a folder with its content from a plugin or theme, for good. On an active plugin or theme it is put back if the site breaks without it.',
				'properties'  => array(
					'type' => $type,
					'slug' => $slug,
					'path' => $path,
				),
				'required'    => array( 'type', 'slug', 'path' ),
				'write'       => true,
				'destructive' => true,
			),
			'move'          => array(
				'description' => 'Moves or renames a file or folder inside a plugin or theme; missing folders are created.',
				'properties'  => array(
					'type'      => $type,
					'slug'      => $slug,
					'from'      => $path,
					'to'        => $path,
					'overwrite' => array(
						'type'        => 'boolean',
						'description' => 'Replace what exists at "to".',
					),
				),
				'required'    => array( 'type', 'slug', 'from', 'to' ),
				'write'       => true,
				'destructive' => true,
			),
		);
	}

	/**
	 * Marks a tool as read only.
	 *
	 * @param array $tool Tool.
	 * @return array
	 */
	private static function read_only( $tool ) {
		return $tool + array( 'write' => false );
	}

	/**
	 * tools/call: runs a tool. Its failures are results flagged isError, which the assistant
	 * reads, not protocol errors.
	 *
	 * @param string $name Tool name.
	 * @param array  $args Arguments.
	 * @return array
	 */
	private static function call_tool( $name, $args ) {
		$tools = self::tools();
		if ( ! isset( $tools[ $name ] ) ) {
			$result = new WP_Error( 'unknown_tool', self::can_write() ? "Unknown tool: $name." : "Unknown tool: $name. This access token is read only." );
		} else {
			$result = true;
			foreach ( $tools[ $name ]['required'] as $required ) {
				// Only new_string and content can be empty.
				if ( ! isset( $args[ $required ] ) || ! is_scalar( $args[ $required ] ) || ( '' === (string) $args[ $required ] && ! in_array( $required, array( 'new_string', 'content' ), true ) ) ) {
					$result = new WP_Error( 'missing_argument', "Missing argument: $required." );
					break;
				}
			}
			if ( true === $result ) {
				$result = call_user_func( array( __CLASS__, 'tool_' . $name ), $args );
			}
		}

		if ( is_wp_error( $result ) ) {
			return array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => wp_strip_all_tags( $result->get_error_message() ),
					),
				),
				'isError' => true,
			);
		}

		return array(
			'content' => is_array( $result ) ? $result : array(
				array(
					'type' => 'text',
					'text' => $result,
				),
			),
		);
	}

	/**
	 * Tool list_projects.
	 *
	 * @return string
	 */
	private static function tool_list_projects() {
		$projects = array();

		if ( current_user_can( 'edit_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			foreach ( get_plugins() as $file => $plugin ) {
				$projects[] = array(
					'type'    => 'plugin',
					'slug'    => false === strpos( $file, '/' ) ? $file : strtok( $file, '/' ),
					'name'    => $plugin['Name'],
					'version' => $plugin['Version'],
					'active'  => is_plugin_active( $file ),
				);
			}
		}

		if ( current_user_can( 'edit_themes' ) ) {
			foreach ( wp_get_themes() as $stylesheet => $theme ) {
				$projects[] = array(
					'type'    => 'theme',
					'slug'    => $stylesheet,
					'name'    => $theme->get( 'Name' ),
					'version' => $theme->get( 'Version' ),
					'active'  => in_array( $stylesheet, array( get_stylesheet(), get_template() ), true ),
				);
			}
		}

		return (string) wp_json_encode( $projects, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Tool list_files.
	 *
	 * @param array $args Arguments.
	 * @return string|WP_Error
	 */
	private static function tool_list_files( $args ) {
		$path = self::path( $args );

		if ( empty( $args['recursive'] ) && empty( $args['pattern'] ) ) {
			$entries = self::files( 'readdir', $args, array( 'path' => $path ) );
			if ( is_wp_error( $entries ) ) {
				return $entries;
			}

			$names = array();
			foreach ( $entries as list( $name, $type ) ) {
				$names[] = Code_Workbench_Files::TYPE_DIRECTORY === $type ? $name . '/' : $name;
			}
			sort( $names );

			return $names ? implode( "\n", $names ) : 'The folder is empty.';
		}

		$found = self::files(
			'find',
			$args,
			array(
				'path'     => $path,
				'includes' => empty( $args['pattern'] ) ? array() : array( (string) $args['pattern'] ),
			)
		);
		if ( is_wp_error( $found ) ) {
			return $found;
		}

		$files = $found['files'];
		sort( $files );
		$text = implode( "\n", array_slice( $files, 0, self::MAX_LIST ) );
		if ( count( $files ) > self::MAX_LIST || $found['limitHit'] ) {
			$text .= sprintf( "\n[%d files listed out of %s. Narrow the list with path or pattern.]", self::MAX_LIST, $found['limitHit'] ? 'more than ' . count( $files ) : count( $files ) );
		}

		return '' === $text ? 'No files.' : $text;
	}

	/**
	 * Tool read_file.
	 *
	 * @param array $args Arguments.
	 * @return string|array|WP_Error
	 */
	private static function tool_read_file( $args ) {
		$file = self::readable_file( $args );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$type = wp_check_filetype( $file );
		if ( in_array( $type['type'], array( 'image/png', 'image/jpeg', 'image/gif', 'image/webp' ), true ) ) {
			if ( filesize( $file ) > self::MAX_IMAGE_BYTES ) {
				return new WP_Error( 'too_large', 'The image is too large to be read.' );
			}
			return array(
				array(
					'type'     => 'image',
					'data'     => base64_encode( (string) file_get_contents( $file ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					'mimeType' => $type['type'],
				),
			);
		}

		// Read line by line: only the lines asked for are held in memory.
		$offset = isset( $args['offset'] ) ? max( 1, (int) $args['offset'] ) : 1;
		$limit  = isset( $args['limit'] ) ? min( self::MAX_LINES, max( 1, (int) $args['limit'] ) ) : self::MAX_LINES;
		$lines  = new SplFileObject( $file, 'rb' );
		$head   = $lines->fread( 1024 );
		if ( is_string( $head ) && false !== strpos( $head, "\0" ) ) {
			return sprintf( 'Binary file, %d bytes.', filesize( $file ) );
		}
		$lines->rewind();

		$text = '';
		$last = $offset - 1;
		$more = false;
		foreach ( $lines as $index => $line ) {
			if ( $index + 1 < $offset ) {
				continue;
			}
			if ( $index + 1 >= $offset + $limit || strlen( $text ) + strlen( $line ) > self::MAX_TEXT_BYTES ) {
				$more = '' !== $line;
				if ( '' === $text ) {
					// A single line longer than the limit (minified code): its beginning.
					$text = mb_strcut( $line, 0, self::MAX_TEXT_BYTES, 'UTF-8' ) . "\n[Line cut.]";
					$last = $index + 1;
				}
				break;
			}
			$text .= $line;
			$last  = $index + 1;
		}

		if ( '' === $text ) {
			return 1 === $offset ? 'The file is empty.' : sprintf( 'The file has fewer than %d lines.', $offset );
		}
		if ( $more || $offset > 1 ) {
			$text .= sprintf( "\n[Lines %d-%d.%s]", $offset, $last, $more ? sprintf( ' Read on with offset %d.', $last + 1 ) : ' End of file.' );
		}

		return $text;
	}

	/**
	 * Tool search.
	 *
	 * @param array $args Arguments.
	 * @return string|WP_Error
	 */
	private static function tool_search( $args ) {
		$found = self::files(
			'search',
			$args,
			array(
				'path'            => self::path( $args ),
				'pattern'         => (string) $args['pattern'],
				'isRegExp'        => ! empty( $args['regex'] ),
				'isCaseSensitive' => ! empty( $args['case_sensitive'] ),
				'includes'        => empty( $args['include'] ) ? array() : array( (string) $args['include'] ),
				'maxResults'      => isset( $args['max_results'] ) ? min( 500, max( 1, (int) $args['max_results'] ) ) : 100,
			)
		);
		if ( is_wp_error( $found ) ) {
			return $found;
		}

		$prefix = self::relative( $args, 'path' );
		$prefix = '' === $prefix ? '' : $prefix . '/';
		$lines  = array();
		foreach ( $found['matches'] as $match ) {
			$lines[] = $prefix . $match['path'] . ':' . ( $match['line'] + 1 ) . ': ' . trim( $match['preview'] );
		}
		if ( $found['limitHit'] ) {
			$lines[] = '[More matches: narrow the search or raise max_results.]';
		}

		return $lines ? implode( "\n", $lines ) : 'No matches.';
	}

	/**
	 * Tool fetch_page. Only this site is reached, at every redirect, so the token cannot be
	 * used to make the site request other hosts.
	 *
	 * @param array $args Arguments.
	 * @return string|WP_Error
	 */
	private static function tool_fetch_page( $args ) {
		$url = (string) $args['url'];
		if ( '/' === substr( $url, 0, 1 ) ) {
			$url = home_url( $url );
		}

		// Logged in through a short session of its own, closed right after.
		$cookies = array();
		$session = '';
		if ( ! empty( $args['as_user'] ) && self::can_write() ) {
			$user       = get_current_user_id();
			$expiration = time() + HOUR_IN_SECONDS;
			$session    = WP_Session_Tokens::get_instance( $user )->create( $expiration );
			$cookies    = array(
				LOGGED_IN_COOKIE   => wp_generate_auth_cookie( $user, $expiration, 'logged_in', $session ),
				AUTH_COOKIE        => wp_generate_auth_cookie( $user, $expiration, 'auth', $session ),
				SECURE_AUTH_COOKIE => wp_generate_auth_cookie( $user, $expiration, 'secure_auth', $session ),
			);
		}

		$visited = array();
		$start   = microtime( true );
		for ( $hops = 0; $hops <= 5; $hops++ ) {
			if ( ! self::is_site_url( $url ) ) {
				$response = new WP_Error( 'external_url', "Only pages of this site can be loaded, not $url." );
				break;
			}

			$visited[] = $url;
			$response  = wp_remote_get(
				$url,
				array(
					'timeout'             => 30,
					'redirection'         => 0,
					'cookies'             => $cookies,
					'headers'             => array( 'Cache-Control' => 'no-cache' ),
					'limit_response_size' => 2 * MB_IN_BYTES,
					/** This filter is documented in wp-includes/class-wp-http-streams.php */
					'sslverify'           => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter, as in the core loopback requests.
				)
			);

			$location = wp_remote_retrieve_header( $response, 'location' );
			if ( is_wp_error( $response ) || ! is_string( $location ) || '' === $location || wp_remote_retrieve_response_code( $response ) < 300 || wp_remote_retrieve_response_code( $response ) > 399 ) {
				break;
			}
			$url = WP_Http::make_absolute_url( $location, $url );
		}

		if ( $session ) {
			WP_Session_Tokens::get_instance( get_current_user_id() )->destroy( $session );
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$info = array(
			sprintf( 'GET %s: %d %s in %.2f s', implode( ' -> ', $visited ), wp_remote_retrieve_response_code( $response ), wp_remote_retrieve_response_message( $response ), microtime( true ) - $start ),
			'Content-Type: ' . wp_remote_retrieve_header( $response, 'content-type' ),
		);
		if ( isset( $location ) && is_string( $location ) && '' !== $location && $hops > 5 ) {
			$info[] = 'Stopped after 5 redirects, at ' . $location;
		}
		if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $body, $title ) ) {
			$info[] = 'Title: ' . trim( html_entity_decode( wp_strip_all_tags( $title[1] ), ENT_QUOTES, 'UTF-8' ) );
		}

		// PHP errors printed in the page (display_errors), and the WordPress error screens.
		if ( preg_match_all( '#(?:<b>)?(?:Fatal error|Parse error|Warning|Notice|Deprecated)(?:</b>)?:\s.+? on line (?:<b>)?\d+#i', $body, $errors ) ) {
			$info[] = "PHP errors:\n" . implode( "\n", array_slice( array_unique( array_map( 'wp_strip_all_tags', $errors[0] ) ), 0, 20 ) );
		}
		if ( false !== strpos( $body, 'wp-die-message' ) ) {
			$info[] = 'The page is a WordPress error screen.';
		}

		if ( ! isset( $args['format'] ) || 'html' !== $args['format'] ) {
			$body = (string) preg_replace( '#<(script|style|noscript|svg|template)\b.*?</\1>#is', '', $body );
			$body = html_entity_decode( wp_strip_all_tags( $body ), ENT_QUOTES, 'UTF-8' );
			$body = trim( (string) preg_replace( '/\s*\n\s*/', "\n", (string) preg_replace( '/[ \t]+/', ' ', $body ) ) );
		}
		if ( strlen( $body ) > self::MAX_TEXT_BYTES ) {
			$body = mb_strcut( $body, 0, self::MAX_TEXT_BYTES, 'UTF-8' ) . "\n[Cut at " . size_format( self::MAX_TEXT_BYTES ) . '.]';
		}

		return implode( "\n", $info ) . "\n\n" . $body;
	}

	/**
	 * Tool write_file.
	 *
	 * @param array $args Arguments.
	 * @return string|WP_Error
	 */
	private static function tool_write_file( $args ) {
		$content = (string) $args['content'];
		if ( isset( $args['encoding'] ) && 'base64' === $args['encoding'] ) {
			$content = base64_decode( $content, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $content ) {
				return new WP_Error( 'invalid_base64', 'content is not valid base64.' );
			}
		}

		$folders = self::make_folders( $args, dirname( self::relative( $args, 'path' ) ) );
		if ( is_wp_error( $folders ) ) {
			return $folders;
		}

		$stat = self::files(
			'write',
			$args,
			array(
				'path'      => self::path( $args ),
				'create'    => true,
				'overwrite' => true,
			),
			$content
		);

		return is_wp_error( $stat ) ? $stat : sprintf( 'Saved %s (%d bytes).', self::relative( $args, 'path' ), $stat['size'] );
	}

	/**
	 * Tool edit_file.
	 *
	 * @param array $args Arguments.
	 * @return string|WP_Error
	 */
	private static function tool_edit_file( $args ) {
		$file = self::readable_file( $args );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$old     = (string) $args['old_string'];
		$content = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$count   = substr_count( $content, $old );
		if ( 0 === $count ) {
			return new WP_Error( 'not_found', 'old_string was not found in the file.' );
		}
		if ( $count > 1 && empty( $args['replace_all'] ) ) {
			return new WP_Error( 'ambiguous', "old_string occurs $count times: add context to make it unique, or set replace_all." );
		}

		$stat = self::files(
			'write',
			$args,
			array(
				'path'      => self::path( $args ),
				'overwrite' => true,
			),
			str_replace( $old, (string) $args['new_string'], $content )
		);

		return is_wp_error( $stat ) ? $stat : sprintf( 'Edited %s (%d %s).', self::relative( $args, 'path' ), $count, 1 === $count ? 'replacement' : 'replacements' );
	}

	/**
	 * Tool create_folder.
	 *
	 * @param array $args Arguments.
	 * @return string|WP_Error
	 */
	private static function tool_create_folder( $args ) {
		$made = self::make_folders( $args, self::relative( $args, 'path' ) );

		return is_wp_error( $made ) ? $made : 'Created ' . self::relative( $args, 'path' ) . '/.';
	}

	/**
	 * Tool delete.
	 *
	 * @param array $args Arguments.
	 * @return string|WP_Error
	 */
	private static function tool_delete( $args ) {
		$deleted = self::files(
			'delete',
			$args,
			array(
				'path'      => self::path( $args ),
				'recursive' => true,
			)
		);

		return is_wp_error( $deleted ) ? $deleted : 'Deleted ' . self::relative( $args, 'path' ) . '.';
	}

	/**
	 * Tool move.
	 *
	 * @param array $args Arguments.
	 * @return string|WP_Error
	 */
	private static function tool_move( $args ) {
		$folders = self::make_folders( $args, dirname( self::relative( $args, 'to' ) ) );
		if ( is_wp_error( $folders ) ) {
			return $folders;
		}

		$moved = self::files(
			'rename',
			$args,
			array(
				'from'      => self::path( $args, 'from' ),
				'to'        => self::path( $args, 'to' ),
				'overwrite' => ! empty( $args['overwrite'] ),
			)
		);

		return is_wp_error( $moved ) ? $moved : sprintf( 'Moved %s to %s.', self::relative( $args, 'from' ), self::relative( $args, 'to' ) );
	}

	/**
	 * Calls a route of the file API (Code_Workbench_Files) as the token owner: its validation,
	 * permission check and scope apply.
	 *
	 * @param string      $route  Route.
	 * @param array       $args   Tool arguments, with type and slug.
	 * @param array       $params Route parameters.
	 * @param string|null $body   Request body.
	 * @return mixed|WP_Error Response data.
	 */
	private static function files( $route, $args, $params, $body = null ) {
		$request = new WP_REST_Request( in_array( $route, array( 'stat', 'readdir' ), true ) ? 'GET' : 'POST', '/' . Code_Workbench_Files::REST_NAMESPACE . '/' . $route );
		$request->set_query_params(
			$params + array(
				'type' => (string) $args['type'],
				'item' => (string) $args['slug'],
			)
		);
		if ( null !== $body ) {
			$request->set_body( $body );
		}

		$response = rest_do_request( $request );

		return $response->is_error() ? $response->as_error() : $response->get_data();
	}

	/**
	 * The file read_file and edit_file read. The read route streams the file to the browser,
	 * so this goes through its checks directly.
	 *
	 * @param array $args Tool arguments.
	 * @return string|WP_Error Absolute path.
	 */
	private static function readable_file( $args ) {
		$request = new WP_REST_Request();
		$request->set_param( 'type', (string) $args['type'] );
		$request->set_param( 'item', (string) $args['slug'] );
		$request->set_param( 'path', self::path( $args ) );

		if ( ! in_array( $request['type'], array( 'plugin', 'theme' ), true ) || ! Code_Workbench_Files::can_edit( $request ) ) {
			return new WP_Error( 'forbidden', 'type must be plugin or theme, among those the owner of the token can edit.' );
		}

		return Code_Workbench_Files::readable_file( $request );
	}

	/**
	 * Creates the missing folders of a path, one level at a time.
	 *
	 * @param array  $args     Tool arguments.
	 * @param string $relative Folder path inside the plugin or theme.
	 * @return true|WP_Error
	 */
	private static function make_folders( $args, $relative ) {
		$folder = '/' . $args['slug'];
		foreach ( explode( '/', $relative ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			$folder .= '/' . $segment;
			$made    = self::files( 'mkdir', $args, array( 'path' => $folder ) );
			if ( is_wp_error( $made ) && 'exists' !== $made->get_error_code() ) {
				return $made;
			}
		}

		return true;
	}

	/**
	 * Path argument relative to the plugin or theme folder, without leading or trailing slash.
	 *
	 * @param array  $args Tool arguments.
	 * @param string $key  Argument.
	 * @return string
	 */
	private static function relative( $args, $key ) {
		return isset( $args[ $key ] ) ? trim( (string) $args[ $key ], '/' ) : '';
	}

	/**
	 * Workspace path ("/<slug>/<path>") of a path argument, as the file API expects.
	 *
	 * @param array  $args Tool arguments.
	 * @param string $key  Argument.
	 * @return string
	 */
	private static function path( $args, $key = 'path' ) {
		$relative = self::relative( $args, $key );

		return '/' . $args['slug'] . ( '' === $relative ? '' : '/' . $relative );
	}

	/**
	 * Whether a URL points to this site (host and port of the home or site URL).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function is_site_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) || ! isset( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}

		foreach ( array( home_url(), site_url() ) as $site ) {
			$site = wp_parse_url( $site );
			if ( strtolower( $parts['host'] ) === strtolower( $site['host'] ) && ( isset( $parts['port'] ) ? $parts['port'] : null ) === ( isset( $site['port'] ) ? $site['port'] : null ) ) {
				return true;
			}
		}

		return false;
	}
}
