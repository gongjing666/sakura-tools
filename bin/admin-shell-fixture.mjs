#!/usr/bin/env node
/**
 * Regenerate the persistent-shell fixture.
 *
 * Two files come out of this, and both are committed:
 *
 *   tests/fixtures/admin-shell.json  the chrome description the server prints
 *   tests/fixtures/admin-shell.html  the markup React renders from it
 *
 * tests/Admin/ShellMarkupTest.php compares Shell::render() with that markup
 * node by node. Hydration in React 19 is exact — a newline between two tags is
 * a text node the client tree does not have, and the whole tree is thrown away
 * and re-rendered — so the two renderers have to agree on structure, text and
 * whitespace, not merely on content.
 *
 * Run after changing Shell.php, assets/ui/src/shared/ui.jsx (the chrome parts)
 * or any module/tab label in Registry.
 */

import { build } from 'esbuild';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';

const root = path.resolve( import.meta.dirname, '..' );
const fixtures = path.join( root, 'tests', 'fixtures' );

mkdirSync( fixtures, { recursive: true } );

// 1. Ask PHP for the chrome: the server owns the description.
const dump = execFileSync( 'php', [ path.join( root, 'bin', 'admin-shell-chrome.php' ) ], {
	encoding: 'utf8',
} );
const data = JSON.parse( dump );

// 2. Render the same description with React, through the real components.
const work = mkdtempSync( path.join( tmpdir(), 'sakura-shell-' ) );
const entry = path.join( work, 'entry.jsx' );
const ui = path.join( root, 'assets', 'ui', 'src', 'shared', 'ui.jsx' );
const chromeFile = path.join( work, 'chrome.json' );

writeFileSync( chromeFile, JSON.stringify( data.chrome ) );
writeFileSync(
	entry,
	[
		"import { readFileSync } from 'node:fs';",
		"import { renderToStaticMarkup } from 'react-dom/server';",
		'import { Tabs, Topbar } from ' + JSON.stringify( ui ) + ';',
		'const chrome = JSON.parse( readFileSync( process.argv[ 2 ], "utf8" ) );',
		'process.stdout.write( renderToStaticMarkup( <><Topbar chrome={ chrome } /><Tabs chrome={ chrome } /></> ) );',
	].join( '\n' )
);

const bundle = path.join( work, 'render.cjs' );

await build( {
	entryPoints: [ entry ],
	outfile: bundle,
	bundle: true,
	platform: 'node',
	format: 'cjs',
	jsx: 'automatic',
	nodePaths: [ path.join( root, 'node_modules' ) ],
	logLevel: 'silent',
} );

const html = execFileSync( process.execPath, [ bundle, chromeFile ], { encoding: 'utf8' } );

writeFileSync( path.join( fixtures, 'admin-shell.json' ), JSON.stringify( data, null, '\t' ) + '\n' );
writeFileSync( path.join( fixtures, 'admin-shell.html' ), html );

process.stdout.write(
	'admin-shell fixture: ' + data.chrome.mods.length + ' modules, ' + data.chrome.tabs.length + ' tabs, ' + html.length + ' bytes\n'
);
