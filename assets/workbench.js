/**
 * Starts the VS Code workbench on the selected plugin or theme folder ("wordpress:" scheme,
 * served by the extension in ../extension).
 */
const config = JSON.parse( document.getElementById( 'code-workbench-config' ).textContent );
const { create, URI } = await import( config.workbench );

const folderUri = URI.from( { scheme: 'wordpress', path: config.folder } );

create( document.body, {
	productConfiguration: {
		nameShort: 'Code Workbench',
		nameLong: 'Code Workbench',
		extensionEnabledApiProposals: {
			'code-workbench.files': [ 'fileSearchProvider', 'textSearchProvider' ],
		},
	},
	folderUri,
	webviewEndpoint: config.webviews,
	additionalBuiltinExtensions: [ URI.parse( config.extension ) ],
	configurationDefaults: {
		'workbench.startupEditor': 'none',
		'chat.disableAIFeatures': true,
		// VS Code for the Web saves after a delay by default, which would publish half-typed
		// code on the site. Files are saved on Ctrl+S, like the Update File button of core.
		'files.autoSave': 'off',
		// Its model is not bundled.
		'workbench.editor.languageDetection': false,
		// The editor makes no external requests (schemas.wp.org would fail CORS anyway).
		'json.schemaDownload.enable': false,
	},
	windowIndicator: {
		label: `$(globe) ${ config.siteName }`,
		tooltip: config.siteName,
	},
	commands: [
		{
			id: 'codeWorkbench.config',
			handler: () => config.wordpress,
		},
	],
	workspaceProvider: {
		workspace: { folderUri },
		trusted: true,
		// Another plugin or theme is picked from the select menu of the admin screen.
		open: async () => false,
	},
} );
