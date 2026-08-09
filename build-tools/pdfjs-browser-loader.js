'use strict';

const nodeImportMeta =
	'process.getBuiltinModule("module").createRequire(import.meta.url)';
const portableNodeBase =
	'process.getBuiltinModule("module").createRequire("file:///pdfjs-dist/build/pdf.mjs")';

module.exports = function pdfjsBrowserLoader( source ) {
	const occurrences = source.split( nodeImportMeta ).length - 1;
	if ( occurrences !== 1 ) {
		throw new Error(
			`Expected one PDF.js Node import.meta reference, found ${ occurrences }.`
		);
	}

	return source.replace( nodeImportMeta, portableNodeBase );
};
