#!/usr/bin/env node
/**
 * Bundle the admin React islands.
 *
 * Every directory under assets/ui/src/ that holds an index.jsx becomes one
 * bundle at assets/ui/dist/<name>.js. The output is committed on purpose: the
 * release package is assembled from tracked files with `git archive`, so a
 * bundle that is not committed simply does not exist on a site. Run
 * `npm run build` after editing anything under assets/ui/src/.
 *
 * The plugin never loads node_modules at runtime; React is bundled into the
 * output and the files under src/ are the only source of truth.
 */

import { build, context } from 'esbuild';
import { existsSync, mkdirSync, readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';

const root = path.resolve( import.meta.dirname, '..' );
const sourceDir = path.join( root, 'assets', 'ui', 'src' );
const outputDir = path.join( root, 'assets', 'ui', 'dist' );
const watch = process.argv.includes( '--watch' );

const pluginHeader = readFileSync( path.join( root, 'sakura-tools.php' ), 'utf8' );
const versionMatch = pluginHeader.match( /^\s*\*\s*Version:\s*(\S+)/mu );
const version = versionMatch ? versionMatch[ 1 ] : 'dev';

const entries = readdirSync( sourceDir, { withFileTypes: true } )
	.filter( ( entry ) => entry.isDirectory() && existsSync( path.join( sourceDir, entry.name, 'index.jsx' ) ) )
	.map( ( entry ) => entry.name );

if ( 0 === entries.length ) {
	console.error( 'build-ui: no entry points found under assets/ui/src/*/index.jsx' );
	process.exit( 1 );
}

mkdirSync( outputDir, { recursive: true } );

/** @type {import('esbuild').BuildOptions} */
const options = {
	entryPoints: entries.map( ( name ) => ( {
		in: path.join( sourceDir, name, 'index.jsx' ),
		out: name,
	} ) ),
	outdir: outputDir,
	bundle: true,
	format: 'iife',
	globalName: 'SakuraUI',
	platform: 'browser',
	target: [ 'es2020', 'chrome111', 'firefox115', 'safari16' ],
	jsx: 'automatic',
	minify: ! watch,
	sourcemap: false,
	legalComments: 'none',
	charset: 'utf8',
	define: {
		'process.env.NODE_ENV': watch ? '"development"' : '"production"',
	},
	banner: {
		js: `/*! sakura-tools admin bundle ${version} — built from assets/ui/src, do not edit by hand. */`,
	},
	logLevel: 'info',
};

if ( watch ) {
	const ctx = await context( options );
	await ctx.watch();
	process.stdout.write( 'build-ui: watching assets/ui/src\n' );
} else {
	await build( options );
	for ( const name of entries ) {
		const file = path.join( outputDir, `${ name }.js` );
		process.stdout.write( `build-ui: ${ path.relative( root, file ) } (${ Math.round( ( await import( 'node:fs' ) ).statSync( file ).size / 1024 ) } KB)\n` );
	}
}
