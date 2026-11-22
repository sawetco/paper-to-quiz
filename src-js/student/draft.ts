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
		let blocked = false;
		request.onblocked = () => {
			blocked = true;
			reject( new Error( 'The draft database upgrade is blocked.' ) );
		};
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
		request.onsuccess = () => {
			const db = request.result;
			if ( blocked ) {
				db.close();
				return;
			}
			db.onversionchange = () => db.close();
			resolve( db );
		};
		request.onerror = () => reject( request.error );
	} );
}

async function runStoreRequest< T >(
	store: string,
	mode: IDBTransactionMode,
	operation: ( store: IDBObjectStore ) => IDBRequest< T >
): Promise< T > {
	const db = await database();
	try {
		return await new Promise< T >( ( resolve, reject ) => {
			const transaction = db.transaction( store, mode );
			const request = operation( transaction.objectStore( store ) );
			transaction.oncomplete = () => resolve( request.result );
			transaction.onabort = () =>
				reject(
					transaction.error ||
						new Error(
							'The draft database transaction was aborted.'
						)
				);
		} );
	} finally {
		db.close();
	}
}

export async function readDraft(
	assessmentId: number
): Promise< AttemptDraft | null > {
	const draft = await runStoreRequest< AttemptDraft | undefined >(
		DRAFT_STORE,
		'readonly',
		( store ) => store.get( assessmentId )
	);
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
	const now = Date.now();
	await runStoreRequest( DRAFT_STORE, 'readwrite', ( store ) =>
		store.put( { ...draft, updatedAt: now, expiresAt: now + TTL } )
	);
}

export async function deleteDraft( assessmentId: number ): Promise< void > {
	await runStoreRequest( DRAFT_STORE, 'readwrite', ( store ) =>
		store.delete( assessmentId )
	);
}

export async function readReceipt(
	assessmentId: number
): Promise< AttemptReceipt | null > {
	const receipt = await runStoreRequest< AttemptReceipt | undefined >(
		RECEIPT_STORE,
		'readonly',
		( store ) => store.get( assessmentId )
	);
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
	const now = Date.now();
	await runStoreRequest( RECEIPT_STORE, 'readwrite', ( store ) =>
		store.put( {
			...receipt,
			completedAt: now,
			expiresAt: now + RECEIPT_TTL,
		} )
	);
}

export async function deleteReceipt( assessmentId: number ): Promise< void > {
	await runStoreRequest( RECEIPT_STORE, 'readwrite', ( store ) =>
		store.delete( assessmentId )
	);
}
