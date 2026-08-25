import { readFileSync } from 'node:fs';
import { join } from 'node:path';

function source( fileName ) {
	return readFileSync( join( __dirname, fileName ), 'utf8' );
}

describe( 'admin authoring loading boundaries', () => {
	it( 'keeps Wizard out of the synchronous App imports', () => {
		const app = source( 'App.tsx' );

		expect( app ).not.toMatch(
			/import\s+\{\s*Wizard\s*\}\s+from\s+['"]\.\/Wizard['"]/
		);
		expect( app ).toMatch(
			/lazy\(\s*\(\)\s*=>\s*import\([\s\S]*webpackChunkName:\s*["']admin-wizard["'][\s\S]*['"]\.\/Wizard['"]\s*\)/
		);
	} );

	it( 'keeps PDF.js and Konva behind the wizard step boundary', () => {
		const wizard = source( 'Wizard.tsx' );
		const editor = source( 'PdfEditor.tsx' );

		expect( wizard ).not.toMatch(
			/import\s+\{\s*PdfEditor\s*\}\s+from\s+['"]\.\/PdfEditor['"]/
		);
		expect( wizard ).toMatch(
			/lazy\(\s*\(\)\s*=>\s*import\([\s\S]*webpackChunkName:\s*["']admin-pdf-editor["'][\s\S]*['"]\.\/PdfEditor['"]\s*\)/
		);
		expect( editor ).toContain( "'pdfjs-dist/build/pdf.worker.min.mjs'" );
	} );

	it( 'uses the enqueued script URL for dynamic assets', () => {
		const config = source( '../../webpack.config.js' );
		expect( config ).toContain( "publicPath: 'auto'" );
		expect( config ).toContain( "chunkFilename: '[name].js'" );
	} );

	it( 'registers translation-ready handles for translatable admin chunks', () => {
		const adminMenu = source( '../../src/Admin/AdminMenu.php' );

		expect( adminMenu ).toContain(
			"'paper-to-quiz-admin-wizard'     => 'admin-wizard.js'"
		);
		expect( adminMenu ).toContain(
			"'paper-to-quiz-admin-pdf-editor' => 'admin-pdf-editor.js'"
		);
		expect( adminMenu ).toContain(
			"wp_set_script_translations($handle, 'paper-to-quiz')"
		);
		expect( adminMenu ).toContain( '$dependencies[] = $handle' );
	} );

	it( 'keeps generated translation catalogs out of nested package paths', () => {
		const packageFiles = JSON.parse( source( '../../package.json' ) ).files;

		expect( packageFiles ).toEqual(
			expect.arrayContaining( [
				'!languages/**/*.json',
				'!languages/**/*.mo',
				'!languages/**/*.po',
				'!languages/**/*.php',
			] )
		);
	} );

	it( 'uses the registered admin term routes for authoring prerequisites', () => {
		const app = source( 'App.tsx' );
		const wizard = source( 'Wizard.tsx' );

		expect( app ).toContain(
			'/admin/classes?status=active&page=1&per_page=1'
		);
		expect( app ).toContain(
			'/admin/subjects?status=active&page=1&per_page=1'
		);
		expect( wizard ).toContain(
			'/admin/classes?status=active&page=1&per_page=100'
		);
		expect( wizard ).toContain(
			'/admin/subjects?status=active&page=1&per_page=100'
		);
	} );
} );
