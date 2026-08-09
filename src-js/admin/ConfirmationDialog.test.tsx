import React from 'react';
import '@testing-library/jest-dom';
import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
} from '@testing-library/react';
import { ConfirmationDialog } from './ConfirmationDialog';

jest.mock( '@wordpress/element', () => {
	const ReactActual = jest.requireActual( 'react' );
	return {
		...ReactActual,
	};
} );

jest.mock( '@wordpress/i18n', () => ( {
	__: ( msgid: string ) => msgid,
} ) );

jest.mock( '@wordpress/components', () => ( {
	Button: ( {
		children,
		...props
	}: React.ButtonHTMLAttributes< HTMLButtonElement > & {
		children: React.ReactNode;
	} ) => {
		const { isDestructive, variant, ...buttonProps } =
			props as React.ButtonHTMLAttributes< HTMLButtonElement > & {
				isDestructive?: boolean;
				variant?: string;
			};
		return <button { ...buttonProps }>{ children }</button>;
	},
	Modal: ( {
		children,
		onRequestClose,
		title,
	}: {
		children: React.ReactNode;
		onRequestClose: () => void;
		title: string;
	} ) => (
		// eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions
		<div
			role="dialog"
			aria-label={ title }
			onKeyDown={ ( event ) => {
				if ( event.key === 'Escape' ) {
					onRequestClose();
				}
			} }
			tabIndex={ -1 }
		>
			<h2>{ title }</h2>
			<button type="button" onClick={ onRequestClose }>
				Close
			</button>
			{ children }
		</div>
	),
	Notice: ( { children }: { children: React.ReactNode } ) => (
		<div role="alert">{ children }</div>
	),
	TextControl: ( {
		label,
		onChange,
		value,
		disabled,
	}: {
		label: string;
		onChange: ( value: string ) => void;
		value?: string;
		disabled?: boolean;
	} ) => (
		<label htmlFor="confirmation-dialog-confirm-text">
			<span>{ label }</span>
			<input
				id="confirmation-dialog-confirm-text"
				aria-label={ label }
				disabled={ disabled }
				value={ value ?? '' }
				onChange={ ( event ) => onChange( event.currentTarget.value ) }
			/>
		</label>
	),
} ) );

describe( 'ConfirmationDialog', () => {
	async function renderDialog(
		overrides?: Partial< React.ComponentProps< typeof ConfirmationDialog > >
	) {
		const onClose = jest.fn();
		const onConfirm = jest.fn();

		function Harness() {
			const [ isOpen, setIsOpen ] = React.useState( false );
			return (
				<>
					<button type="button" onClick={ () => setIsOpen( true ) }>
						Trigger
					</button>
					<ConfirmationDialog
						isOpen={ isOpen }
						title="Delete item"
						description="This action cannot be undone."
						onClose={ onClose }
						onConfirm={ onConfirm }
						{ ...overrides }
					/>
				</>
			);
		}

		render( <Harness /> );
		const trigger = screen.getByRole( 'button', { name: 'Trigger' } );
		trigger.focus();
		await act( async () => {
			fireEvent.click( trigger );
			await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
		} );
		await waitFor( () =>
			expect(
				screen.getByRole( 'button', { name: 'Cancel' } )
			).toHaveFocus()
		);

		return { onClose, onConfirm };
	}

	it( 'opens with focus on Cancel and returns focus to the trigger on cancel', async () => {
		const { onClose } = await renderDialog();

		const cancel = await screen.findByRole( 'button', {
			name: 'Cancel',
		} );
		await waitFor( () => expect( cancel ).toHaveFocus() );

		act( () => {
			fireEvent.click( cancel );
		} );

		expect( onClose ).toHaveBeenCalledTimes( 1 );
		await waitFor( () =>
			expect(
				screen.getByRole( 'button', { name: 'Trigger' } )
			).toHaveFocus()
		);
	} );

	it( 'keeps Confirm disabled until the exact phrase is typed, then calls onConfirm once', async () => {
		const { onConfirm } = await renderDialog( {
			confirmPhrase: 'DELETE',
			confirmLabel: 'Confirm delete',
		} );

		const confirm = screen.getByRole( 'button', {
			name: 'Confirm delete',
		} );
		const input = screen.getByLabelText( 'Type to confirm' );

		expect( confirm ).toBeDisabled();

		act( () => {
			fireEvent.change( input, { target: { value: 'DEL' } } );
		} );
		expect( confirm ).toBeDisabled();

		act( () => {
			fireEvent.change( input, { target: { value: 'DELETE' } } );
		} );
		expect( confirm ).toBeEnabled();

		await act( async () => {
			fireEvent.click( confirm );
			await Promise.resolve();
		} );
		expect( onConfirm ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'disables actions while pending and blocks close requests until the promise settles', async () => {
		let resolveConfirm!: () => void;
		const onConfirm = jest.fn(
			() =>
				new Promise< void >( ( resolve ) => {
					resolveConfirm = resolve;
				} )
		);
		const onClose = jest.fn();
		await renderDialog( {
			onConfirm,
			onClose,
			confirmPhrase: 'DELETE',
		} );

		const confirm = screen.getByRole( 'button', { name: 'Confirm' } );
		const cancel = screen.getByRole( 'button', { name: 'Cancel' } );

		act( () => {
			fireEvent.change( screen.getByLabelText( 'Type to confirm' ), {
				target: { value: 'DELETE' },
			} );
			fireEvent.click( confirm );
		} );

		expect( onConfirm ).toHaveBeenCalledTimes( 1 );
		expect( confirm ).toBeDisabled();
		expect( cancel ).toBeDisabled();
		expect(
			screen.getByRole( 'button', { name: 'Working…' } )
		).toBeDisabled();

		act( () => {
			fireEvent.keyDown( screen.getByRole( 'dialog' ), {
				key: 'Escape',
			} );
			fireEvent.click( screen.getByRole( 'button', { name: 'Close' } ) );
		} );
		expect( onClose ).not.toHaveBeenCalled();

		await act( async () => {
			resolveConfirm();
		} );

		await waitFor( () => expect( onClose ).toHaveBeenCalledTimes( 1 ) );
	} );

	it( 'shows an inline error when onConfirm rejects and unlocks after the rejection', async () => {
		const onConfirm = jest.fn( () =>
			Promise.reject( new Error( 'Boom' ) )
		);
		await renderDialog( {
			onConfirm,
			confirmPhrase: 'DELETE',
		} );

		act( () => {
			fireEvent.change( screen.getByLabelText( 'Type to confirm' ), {
				target: { value: 'DELETE' },
			} );
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Confirm' } )
			);
		} );

		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			'Boom'
		);
		await waitFor( () =>
			expect(
				screen.getByRole( 'button', { name: 'Confirm' } )
			).toBeEnabled()
		);
		expect(
			screen.getByRole( 'button', { name: 'Cancel' } )
		).toBeEnabled();
		expect( screen.getByLabelText( 'Type to confirm' ) ).toBeEnabled();
	} );

	it( 'does not call onConfirm when cancel is used', async () => {
		const { onClose, onConfirm } = await renderDialog();

		act( () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
		} );

		expect( onClose ).toHaveBeenCalledTimes( 1 );
		expect( onConfirm ).not.toHaveBeenCalled();
	} );
} );
