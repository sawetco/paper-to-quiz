import { IDBFactory, IDBObjectStore } from 'fake-indexeddb';
import { readDraft, writeDraft } from './draft';

beforeEach( () => {
	Object.defineProperty( globalThis, 'structuredClone', {
		configurable: true,
		value: ( value: unknown ) => JSON.parse( JSON.stringify( value ) ),
	} );
	Object.defineProperty( globalThis, 'indexedDB', {
		configurable: true,
		value: new IDBFactory(),
	} );
} );

it( 'rejects a draft write whose transaction aborts after the request succeeds', async () => {
	const put = IDBObjectStore.prototype.put;
	const aborted = jest
		.spyOn( IDBObjectStore.prototype, 'put' )
		.mockImplementationOnce( function (
			this: IDBObjectStore,
			value: unknown,
			key?: IDBValidKey
		) {
			const request = put.call( this, value, key );
			request.addEventListener( 'success', () =>
				this.transaction.abort()
			);
			return request;
		} );
	const draft = {
		assessmentId: 9,
		publicId: 'public-id',
		revisionId: 12,
		active: 1,
		answers: { 3: { option: 'A', flagged: true } },
		submissionId: '11111111-1111-4111-8111-111111111111',
		finishRequested: true,
		automatic: true,
	};
	try {
		await expect( writeDraft( draft ) ).rejects.toThrow( /aborted/ );
		await expect( readDraft( draft.assessmentId ) ).resolves.toBeNull();
		await writeDraft( draft );
		await expect( readDraft( draft.assessmentId ) ).resolves.toMatchObject(
			draft
		);
	} finally {
		aborted.mockRestore();
	}
} );
