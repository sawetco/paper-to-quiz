import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { Button, Modal, Notice, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

export type ConsequenceRow = {
	label: string;
	value: string | number;
};

export type ConfirmationSecondaryAction = {
	label: string;
	isDestructive?: boolean;
	onAction: () => Promise< void > | void;
};

export function ConfirmationDialog( {
	isOpen,
	title,
	description,
	consequences,
	consequence,
	cancelLabel = __( 'Cancel', 'paper-to-quiz' ),
	confirmLabel = __( 'Confirm', 'paper-to-quiz' ),
	secondaryAction,
	isDestructive = false,
	confirmPhrase,
	onClose,
	onConfirm,
}: {
	isOpen: boolean;
	title: string;
	description: ReactNode;
	consequences?: ConsequenceRow[];
	consequence?: string;
	cancelLabel?: string;
	confirmLabel?: string;
	secondaryAction?: ConfirmationSecondaryAction;
	isDestructive?: boolean;
	confirmPhrase?: string;
	onClose: () => void;
	onConfirm: () => Promise< void > | void;
} ) {
	const [ phrase, setPhrase ] = useState( '' );
	const [ pending, setPending ] = useState( false );
	const [ error, setError ] = useState( '' );
	const actionsRef = useRef< HTMLDivElement | null >( null );
	const returnRef = useRef< HTMLElement | null >( null );

	const phraseMatches = useMemo(
		() => ! confirmPhrase || phrase === confirmPhrase,
		[ confirmPhrase, phrase ]
	);

	// Keep the dialog mounted through the async action. On open, reset local
	// state, capture the invoking control, and default focus to Cancel. On
	// close, focus returns to that control via handleClose. Escape/cancel stay
	// blocked while a request is in flight via onRequestClose and disabled.
	useEffect( () => {
		if ( ! isOpen ) {
			return undefined;
		}
		setPhrase( '' );
		setPending( false );
		setError( '' );
		const activeElement = actionsRef.current?.ownerDocument
			.activeElement as HTMLElement | null;
		returnRef.current = activeElement ?? null;
		const timeoutId = window.setTimeout( () => {
			const cancel = actionsRef.current?.querySelector(
				'.ptq-confirmation-dialog__cancel'
			) as HTMLButtonElement | null;
			cancel?.focus();
		}, 0 );
		return () => window.clearTimeout( timeoutId );
	}, [ isOpen ] );

	function handleClose() {
		const target = returnRef.current;
		returnRef.current = null;
		target?.focus?.();
		onClose();
	}

	async function submit( handler: () => Promise< void > | void ) {
		if ( pending ) {
			return;
		}
		if ( confirmPhrase && ! phraseMatches ) {
			setError(
				__( 'The confirmation text does not match.', 'paper-to-quiz' )
			);
			return;
		}
		setPending( true );
		setError( '' );
		try {
			await handler();
			// Success: callers receive close via this single path, so no
			// mutation runs again and the dialog state stays consistent.
			handleClose();
		} catch ( caught ) {
			setError(
				caught instanceof Error
					? caught.message
					: __(
							'The action could not be completed.',
							'paper-to-quiz'
					  )
			);
		} finally {
			setPending( false );
		}
	}

	if ( ! isOpen ) {
		return null;
	}

	return (
		<Modal
			title={ title }
			focusOnMount={ false }
			onRequestClose={ () => {
				if ( ! pending ) {
					handleClose();
				}
			} }
		>
			<div className="ptq-confirmation-dialog">
				{ description && (
					<p className="ptq-confirmation-dialog__description">
						{ description }
					</p>
				) }
				{ !! consequences?.length && (
					<ul className="ptq-confirmation-dialog__consequences">
						{ consequences.map( ( row ) => (
							<li key={ row.label }>
								<span>{ row.label }</span>
								<strong>{ row.value }</strong>
							</li>
						) ) }
					</ul>
				) }
				{ consequence && (
					<p className="ptq-confirmation-dialog__consequence">
						<strong>{ consequence }</strong>
					</p>
				) }
				{ confirmPhrase && (
					<TextControl
						label={ __( 'Type to confirm', 'paper-to-quiz' ) }
						value={ phrase }
						disabled={ pending }
						onChange={ ( value ) => {
							setPhrase( value );
							if ( error ) {
								setError( '' );
							}
						} }
					/>
				) }
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				<div
					ref={ actionsRef }
					className="ptq-confirmation-dialog__actions"
				>
					<Button
						className="ptq-confirmation-dialog__cancel"
						variant="tertiary"
						disabled={ pending }
						onClick={ handleClose }
					>
						{ cancelLabel }
					</Button>
					{ secondaryAction && (
						<Button
							variant="secondary"
							isDestructive={ secondaryAction.isDestructive }
							disabled={ pending }
							onClick={ () =>
								void submit( secondaryAction.onAction )
							}
						>
							{ pending
								? __( 'Working…', 'paper-to-quiz' )
								: secondaryAction.label }
						</Button>
					) }
					<Button
						variant={ isDestructive ? 'primary' : 'secondary' }
						isDestructive={ isDestructive }
						disabled={
							pending || ( !! confirmPhrase && ! phraseMatches )
						}
						onClick={ () => void submit( onConfirm ) }
					>
						{ pending
							? __( 'Working…', 'paper-to-quiz' )
							: confirmLabel }
					</Button>
				</div>
			</div>
		</Modal>
	);
}
