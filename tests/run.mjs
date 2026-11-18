// Runs the checks against a real shop.
//
// Without arguments it starts the demo store in WordPress Playground, runs tests/checks.php in it
// and then asks the shop pages for filtered lists as a visitor would.
//
// With SFFILTERS_TEST_URL set it skips Playground and asks that store instead. Run
// `wp eval-file tests/checks.php` there first and point SFFILTERS_TEST_RESULTS at its report.
import { spawn } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';

const external = process.env.SFFILTERS_TEST_URL?.replace( /\/$/, '' );
const port = Number( process.env.SFFILTERS_TEST_PORT || 9411 );
const base = external || `http://127.0.0.1:${ port }`;
const report = resolve( process.env.SFFILTERS_TEST_RESULTS || '.cache/test-results.json' );
const results = [];
let server;

function check( name, pass, detail = '' ) {
	results.push( { name, pass, detail: pass ? '' : String( detail ) } );
}

async function page( path ) {
	const response = await fetch( base + path, {
		// Playground logs visitors in unless this cookie says it already did.
		headers: { cookie: 'playground_auto_login_already_happened=1' },
	} );

	return { status: response.status, html: await response.text() };
}

// Number of products the page says it found, read from the WooCommerce result count.
function total( html ) {
	const text = html.match( /<p class="woocommerce-result-count"[^>]*>([\s\S]*?)<\/p>/ )?.[ 1 ];

	if ( text === undefined ) {
		return ( html.match( /<li class="[^"]*\btype-product\b/g ) || [] ).length;
	}

	if ( /single result/.test( text ) ) {
		return 1;
	}

	return Number( text.match( /(?:all|of) (\d+) results/ )?.[ 1 ] ?? NaN );
}

async function startPlayground() {
	const blueprint = JSON.parse( readFileSync( 'playground/blueprint.json', 'utf8' ) );

	blueprint.login = false;
	blueprint.steps.push( {
		step: 'runPHP',
		// An error that stops the checks is reported as a failed check.
		code: `<?php
require_once '/wordpress/wp-load.php';
$plugin = '/wordpress/wp-content/plugins/starfiniti-filters/';
try {
	require $plugin . 'tests/checks.php';
} catch ( Throwable $error ) {
	$failed = array( 'name' => 'tests/checks.php ran to the end', 'pass' => false, 'detail' => $error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine() );
	file_put_contents( $plugin . '.cache/test-results.json', json_encode( array( 'checks' => array( $failed ), 'http' => array() ) ) );
}`,
	} );

	mkdirSync( '.cache', { recursive: true } );
	rmSync( report, { force: true } );
	writeFileSync( '.cache/test-blueprint.json', JSON.stringify( blueprint ) );

	let output = '';
	let exited = false;

	server = spawn(
		process.execPath,
		[
			'node_modules/@wp-playground/cli/wp-playground.js',
			'server',
			'--blueprint=.cache/test-blueprint.json',
			'--mount-before-install=.:/wordpress/wp-content/plugins/starfiniti-filters',
			`--port=${ port }`,
		],
		{ stdio: [ 'ignore', 'pipe', 'pipe' ] }
	);
	server.stdout.on( 'data', ( chunk ) => ( output += chunk ) );
	server.stderr.on( 'data', ( chunk ) => ( output += chunk ) );
	server.on( 'exit', () => ( exited = true ) );

	const deadline = Date.now() + 10 * 60 * 1000;

	while ( Date.now() < deadline ) {
		if ( exited ) {
			throw new Error( `WordPress Playground stopped before the shop was ready.\n${ output }` );
		}

		if ( existsSync( report ) ) {
			const ready = await page( '/shop/' ).catch( () => null );

			if ( ready?.status === 200 ) {
				return;
			}
		}

		await new Promise( ( done ) => setTimeout( done, 2000 ) );
	}

	throw new Error( `The shop did not answer within ten minutes.\n${ output }` );
}

async function shopChecks( expected ) {
	const shop = await page( '/shop/' );
	const robots = ( html ) => html.match( /<meta name=["']robots["'][^>]*>/ )?.[ 0 ] ?? '';

	check( 'shop: the page loads', shop.status === 200, shop.status );
	check( 'shop: the products are wrapped for swapping', shop.html.includes( 'data-sffilters-results' ) );
	check( 'shop: the panel is on the page', /name="sf_cat\[\]"[^>]*value="headphones"|value="headphones"[^>]*name="sf_cat\[\]"/.test( shop.html ) );
	check( 'shop: the unfiltered page is not marked noindex', ! /noindex/.test( robots( shop.html ) ), robots( shop.html ) );
	check( 'shop: the script knows the page links of the WooCommerce loop, the Breakdance post loop and a Bricks query loop', [ '.woocommerce-pagination a', '.bde-posts-pagination a', '.bricks-pagination a' ].every( ( selector ) => shop.html.includes( selector ) ) );
	const styles = readFileSync( 'assets/filters.css', 'utf8' );

	check( 'shop: a checkbox sits in the middle of the first line of its label', /input\[type="checkbox"\] \{[^}]*align-self: flex-start;[^}]*margin-top: calc\(\(1lh - 1\.25em\) \/ 2\);/.test( styles ) && ! /translateY/.test( styles ) );
	check( 'shop: the handles of the price slider take the accent colour, not the colour the theme gives to inputs', /\.sffilters-slider input \{[^}]*color: inherit;/.test( styles ) );
	check( 'shop: a checkbox stays square where the theme pads every input', /input\[type="checkbox"\] \{[^}]*min-width: 0;[^}]*padding: 0;/.test( styles ) );
	check( 'shop: the wrapper keeps the layout of the Breakdance shop element', /\.breakdance-woocommerce > \.sffilters-results \{[^}]*display: inherit;[^}]*flex-flow: inherit;/.test( styles ) );
	check( 'litespeed: the page holds the words the script exclusions look for', shop.html.includes( 'sffiltersConfig' ) && shop.html.includes( 'sffilters-mobile' ) && shop.html.includes( 'starfiniti-filters/assets/filters.js' ) && shop.html.includes( 'starfiniti-filters/assets/filters.css' ) );
	check( 'shop: small screens are marked before the page is drawn', /matchMedia\("\(max-width: 768px\)"\)[^<]*sffilters-mobile/.test( shop.html ) );

	const filtered = await page( '/shop/?sf_cat=headphones' );
	const shown = Number( shop.html.match( /value="headphones"[\s\S]*?sffilters-option__count[^>]*>\s*\(?(\d+)/ )?.[ 1 ] ?? NaN );

	check( 'shop: the count next to a category is what choosing it lists', shown > 0 && shown === total( filtered.html ), `panel says ${ shown }, the list has ${ total( filtered.html ) }` );
	check( 'shop: a filtered page is marked noindex', /noindex/.test( robots( filtered.html ) ), robots( filtered.html ) );
	check( 'shop: the chosen value is ticked in the panel', /value="headphones"[^>]*\bchecked\b|\bchecked\b[^>]*value="headphones"/.test( filtered.html ) );
	check( 'shop: the chosen value can be removed above the products', /sffilters-chip/.test( filtered.html ) && ! /sffilters-chip/.test( shop.html ) );

	// Headphones is a subcategory of Audio in the demo store.
	const branch = ( html ) => html.match( /<details class="sffilters-branch" data-sffilters-branch="audio"[^>]*>/ )?.[ 0 ] ?? '';

	check( 'tree: subcategories fold away behind an arrow', '' !== branch( shop.html ) && ! / open/.test( branch( shop.html ) ) && /sffilters-options sffilters-options--tree/.test( shop.html ), branch( shop.html ) );
	check( 'tree: the arrow says which category it opens', /<summary class="sffilters-branch__toggle" aria-label="Audio: subcategories"><\/summary><ul class="sffilters-options/.test( shop.html ) );
	check( 'tree: the branch with a chosen value starts open', / open/.test( branch( filtered.html ) ), branch( filtered.html ) );
	check( 'tree: a chosen category does not open its own subcategories', ! / open/.test( branch( ( await page( '/shop/?sf_cat=audio' ) ).html ) ) );
	check( 'tree: every row leaves room for the arrow', /\.sffilters-options--tree \.sffilters-option__control \{\s*margin-left: 24px;/.test( styles ) && /\.sffilters-branch__toggle \{[^}]*position: absolute;[^}]*width: 24px;/.test( styles ) );
	check( 'tree: the script keeps the opened branches open', /\[data-sffilters-branch\]\[open\]/.test( readFileSync( 'assets/filters.js', 'utf8' ) ) );

	for ( const item of expected ) {
		const result = await page( item.path + ( item.query ? `?${ item.query }` : '' ) );
		const found = total( result.html );

		check( `shop: ${ item.name }`, result.status === 200 && found === item.total, `${ item.path }?${ item.query } answered ${ result.status } with ${ found } products, expected ${ item.total }` );
	}

	const second = await page( '/shop/page/2/?sf_stock=instock' );
	const inStock = expected.find( ( item ) => item.name === 'in stock' )?.total;

	check( 'shop: the second page keeps the filter', second.status === 200 && total( second.html ) === inStock, `answered ${ second.status } with ${ total( second.html ) } of ${ inStock }` );
}

// A product list drawn from the main query without the WooCommerce loop hooks, as a page builder loop does.
async function loopChecks( loop ) {
	const plain = await page( loop.path );
	const filtered = await page( `${ loop.path }?${ loop.query }` );
	const base = plain.html.match( /class="sffilters-bar[^"]*" data-sffilters-base="([^"]*)"/ )?.[ 1 ] ?? '';

	check( 'layout: the page of the test layout loads', plain.status === 200 && plain.html.includes( '<ul class="bde-loop">' ), plain.status );
	check( 'layout: the plugin adds no wrapper of its own there', ! plain.html.includes( 'data-sffilters-results' ) );
	check( 'layout: the bar knows page one of the list', base.endsWith( loop.path ), base );
	check( 'layout: the sorting dropdown is drawn', /<form class="woocommerce-ordering"/.test( plain.html ) );
	check( 'layout: the panel is drawn', /name="sf_brand\[\]"/.test( plain.html ) );
	check( 'layout: a filter narrows the list', filtered.status === 200 && total( filtered.html ) === loop.filtered, `answered ${ filtered.status } with ${ total( filtered.html ) } products, expected ${ loop.filtered }` );
	check( 'layout: the chosen value can be removed above the products', /sffilters-chip/.test( filtered.html ) && ! /sffilters-chip/.test( plain.html ) );
}

// Filters by what the store works out for its products, see tests/properties.php.
async function propertyChecks( properties ) {
	const shop = await page( '/shop/' );
	const category = await page( properties.path );

	check( 'properties: the property is in the panel', /name="sf_tone\[\]"[^>]*value="dark"|value="dark"[^>]*name="sf_tone\[\]"/.test( shop.html ) );
	check( 'properties: the number is left out where few products have it', ! shop.html.includes( 'name="sf_length[min]"' ) );
	check( 'properties: the number is in the panel where enough products have it', category.status === 200 && category.html.includes( 'name="sf_length[min]"' ), category.status );
	check( 'properties: the number shows its unit', /sffilters-range__unit">cm</.test( category.html ) );

	// A third of the demo products has a tag.
	const always = await page( '/shop/?sffilters_test_share=0' );
	const mostly = await page( '/shop/?sffilters_test_share=0.9' );
	const some = await page( '/shop/?sffilters_test_share=0.3' );

	check( 'share: a taxonomy filter shows whenever a listed product has a term', always.html.includes( 'name="sf_tag[]"' ) );
	check( 'share: it is left out where too few of the listed products have one', mostly.status === 200 && ! mostly.html.includes( 'name="sf_tag[]"' ) );
	check( 'share: it shows where enough of them do', some.html.includes( 'name="sf_tag[]"' ) );

	// Ready-made price ranges.
	const banded = await page( '/shop/?sffilters_test_ranges=1' );
	const band = banded.html.match( /<input type="radio" name="sf_price" value="([^"]+)"> <span class="sffilters-option__label">[^<]*<\/span> <span class="sffilters-option__count">(\d+)/ );
	const picked = band ? await page( `/shop/?sffilters_test_ranges=1&sf_price=${ band[ 1 ] }` ) : { html: '' };
	const styles = readFileSync( 'assets/filters.css', 'utf8' );

	check( 'ranges: the price filter offers radio buttons and no fields', band !== null && ! banded.html.includes( 'name="sf_price[min]"' ) && shop.html.includes( 'name="sf_price[min]"' ) );
	check( 'ranges: choosing one lists what it counted', band !== null && total( picked.html ) === Number( band[ 2 ] ), band ? `the range ${ band[ 1 ] } says ${ band[ 2 ] }, the list has ${ total( picked.html ) }` : 'no range found' );
	check( 'ranges: the chosen one is marked for the script', band !== null && picked.html.includes( `value="${ band[ 1 ] }" checked data-sffilters-on>` ) );
	check( 'ranges: the chosen one can be removed above the products', /sffilters-chip/.test( picked.html ) );
	check( 'ranges: radio buttons are drawn round and sit on the first line', /input\[type="radio"\] \{[^}]*border-radius: 50%;[^}]*margin-top: calc\(\(1lh - 1\.25em\) \/ 2\);/.test( styles ) );
	check( 'ranges: choosing the chosen range again gives it up', /input\[type="radio"\]\[data-sffilters-on\]/.test( readFileSync( 'assets/filters.js', 'utf8' ) ) );

	const pruned = await page( '/shop/?sffilters_test_facet=1' );

	check( 'hooks: a store can leave a value out of a filter', shop.html.includes( 'value="outofstock"' ) && pruned.html.includes( 'value="instock"' ) && ! pruned.html.includes( 'value="outofstock"' ) );
}

// Categories as links, one level at a time. The helper of the checks switches this on for `sffilters_test_level`.
async function levelChecks( level ) {
	const panel = ( html ) => html.match( /<form class="sffilters-panel"[\s\S]*?<\/form>/ )?.[ 0 ] ?? '';
	const shop = panel( ( await page( '/shop/?sffilters_test_level=1' ) ).html );
	const leaf = panel( ( await page( `${ level.leaf }?sffilters_test_level=1` ) ).html );
	const link = ( path ) => new RegExp( `href="[^"]*${ path }"` );

	check( 'level: the shop links to the top categories only', link( level.above ).test( shop ) && ! link( level.leaf ).test( shop ) && ! shop.includes( 'name="sf_cat[]"' ) );
	check( 'level: a category without subcategories links to the one beside it', link( level.beside ).test( leaf ) );
	check( 'level: the category being viewed is marked', /aria-current="page"/.test( leaf ) );
	check( 'level: the way back leads one level up', new RegExp( `sffilters-filter__all"><a href="[^"]*${ level.above }"` ).test( leaf ) );
	check( 'level: one level has nothing to fold', ! shop.includes( 'sffilters-branch' ) && ! leaf.includes( 'sffilters-branch' ) );

	// The whole tree as links: the branch of the category being viewed is open, the others are folded.
	const tree = panel( ( await page( '/shop/?sffilters_test_level=links' ) ).html );
	const inside = panel( ( await page( `${ level.leaf }?sffilters_test_level=links` ) ).html );
	const open = ( html ) => ( html.match( /<details class="sffilters-branch"[^>]* open>/g ) || [] ).length;

	check( 'links: the tree of links folds its subcategories', tree.includes( '<details class="sffilters-branch"' ) && 0 === open( tree ) && link( level.leaf ).test( tree ) );
	check( 'links: the branch of the category being viewed is open', 1 === open( inside ) && new RegExp( `data-sffilters-branch="[^"]*${ level.above }" open>` ).test( inside ), open( inside ) );
}

try {
	if ( ! external ) {
		await startPlayground();
	}

	if ( ! existsSync( report ) ) {
		throw new Error( `No report from tests/checks.php at ${ report }.` );
	}

	const fromPhp = JSON.parse( readFileSync( report, 'utf8' ) );

	results.push( ...fromPhp.checks );
	await shopChecks( fromPhp.http );

	if ( fromPhp.loop ) {
		await loopChecks( fromPhp.loop );
	}

	if ( fromPhp.properties ) {
		await propertyChecks( fromPhp.properties );
	}

	if ( fromPhp.level ) {
		await levelChecks( fromPhp.level );
	}

	if ( fromPhp.loop ) {
		const seo = ( html ) => html.match( /<meta name="sffilters-test-robots" content="([^"]*)"/ )?.[ 1 ] ?? '';

		check( 'robots: Rank Math is told to keep a filtered page out of search engines', 'noindex,follow' === seo( ( await page( '/shop/?sf_cat=headphones&sffilters_test_robots=1' ) ).html ) );
		check( 'robots: Rank Math is left alone on an unfiltered page', 'index,follow' === seo( ( await page( '/shop/?sffilters_test_robots=1' ) ).html ) );
	}
} catch ( error ) {
	check( 'the test run itself', false, error.stack || error.message );
} finally {
	server?.kill();
}

const failed = results.filter( ( result ) => ! result.pass );

for ( const result of results ) {
	console.log( ( result.pass ? 'ok      ' : 'FAILED  ' ) + result.name + ( result.detail ? `\n        ${ result.detail }` : '' ) );
}

console.log( `\n${ results.length - failed.length } of ${ results.length } checks passed.` );
process.exit( failed.length ? 1 : 0 );
