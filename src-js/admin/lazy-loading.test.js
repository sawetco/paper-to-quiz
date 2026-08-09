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
			/lazy\(\s*\(\)\s*=>\s*import\(\s*['"]\.\/Wizard['"]\s*\)/s
		);
	} );

	it( 'keeps PDF.js and Konva behind the wizard step boundary', () => {
		const wizard = source( 'Wizard.tsx' );
		const editor = source( 'PdfEditor.tsx' );

		expect( wizard ).not.toMatch(
			/import\s+\{\s*PdfEditor\s*\}\s+from\s+['"]\.\/PdfEditor['"]/
		);
		expect( wizard ).toMatch(
			/lazy\(\s*\(\)\s*=>\s*import\(\s*['"]\.\/PdfEditor['"]\s*\)/s
		);
		expect( editor ).toContain( "'pdfjs-dist/build/pdf.worker.min.mjs'" );
	} );

	it( 'uses the enqueued script URL for dynamic assets', () => {
		expect( source( '../../webpack.config.js' ) ).toContain(
			"publicPath: 'auto'"
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
