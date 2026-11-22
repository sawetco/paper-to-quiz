export function randomUuid(): string {
	if ( typeof crypto.randomUUID === 'function' ) {
		return crypto.randomUUID();
	}

	// getRandomValues is also available on ordinary HTTP origins.
	const bytes = crypto.getRandomValues( new Uint8Array( 16 ) );
	// Set the UUID version and variant while retaining the random low bits.
	bytes[ 6 ] = 0x40 + ( bytes[ 6 ] % 0x10 );
	bytes[ 8 ] = 0x80 + ( bytes[ 8 ] % 0x40 );
	const hex = Array.from( bytes, ( byte ) =>
		byte.toString( 16 ).padStart( 2, '0' )
	).join( '' );
	return [
		hex.slice( 0, 8 ),
		hex.slice( 8, 12 ),
		hex.slice( 12, 16 ),
		hex.slice( 16, 20 ),
		hex.slice( 20 ),
	].join( '-' );
}
