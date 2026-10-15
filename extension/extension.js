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
 * { code, message }, the WP_Error shape.
 */
async function request( route, { method = 'GET', query = {}, body, json, signal } = {}, retry = true ) {
	const { restUrl, nonce, type, item } = await getSettings();
	const url = new URL( `${ restUrl }/${ route }` );
	for ( const [ key, value ] of Object.entries( { ...query, type, item } ) ) {
		url.searchParams.set( key, String( value ) );
	}

	const headers = { 'X-WP-Nonce': nonce };
	if ( json ) {
		headers[ 'Content-Type' ] = 'application/json';
		body = JSON.stringify( json );
	} else if ( body ) {
		headers[ 'Content-Type' ] = 'application/octet-stream';
	}

	const response = await fetch( url, { method, headers, body, signal, credentials: 'same-origin' } );
	const data = await response.json().catch( () => null );
	if ( response.ok ) {
		return data;
	}

	// REST nonces expire after a day: get a fresh one and try once more.
	if ( retry && data?.code === 'rest_cookie_invalid_nonce' && ( await refreshNonce() ) ) {
		return request( route, { method, query, body, json, signal }, false );
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

function decodeBase64( base64 ) {
	const binary = atob( base64 );
	const bytes = new Uint8Array( binary.length );
	for ( let i = 0; i < binary.length; i++ ) {
		bytes[ i ] = binary.charCodeAt( i );
	}
	return bytes;
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

	async readFile( uri ) {
		const { content } = await call( uri, 'read', { query: { path: uri.path } } );
		return decodeBase64( content );
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
			const previewEnd = Math.min( match.end, match.previewStart + match.preview.length );
			progress.report( {
				uri: vscode.Uri.joinPath( options.folder, match.path ),
				ranges: new vscode.Range( match.line, match.start, match.line, match.end ),
				preview: {
					text: match.preview,
					matches: new vscode.Range( 0, match.start - match.previewStart, 0, previewEnd - match.previewStart ),
				},
			} );
		}

		return { limitHit };
	}
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

	// File asked by the screen, e.g. theme-editor.php?file=functions.php, else the main file.
	if ( openFile ) {
		vscode.window
			.showTextDocument( vscode.Uri.from( { scheme: SCHEME, path: openFile } ), { preview: false } )
			.then( undefined, () => {} );
	}
};
