import {
	lazy,
	Suspense,
	useCallback,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { Notice, Spinner } from '@wordpress/components';
import { __, _n, _x, sprintf } from '@wordpress/i18n';
import type {
	AssessmentRecord,
	AssessmentType,
	ListResponse,
	Term,
} from '../types';
import { api, ApiError } from './api';
import { ConfirmationDialog, type ConsequenceRow } from './ConfirmationDialog';
import { assessmentStatusLabels } from './labels';
import { ListPagination } from './ListPagination';
import { ClassesPage, SubjectsPage } from './TermsPage';
import { ResultsPage } from './ResultsPage';
import { SettingsPage } from './SettingsPage';
import { BusyLabel, LoadingRegion } from './BusyLabel';

const LazyWizard = lazy( () =>
	import( './Wizard' ).then( ( module ) => ( { default: module.Wizard } ) )
);

type AssessmentListItem = {
	id: string;
	title: string;
	status: string;
	class_name?: string;
	subject_name?: string;
	subject_names?: string[];
	question_count: string;
	participation_count: string;
	access_mode: string;
	created_at: string;
	created_at_display: string;
	has_unpublished_changes?: string;
};

type SortOrder = 'asc' | 'desc';
type AssessmentAction = 'trash' | 'restore' | 'delete_permanently';
type DeleteImpact = {
	id: number;
	title: string;
	revisions: number;
	questions: number;
	attempts: number;
	answers: number;
};
type ConfirmState = null | {
	action: AssessmentAction;
	ids: number[];
	title: string;
	description: string;
	consequences?: ConsequenceRow[];
	consequence: string;
	confirmLabel: string;
	confirmPhrase?: string;
};

const emptyResponse: ListResponse< AssessmentListItem > = {
	items: [],
	total: 0,
	pages: 0,
	page: 1,
	counts: {},
};

type PrerequisiteState = {
	loading: boolean;
	hasClasses: boolean;
	hasSubjects: boolean;
	error: string;
};

function PrerequisiteNotice( {
	hasClasses,
	hasSubjects,
}: {
	hasClasses: boolean;
	hasSubjects: boolean;
} ) {
	let message: string;
	if ( ! hasClasses && ! hasSubjects ) {
		message = __(
			'Add at least one class and one subject before creating an exam or test.',
			'paper-to-quiz'
		);
	} else if ( ! hasClasses ) {
		message = __(
			'Add at least one class before creating an exam or test.',
			'paper-to-quiz'
		);
	} else {
		message = __(
			'Add at least one subject before creating an exam or test.',
			'paper-to-quiz'
		);
	}

	return (
		<Notice status="warning" isDismissible={ false }>
			<p>{ message }</p>
			<p>
				{ ! hasClasses && (
					<a href="?page=ptq-classes">
						{ __( 'Classes', 'paper-to-quiz' ) }
					</a>
				) }
				{ ! hasClasses && ! hasSubjects && ' · ' }
				{ ! hasSubjects && (
					<a href="?page=ptq-subjects">
						{ __( 'Subjects', 'paper-to-quiz' ) }
					</a>
				) }
			</p>
		</Notice>
	);
}

function CreationPrerequisiteGate( {
	type,
	hasClasses,
	hasSubjects,
	error,
	onBack,
}: {
	type: AssessmentType;
	hasClasses: boolean;
	hasSubjects: boolean;
	error: string;
	onBack: () => void;
} ) {
	return (
		<div className="ptq-page ptq-list-page">
			<h1>
				{ type === 'exam'
					? __( 'Exams', 'paper-to-quiz' )
					: __( 'Tests', 'paper-to-quiz' ) }
			</h1>
			{ error ? (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) : (
				<PrerequisiteNotice
					hasClasses={ hasClasses }
					hasSubjects={ hasSubjects }
				/>
			) }
			<button type="button" className="button" onClick={ onBack }>
				{ __( 'Back to list', 'paper-to-quiz' ) }
			</button>
		</div>
	);
}

function subjectListLabel( subjects: string[] ): string {
	if ( ! subjects.length ) {
		return '—';
	}
	const visible = subjects.slice( 0, 2 ).join( ', ' );
	const remaining = subjects.length - 2;
	return remaining > 0 ? `${ visible } +${ remaining }` : visible;
}

async function copyText( value: string ): Promise< void > {
	if ( navigator.clipboard?.writeText ) {
		await navigator.clipboard.writeText( value );
		return;
	}
	const input = document.createElement( 'textarea' );
	input.value = value;
	input.style.position = 'fixed';
	input.style.opacity = '0';
	document.body.appendChild( input );
	input.select();
	document.execCommand( 'copy' );
	input.remove();
}

export function App() {
	const config = window.ptqAdmin;
	const isAssessmentPage = [ 'ptq-exams', 'ptq-tests' ].includes(
		config.page
	);
	const [ editing, setEditing ] = useState< number | 'new' | null >(
		config.assessmentId ||
			new URLSearchParams( location.search ).get( 'action' ) === 'new'
			? config.assessmentId || 'new'
			: null
	);
	const [ initialWizardStep, setInitialWizardStep ] = useState( 0 );
	const [ list, setList ] = useState( emptyResponse );
	const [ selected, setSelected ] = useState< number[] >( [] );
	const [ status, setStatus ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ searchInput, setSearchInput ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ orderby, setOrderby ] = useState( 'updated' );
	const [ order, setOrder ] = useState< SortOrder >( 'desc' );
	const [ loading, setLoading ] = useState( false );
	const [ pendingAction, setPendingAction ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ message, setMessage ] = useState( '' );
	const [ confirmation, setConfirmation ] = useState< ConfirmState >( null );
	const actionLock = useRef( false );
	const [ prerequisites, setPrerequisites ] = useState< PrerequisiteState >(
		() => ( {
			loading: isAssessmentPage,
			hasClasses: false,
			hasSubjects: false,
			error: '',
		} )
	);

	const type: AssessmentType = config.page === 'ptq-exams' ? 'exam' : 'test';
	const canCreate = prerequisites.hasClasses && prerequisites.hasSubjects;

	const loadPrerequisites = useCallback( async () => {
		if ( ! isAssessmentPage ) {
			return;
		}
		setPrerequisites( ( current ) => ( {
			...current,
			loading: true,
			error: '',
		} ) );
		try {
			const [ classes, subjects ] = await Promise.all( [
				api< ListResponse< Term > >(
					'/admin/classes?status=active&page=1&per_page=1'
				),
				api< ListResponse< Term > >(
					'/admin/subjects?status=active&page=1&per_page=1'
				),
			] );
			setPrerequisites( {
				loading: false,
				hasClasses: classes.total > 0 || classes.items.length > 0,
				hasSubjects: subjects.total > 0 || subjects.items.length > 0,
				error: '',
			} );
		} catch ( caught ) {
			setPrerequisites( {
				loading: false,
				hasClasses: false,
				hasSubjects: false,
				error:
					caught instanceof Error
						? caught.message
						: __(
								'The class and subject lists could not be loaded.',
								'paper-to-quiz'
						  ),
			} );
		}
	}, [ isAssessmentPage ] );

	const loadList = useCallback( async () => {
		if (
			! [ 'ptq-exams', 'ptq-tests' ].includes( config.page ) ||
			editing
		) {
			return;
		}
		setLoading( true );
		setError( '' );
		try {
			const query = new URLSearchParams( {
				type,
				page: String( page ),
				per_page: '20',
				orderby,
				order,
			} );
			if ( status ) {
				query.set( 'status', status );
			}
			if ( search ) {
				query.set( 'search', search );
			}
			setList(
				await api< ListResponse< AssessmentListItem > >(
					`/admin/assessments?${ query }`
				)
			);
			setSelected( [] );
		} catch ( caught ) {
			setError(
				caught instanceof Error
					? caught.message
					: __( 'Could not load the list.', 'paper-to-quiz' )
			);
		} finally {
			setLoading( false );
		}
	}, [ editing, type, config.page, page, search, status, orderby, order ] );

	useEffect( () => {
		void loadList();
	}, [ loadList ] );

	useEffect( () => {
		void loadPrerequisites();
	}, [ loadPrerequisites ] );

	if ( config.page === 'ptq-classes' ) {
		return <ClassesPage />;
	}
	if ( config.page === 'ptq-subjects' ) {
		return <SubjectsPage />;
	}
	if ( config.page === 'ptq-results' ) {
		return <ResultsPage />;
	}
	if ( config.page === 'ptq-settings' ) {
		return <SettingsPage />;
	}
	if (
		editing === 'new' &&
		! prerequisites.loading &&
		( ! canCreate || prerequisites.error )
	) {
		return (
			<CreationPrerequisiteGate
				type={ type }
				hasClasses={ prerequisites.hasClasses }
				hasSubjects={ prerequisites.hasSubjects }
				error={ prerequisites.error }
				onBack={ () => setEditing( null ) }
			/>
		);
	}
	if ( editing ) {
		return (
			<Suspense
				fallback={
					<LoadingRegion>
						{ __( 'Loading editor…', 'paper-to-quiz' ) }
					</LoadingRegion>
				}
			>
				<LazyWizard
					assessmentId={ editing === 'new' ? undefined : editing }
					initialType={ type }
					initialStep={ initialWizardStep }
					onClose={ () => {
						setEditing( null );
						setInitialWizardStep( 0 );
					} }
					onComplete={ ( successMessage ) => {
						setEditing( null );
						setInitialWizardStep( 0 );
						setMessage( successMessage );
					} }
				/>
			</Suspense>
		);
	}

	const allChecked =
		list.items.length > 0 &&
		list.items.every( ( item ) => selected.includes( Number( item.id ) ) );
	const views = [
		{
			key: '',
			label: __( 'All', 'paper-to-quiz' ),
			count: list.counts.all || 0,
		},
		{
			key: 'published',
			label: __( 'Published', 'paper-to-quiz' ),
			count: list.counts.published || 0,
		},
		{
			key: 'draft',
			label: __( 'Draft', 'paper-to-quiz' ),
			count: list.counts.draft || 0,
		},
		{
			key: 'archived',
			label: __( 'Archived', 'paper-to-quiz' ),
			count: list.counts.archived || 0,
		},
		{
			key: 'trash',
			label: __( 'Trash', 'paper-to-quiz' ),
			count: list.counts.trash || 0,
		},
	].filter( ( view ) => view.key !== 'archived' || view.count > 0 );

	async function runConfirmedAction( next: Exclude< ConfirmState, null > ) {
		const success = await mutate( next.action, next.ids, false );
		if ( success ) {
			setConfirmation( null );
		}
	}

	async function mutate(
		action: AssessmentAction,
		ids: number[],
		askConfirmation = true
	): Promise< boolean > {
		if ( actionLock.current ) {
			return false;
		}
		if ( ! ids.length ) {
			setError(
				__(
					'Select at least one record to perform an action.',
					'paper-to-quiz'
				)
			);
			return false;
		}
		if ( askConfirmation && action === 'trash' ) {
			setConfirmation( {
				action,
				ids,
				title: __(
					'Move the selected records to the trash?',
					'paper-to-quiz'
				),
				description: __(
					'Move the selected records to the trash?',
					'paper-to-quiz'
				),
				consequence: __(
					'This keeps the records recoverable from Trash.',
					'paper-to-quiz'
				),
				confirmLabel: __( 'Move to trash', 'paper-to-quiz' ),
			} );
			return false;
		}
		if ( action === 'delete_permanently' ) {
			let impacts: DeleteImpact[];
			try {
				impacts = await Promise.all(
					ids.map( ( itemId ) =>
						api< DeleteImpact >(
							`/admin/assessments/${ itemId }/delete-impact`
						)
					)
				);
			} catch ( caught ) {
				setError(
					caught instanceof Error
						? caught.message
						: __(
								'Could not calculate the deletion impact.',
								'paper-to-quiz'
						  )
				);
				return false;
			}
			const totals = impacts.reduce(
				( current, impact ) => ( {
					revisions: current.revisions + impact.revisions,
					questions: current.questions + impact.questions,
					attempts: current.attempts + impact.attempts,
					answers: current.answers + impact.answers,
				} ),
				{ revisions: 0, questions: 0, attempts: 0, answers: 0 }
			);
			const required =
				impacts.length === 1
					? impacts[ 0 ].title
					: __( 'PERMANENTLY DELETE', 'paper-to-quiz' );
			const confirmationMessage = sprintf(
				/* translators: %s: Required confirmation text. */
				__( 'Type “%s” to confirm.', 'paper-to-quiz' ),
				required
			);
			const deletionMessage = sprintf(
				/* translators: 1: Revisions to delete. 2: Questions to delete. 3: Attempts to delete. 4: Answers to delete. */
				__(
					'%1$s, %2$s, %3$s, and %4$s will be permanently deleted.',
					'paper-to-quiz'
				),
				sprintf(
					/* translators: %d: Number of revisions. */
					_n(
						'%d revision',
						'%d revisions',
						totals.revisions,
						'paper-to-quiz'
					),
					totals.revisions
				),
				sprintf(
					/* translators: %d: Number of questions. */
					_n(
						'%d question',
						'%d questions',
						totals.questions,
						'paper-to-quiz'
					),
					totals.questions
				),
				sprintf(
					/* translators: %d: Number of attempts. */
					_n(
						'%d attempt',
						'%d attempts',
						totals.attempts,
						'paper-to-quiz'
					),
					totals.attempts
				),
				sprintf(
					/* translators: %d: Number of answers. */
					_n(
						'%d answer',
						'%d answers',
						totals.answers,
						'paper-to-quiz'
					),
					totals.answers
				)
			);
			const consequences = [
				{
					label: __( 'Revisions', 'paper-to-quiz' ),
					value: totals.revisions,
				},
				{
					label: __( 'Questions', 'paper-to-quiz' ),
					value: totals.questions,
				},
				{
					label: __( 'Attempts', 'paper-to-quiz' ),
					value: totals.attempts,
				},
				{
					label: __( 'Answers', 'paper-to-quiz' ),
					value: totals.answers,
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
			} >( '/admin/assessments/bulk', {
				method: 'POST',
				json: { action, ids },
			} );
			if ( response.errors?.length ) {
				setError( response.errors.join( '\n' ) );
			}
			let successMessage: string = __(
				'The selected records were moved to the trash.',
				'paper-to-quiz'
			);
			if ( action === 'restore' ) {
				successMessage = __(
					'The selected records were restored.',
					'paper-to-quiz'
				);
			} else if ( action === 'delete_permanently' ) {
				successMessage = sprintf(
					/* translators: %d: Number of permanently deleted records. */
					_n(
						'%d record was permanently deleted.',
						'%d records were permanently deleted.',
						response.changed,
						'paper-to-quiz'
					),
					response.changed
				);
			}
			setMessage( successMessage );
			await loadList();
			return true;
		} catch ( caught ) {
			setError(
				caught instanceof ApiError
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

	async function duplicate( id: number ) {
		if ( actionLock.current ) {
			return;
		}
		actionLock.current = true;
		setPendingAction( `duplicate-${ id }` );
		setError( '' );
		try {
			const copied = await api< AssessmentRecord >(
				`/admin/assessments/${ id }/duplicate`,
				{ method: 'POST', json: {} }
			);
			setEditing( Number( copied.assessment.id ) );
		} catch ( caught ) {
			setError(
				caught instanceof Error
					? caught.message
					: __(
							'The record could not be duplicated.',
							'paper-to-quiz'
					  )
			);
		} finally {
			actionLock.current = false;
			setPendingAction( '' );
		}
	}

	function sortBy( column: string ) {
		setOrder( ( current ) =>
			orderby === column && current === 'asc' ? 'desc' : 'asc'
		);
		setOrderby( column );
		setPage( 1 );
	}

	const bulkControls = (
		<div className="alignleft actions bulkactions">
			<label className="screen-reader-text" htmlFor="ptq-bulk-action">
				{ __( 'Select a bulk action', 'paper-to-quiz' ) }
			</label>
			<select
				id="ptq-bulk-action"
				defaultValue=""
				disabled={ Boolean( pendingAction ) }
			>
				<option value="">
					{ __( 'Bulk actions', 'paper-to-quiz' ) }
				</option>
				{ status === 'trash' ? (
					<>
						<option value="restore">
							{ __( 'Restore', 'paper-to-quiz' ) }
						</option>
						<option value="delete_permanently">
							{ __( 'Permanently delete', 'paper-to-quiz' ) }
						</option>
					</>
				) : (
					<option value="trash">
						{ __( 'Move to trash', 'paper-to-quiz' ) }
					</option>
				) }
			</select>
			<button
				type="button"
				className="button action"
				disabled={ Boolean( pendingAction ) }
				onClick={ ( event ) => {
					const select = event.currentTarget
						.previousElementSibling as HTMLSelectElement;
					if ( select.value ) {
						void mutate(
							select.value as AssessmentAction,
							selected
						);
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
		<div className="ptq-page ptq-list-page">
			<h1 className="wp-heading-inline">
				{ type === 'exam'
					? __( 'Exams', 'paper-to-quiz' )
					: __( 'Tests', 'paper-to-quiz' ) }
			</h1>
			<button
				type="button"
				className="page-title-action"
				disabled={ loading || prerequisites.loading || ! canCreate }
				onClick={ () => setEditing( 'new' ) }
			>
				{ __( 'Add new', 'paper-to-quiz' ) }
			</button>
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
			{ prerequisites.error && (
				<Notice status="error" isDismissible={ false }>
					{ prerequisites.error }
				</Notice>
			) }
			{ ! prerequisites.loading &&
				! prerequisites.error &&
				! canCreate && (
					<PrerequisiteNotice
						hasClasses={ prerequisites.hasClasses }
						hasSubjects={ prerequisites.hasSubjects }
					/>
				) }
			<div className="ptq-list-controls">
				<ul className="subsubsub">
					{ views.map( ( view, index ) => (
						<li key={ view.key || 'all' }>
							<button
								type="button"
								disabled={ loading }
								className={
									status === view.key ? 'current' : ''
								}
								onClick={ () => {
									setStatus( view.key );
									setPage( 1 );
								} }
							>
								{ view.label }{ ' ' }
								<span className="count">({ view.count })</span>
							</button>
							{ index < views.length - 1 ? ' | ' : '' }
						</li>
					) ) }
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
						htmlFor="ptq-search-input"
					>
						{ __( 'Search records', 'paper-to-quiz' ) }
					</label>
					<input
						id="ptq-search-input"
						type="search"
						value={ searchInput }
						disabled={ loading }
						onChange={ ( event ) =>
							setSearchInput( event.target.value )
						}
					/>
					<button
						className="button"
						type="submit"
						disabled={ loading }
					>
						{ type === 'exam'
							? __( 'Search exams', 'paper-to-quiz' )
							: __( 'Search tests', 'paper-to-quiz' ) }
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
												: list.items.map( ( item ) =>
														Number( item.id )
												  )
										)
									}
								/>
							</td>
							<SortableColumn
								label={ __( 'Name', 'paper-to-quiz' ) }
								column="title"
								className="column-primary column-title"
								orderby={ orderby }
								order={ order }
								onSort={ sortBy }
							/>
							<SortableColumn
								label={ _x(
									'Class',
									'Assessment list column label',
									'paper-to-quiz'
								) }
								column="class"
								className="column-class"
								orderby={ orderby }
								order={ order }
								onSort={ sortBy }
							/>
							<SortableColumn
								label={ _x(
									'Subject',
									'Assessment list column label',
									'paper-to-quiz'
								) }
								column="subject"
								className="column-subject"
								orderby={ orderby }
								order={ order }
								onSort={ sortBy }
							/>
							<SortableColumn
								label={ __( 'Questions', 'paper-to-quiz' ) }
								column="questions"
								className="column-questions"
								orderby={ orderby }
								order={ order }
								onSort={ sortBy }
							/>
							<th className="column-access">
								{ __( 'Access', 'paper-to-quiz' ) }
							</th>
							<SortableColumn
								label={ __( 'Status', 'paper-to-quiz' ) }
								column="status"
								className="column-status"
								orderby={ orderby }
								order={ order }
								onSort={ sortBy }
							/>
							<SortableColumn
								label={ __( 'Participation', 'paper-to-quiz' ) }
								column="participation"
								className="column-participation"
								orderby={ orderby }
								order={ order }
								onSort={ sortBy }
							/>
							<SortableColumn
								label={ __( 'Date', 'paper-to-quiz' ) }
								column="created"
								className="column-created"
								orderby={ orderby }
								order={ order }
								onSort={ sortBy }
							/>
							<th className="column-shortcode">
								{ __( 'Shortcode', 'paper-to-quiz' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ list.items.length === 0 && (
							<tr className="no-items">
								<td colSpan={ 10 }>
									{ search
										? __(
												'No records matched your search.',
												'paper-to-quiz'
										  )
										: __(
												'No records yet.',
												'paper-to-quiz'
										  ) }
								</td>
							</tr>
						) }
						{ list.items.map( ( item ) => {
							const id = Number( item.id );
							const shortcode = `[paper_to_quiz id="${ item.id }"]`;
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
												item.title
											) }
											checked={ selected.includes( id ) }
											onChange={ () =>
												setSelected( ( current ) =>
													current.includes( id )
														? current.filter(
																( value ) =>
																	value !== id
														  )
														: [ ...current, id ]
												)
											}
										/>
									</th>
									<td
										className="column-primary column-title"
										data-colname={ __(
											'Name',
											'paper-to-quiz'
										) }
									>
										<strong>
											<button
												type="button"
												className="row-title button-link"
												onClick={ () =>
													setEditing( id )
												}
											>
												{ item.title ||
													__(
														'(Untitled)',
														'paper-to-quiz'
													) }
											</button>
										</strong>
										<div className="row-actions">
											{ status === 'trash' ? (
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
																	[ id ]
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
														</button>
														{ ' | ' }
													</span>
													<span className="delete">
														<button
															type="button"
															className="button-link-delete"
															disabled={ Boolean(
																pendingAction
															) }
															onClick={ () =>
																void mutate(
																	'delete_permanently',
																	[ id ]
																)
															}
														>
															{ pendingAction ===
															`delete_permanently-${ id }`
																? __(
																		'Deleting…',
																		'paper-to-quiz'
																  )
																: __(
																		'Permanently delete',
																		'paper-to-quiz'
																  ) }
														</button>
													</span>
												</>
											) : (
												<>
													<span>
														<button
															type="button"
															className="button-link"
															disabled={ Boolean(
																pendingAction
															) }
															onClick={ () =>
																setEditing( id )
															}
														>
															{ __(
																'Edit',
																'paper-to-quiz'
															) }
														</button>
														{ ' | ' }
													</span>
													<span>
														<a
															href={ `admin.php?page=ptq-results&assessment_id=${ id }` }
														>
															{ __(
																'Results',
																'paper-to-quiz'
															) }
														</a>
														{ ' | ' }
													</span>
													<span>
														<button
															type="button"
															className="button-link"
															disabled={ Boolean(
																pendingAction
															) }
															onClick={ () =>
																void duplicate(
																	id
																)
															}
														>
															{ pendingAction ===
															`duplicate-${ id }`
																? __(
																		'Duplicating…',
																		'paper-to-quiz'
																  )
																: __(
																		'Duplicate',
																		'paper-to-quiz'
																  ) }
														</button>
														{ ' | ' }
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
																	[ id ]
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
										</div>
									</td>
									<td
										className="column-class"
										data-colname={ _x(
											'Class',
											'Assessment list column label',
											'paper-to-quiz'
										) }
									>
										{ item.class_name || '—' }
									</td>
									<td
										className="column-subject"
										data-colname={ _x(
											'Subject',
											'Assessment list column label',
											'paper-to-quiz'
										) }
									>
										{ subjectListLabel(
											item.subject_names ||
												( item.subject_name
													? [ item.subject_name ]
													: [] )
										) }
									</td>
									<td
										className="column-questions"
										data-colname={ __(
											'Questions',
											'paper-to-quiz'
										) }
									>
										{ item.question_count }
									</td>
									<td
										className="column-access"
										data-colname={ __(
											'Access',
											'paper-to-quiz'
										) }
									>
										{ item.access_mode === 'login_required'
											? __(
													'Membership required',
													'paper-to-quiz'
											  )
											: __( 'Public', 'paper-to-quiz' ) }
									</td>
									<td
										className="column-status"
										data-colname={ __(
											'Status',
											'paper-to-quiz'
										) }
									>
										{ assessmentStatusLabels[
											item.status
										] || item.status }
										{ item.status === 'published' &&
											item.has_unpublished_changes ===
												'1' && (
												<>
													{ ' ' }
													·{ ' ' }
													{ __(
														'Unpublished changes are present',
														'paper-to-quiz'
													) }
												</>
											) }
									</td>
									<td
										className="column-participation"
										data-colname={ __(
											'Participation',
											'paper-to-quiz'
										) }
									>
										{ item.participation_count }
									</td>
									<td
										className="column-created"
										data-colname={ __(
											'Date',
											'paper-to-quiz'
										) }
									>
										{ item.created_at_display || '—' }
									</td>
									<td
										className="column-shortcode"
										data-colname={ __(
											'Shortcode',
											'paper-to-quiz'
										) }
									>
										<button
											type="button"
											className="ptq-shortcode-copy"
											title={ __(
												'Copy shortcode',
												'paper-to-quiz'
											) }
											onClick={ async () => {
												await copyText( shortcode );
												setMessage(
													__(
														'Shortcode copied to the clipboard.',
														'paper-to-quiz'
													)
												);
											} }
										>
											<code>{ shortcode }</code>
											<span className="screen-reader-text">
												{ __(
													'Copy',
													'paper-to-quiz'
												) }
											</span>
										</button>
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
					isDestructive={ confirmation.action !== 'trash' }
					onClose={ () => setConfirmation( null ) }
					onConfirm={ () => runConfirmedAction( confirmation ) }
				/>
			) }
		</div>
	);
}

function SortableColumn( {
	label,
	column,
	className,
	orderby,
	order,
	onSort,
}: {
	label: string;
	column: string;
	className: string;
	orderby: string;
	order: SortOrder;
	onSort: ( column: string ) => void;
} ) {
	const active = orderby === column;
	return (
		<th
			className={ `manage-column ${ className } ${
				active ? `sorted ${ order }` : 'sortable desc'
			}` }
		>
			<button
				type="button"
				className="ptq-sort-button"
				onClick={ () => onSort( column ) }
			>
				<span>{ label }</span>
				<span className="sorting-indicators" aria-hidden="true">
					<span className="sorting-indicator asc" />
					<span className="sorting-indicator desc" />
				</span>
			</button>
		</th>
	);
}
