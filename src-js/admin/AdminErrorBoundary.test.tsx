import React from 'react';
import '@testing-library/jest-dom';
import { fireEvent, render, screen } from '@testing-library/react';
import { AdminErrorBoundary } from './AdminErrorBoundary';

jest.mock( '@wordpress/element', () => jest.requireActual( 'react' ) );

jest.mock( '@wordpress/i18n', () => ( {
	__: ( msgid: string ) => msgid,
} ) );

function BrokenEditor(): React.ReactElement {
	throw new Error( 'Render failed' );
}

describe( 'AdminErrorBoundary', () => {
	it( 'replaces a crashed editor with a reload action', () => {
		const onReload = jest.fn();
		const consoleError = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => undefined );

		render(
			<AdminErrorBoundary onReload={ onReload }>
				<BrokenEditor />
			</AdminErrorBoundary>
		);

		expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
			'The editor could not be displayed.'
		);
		expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
			'Your saved changes are safe. Reload the page to continue.'
		);

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Reload page' } )
		);
		expect( onReload ).toHaveBeenCalledTimes( 1 );

		consoleError.mockRestore();
	} );
} );
