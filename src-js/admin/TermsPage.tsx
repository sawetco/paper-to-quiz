import { useEffect, useRef, useState } from '@wordpress/element';
import { Notice, Spinner } from '@wordpress/components';
import { __, _n, _x, sprintf } from '@wordpress/i18n';
import type { ListResponse, Term } from '../types';
import { api } from './api';
import { ConfirmationDialog, type ConsequenceRow } from './ConfirmationDialog';
import { ListPagination } from './ListPagination';
import { BusyLabel } from './BusyLabel';

const emptyResponse: ListResponse< Term > = {
	items: [],
	total: 0,
	pages: 0,
	page: 1,
	counts: {},
};
type TermAction = 'archive' | 'trash' | 'restore' | 'delete_permanently';
type TermView = {
	status: 'active' | 'archived' | 'trash';
	page: number;
	search: string;
	focus?: string;
};
type ConfirmState = null | {
	action: TermAction;
	ids: number[];
	title: string;
	description: string;
	consequences?: ConsequenceRow[];
	consequence: string;
	confirmLabel: string;
	confirmPhrase?: string;
};

export function ClassesPage() {
	return <TermsPage type="classes" />;
}

export function SubjectsPage() {
	return <TermsPage type="subjects" />;
}

function TermsPage( { type }: { type: 'classes' | 'subjects' } ) {
	const [ list, setList ] = useState( emptyResponse );
	const [ name, setName ] = useState( '' );
	const [ color, setColor ] = useState( '' );
	const [ editingId, setEditingId ] = useState< number | null >( null );
	const [ selected, setSelected ] = useState< number[] >( [] );
	const [ searchInput, setSearchInput ] = useState( '' );
	const [ view, setView ] = useState< TermView >( {
		status: 'active',
		page: 1,
		search: '',
	} );
	const { status } = view;
	const [ loading, setLoading ] = useState( true );
	const [ listError, setListError ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ pendingAction, setPendingAction ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ message, setMessage ] = useState( '' );
	const [ confirmation, setConfirmation ] = useState< ConfirmState >( null );
	const actionLock = useRef( false );
	const navigationDisabled = loading || saving || Boolean( pendingAction );
	const label =
		type === 'classes'
			? _x( 'Class', 'Term label', 'paper-to-quiz' )
			: _x( 'Subject', 'Term label', 'paper-to-quiz' );
	const pluralLabel =
		type === 'classes'
			? _x( 'Classes', 'Term page heading', 'paper-to-quiz' )
			: _x( 'Subjects', 'Term page heading', 'paper-to-quiz' );
	const submitAction = editingId
		? __( 'Update', 'paper-to-quiz' )
		: __( 'Add', 'paper-to-quiz' );
	const submitLabel = sprintf(
		/* translators: 1: First formatted value. 2: Second formatted value. */
		__( '%1$s %2$s', 'paper-to-quiz' ),
		submitAction,
		label
	);

	useEffect( () => {
		const controller = new AbortController();
		const { signal } = controller;
		const query = new URLSearchParams( {
			status: view.status,
			page: String( view.page ),
			per_page: '20',
		} );
		if ( view.search ) {
			query.set( 'search', view.search );
		}
		if ( view.focus ) {
			query.set( 'focus', view.focus );
		}
		setLoading( true );
		setListError( '' );
		setSelected( [] );
		api< ListResponse< Term > >( `/admin/${ type }?${ query }`, {
			signal,
		} )
			.then( ( response ) => {
				if ( ! signal.aborted ) {
					setList( response );
				}
			} )
			.catch( ( caught ) => {
				if ( ! signal.aborted ) {
					setList( emptyResponse );
					setListError(
						caught instanceof Error
							? caught.message
							: __(
									'The list could not be loaded.',
									'paper-to-quiz'
								)
					);
				}
			} )
			.finally( () => {
				if ( ! signal.aborted ) {
					setLoading( false );
				}
			} );
		return () => controller.abort();
	}, [ type, view ] );

	function selectStatus( nextStatus: TermView[ 'status' ] ) {
		setLoading( true );
		setView( ( current ) => ( {
			status: nextStatus,
			page: 1,
			search: current.search,
		} ) );
	}

	function selectPage( page: number ) {
		setLoading( true );
		setView( ( current ) => ( {
			status: current.status,
			page,
			search: current.search,
		} ) );
	}

	async function save( event: React.FormEvent ) {
		event.preventDefault();
		if ( actionLock.current ) {
			return;
		}
		if ( ! name.trim() ) {
			setError(
				sprintf(
					/* translators: %s: Item type. */
					__( 'Enter a %s name.', 'paper-to-quiz' ),
					label
				)
			);
			return;
		}
		actionLock.current = true;
		setSaving( true );
		setError( '' );
		setMessage( '' );
		try {
			const saved = await api< Term >( `/admin/${ type }`, {
				method: 'POST',
				json: {
					name: name.trim(),
					id: editingId || undefined,
					...( type === 'classes' ? { color } : {} ),
				},
			} );
			setMessage(
				sprintf(
					/* translators: 1: Action, 2: Term type. */
					__( '%1$s %2$s.', 'paper-to-quiz' ),
					editingId
						? __( 'Updated', 'paper-to-quiz' )
						: __( 'Added', 'paper-to-quiz' ),
					label
				)
			);
			setName( '' );
			setColor( '' );
			setEditingId( null );
			setSearchInput( '' );
			setLoading( true );
			setView( {
				status: 'active',
				page: 1,
				search: '',
				focus: saved.id,
			} );
		} catch ( caught ) {
			setError(
				caught instanceof Error
					? caught.message
					: __( 'The record could not be saved.', 'paper-to-quiz' )
			);
		} finally {
			actionLock.current = false;
			setSaving( false );
		}
	}

	async function mutate(
		action: TermAction,
		ids: number[],
		askConfirmation = true
	): Promise< void > {
		if ( actionLock.current ) {
			return;
		}
		if ( ! ids.length ) {
			setError(
				__(
					'Select at least one record for the action.',
					'paper-to-quiz'
				)
			);
			return;
		}
		if ( askConfirmation && action === 'archive' ) {
			setConfirmation( {
				action,
				ids,
				title: sprintf(
					/* translators: %s: Lowercase plural term type. */
					__( 'Archive the selected %s?', 'paper-to-quiz' ),
					pluralLabel.toLocaleLowerCase()
				),
				description: sprintf(
					/* translators: %s: Lowercase plural term type. */
					__( 'Archive the selected %s?', 'paper-to-quiz' ),
					pluralLabel.toLocaleLowerCase()
				),
				consequence: __(
					'Archived records stay available for restoration.',
					'paper-to-quiz'
				),
				confirmLabel: __( 'Archive', 'paper-to-quiz' ),
			} );
			return;
		}
		if ( askConfirmation && action === 'trash' ) {
			setConfirmation( {
				action,
				ids,
				title: sprintf(
					/* translators: %s: Lowercase plural term type. */
					__(
						'Move the selected %s to the trash? Historical usage will be preserved.',
						'paper-to-quiz'
					),
					pluralLabel.toLocaleLowerCase()
				),
				description: sprintf(
					/* translators: %s: Lowercase plural term type. */
					__( 'Move the selected %s to the trash?', 'paper-to-quiz' ),
					pluralLabel.toLocaleLowerCase()
				),
				consequence: __(
					'Historical usage will be preserved.',
					'paper-to-quiz'
				),
				confirmLabel: __( 'Move to trash', 'paper-to-quiz' ),
			} );
			return;
		}
		if ( askConfirmation && action === 'delete_permanently' ) {
			const selectedTerms = list.items.filter( ( item ) =>
				ids.includes( Number( item.id ) )
			);
			const required =
				selectedTerms.length === 1
					? selectedTerms[ 0 ].name
					: __( 'PERMANENTLY DELETE', 'paper-to-quiz' );
			const deletionMessage = sprintf(
				/* translators: %s: Lowercase plural term type. */
				__(
					'The selected %s will be permanently deleted.',
					'paper-to-quiz'
				),
				pluralLabel.toLocaleLowerCase()
			);
			const confirmationMessage = sprintf(
				/* translators: %s: Required confirmation text. */
				__( 'Type “%s” to confirm.', 'paper-to-quiz' ),
				required
			);
			const consequences = [
				{
					label: __( 'Selected terms', 'paper-to-quiz' ),
					value: selectedTerms.length,
				},
			];
			setConfirmation( {
				action,
				ids,
				title: __( 'Delete permanently?', 'paper-to-quiz' ),
				description: deletionMessage,
				consequences,
				consequence: confirmationMessage,
				confirmLabel: __( 'Delete permanently', 'paper-to-quiz' ),
				confirmPhrase: required,
			} );
			return;
		}
		actionLock.current = true;
		setPendingAction(
			ids.length === 1 ? `${ action }-${ ids[ 0 ] }` : 'bulk'
		);
		setError( '' );
		setMessage( '' );
		try {
			const response = await api< {
				changed: number;
				errors?: string[];
			} >( `/admin/${ type }/bulk`, {
				method: 'POST',
				json: { action, ids },
			} );
			if ( response.errors?.length ) {
				const details = response.errors.join( '\n' );
				if ( response.changed === 0 ) {
					throw new Error( details );
				}
				setError( details );
			}
			let successMessage: string = sprintf(
				/* translators: %d: Number of permanently deleted records. */
				_n(
					'%d record was permanently deleted.',
					'%d records were permanently deleted.',
					response.changed,
					'paper-to-quiz'
				),
				response.changed
			);
			if ( action === 'restore' ) {
				successMessage = __(
					'The selected records were restored.',
					'paper-to-quiz'
				);
			} else if ( action === 'archive' ) {
				successMessage = __(
					'The selected records were archived.',
					'paper-to-quiz'
				);
			} else if ( action === 'trash' ) {
				successMessage = __(
					'The selected records were moved to the trash.',
					'paper-to-quiz'
				);
			}
			if ( response.changed > 0 ) {
				setMessage( successMessage );
			}
			setLoading( true );
			setView( ( current ) => ( {
				status: current.status,
				page: list.page,
				search: current.search,
			} ) );
		} catch ( caught ) {
			if ( ! askConfirmation ) {
				throw caught;
			}
			setError(
				caught instanceof Error
					? caught.message
					: __(
							'The action could not be completed.',
							'paper-to-quiz'
						)
			);
		} finally {
			actionLock.current = false;
			setPendingAction( '' );
		}
	}

	const allChecked =
		list.items.length > 0 &&
		list.items.every( ( item ) => selected.includes( Number( item.id ) ) );
	const bulkControls = (
		<div className="alignleft actions bulkactions">
			<select
				aria-label={ __( 'Bulk action', 'paper-to-quiz' ) }
				disabled={ navigationDisabled || Boolean( listError ) }
			>
				<option value="">
					{ __( 'Bulk actions', 'paper-to-quiz' ) }
				</option>
				{ status === 'active' && (
					<>
						<option value="archive">
							{ __( 'Archive', 'paper-to-quiz' ) }
						</option>
						<option value="trash">
							{ __( 'Move to trash', 'paper-to-quiz' ) }
						</option>
					</>
				) }
				{ status === 'archived' && (
					<>
						<option value="restore">
							{ __( 'Restore', 'paper-to-quiz' ) }
						</option>
						<option value="trash">
							{ __( 'Move to trash', 'paper-to-quiz' ) }
						</option>
					</>
				) }
				{ status === 'trash' && (
					<>
						<option value="restore">
							{ __( 'Restore', 'paper-to-quiz' ) }
						</option>
						<option value="delete_permanently">
							{ __( 'Delete permanently', 'paper-to-quiz' ) }
						</option>
					</>
				) }
			</select>
			<button
				type="button"
				className="button action"
				disabled={ navigationDisabled || Boolean( listError ) }
				onClick={ ( event ) => {
					const select = event.currentTarget
						.previousElementSibling as HTMLSelectElement;
					if ( select.value ) {
						void mutate( select.value as TermAction, selected );
					}
				} }
			>
				{ pendingAction === 'bulk' ? (
					<BusyLabel>
						{ __( 'Processing…', 'paper-to-quiz' ) }
					</BusyLabel>
				) : (
					__( 'Apply', 'paper-to-quiz' )
				) }
			</button>
		</div>
	);

	return (
		<div className="ptq-page ptq-terms-page">
			<h1 className="wp-heading-inline">{ pluralLabel }</h1>
			<hr className="wp-header-end" />
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			{ message && (
				<Notice status="success" onRemove={ () => setMessage( '' ) }>
					{ message }
				</Notice>
			) }
			<div className="col-container">
				<div className="col-left">
					<div className="col-wrap">
						<div className="form-wrap">
							<h2>
								{ editingId
									? sprintf(
											/* translators: %s: Item type. */
											__( 'Edit %s', 'paper-to-quiz' ),
											label
										)
									: sprintf(
											/* translators: %s: Term type. */
											__(
												'Add a new %s',
												'paper-to-quiz'
											),
											label
										) }
							</h2>
							<form onSubmit={ save }>
								<div className="form-field form-required">
									<label htmlFor="ptq-term-name">
										{ __( 'Name', 'paper-to-quiz' ) }
									</label>
									<input
										id="ptq-term-name"
										name="name"
										type="text"
										value={ name }
										disabled={ saving }
										onChange={ ( event ) =>
											setName( event.target.value )
										}
										maxLength={ 190 }
										required
									/>
									<p>
										{ sprintf(
											/* translators: %s: Lowercase term type. */
											__(
												'Enter the %s name that will appear when creating exams and tests.',
												'paper-to-quiz'
											),
											label.toLocaleLowerCase()
										) }
									</p>
								</div>
								{ type === 'classes' && (
									<div className="form-field">
										<label htmlFor="ptq-term-color">
											{ __(
												'Primary color',
												'paper-to-quiz'
											) }
										</label>
										<div className="ptq-color-control">
											<input
												id="ptq-term-color"
												name="color"
												type="color"
												value={ color || '#1769aa' }
												disabled={ saving }
												onChange={ ( event ) =>
													setColor(
														event.target.value
													)
												}
											/>
											<input
												className="ptq-color-hex"
												type="text"
												aria-label={ __(
													'Color code',
													'paper-to-quiz'
												) }
												value={ color }
												placeholder="#1769aa"
												disabled={ saving }
												maxLength={ 7 }
												pattern="#[0-9A-Fa-f]{6}"
												onChange={ ( event ) =>
													setColor(
														event.target.value
													)
												}
											/>
											<button
												type="button"
												className="button button-small"
												disabled={
													saving || color === ''
												}
												onClick={ () => setColor( '' ) }
											>
												{ __(
													'Use default',
													'paper-to-quiz'
												) }
											</button>
										</div>
										<p>
											{ __(
												'The student interface for exams and tests in this class uses this color. If no color is selected, the default blue is used.',
												'paper-to-quiz'
											) }
										</p>
									</div>
								) }
								<p className="submit">
									<button
										type="submit"
										className="button button-primary"
										disabled={
											saving || Boolean( pendingAction )
										}
									>
										{ saving ? (
											<BusyLabel>
												{ __(
													'Saving…',
													'paper-to-quiz'
												) }
											</BusyLabel>
										) : (
											submitLabel
										) }
									</button>
									{ editingId && (
										<button
											type="button"
											className="button ptq-cancel-edit"
											disabled={ saving }
											onClick={ () => {
												setEditingId( null );
												setName( '' );
												setColor( '' );
											} }
										>
											{ __( 'Cancel', 'paper-to-quiz' ) }
										</button>
									) }
								</p>
							</form>
						</div>
					</div>
				</div>
				<div className="col-right">
					<div className="col-wrap">
						<div className="ptq-list-controls">
							<ul className="subsubsub">
								<li>
									<button
										type="button"
										disabled={ navigationDisabled }
										className={
											status === 'active' ? 'current' : ''
										}
										onClick={ () =>
											selectStatus( 'active' )
										}
									>
										{ sprintf(
											/* translators: %d: Number of active terms. */
											__( 'All (%d)', 'paper-to-quiz' ),
											list.counts.active || 0
										) }
									</button>{ ' ' }
									|{ ' ' }
								</li>
								<li>
									<button
										type="button"
										disabled={ navigationDisabled }
										className={
											status === 'archived'
												? 'current'
												: ''
										}
										onClick={ () =>
											selectStatus( 'archived' )
										}
									>
										{ sprintf(
											/* translators: %d: Number of archived terms. */
											__(
												'Archived (%d)',
												'paper-to-quiz'
											),
											list.counts.archived || 0
										) }
									</button>{ ' ' }
									|{ ' ' }
								</li>
								<li>
									<button
										type="button"
										disabled={ navigationDisabled }
										className={
											status === 'trash' ? 'current' : ''
										}
										onClick={ () =>
											selectStatus( 'trash' )
										}
									>
										{ sprintf(
											/* translators: %d: Number of trashed terms. */
											__( 'Trash (%d)', 'paper-to-quiz' ),
											list.counts.trash || 0
										) }
									</button>
								</li>
							</ul>
							<form
								className="search-box"
								onSubmit={ ( event ) => {
									event.preventDefault();
									setLoading( true );
									setView( {
										status,
										page: 1,
										search: searchInput.trim(),
									} );
								} }
							>
								<label
									className="screen-reader-text"
									htmlFor="ptq-term-search"
								>
									{ __( 'Search', 'paper-to-quiz' ) }
								</label>
								<input
									id="ptq-term-search"
									type="search"
									value={ searchInput }
									disabled={ navigationDisabled }
									onChange={ ( event ) =>
										setSearchInput( event.target.value )
									}
								/>
								<button
									type="submit"
									className="button"
									disabled={ navigationDisabled }
								>
									{ sprintf(
										/* translators: %s: Plural term type. */
										__( 'Search %s', 'paper-to-quiz' ),
										pluralLabel
									) }
								</button>
							</form>
						</div>
						<div className="tablenav top">
							{ bulkControls }
							<ListPagination
								page={ list.page }
								pages={ list.pages }
								total={ list.total }
								onChange={ selectPage }
								disabled={ navigationDisabled }
							/>
							<br className="clear" />
						</div>
						{ listError && (
							<Notice status="error" isDismissible={ false }>
								{ listError }
							</Notice>
						) }
						{ loading && (
							<div className="ptq-loading">
								<Spinner />
							</div>
						) }
						{ ! loading && ! listError && (
							<table className="wp-list-table widefat fixed striped table-view-list">
								<thead>
									<tr>
										<td className="manage-column column-cb check-column">
											<input
												type="checkbox"
												aria-label={ __(
													'Select all',
													'paper-to-quiz'
												) }
												checked={ allChecked }
												onChange={ () =>
													setSelected(
														allChecked
															? []
															: list.items.map(
																	( item ) =>
																		Number(
																			item.id
																		)
																)
													)
												}
											/>
										</td>
										<th className="column-primary">
											{ __( 'Name', 'paper-to-quiz' ) }
										</th>
										{ type === 'classes' && (
											<th className="column-color">
												{ __(
													'Color',
													'paper-to-quiz'
												) }
											</th>
										) }
										<th className="column-posts">
											{ __( 'Count', 'paper-to-quiz' ) }
										</th>
									</tr>
								</thead>
								<tbody>
									{ list.items.length === 0 && (
										<tr className="no-items">
											<td
												colSpan={
													type === 'classes' ? 4 : 3
												}
											>
												{ __(
													'No records found.',
													'paper-to-quiz'
												) }
											</td>
										</tr>
									) }
									{ list.items.map( ( item ) => {
										const id = Number( item.id );
										return (
											<tr key={ item.id }>
												<th className="check-column">
													<input
														type="checkbox"
														aria-label={ sprintf(
															/* translators: %s: Item name. */
															__(
																'Select %s',
																'paper-to-quiz'
															),
															item.name
														) }
														checked={ selected.includes(
															id
														) }
														onChange={ () =>
															setSelected(
																( current ) =>
																	current.includes(
																		id
																	)
																		? current.filter(
																				(
																					value
																				) =>
																					value !==
																					id
																			)
																		: [
																				...current,
																				id,
																			]
															)
														}
													/>
												</th>
												<td className="column-primary">
													<strong>
														{ item.name }
													</strong>
													<div className="row-actions">
														{ status ===
															'active' && (
															<>
																<span>
																	<button
																		type="button"
																		className="button-link"
																		disabled={ Boolean(
																			pendingAction
																		) }
																		onClick={ () => {
																			setEditingId(
																				id
																			);
																			setName(
																				item.name
																			);
																			setColor(
																				item.color ||
																					''
																			);
																		} }
																	>
																		{ __(
																			'Edit',
																			'paper-to-quiz'
																		) }
																	</button>{ ' ' }
																	|{ ' ' }
																</span>
																<span>
																	<button
																		type="button"
																		className="button-link"
																		disabled={ Boolean(
																			pendingAction
																		) }
																		onClick={ () =>
																			void mutate(
																				'archive',
																				[
																					id,
																				]
																			)
																		}
																	>
																		{ pendingAction ===
																		`archive-${ id }`
																			? __(
																					'Archiving…',
																					'paper-to-quiz'
																				)
																			: __(
																					'Archive',
																					'paper-to-quiz'
																				) }
																	</button>{ ' ' }
																	|{ ' ' }
																</span>
																<span className="trash">
																	<button
																		type="button"
																		className="button-link-delete"
																		disabled={ Boolean(
																			pendingAction
																		) }
																		onClick={ () =>
																			void mutate(
																				'trash',
																				[
																					id,
																				]
																			)
																		}
																	>
																		{ pendingAction ===
																		`trash-${ id }`
																			? __(
																					'Moving…',
																					'paper-to-quiz'
																				)
																			: __(
																					'Move to trash',
																					'paper-to-quiz'
																				) }
																	</button>
																</span>
															</>
														) }
														{ status ===
															'archived' && (
															<>
																<span>
																	<button
																		type="button"
																		className="button-link"
																		disabled={ Boolean(
																			pendingAction
																		) }
																		onClick={ () =>
																			void mutate(
																				'restore',
																				[
																					id,
																				]
																			)
																		}
																	>
																		{ pendingAction ===
																		`restore-${ id }`
																			? __(
																					'Restoring…',
																					'paper-to-quiz'
																				)
																			: __(
																					'Restore',
																					'paper-to-quiz'
																				) }
																	</button>{ ' ' }
																	|{ ' ' }
																</span>
																<span className="trash">
																	<button
																		type="button"
																		className="button-link-delete"
																		disabled={ Boolean(
																			pendingAction
																		) }
																		onClick={ () =>
																			void mutate(
																				'trash',
																				[
																					id,
																				]
																			)
																		}
																	>
																		{ __(
																			'Move to trash',
																			'paper-to-quiz'
																		) }
																	</button>
																</span>
															</>
														) }
														{ status ===
															'trash' && (
															<>
																<span>
																	<button
																		type="button"
																		className="button-link"
																		disabled={ Boolean(
																			pendingAction
																		) }
																		onClick={ () =>
																			void mutate(
																				'restore',
																				[
																					id,
																				]
																			)
																		}
																	>
																		{ pendingAction ===
																		`restore-${ id }`
																			? __(
																					'Restoring…',
																					'paper-to-quiz'
																				)
																			: __(
																					'Restore',
																					'paper-to-quiz'
																				) }
																	</button>{ ' ' }
																	|{ ' ' }
																</span>
																<span className="delete">
																	<button
																		type="button"
																		className="button-link-delete"
																		title={
																			Number(
																				item.usage_count ||
																					0
																			) >
																			0
																				? __(
																						'A record in use cannot be permanently deleted.',
																						'paper-to-quiz'
																					)
																				: undefined
																		}
																		disabled={
																			Boolean(
																				pendingAction
																			) ||
																			Number(
																				item.usage_count ||
																					0
																			) >
																				0
																		}
																		onClick={ () =>
																			void mutate(
																				'delete_permanently',
																				[
																					id,
																				]
																			)
																		}
																	>
																		{ __(
																			'Delete permanently',
																			'paper-to-quiz'
																		) }
																	</button>
																</span>
															</>
														) }
													</div>
												</td>
												{ type === 'classes' && (
													<td className="column-color">
														<span
															className="ptq-color-swatch"
															style={ {
																backgroundColor:
																	item.color ||
																	'#1769aa',
															} }
															title={
																item.color ||
																__(
																	'Default blue',
																	'paper-to-quiz'
																)
															}
														/>
														<code>
															{ item.color ||
																'#1769aa' }
														</code>
													</td>
												) }
												<td>
													{ item.usage_count || 0 }
												</td>
											</tr>
										);
									} ) }
								</tbody>
							</table>
						) }
						<div className="tablenav bottom">
							{ bulkControls }
							<ListPagination
								page={ list.page }
								pages={ list.pages }
								total={ list.total }
								onChange={ selectPage }
								disabled={ navigationDisabled }
							/>
							<br className="clear" />
						</div>
						{ confirmation && (
							<ConfirmationDialog
								isOpen={ Boolean( confirmation ) }
								title={ confirmation.title }
								description={ confirmation.description }
								consequence={ confirmation.consequence }
								confirmLabel={ confirmation.confirmLabel }
								confirmPhrase={ confirmation.confirmPhrase }
								isDestructive={
									confirmation.action === 'delete_permanently'
								}
								onClose={ () => setConfirmation( null ) }
								onConfirm={ () =>
									mutate(
										confirmation.action,
										confirmation.ids,
										false
									)
								}
							/>
						) }
					</div>
				</div>
			</div>
		</div>
	);
}
