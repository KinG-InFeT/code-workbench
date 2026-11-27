/**
 * File system, quick open and text search for the "wordpress:" scheme, backed by the
 * plugin REST API (includes/class-code-workbench-files.php). The workspace is the plugin or
 * theme picked in the admin screen: "/<item>/...", nothing outside it exists.
 *
 * Loaded by the VS Code web extension host as CommonJS, no build step.
 */
const vscode = require( 'vscode' );

const SCHEME = 'wordpress';

/** REST URL, nonce, scope (type and item) and file to open, from the workbench page. */
let settings;

async function getSettings() {
	if ( ! settings ) {
		settings = await vscode.commands.executeCommand( 'codeWorkbench.config' );
	}
	return settings;
}

/**
 * Calls the REST API, scoped to the selected plugin or theme. Errors are thrown as
 * { code, message }, the WP_Error shape. With "raw", the answer is the response bytes.
 */
async function request( route, options = {}, retry = true ) {
	const { method = 'GET', query = {}, json, signal, raw } = options;
	const { restUrl, nonce, type, item } = await getSettings();
	const url = new URL( `${ restUrl }/${ route }` );
	for ( const [ key, value ] of Object.entries( { ...query, type, item } ) ) {
		url.searchParams.set( key, String( value ) );
	}

	const headers = { 'X-WP-Nonce': nonce };
	let body = options.body;
	if ( json ) {
		headers[ 'Content-Type' ] = 'application/json';
		body = JSON.stringify( json );
	} else if ( body ) {
		headers[ 'Content-Type' ] = 'application/octet-stream';
	}

	const response = await fetch( url, { method, headers, body, signal, credentials: 'same-origin' } );
	if ( response.ok ) {
		return raw ? new Uint8Array( await response.arrayBuffer() ) : response.json();
	}

	// REST nonces expire after a day: get a fresh one and try once more.
	const data = await response.json().catch( () => null );
	if ( retry && data?.code === 'rest_cookie_invalid_nonce' && ( await refreshNonce() ) ) {
		return request( route, options, false );
	}

	throw { code: data?.code || 'failed', message: data?.message || `${ response.status } ${ response.statusText }` };
}

async function refreshNonce() {
	const response = await fetch( `${ settings.ajaxUrl }?action=rest-nonce`, { credentials: 'same-origin' } );
	if ( ! response.ok ) {
		return false;
	}
	settings.nonce = await response.text();
	return true;
}

function toFileSystemError( error, uri ) {
	switch ( error.code ) {
		case 'not_found':
			return vscode.FileSystemError.FileNotFound( uri );
		case 'exists':
			return vscode.FileSystemError.FileExists( uri );
		case 'not_dir':
			return vscode.FileSystemError.FileNotADirectory( uri );
		case 'is_dir':
			return vscode.FileSystemError.FileIsADirectory( uri );
		case 'forbidden':
		case 'rest_forbidden':
			return vscode.FileSystemError.NoPermissions( error.message );
		default:
			return new vscode.FileSystemError( error.message || String( error ) );
	}
}

/**
 * Folder listings read by readDirectory(), by path. VS Code probes many paths that do not
 * exist (".vscode/tasks.json", ".copilot", "pyproject.toml"...) on startup and around saves,
 * and each request boots WordPress: when a recent listing of an ancestor lacks the path, the
 * answer is known. Only absence is answered this way, and any change made from the editor
 * clears the listings ("generation" drops a listing read while a change was running).
 */
const listings = new Map();
const LISTING_TTL = 30 * 1000;
let generation = 0;

function knownMissing( path ) {
	const parts = path.split( '/' );
	for ( let i = 2; i < parts.length; i++ ) {
		const listing = listings.get( parts.slice( 0, i ).join( '/' ) );
		if ( ! listing || Date.now() - listing.time > LISTING_TTL ) {
			return false;
		}
		if ( ! listing.names.has( parts[ i ] ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Same as request(), with errors turned into FileSystemErrors for the given uri. Paths
 * outside the folder ("/.vscode/settings.json", "/.vimrc"...) and paths known to be missing
 * are answered without a request.
 */
async function call( uri, route, options ) {
	const root = `/${ ( await getSettings() ).item }`;
	const reading = ! options.method;
	if ( ( uri.path !== root && ! uri.path.startsWith( `${ root }/` ) ) || ( reading && knownMissing( uri.path ) ) ) {
		throw vscode.FileSystemError.FileNotFound( uri );
	}
	try {
		return await request( route, options );
	} catch ( error ) {
		throw toFileSystemError( error, uri );
	} finally {
		if ( ! reading ) {
			listings.clear();
			generation++;
		}
	}
}

function abortSignal( token ) {
	const controller = new AbortController();
	token.onCancellationRequested( () => controller.abort() );
	return controller.signal;
}

class WordPressFileSystem {
	constructor() {
		// Never fired: VS Code updates editors and explorer after its own operations, and an
		// event would only make it read the saved file back. Changes made outside VS Code
		// are not watched; VS Code still detects them on save.
		this.onDidChangeFile = new vscode.EventEmitter().event;
	}

	watch() {
		return new vscode.Disposable( () => {} );
	}

	stat( uri ) {
		return call( uri, 'stat', { query: { path: uri.path } } );
	}

	async readDirectory( uri ) {
		const since = generation;
		const entries = await call( uri, 'readdir', { query: { path: uri.path } } );
		if ( since === generation ) {
			listings.set( uri.path, { names: new Set( entries.map( ( [ name ] ) => name ) ), time: Date.now() } );
		}
		return entries;
	}

	readFile( uri ) {
		return call( uri, 'read', { query: { path: uri.path }, raw: true } );
	}

	async writeFile( uri, content, options ) {
		await call( uri, 'write', {
			method: 'POST',
			query: { path: uri.path, create: !! options.create, overwrite: !! options.overwrite },
			body: content,
		} );
	}

	async createDirectory( uri ) {
		await call( uri, 'mkdir', { method: 'POST', query: { path: uri.path } } );
	}

	async delete( uri, options ) {
		await call( uri, 'delete', { method: 'POST', query: { path: uri.path, recursive: !! options.recursive } } );
	}

	async rename( oldUri, newUri, options ) {
		await call( oldUri, 'rename', {
			method: 'POST',
			query: { from: oldUri.path, to: newUri.path, overwrite: !! options.overwrite },
		} );
	}

	// Quick Open: VS Code asks for every file and filters them itself.
	async provideFileSearchResults( query, options, token ) {
		const { files } = await request( 'find', {
			method: 'POST',
			json: { path: options.folder.path, includes: options.includes, excludes: options.excludes },
			signal: abortSignal( token ),
		} );
		return files.map( ( path ) => vscode.Uri.joinPath( options.folder, path ) );
	}

	async provideTextSearchResults( query, options, progress, token ) {
		const { matches, limitHit } = await request( 'search', {
			method: 'POST',
			json: {
				path: options.folder.path,
				pattern: query.pattern,
				isRegExp: !! query.isRegExp,
				isCaseSensitive: !! query.isCaseSensitive,
				isWordMatch: !! query.isWordMatch,
				includes: options.includes,
				excludes: options.excludes,
				maxResults: options.maxResults,
			},
			signal: abortSignal( token ),
		} );

		for ( const match of matches ) {
			progress.report( {
				uri: vscode.Uri.joinPath( options.folder, match.path ),
				ranges: new vscode.Range( match.line, match.start, match.endLine, match.end ),
				preview: {
					text: match.preview,
					matches: new vscode.Range( 0, match.previewStart, 0, match.previewEnd ),
				},
			} );
		}

		return { limitHit };
	}
}

/**
 * AI Access (MCP), a container of the activity bar ($(mcp) icon): "Connect" (buttons only) and
 * "Access Tokens", the access tokens of the site's MCP server
 * (includes/class-code-workbench-mcp.php). They are stored on the server, through the
 * "mcp-tokens" routes. A new or regenerated token is shown once, in a read only document
 * with the settings of the assistants; that document lives in memory only (no backup of
 * unsaved files keeps the token in the browser).
 */
const SETTINGS_SCHEME = 'code-workbench-mcp';

class TokensView {
	constructor() {
		this.changed = new vscode.EventEmitter();
		this.onDidChangeTreeData = this.changed.event;
		/** Last answer of GET mcp-tokens: endpoint, server, site and tokens. */
		this.info = null;
	}

	refresh() {
		this.changed.fire();
	}

	async load() {
		this.info = await request( 'mcp-tokens' );
		return this.info;
	}

	getTreeItem( token ) {
		const access = token.access === 'write' ? 'Read and write' : 'Read only';
		const item = new vscode.TreeItem( token.name );
		item.description = `${ access } · ${ token.used ? `used ${ formatDate( token.used ) }` : 'never used' }`;
		item.tooltip = [ access, `Owner: ${ token.owner || 'deleted user' }`, `Created: ${ formatDate( token.created ) }`, `Last used: ${ token.used ? formatDate( token.used ) : 'never' }` ].join( '\n' );
		// Screen readers would otherwise read the tooltip, without the name.
		item.accessibilityInformation = { label: `${ token.name }, ${ item.description }` };
		item.iconPath = new vscode.ThemeIcon( token.access === 'write' ? 'edit' : 'eye' );
		// Only the owner regenerates a token: the new secret acts as the owner.
		item.contextValue = token.mine ? 'token.mine' : 'token';
		return item;
	}

	async getChildren( parent ) {
		if ( parent ) {
			return [];
		}
		try {
			return ( await this.load() ).tokens;
		} catch ( error ) {
			vscode.window.showErrorMessage( error.message || String( error ) );
			return [];
		}
	}
}

/** Read only documents with the connection settings, by URI. */
class SettingsDocuments {
	constructor() {
		this.texts = new Map();
		this.changed = new vscode.EventEmitter();
		this.onDidChange = this.changed.event;
	}

	provideTextDocumentContent( uri ) {
		return this.texts.get( uri.toString() ) ?? 'Run "Code Workbench: Show MCP Connection Settings" again.';
	}

	async show( name, text ) {
		const uri = vscode.Uri.from( { scheme: SETTINGS_SCHEME, path: `/${ name.replace( /[\\/]/g, '-' ) } - MCP connection settings.md` } );
		this.texts.set( uri.toString(), text );
		this.changed.fire( uri );
		await vscode.window.showTextDocument( await vscode.workspace.openTextDocument( uri ), { preview: false } );
	}
}

function formatDate( seconds ) {
	return new Date( seconds * 1000 ).toLocaleString();
}

async function confirm( message, action ) {
	return ( await vscode.window.showWarningMessage( message, { modal: true }, action ) ) === action;
}

/** Settings of the assistants, as Markdown; "token" is the secret or a placeholder. */
function settingsText( info, token, name ) {
	const header = { Authorization: `Bearer ${ token }` };
	const json = JSON.stringify( { mcpServers: { [ info.server ]: { type: 'http', url: info.endpoint, headers: header } } }, null, '\t' );
	const intro = name
		? `Access token "${ name }". **Copy it now: it is not shown again.** Closing this document forgets it.\n\n\t${ token }`
		: 'Replace YOUR_TOKEN with an access token. Create one, or regenerate one of yours, to get these settings with the token filled in.';

	return `# MCP connection settings: ${ info.site }

${ intro }

## Claude Code

Run in a terminal:

\tclaude mcp add --transport http ${ info.server } ${ info.endpoint } --header "Authorization: Bearer ${ token }"

## Cursor, VS Code, Windsurf and other clients

Add the server to the MCP configuration of the client (in VS Code, the list is called "servers" instead of "mcpServers"):

\`\`\`json
${ json }
\`\`\`

Server URL: ${ info.endpoint }
Header: Authorization: Bearer ${ token }

## claude.ai and ChatGPT

Their custom connectors cannot send the token in a header: add a custom connector (in ChatGPT, with developer mode on) with this secret URL, and no authentication:

\t${ info.endpoint }/${ token }

Anyone who has the secret URL can use the token, and the URL may be written in the access logs of the server. Regenerate the token if it leaks.
`;
}

async function showNewToken( view, documents, name, token ) {
	view.refresh();
	await documents.show( name, settingsText( view.info || ( await view.load() ), token, name ) );
	vscode.window.showInformationMessage( `Access token "${ name }" created. Copy it from the settings document now: it is not shown again.` );
}

async function createToken( view, documents ) {
	const name = await vscode.window.showInputBox( {
		title: 'Create MCP Access Token (1/2)',
		prompt: 'A name to recognize the token, e.g. the assistant and the device that use it.',
		placeHolder: 'Claude on my laptop',
		ignoreFocusOut: true,
	} );
	if ( name === undefined ) {
		return;
	}

	const choice = await vscode.window.showQuickPick(
		[
			{ label: '$(eye) Read only', detail: 'Browse, search and read the files of plugins and themes, and load the public pages of the site.', access: 'read' },
			{ label: '$(edit) Read and write', detail: 'Also create, edit, upload, move and delete files, and load pages logged in as you.', access: 'write' },
		],
		{ title: 'Create MCP Access Token (2/2)', placeHolder: 'What the assistant can do', ignoreFocusOut: true }
	);
	if ( ! choice ) {
		return;
	}
	if ( choice.access === 'write' && ! ( await confirm( 'A read and write token can change code that runs on this site. Give it only to assistants you trust, and revoke it when you no longer use it.', 'Create' ) ) ) {
		return;
	}

	const created = await request( 'mcp-tokens', { method: 'POST', json: { name, access: choice.access } } );
	await showNewToken( view, documents, created.name, created.token );
}

async function regenerateToken( view, documents, token ) {
	if ( ! ( await confirm( `Regenerate "${ token.name }"? It gets a new secret: the assistants using it must be set up again.`, 'Regenerate' ) ) ) {
		return;
	}
	const regenerated = await request( `mcp-tokens/${ token.id }/regenerate`, { method: 'POST' } );
	await showNewToken( view, documents, regenerated.name, regenerated.token );
}

async function revokeToken( view, token ) {
	if ( ! ( await confirm( `Revoke "${ token.name }"? The assistants using it can no longer connect.`, 'Revoke' ) ) ) {
		return;
	}
	await request( `mcp-tokens/${ token.id }`, { method: 'DELETE' } );
	view.refresh();
}

/** A command whose errors (WP_Error shape) are shown to the user. */
function command( id, handler ) {
	return vscode.commands.registerCommand( id, async ( ...args ) => {
		try {
			await handler( ...args );
		} catch ( error ) {
			vscode.window.showErrorMessage( error.message || String( error ) );
		}
	} );
}

exports.activate = async function ( context ) {
	const fileSystem = new WordPressFileSystem();
	const { item, openFile } = await getSettings();

	// The probes VS Code queued while the extension loaded run as soon as the provider is
	// registered: with the folder listing already read, they need no request.
	await fileSystem.readDirectory( vscode.Uri.from( { scheme: SCHEME, path: `/${ item }` } ) ).catch( () => {} );

	context.subscriptions.push(
		vscode.workspace.registerFileSystemProvider( SCHEME, fileSystem, { isCaseSensitive: true } ),
		vscode.workspace.registerFileSearchProvider( SCHEME, fileSystem ),
		vscode.workspace.registerTextSearchProvider( SCHEME, fileSystem )
	);

	const tokens = new TokensView();
	const documents = new SettingsDocuments();
	context.subscriptions.push(
		// Never has items: VS Code shows its welcome text, with the connection buttons, for good.
		vscode.window.registerTreeDataProvider( 'codeWorkbench.mcpConnect', { getChildren: () => [], getTreeItem: ( item ) => item } ),
		vscode.window.registerTreeDataProvider( 'codeWorkbench.mcpTokens', tokens ),
		vscode.workspace.registerTextDocumentContentProvider( SETTINGS_SCHEME, documents ),
		command( 'codeWorkbench.mcp.create', () => createToken( tokens, documents ) ),
		command( 'codeWorkbench.mcp.settings', async () => {
			const info = tokens.info || ( await tokens.load() );
			await documents.show( info.site, settingsText( info, 'YOUR_TOKEN' ) );
		} ),
		command( 'codeWorkbench.mcp.refresh', () => tokens.refresh() ),
		command( 'codeWorkbench.mcp.regenerate', ( token ) => regenerateToken( tokens, documents, token ) ),
		command( 'codeWorkbench.mcp.revoke', ( token ) => revokeToken( tokens, token ) )
	);

	// File asked by the screen, e.g. theme-editor.php?file=functions.php, else the main file.
	if ( openFile ) {
		vscode.window
			.showTextDocument( vscode.Uri.from( { scheme: SCHEME, path: openFile } ), { preview: false } )
			.then( undefined, () => {} );
	}
};
