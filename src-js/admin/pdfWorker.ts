import { GlobalWorkerOptions } from 'pdfjs-dist';

export function initializePdfWorker(): void {
	GlobalWorkerOptions.workerSrc = new URL(
		'pdfjs-dist/build/pdf.worker.min.mjs',
		import.meta.url
	).toString();
}
