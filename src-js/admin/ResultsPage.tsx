import { useEffect, useRef, useState } from '@wordpress/element';
import { Notice, Spinner } from '@wordpress/components';
import { __, _n, _x, sprintf } from '@wordpress/i18n';
import type { ListResponse } from '../types';
import { api } from './api';
import { attemptStatusLabels, formatDuration } from './labels';
import { formatScore } from '../shared/format';
import { ListPagination } from './ListPagination';
import { BusyLabel } from './BusyLabel';

type ResultItem = {
	id: string;
	title: string;
	type: 'exam' | 'test';
	participant_label: string;
	participant_type: 'member' | 'guest';
	started_at_display: string;
	submitted_at_display: string;
	duration_seconds?: string;
	correct_count: string;
	wrong_count: string;
	blank_count: string;
	score: string;
	score_has_fraction?: string;
	status: string;
};

type AssessmentOption = {
	id: string;
	title: string;
	type: 'exam' | 'test';
};

const emptyResponse: ListResponse< ResultItem > = {
	items: [],
	total: 0,
	pages: 0,
	page: 1,
	counts: {},
};

const participantFieldLabels: Record< string, string > = {
	first_name: __( 'First name', 'paper-to-quiz' ),
	last_name: __( 'Last name', 'paper-to-quiz' ),
	school: __( 'School', 'paper-to-quiz' ),
	class_section: __( 'Class and section', 'paper-to-quiz' ),
	email: __( 'Email', 'paper-to-quiz' ),
	phone: __( 'Phone', 'paper-to-quiz' ),
};

const diagnosticLabels: Record< string, string > = {
	ip_address: __( 'IP address', 'paper-to-quiz' ),
	browser: __( 'Browser', 'paper-to-quiz' ),
	platform: __( 'Operating system / platform', 'paper-to-quiz' ),
	user_agent: __( 'Browser identifier', 'paper-to-quiz' ),
	language: __( 'Language', 'paper-to-quiz' ),
	timezone: __( 'Time zone', 'paper-to-quiz' ),
};

function answerStatus( answer: any ): string {
	if ( ! answer.selected_option ) {
		return __( 'Blank', 'paper-to-quiz' );
	}
	return answer.is_correct === '1'
		? __( 'Correct', 'paper-to-quiz' )
		: __( 'Wrong', 'paper-to-quiz' );
}

export function ResultsPage() {
	const queryParams = new URLSearchParams( location.search );
	const [ participantType, setParticipantType ] = useState( '' );
	const [ status, setStatus ] = useState( '' );
	const [ assessmentId, setAssessmentId ] = useState(
		queryParams.get( 'assessment_id' ) || ''
	);
	const [ searchInput, setSearchInput ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ orderby, setOrderby ] = useState( 'started' );
	const [ order, setOrder ] = useState< 'asc' | 'desc' >( 'desc' );
	const [ assessments, setAssessments ] = useState< AssessmentOption[] >(
		[]
	);
	const [ response, setResponse ] = useState<
		ListResponse< ResultItem > & { subject_analytics?: any[] }
	>( emptyResponse );
	const [ detail, setDetail ] = useState< any | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ detailLoadingId, setDetailLoadingId ] = useState< number | null >(
		null
	);
	const [ error, setError ] = useState( '' );
	const detailLock = useRef( false );

	useEffect( () => {
		api< { items: AssessmentOption[] } >(
			'/admin/assessments?per_page=100'
		)
			.then( ( result ) => setAssessments( result.items ) )
			.catch( ( caught ) => setError( caught.message ) );
	}, [] );

	useEffect( () => {
		setLoading( true );
		setError( '' );
		const query = new URLSearchParams( {
			page: String( page ),
			per_page: '20',
			orderby,
			order,
		} );
		if ( participantType ) {
			query.set( 'participant_type', participantType );
		}
		if ( assessmentId ) {
			query.set( 'assessment_id', assessmentId );
		}
		if ( status ) {
			query.set( 'status', status );
		}
		if ( search ) {
			query.set( 'search', search );
		}
		api< ListResponse< ResultItem > >( `/admin/results?${ query }` )
			.then( setResponse )
			.catch( ( caught ) => setError( caught.message ) )
			.finally( () => setLoading( false ) );
	}, [
		assessmentId,
		page,
		participantType,
		search,
		status,
		orderby,
		order,
	] );

	async function openDetail( id: number ) {
		if ( detailLock.current ) {
			return;
		}
		detailLock.current = true;
		setDetailLoadingId( id );
		setError( '' );
		try {
			setDetail( await api( `/admin/results/${ id }` ) );
		} catch ( caught ) {
			setError(
				caught instanceof Error
					? caught.message
					: __(
							'The result details could not be opened.',
							'paper-to-quiz'
					  )
			);
		} finally {
			detailLock.current = false;
			setDetailLoadingId( null );
		}
	}

	function sortBy( column: string ) {
		setOrder( ( current ) =>
			orderby === column && current === 'asc' ? 'desc' : 'asc'
		);
		setOrderby( column );
		setPage( 1 );
	}

	if ( detail ) {
		return (
			<ResultDetail
				detail={ detail }
				onClose={ () => setDetail( null ) }
			/>
		);
	}

	const views = [
		{
			key: '',
			label: __( 'All', 'paper-to-quiz' ),
			count: response.counts.all || 0,
		},
		{
			key: 'in_progress',
			label: attemptStatusLabels.in_progress,
			count: response.counts.in_progress || 0,
		},
		{
			key: 'submitted',
			label: attemptStatusLabels.submitted,
			count: response.counts.submitted || 0,
		},
		{
			key: 'auto_submitted',
			label: attemptStatusLabels.auto_submitted,
			count: response.counts.auto_submitted || 0,
		},
		{
			key: 'expired',
			label: attemptStatusLabels.expired,
			count: response.counts.expired || 0,
		},
	].filter( ( view ) => view.key === '' || view.count > 0 );

	return (
		<div className="ptq-page ptq-list-page paper-to-quiz-results-page">
			<h1 className="wp-heading-inline">
				{ __( 'Results', 'paper-to-quiz' ) }
			</h1>
			<hr className="wp-header-end" />
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
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
						htmlFor="ptq-result-search"
					>
						{ __( 'Search results', 'paper-to-quiz' ) }
					</label>
					<input
						id="ptq-result-search"
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
						{ __( 'Search results', 'paper-to-quiz' ) }
					</button>
				</form>
			</div>
			<div className="tablenav top ptq-result-filters">
				<div className="alignleft actions">
					<label
						className="screen-reader-text"
						htmlFor="ptq-assessment-filter"
					>
						{ __( 'Content', 'paper-to-quiz' ) }
					</label>
					<select
						id="ptq-assessment-filter"
						value={ assessmentId }
						disabled={ loading }
						onChange={ ( event ) => {
							setAssessmentId( event.target.value );
							setPage( 1 );
						} }
					>
						<option value="">
							{ __( 'All exams and tests', 'paper-to-quiz' ) }
						</option>
						{ assessments.map( ( assessment ) => (
							<option
								key={ assessment.id }
								value={ assessment.id }
							>
								{ assessment.title } (
								{ assessment.type === 'exam'
									? __( 'Exam', 'paper-to-quiz' )
									: __( 'Test', 'paper-to-quiz' ) }
								)
							</option>
						) ) }
					</select>
					<label
						className="screen-reader-text"
						htmlFor="ptq-participant-filter"
					>
						{ __( 'Participant type', 'paper-to-quiz' ) }
					</label>
					<select
						id="ptq-participant-filter"
						value={ participantType }
						disabled={ loading }
						onChange={ ( event ) => {
							setParticipantType( event.target.value );
							setPage( 1 );
						} }
					>
						<option value="">
							{ __( 'All participants', 'paper-to-quiz' ) }
						</option>
						<option value="member">
							{ __( 'Members', 'paper-to-quiz' ) }
						</option>
						<option value="guest">
							{ __( 'Guests', 'paper-to-quiz' ) }
						</option>
					</select>
				</div>
				<ListPagination
					page={ response.page }
					pages={ response.pages }
					total={ response.total }
					onChange={ setPage }
					disabled={ loading || detailLoadingId !== null }
				/>
				<br className="clear" />
			</div>
			{ loading ? (
				<div className="ptq-loading">
					<Spinner />
				</div>
			) : (
				<>
					{ assessmentId &&
						response.subject_analytics &&
						response.subject_analytics.length > 0 && (
							<div className="ptq-subject-analytics">
								<h2>
									{ __( 'Subject summary', 'paper-to-quiz' ) }
								</h2>
								{ response.subject_analytics.map( ( row ) => (
									<div key={ row.subject_id }>
										<strong>{ row.subject_name }</strong>
										<span>
											{ sprintf(
												/* translators: %d: Number of participants. */
												_n(
													'%d participant',
													'%d participants',
													Number(
														row.participant_count
													),
													'paper-to-quiz'
												),
												Number( row.participant_count )
											) }
										</span>
										<span>
											{ sprintf(
												/* translators: %s: Average percentage. */
												__(
													'Average: %s%%',
													'paper-to-quiz'
												),
												row.average_percentage
											) }
										</span>
										<span>
											{ sprintf(
												/* translators: 1: Correct count. 2: Wrong count. 3: Blank count. */
												__(
													'Correct: %1$s · Wrong: %2$s · Blank: %3$s',
													'paper-to-quiz'
												),
												row.correct_count,
												row.wrong_count,
												row.blank_count
											) }
										</span>
									</div>
								) ) }
							</div>
						) }
					<table className="wp-list-table widefat fixed striped table-view-list">
						<thead>
							<tr>
								<ResultSortableColumn
									label={ __( 'Title', 'paper-to-quiz' ) }
									column="title"
									className="column-primary column-result-title"
									orderby={ orderby }
									order={ order }
									onSort={ sortBy }
								/>
								<th className="column-participant">
									{ __( 'Participant', 'paper-to-quiz' ) }
								</th>
								<ResultSortableColumn
									label={ __( 'Start', 'paper-to-quiz' ) }
									column="started"
									className="column-started"
									orderby={ orderby }
									order={ order }
									onSort={ sortBy }
								/>
								<ResultSortableColumn
									label={ __( 'End', 'paper-to-quiz' ) }
									column="finished"
									className="column-finished"
									orderby={ orderby }
									order={ order }
									onSort={ sortBy }
								/>
								<ResultSortableColumn
									label={ __( 'Duration', 'paper-to-quiz' ) }
									column="duration"
									className="column-duration"
									orderby={ orderby }
									order={ order }
									onSort={ sortBy }
								/>
								<ResultSortableColumn
									label={ __( 'Correct', 'paper-to-quiz' ) }
									column="correct"
									className="column-answer-count"
									orderby={ orderby }
									order={ order }
									onSort={ sortBy }
								/>
								<ResultSortableColumn
									label={ __( 'Wrong', 'paper-to-quiz' ) }
									column="wrong"
									className="column-answer-count"
									orderby={ orderby }
									order={ order }
									onSort={ sortBy }
								/>
								<ResultSortableColumn
									label={ __( 'Blank', 'paper-to-quiz' ) }
									column="blank"
									className="column-answer-count"
									orderby={ orderby }
									order={ order }
									onSort={ sortBy }
								/>
								<ResultSortableColumn
									label={ __( 'Score', 'paper-to-quiz' ) }
									column="score"
									className="column-score"
									orderby={ orderby }
									order={ order }
									onSort={ sortBy }
								/>
								<ResultSortableColumn
									label={ __( 'Status', 'paper-to-quiz' ) }
									column="status"
									className="column-result-status"
									orderby={ orderby }
									order={ order }
									onSort={ sortBy }
								/>
							</tr>
						</thead>
						<tbody>
							{ response.items.length === 0 && (
								<tr className="no-items">
									<td colSpan={ 10 }>
										{ __(
											'No results found.',
											'paper-to-quiz'
										) }
									</td>
								</tr>
							) }
							{ response.items.map( ( item ) => (
								<tr key={ item.id }>
									<td
										className="column-primary column-result-title"
										data-colname={ __(
											'Title',
											'paper-to-quiz'
										) }
									>
										<strong>
											<button
												type="button"
												className="row-title button-link"
												disabled={
													detailLoadingId !== null
												}
												onClick={ () =>
													void openDetail(
														Number( item.id )
													)
												}
											>
												{ item.title }
											</button>
										</strong>
										<div className="row-actions">
											<span>
												<button
													type="button"
													className="button-link"
													disabled={
														detailLoadingId !== null
													}
													onClick={ () =>
														void openDetail(
															Number( item.id )
														)
													}
												>
													{ detailLoadingId ===
													Number( item.id ) ? (
														<BusyLabel>
															{ __(
																'Opening…',
																'paper-to-quiz'
															) }
														</BusyLabel>
													) : (
														__(
															'View details',
															'paper-to-quiz'
														)
													) }
												</button>
											</span>
										</div>
									</td>
									<td
										className="column-participant"
										data-colname={ __(
											'Participant',
											'paper-to-quiz'
										) }
									>
										{ item.participant_label }
									</td>
									<td
										className="column-started"
										data-colname={ __(
											'Start',
											'paper-to-quiz'
										) }
									>
										{ item.started_at_display || '—' }
									</td>
									<td
										className="column-finished"
										data-colname={ __(
											'End',
											'paper-to-quiz'
										) }
									>
										{ item.submitted_at_display || '—' }
									</td>
									<td
										className="column-duration"
										data-colname={ __(
											'Duration',
											'paper-to-quiz'
										) }
									>
										{ formatDuration(
											item.duration_seconds
										) }
									</td>
									<td
										className="column-answer-count"
										data-colname={ __(
											'Correct',
											'paper-to-quiz'
										) }
									>
										{ item.correct_count }
									</td>
									<td
										className="column-answer-count"
										data-colname={ __(
											'Wrong',
											'paper-to-quiz'
										) }
									>
										{ item.wrong_count }
									</td>
									<td
										className="column-answer-count"
										data-colname={ __(
											'Blank',
											'paper-to-quiz'
										) }
									>
										{ item.blank_count }
									</td>
									<td
										className="column-score"
										data-colname={ __(
											'Score',
											'paper-to-quiz'
										) }
									>
										{ formatScore(
											Number( item.score ),
											item.score_has_fraction === '1'
												? 2
												: 0
										) }
									</td>
									<td
										className="column-result-status"
										data-colname={ __(
											'Status',
											'paper-to-quiz'
										) }
									>
										{ attemptStatusLabels[ item.status ] ||
											item.status }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</>
			) }
			<div className="tablenav bottom">
				<ListPagination
					page={ response.page }
					pages={ response.pages }
					total={ response.total }
					onChange={ setPage }
					disabled={ loading || detailLoadingId !== null }
				/>
				<br className="clear" />
			</div>
		</div>
	);
}

function ResultSortableColumn( {
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
	order: 'asc' | 'desc';
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

function ResultDetail( {
	detail,
	onClose,
}: {
	detail: any;
	onClose: () => void;
} ) {
	const participantEntries = Object.entries( detail.participant || {} );
	const diagnosticEntries = Object.entries( detail.diagnostics || {} ).filter(
		( [ key ] ) =>
			! [
				'screen',
				'viewport',
				'pixel_ratio',
				'touch_supported',
			].includes( key )
	);
	const typeLabel =
		detail.type === 'exam'
			? _x( 'Exam', 'Assessment type', 'paper-to-quiz' )
			: _x( 'Test', 'Assessment type', 'paper-to-quiz' );
	const systemRows: Array< [ string, unknown ] > = [
		[ __( 'Record ID', 'paper-to-quiz' ), detail.id ],
		[
			sprintf(
				/* translators: %s: Assessment type. */
				__( '%s ID', 'paper-to-quiz' ),
				typeLabel
			),
			detail.assessment_id,
		],
		[ __( 'Revision ID', 'paper-to-quiz' ), detail.revision_id ],
		[ __( 'Participation code', 'paper-to-quiz' ), detail.public_id ],
		[
			__( 'WordPress user ID', 'paper-to-quiz' ),
			detail.wp_user_id || '—',
		],
		[
			__( 'Participant type', 'paper-to-quiz' ),
			detail.participant_type === 'member'
				? __( 'Member', 'paper-to-quiz' )
				: __( 'Guest', 'paper-to-quiz' ),
		],
		[
			__( 'Status', 'paper-to-quiz' ),
			attemptStatusLabels[ detail.status ] || detail.status,
		],
		[ __( 'Start', 'paper-to-quiz' ), detail.started_at_display || '—' ],
		[
			__( 'Last activity', 'paper-to-quiz' ),
			detail.last_activity_at_display || '—',
		],
		[ __( 'End', 'paper-to-quiz' ), detail.submitted_at_display || '—' ],
		[
			__( 'Submission deadline', 'paper-to-quiz' ),
			detail.deadline_at_display || '—',
		],
		[
			__( 'Total duration', 'paper-to-quiz' ),
			formatDuration( detail.duration_seconds ),
		],
	];

	return (
		<div className="ptq-page ptq-result-detail">
			<button
				type="button"
				className="button button-secondary paper-to-quiz-results-back"
				onClick={ onClose }
			>
				← { __( 'Back to results', 'paper-to-quiz' ) }
			</button>
			<h1>{ detail.title || __( 'Result details', 'paper-to-quiz' ) }</h1>
			<p className="description">
				{ sprintf(
					/* translators: 1: Participant label. 2: Assessment type. */
					__( '%1$s · %2$s result', 'paper-to-quiz' ),
					detail.participant_label,
					typeLabel
				) }
			</p>
			<div className="ptq-summary-cards">
				<div>
					<span>{ __( 'Score', 'paper-to-quiz' ) }</span>
					<strong>
						{ formatScore(
							Number( detail.score ),
							detail.score_precision || 0
						) }
					</strong>
				</div>
				<div>
					<span>{ __( 'Correct', 'paper-to-quiz' ) }</span>
					<strong>{ detail.correct_count }</strong>
				</div>
				<div>
					<span>{ __( 'Wrong', 'paper-to-quiz' ) }</span>
					<strong>{ detail.wrong_count }</strong>
				</div>
				<div>
					<span>{ __( 'Blank', 'paper-to-quiz' ) }</span>
					<strong>{ detail.blank_count }</strong>
				</div>
				<div>
					<span>{ __( 'Success', 'paper-to-quiz' ) }</span>
					<strong>%{ detail.percentage }</strong>
				</div>
			</div>
			{ detail.subjects?.length > 0 && (
				<DetailPanel
					title={ __( 'Subject results', 'paper-to-quiz' ) }
					wide
				>
					<div className="ptq-subject-analytics">
						{ detail.subjects.map( ( subject: any ) => (
							<div key={ subject.subject_id }>
								<strong>{ subject.name }</strong>
								<span>
									{ formatScore(
										Number( subject.score ),
										detail.score_precision || 0
									) }{ ' ' }
									/{ ' ' }
									{ formatScore(
										Number( subject.max_score ),
										detail.score_precision || 0
									) }
								</span>
								<span>%{ subject.percentage }</span>
								<span>
									{ subject.correct }D · { subject.wrong }Y ·{ ' ' }
									{ subject.blank }B
								</span>
							</div>
						) ) }
					</div>
				</DetailPanel>
			) }
			<div className="ptq-result-detail-grid">
				<DetailPanel
					title={ __( 'Participant information', 'paper-to-quiz' ) }
				>
					{ participantEntries.length ? (
						<DetailList
							rows={ participantEntries.map(
								( [ key, value ] ) => [
									participantFieldLabels[ key ] || key,
									value,
								]
							) }
						/>
					) : (
						<p>
							{ __(
								'No additional participant information is available.',
								'paper-to-quiz'
							) }
						</p>
					) }
				</DetailPanel>
				<DetailPanel
					title={ __(
						'Device and connection information',
						'paper-to-quiz'
					) }
				>
					{ diagnosticEntries.length ? (
						<DetailList
							rows={ diagnosticEntries.map(
								( [ key, value ] ) => [
									diagnosticLabels[ key ] || key,
									value,
								]
							) }
						/>
					) : (
						<p>
							{ __(
								'No device or connection information was recorded for this participation.',
								'paper-to-quiz'
							) }
						</p>
					) }
				</DetailPanel>
				<DetailPanel
					title={ __( 'Record details', 'paper-to-quiz' ) }
					wide
				>
					<DetailList rows={ systemRows } />
				</DetailPanel>
			</div>
			<h2>{ __( 'Answers', 'paper-to-quiz' ) }</h2>
			<div className="ptq-table-scroll">
				<table className="wp-list-table widefat fixed striped ptq-answer-detail-table">
					<thead>
						<tr>
							<th>{ __( 'Question', 'paper-to-quiz' ) }</th>
							<th>{ __( 'Image', 'paper-to-quiz' ) }</th>
							<th>{ __( 'Subject', 'paper-to-quiz' ) }</th>
							<th>{ __( 'PDF page', 'paper-to-quiz' ) }</th>
							<th>{ __( 'Given answer', 'paper-to-quiz' ) }</th>
							<th>{ __( 'Correct answer', 'paper-to-quiz' ) }</th>
							<th>{ __( 'Result', 'paper-to-quiz' ) }</th>
							<th>{ __( 'Flagged', 'paper-to-quiz' ) }</th>
							<th>{ __( 'Score', 'paper-to-quiz' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ detail.answers.map( ( answer: any ) => {
							return (
								<tr key={ answer.id || answer.question_id }>
									<td>{ answer.ordinal }</td>
									<td>
										{ answer.thumbnail_url ? (
											<AuthenticatedThumbnail
												src={ answer.thumbnail_url }
												alt={ sprintf(
													/* translators: %d: Question number. */
													__(
														'Question %d',
														'paper-to-quiz'
													),
													answer.ordinal
												) }
											/>
										) : (
											'—'
										) }
									</td>
									<td>{ answer.subject_name }</td>
									<td>{ answer.source_page || '—' }</td>
									<td>
										{ answer.selected_option ||
											__( 'Blank', 'paper-to-quiz' ) }
									</td>
									<td>{ answer.correct_option || '—' }</td>
									<td>{ answerStatus( answer ) }</td>
									<td>
										{ answer.is_flagged === '1'
											? __( 'Yes', 'paper-to-quiz' )
											: __( 'No', 'paper-to-quiz' ) }
									</td>
									<td>
										{ formatScore(
											Number( answer.awarded_points ),
											detail.score_precision || 0
										) }
										{ ' / ' }
										{ formatScore(
											Number( answer.question_points ),
											detail.score_precision || 0
										) }
									</td>
								</tr>
							);
						} ) }
					</tbody>
				</table>
			</div>
		</div>
	);
}

function DetailPanel( {
	title,
	wide = false,
	children,
}: {
	title: string;
	wide?: boolean;
	children: React.ReactNode;
} ) {
	return (
		<section className={ `ptq-detail-panel ${ wide ? 'is-wide' : '' }` }>
			<h2>{ title }</h2>
			{ children }
		</section>
	);
}

function DetailList( { rows }: { rows: Array< [ string, unknown ] > } ) {
	return (
		<dl className="ptq-participant-detail">
			{ rows.map( ( [ label, value ] ) => (
				<div key={ label }>
					<dt>{ label }</dt>
					<dd>
						{ value === '' || value === null
							? '—'
							: String( value ) }
					</dd>
				</div>
			) ) }
		</dl>
	);
}

function AuthenticatedThumbnail( { src, alt }: { src: string; alt: string } ) {
	const [ url, setUrl ] = useState( '' );
	useEffect( () => {
		let objectUrl = '';
		fetch( src, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': window.paperToQuizAdmin.nonce },
		} )
			.then( ( response ) => {
				if ( ! response.ok ) {
					throw new Error();
				}
				return response.blob();
			} )
			.then( ( blob ) => {
				objectUrl = URL.createObjectURL( blob );
				setUrl( objectUrl );
			} )
			.catch( () => undefined );
		return () => {
			if ( objectUrl ) {
				URL.revokeObjectURL( objectUrl );
			}
		};
	}, [ src ] );
	return url ? (
		<img className="ptq-result-thumb" src={ url } alt={ alt } />
	) : null;
}

// formatScore is imported from ../shared/format (plan 017).
