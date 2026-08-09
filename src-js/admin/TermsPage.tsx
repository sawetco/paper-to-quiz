import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
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
	const [ status, setStatus ] = useState< 'active' | 'archived' | 'trash' >(
		'active'
	);
	const [ searchInput, setSearchInput ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ pendingAction, setPendingAction ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ message, setMessage ] = useState( '' );
	const [ confirmation, setConfirmation ] = useState< ConfirmState >( null );
	const actionLock = useRef( false );
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

	const load = useCallback( async () => {
		setLoading( true );
		setError( '' );
		try {
			const query = new URLSearchParams( {
				status,
				page: String( page ),
				per_page: '20',
			} );
			if ( search ) {
				query.set( 'search', search );
			}
			setList(
				await api< ListResponse< Term > >(
					`/admin/${ type }?${ query }`
				)
			);
			setSelected( [] );
		} catch ( caught ) {
			setError(
				caught instanceof Error
					? caught.message
					: __( 'The list could not be loaded.', 'paper-to-quiz' )
			);
		} finally {
			setLoading( false );
		}
	}, [ page, search, status, type ] );

	useEffect( () => void load(), [ load ] );

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
		try {
			await api( `/admin/${ type }`, {
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
			await load();
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

	async function runConfirmedAction( next: Exclude< ConfirmState, null > ) {
		const success = await mutate( next.action, next.ids, false );
		if ( success ) {
			setConfirmation( null );
		}
	}

	async function mutate(
		action: TermAction,
		ids: number[],
		askConfirmation = true
	): Promise< boolean > {
		if ( actionLock.current ) {
			return false;
		}
		if ( ! ids.length ) {
			setError(
				__(
					'Select at least one record for the action.',
					'paper-to-quiz'
				)
			);
			return false;
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
			return false;
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
			return false;
		}
		if ( action === 'delete_permanently' ) {
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
			if ( askConfirmation ) {
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
				return false;
			}
		}
		actionLock.current = true;
		setPendingAction(
			ids.length === 1 ? `${ action }-${ ids[ 0 ] }` : 'bulk'
		);
		setError( '' );
		try {
			const response = await api< {
				changed: number;
				errors?: string[];
			} >( `/admin/${ type }/bulk`, {
				method: 'POST',
				json: { action, ids },
			} );
			if ( response.errors?.length ) {
				setError( response.errors.join( '\n' ) );
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
			setMessage( successMessage );
			await load();
			return true;
		} catch ( caught ) {
			setError(
				caught instanceof Error
					? caught.message
					: __(
							'The action could not be completed.',
							'paper-to-quiz'
					  )
			);
			throw caught;
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
				disabled={ Boolean( pendingAction ) || saving }
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
				disabled={ Boolean( pendingAction ) || saving }
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
										disabled={ loading }
										className={
											status === 'active' ? 'current' : ''
										}
										onClick={ () => {
											setStatus( 'active' );
											setPage( 1 );
										} }
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
										disabled={ loading }
										className={
											status === 'archived'
												? 'current'
												: ''
										}
										onClick={ () => {
											setStatus( 'archived' );
											setPage( 1 );
										} }
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
										disabled={ loading }
										className={
											status === 'trash' ? 'current' : ''
										}
										onClick={ () => {
											setStatus( 'trash' );
											setPage( 1 );
										} }
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
									setSearch( searchInput.trim() );
									setPage( 1 );
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
									disabled={ loading }
									onChange={ ( event ) =>
										setSearchInput( event.target.value )
									}
								/>
								<button
									type="submit"
									className="button"
									disabled={ loading }
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
								onChange={ setPage }
								disabled={ loading || Boolean( pendingAction ) }
							/>
							<br className="clear" />
						</div>
						{ loading ? (
							<div className="ptq-loading">
								<Spinner />
							</div>
						) : (
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
								onChange={ setPage }
								disabled={ loading || Boolean( pendingAction ) }
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
									runConfirmedAction( confirmation )
								}
							/>
						) }
					</div>
				</div>
			</div>
		</div>
	);
}
