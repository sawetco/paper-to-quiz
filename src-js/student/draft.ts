export type DraftAnswer = {
	option: string | null;
	flagged: boolean;
};

export type AttemptDraft = {
	assessmentId: number;
	publicId: string;
	revisionId: number;
	active: number;
	answers: Record< number, DraftAnswer >;
	submissionId: string;
	finishRequested: boolean;
	automatic: boolean;
	updatedAt: number;
	expiresAt: number;
};

export type AttemptReceipt = {
	assessmentId: number;
	publicId: string;
	revisionId: number;
	completedAt: number;
	expiresAt: number;
};

const DB_NAME = 'ptq-student-drafts';
const DRAFT_STORE = 'drafts';
const RECEIPT_STORE = 'receipts';
const VERSION = 2;
const TTL = 7 * 24 * 60 * 60 * 1000;
const RECEIPT_TTL = 90 * 24 * 60 * 60 * 1000;

function database(): Promise< IDBDatabase > {
	return new Promise( ( resolve, reject ) => {
		const request = indexedDB.open( DB_NAME, VERSION );
		request.onupgradeneeded = () => {
			const db = request.result;
			if ( ! db.objectStoreNames.contains( DRAFT_STORE ) ) {
				db.createObjectStore( DRAFT_STORE, {
					keyPath: 'assessmentId',
				} );
			}
			if ( ! db.objectStoreNames.contains( RECEIPT_STORE ) ) {
				db.createObjectStore( RECEIPT_STORE, {
					keyPath: 'assessmentId',
				} );
			}
		};
		request.onsuccess = () => resolve( request.result );
		request.onerror = () => reject( request.error );
	} );
}

export async function readDraft(
	assessmentId: number
): Promise< AttemptDraft | null > {
	const db = await database();
	const draft = await new Promise< AttemptDraft | undefined >(
		( resolve, reject ) => {
			const request = db
				.transaction( DRAFT_STORE, 'readonly' )
				.objectStore( DRAFT_STORE )
				.get( assessmentId );
			request.onsuccess = () => resolve( request.result );
			request.onerror = () => reject( request.error );
		}
	);
	db.close();
	if ( ! draft ) {
		return null;
	}
	if ( draft.expiresAt <= Date.now() ) {
		await deleteDraft( assessmentId );
		return null;
	}
	return draft;
}

export async function writeDraft(
	draft: Omit< AttemptDraft, 'updatedAt' | 'expiresAt' >
): Promise< void > {
	const db = await database();
	await new Promise< void >( ( resolve, reject ) => {
		const request = db
			.transaction( DRAFT_STORE, 'readwrite' )
			.objectStore( DRAFT_STORE )
			.put( {
				...draft,
				updatedAt: Date.now(),
				expiresAt: Date.now() + TTL,
			} );
		request.onsuccess = () => resolve();
		request.onerror = () => reject( request.error );
	} );
	db.close();
}

export async function deleteDraft( assessmentId: number ): Promise< void > {
	const db = await database();
	await new Promise< void >( ( resolve, reject ) => {
		const request = db
			.transaction( DRAFT_STORE, 'readwrite' )
			.objectStore( DRAFT_STORE )
			.delete( assessmentId );
		request.onsuccess = () => resolve();
		request.onerror = () => reject( request.error );
	} );
	db.close();
}

export async function readReceipt(
	assessmentId: number
): Promise< AttemptReceipt | null > {
	const db = await database();
	const receipt = await new Promise< AttemptReceipt | undefined >(
		( resolve, reject ) => {
			const request = db
				.transaction( RECEIPT_STORE, 'readonly' )
				.objectStore( RECEIPT_STORE )
				.get( assessmentId );
			request.onsuccess = () => resolve( request.result );
			request.onerror = () => reject( request.error );
		}
	);
	db.close();
	if ( ! receipt ) {
		return null;
	}
	if ( receipt.expiresAt <= Date.now() ) {
		await deleteReceipt( assessmentId );
		return null;
	}
	return receipt;
}

export async function writeReceipt(
	receipt: Omit< AttemptReceipt, 'completedAt' | 'expiresAt' >
): Promise< void > {
	const db = await database();
	await new Promise< void >( ( resolve, reject ) => {
		const request = db
			.transaction( RECEIPT_STORE, 'readwrite' )
			.objectStore( RECEIPT_STORE )
			.put( {
				...receipt,
				completedAt: Date.now(),
				expiresAt: Date.now() + RECEIPT_TTL,
			} );
		request.onsuccess = () => resolve();
		request.onerror = () => reject( request.error );
	} );
	db.close();
}

export async function deleteReceipt( assessmentId: number ): Promise< void > {
	const db = await database();
	await new Promise< void >( ( resolve, reject ) => {
		const request = db
			.transaction( RECEIPT_STORE, 'readwrite' )
			.objectStore( RECEIPT_STORE )
			.delete( assessmentId );
		request.onsuccess = () => resolve();
		request.onerror = () => reject( request.error );
	} );
	db.close();
}
