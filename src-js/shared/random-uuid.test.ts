import { randomUuid } from './random-uuid';

it( 'generates a version 4 UUID without crypto.randomUUID', () => {
	const original = Object.getOwnPropertyDescriptor( crypto, 'randomUUID' );
	Object.defineProperty( crypto, 'randomUUID', {
		configurable: true,
		value: undefined,
	} );
	try {
		expect( randomUuid() ).toMatch(
			/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/
		);
	} finally {
		if ( original ) {
			Object.defineProperty( crypto, 'randomUUID', original );
		} else {
			delete ( crypto as Partial< Crypto > ).randomUUID;
		}
	}
} );
