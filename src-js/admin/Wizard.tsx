import {
	lazy,
	Suspense,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	Notice,
	RadioControl,
	SelectControl,
	Spinner,
	TextareaControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import type {
	AccessMode,
	AssessmentRecord,
	AssessmentType,
	FeedbackTiming,
	ListResponse,
	ResultVisibility,
	Term,
} from '../types';
import { api, ApiError, uploadPdf } from './api';
import { ConfirmationDialog } from './ConfirmationDialog';
import { BusyLabel, LoadingRegion } from './BusyLabel';
import { feedbackTimingForRelease, normalizeExamPolicy } from './policy';

const LazyPdfEditor = lazy( () =>
	import( './PdfEditor' ).then( ( module ) => ( {
		default: module.PdfEditor,
	} ) )
);

const steps = [
	__( 'Information', 'paper-to-quiz' ),
	__( 'PDF upload', 'paper-to-quiz' ),
	__( 'Select questions', 'paper-to-quiz' ),
	__( 'Answer key', 'paper-to-quiz' ),
	__( 'Review and publish', 'paper-to-quiz' ),
];
const participantLabels: Record< string, string > = {
	first_name: __( 'First name', 'paper-to-quiz' ),
	last_name: __( 'Last name', 'paper-to-quiz' ),
	school: __( 'School name', 'paper-to-quiz' ),
	class_section: __( 'Class and section', 'paper-to-quiz' ),
	email: __( 'Email', 'paper-to-quiz' ),
	phone: __( 'Phone', 'paper-to-quiz' ),
};

type Draft = {
	type: AssessmentType;
	title: string;
	description: string;
	class_id: number;
	subject_ids: number[];
	access_mode: AccessMode;
	options: string[];
	total_points: number;
	duration_seconds: number | null;
	window_start_utc: string;
	window_end_utc: string;
	results_release_at_utc: string;
	allow_repeat: boolean;
	ranking_enabled: boolean;
	feedback_timing: FeedbackTiming;
	result_visibility: ResultVisibility;
	participant_fields: Record<
		string,
		{ enabled: boolean; required: boolean }
	>;
};

function emptyDraft( type: AssessmentType ): Draft {
	return {
		type,
		title: '',
		description: '',
		class_id: 0,
		subject_ids: [],
		access_mode: 'guest_allowed',
		options: [ 'A', 'B', 'C', 'D' ],
		total_points: 10000,
		duration_seconds: null,
		window_start_utc: '',
		window_end_utc: '',
		results_release_at_utc: '',
		allow_repeat: type === 'test',
		ranking_enabled: false,
		feedback_timing: 'after_submit',
		result_visibility: 'summary',
		participant_fields: Object.fromEntries(
			Object.keys( participantLabels ).map( ( key ) => [
				key,
				{ enabled: false, required: false },
			] )
		),
	};
}

function validateDraft(
	draft: Draft
): { message: string; field: string } | null {
	const contentName: string =
		draft.type === 'exam'
			? __( 'Exam', 'paper-to-quiz' )
			: __( 'Test', 'paper-to-quiz' );
	if ( ! draft.title.trim() ) {
		return {
			message: sprintf(
				/* translators: %s: Item type. */
				__( 'Enter a %s name.', 'paper-to-quiz' ),
				contentName
			),
			field: 'ptq-title',
		};
	}
	if ( draft.class_id < 1 ) {
		return {
			message: __( 'Select a class.', 'paper-to-quiz' ),
			field: 'ptq-class',
		};
	}
	if ( draft.subject_ids.length < 1 ) {
		return {
			message: __( 'Select at least one subject.', 'paper-to-quiz' ),
			field: 'ptq-subject-list',
		};
	}
	if ( ! Number.isFinite( draft.total_points ) || draft.total_points < 100 ) {
		return {
			message: __( 'Total points must be at least 1.', 'paper-to-quiz' ),
			field: 'ptq-total-points',
		};
	}
	if ( draft.type === 'exam' ) {
		if ( ! draft.allow_repeat && ! draft.window_start_utc ) {
			return {
				message: __( 'Enter the exam start date.', 'paper-to-quiz' ),
				field: 'ptq-window-start',
			};
		}
		if ( ! draft.allow_repeat && ! draft.window_end_utc ) {
			return {
				message: __( 'Enter the exam end date.', 'paper-to-quiz' ),
				field: 'ptq-window-end',
			};
		}
		if (
			! draft.allow_repeat &&
			new Date( draft.window_end_utc ).getTime() <=
				new Date( draft.window_start_utc ).getTime()
		) {
			return {
				message: __(
					'The exam end date must be after the start date.',
					'paper-to-quiz'
				),
				field: 'ptq-window-end',
			};
		}
	}
	return null;
}

export function Wizard( {
	assessmentId,
	initialType,
	initialStep = 0,
	onClose,
	onComplete,
}: {
	assessmentId?: number;
	initialType: AssessmentType;
	initialStep?: number;
	onClose: () => void;
	onComplete: ( message: string ) => void;
} ) {
	const [ id, setId ] = useState( assessmentId );
	const [ step, setStep ] = useState( initialStep );
	const [ draft, setDraft ] = useState< Draft >( emptyDraft( initialType ) );
	const [ record, setRecord ] = useState< AssessmentRecord | null >( null );
	const [ classes, setClasses ] = useState< Term[] >( [] );
	const [ subjects, setSubjects ] = useState< Term[] >( [] );
	const [ loading, setLoading ] = useState( Boolean( assessmentId ) );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ success, setSuccess ] = useState( '' );
	const [ dirty, setDirty ] = useState( false );
	const [ regenerateQuestions, setRegenerateQuestions ] = useState( false );
	const [ leaveConfirmation, setLeaveConfirmation ] = useState( false );
	const operationLock = useRef( false );

	useEffect( () => {
		Promise.all( [
			api< ListResponse< Term > >(
				'/admin/classes?status=active&page=1&per_page=100'
			),
			api< ListResponse< Term > >(
				'/admin/subjects?status=active&page=1&per_page=100'
			),
		] )
			.then( ( [ classItems, subjectItems ] ) => {
				setClasses( classItems.items );
				setSubjects( subjectItems.items );
			} )
			.catch( ( caught ) => setError( caught.message ) );
	}, [] );

	useEffect( () => {
		if ( ! assessmentId ) {
			return;
		}
		api< AssessmentRecord >( `/admin/assessments/${ assessmentId }` )
			.then( ( loaded ) => {
				setRecord( loaded );
				setDraft( fromRecord( loaded ) );
			} )
			.catch( ( caught ) => setError( caught.message ) )
			.finally( () => setLoading( false ) );
	}, [ assessmentId ] );

	useEffect( () => {
		const handler = ( event: BeforeUnloadEvent ) => {
			if ( ! dirty ) {
				return;
			}
			event.preventDefault();
		};
		window.addEventListener( 'beforeunload', handler );
		return () => window.removeEventListener( 'beforeunload', handler );
	}, [ dirty ] );

	const update = < K extends keyof Draft >( key: K, value: Draft[ K ] ) => {
		setDraft( ( current ) => ( { ...current, [ key ]: value } ) );
		setDirty( true );
	};
	const updateMany = ( values: Partial< Draft > ) => {
		setDraft( ( current ) => ( { ...current, ...values } ) );
		setDirty( true );
	};

	const changeStep = ( next: number ) => {
		setError( '' );
		setSuccess( '' );
		setStep( next );
	};

	async function saveInfo( goNext = true ) {
		if ( operationLock.current ) {
			return;
		}
		const validation = validateDraft( draft );
		if ( validation ) {
			setError( validation.message );
			window.requestAnimationFrame( () => {
				document.getElementById( validation.field )?.focus();
				document
					.getElementById( validation.field )
					?.scrollIntoView( { behavior: 'smooth', block: 'center' } );
			} );
			return;
		}
		operationLock.current = true;
		setSaving( true );
		setError( '' );
		try {
			const payload = toPayload( draft );
			const saved = await api< AssessmentRecord >(
				id ? `/admin/assessments/${ id }` : '/admin/assessments',
				{
					method: id ? 'PUT' : 'POST',
					json: payload,
				}
			);
			setId( Number( saved.assessment.id ) );
			setRecord( saved );
			setDraft( fromRecord( saved ) );
			setDirty( false );
			setSuccess( __( 'Draft saved.', 'paper-to-quiz' ) );
			if ( goNext ) {
				setStep( 1 );
			}
		} catch ( caught ) {
			setError(
				caught instanceof Error
					? caught.message
					: __( 'The record could not be saved.', 'paper-to-quiz' )
			);
		} finally {
			operationLock.current = false;
			setSaving( false );
		}
	}

	async function refresh() {
		if ( ! id ) {
			return;
		}
		const loaded = await api< AssessmentRecord >(
			`/admin/assessments/${ id }`
		);
		setRecord( loaded );
		setDraft( fromRecord( loaded ) );
	}

	if ( loading ) {
		return (
			<div className="ptq-page">
				<Spinner />
			</div>
		);
	}

	return (
		<div className="ptq-page ptq-wizard">
			<div className="ptq-page__header">
				<div>
					<Button
						className="ptq-back-to-list"
						variant="secondary"
						onClick={ () => {
							if ( ! dirty ) {
								onClose();
							} else {
								setLeaveConfirmation( true );
							}
						} }
					>
						{ __( 'Back to list', 'paper-to-quiz' ) }
					</Button>
					<h1>
						{ id
							? draft.title ||
							  sprintf(
									/* translators: %s: Item type. */
									__( 'Edit %s', 'paper-to-quiz' ),
									draft.type === 'exam'
										? __( 'Exam', 'paper-to-quiz' )
										: __( 'Test', 'paper-to-quiz' )
							  )
							: sprintf(
									/* translators: %s: Assessment type. */
									__( 'New %s', 'paper-to-quiz' ),
									initialType === 'exam'
										? __( 'Exam', 'paper-to-quiz' )
										: __( 'Test', 'paper-to-quiz' )
							  ) }
					</h1>
				</div>
				{ id && <code>{ `[paper_to_quiz id="${ id }"]` }</code> }
			</div>
			<ol
				className="ptq-stepper"
				aria-label={ __( 'Creation steps', 'paper-to-quiz' ) }
			>
				{ steps.map( ( label, index ) => {
					let stepClass = '';
					if ( index === step ) {
						stepClass = 'is-active';
					} else if ( index < step ) {
						stepClass = 'is-complete';
					}
					return (
						<li key={ label } className={ stepClass }>
							<div
								aria-current={
									index === step ? 'step' : undefined
								}
							>
								<span>{ index + 1 }</span>
								{ label }
							</div>
						</li>
					);
				} ) }
			</ol>
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			{ success && (
				<Notice status="success" onRemove={ () => setSuccess( '' ) }>
					{ success }
				</Notice>
			) }

			<div className="ptq-wizard__content">
				{ step === 0 && (
					<InformationStep
						draft={ draft }
						update={ update }
						updateMany={ updateMany }
						classes={ classes }
						subjects={ subjects }
						createdAt={ record?.assessment.created_at_display }
					/>
				) }
				{ step === 1 && id && (
					<PdfStep
						record={ record }
						onUploaded={ async ( regenerate ) => {
							setRegenerateQuestions( regenerate );
							await refresh();
							changeStep( 2 );
						} }
						assessmentId={ id }
					/>
				) }
				{ step === 2 && record?.revision?.pdf_url && (
					<Suspense
						fallback={
							<LoadingRegion>
								{ __(
									'Loading question editor…',
									'paper-to-quiz'
								) }
							</LoadingRegion>
						}
					>
						<LazyPdfEditor
							record={ record }
							regenerateAll={ regenerateQuestions }
							onSaved={ async () => {
								setRegenerateQuestions( false );
								await refresh();
								changeStep( 3 );
							} }
							onError={ setError }
						/>
					</Suspense>
				) }
				{ step === 3 && record && (
					<AnswerKey
						record={ record }
						onSaved={ async () => {
							await refresh();
							changeStep( 4 );
						} }
						onError={ setError }
					/>
				) }
				{ step === 4 && record && (
					<PublishStep
						record={ record }
						className={
							classes.find(
								( item ) =>
									Number( item.id ) ===
									Number( record.revision.class_id )
							)?.name || '—'
						}
						subjectName={
							( record.revision.subject_names || [] ).join(
								', '
							) || '—'
						}
						onPublish={ async () => {
							if ( ! id || operationLock.current ) {
								return;
							}
							operationLock.current = true;
							try {
								setSaving( true );
								await api(
									`/admin/assessments/${ id }/publish`,
									{
										method: 'POST',
										json: {},
									}
								);
								onComplete(
									record.assessment.type === 'exam'
										? __(
												'Exam saved and published.',
												'paper-to-quiz'
										  )
										: __(
												'Test saved and published.',
												'paper-to-quiz'
										  )
								);
							} catch ( caught ) {
								const apiError = caught as ApiError;
								const errors = ( apiError.data as any )?.data
									?.errors;
								setError(
									errors
										? `${
												apiError.message
										  }\n${ errors.join( '\n' ) }`
										: apiError.message
								);
							} finally {
								operationLock.current = false;
								setSaving( false );
							}
						} }
						busy={ saving }
					/>
				) }
			</div>

			<div className="ptq-wizard__footer">
				<Button
					disabled={ step === 0 || saving }
					variant="secondary"
					onClick={ () => changeStep( Math.max( 0, step - 1 ) ) }
				>
					{ __( 'Back', 'paper-to-quiz' ) }
				</Button>
				{ step === 0 && (
					<Button
						disabled={ saving }
						variant="primary"
						onClick={ () => void saveInfo( true ) }
					>
						{ saving ? (
							<BusyLabel>
								{ __( 'Saving…', 'paper-to-quiz' ) }
							</BusyLabel>
						) : (
							__( 'Save and continue', 'paper-to-quiz' )
						) }
					</Button>
				) }
				{ step === 1 && record?.revision?.pdf_url && (
					<Button variant="primary" onClick={ () => changeStep( 2 ) }>
						{ __( 'Select questions', 'paper-to-quiz' ) }
					</Button>
				) }
			</div>
			{ leaveConfirmation && (
				<ConfirmationDialog
					isOpen={ leaveConfirmation }
					title={ __( 'Leave this screen?', 'paper-to-quiz' ) }
					description={ __(
						'Unsaved changes will be lost. Leave this screen?',
						'paper-to-quiz'
					) }
					consequence={ __(
						'Any edits made since the last save will be discarded.',
						'paper-to-quiz'
					) }
					confirmLabel={ __( 'Leave screen', 'paper-to-quiz' ) }
					isDestructive
					onClose={ () => setLeaveConfirmation( false ) }
					onConfirm={ () => {
						onClose();
					} }
				/>
			) }
		</div>
	);
}

function InformationStep( {
	draft,
	update,
	updateMany,
	classes,
	subjects,
	createdAt,
}: {
	draft: Draft;
	update: < K extends keyof Draft >( key: K, value: Draft[ K ] ) => void;
	updateMany: ( values: Partial< Draft > ) => void;
	classes: Term[];
	subjects: Term[];
	createdAt?: string;
} ) {
	const [ repeatConfirmation, setRepeatConfirmation ] = useState< null | {
		value: boolean;
	} >( null );
	const optionPreset = draft.options.join( '' ) as 'ABC' | 'ABCD' | 'ABCDE';
	const contentName: string =
		draft.type === 'exam'
			? __( 'Exam', 'paper-to-quiz' )
			: __( 'Test', 'paper-to-quiz' );
	const feedbackOptions: Array< { label: string; value: FeedbackTiming } > =
		draft.type === 'exam' && draft.results_release_at_utc
			? [
					{
						label: __(
							'On the result release date',
							'paper-to-quiz'
						),
						value: 'scheduled',
					},
			  ]
			: [
					{
						label: __(
							'Do not show correct answers',
							'paper-to-quiz'
						),
						value: 'never',
					},
					{
						label: __( 'After each answer', 'paper-to-quiz' ),
						value: 'immediate',
					},
					{
						label:
							draft.type === 'test'
								? __(
										'At the end of the test',
										'paper-to-quiz'
								  )
								: __(
										'After completing the exam',
										'paper-to-quiz'
								  ),
						value: 'after_submit',
					},
			  ];
	let rankingHelp: string = __(
		'Only members who submit on time are included in the ranking.',
		'paper-to-quiz'
	);
	if ( draft.allow_repeat ) {
		rankingHelp = __(
			'Repeatable exams cannot use ranking.',
			'paper-to-quiz'
		);
	} else if ( draft.access_mode !== 'login_required' ) {
		rankingHelp = __(
			'Set access to “Membership required” to use ranking.',
			'paper-to-quiz'
		);
	}

	function applyRepeatAttempts( value: boolean ) {
		const values: Partial< Draft > = {
			allow_repeat: false,
		};
		if ( value ) {
			values.allow_repeat = true;
			values.ranking_enabled = false;
			values.window_start_utc = '';
			values.window_end_utc = '';
			values.results_release_at_utc = '';
			values.feedback_timing = draft.feedback_timing;
			if ( draft.feedback_timing === 'scheduled' ) {
				values.feedback_timing = 'after_submit';
			}
		}
		updateMany( values );
	}
	return (
		<div className="ptq-form-section">
			<div className="ptq-information-groups">
				<section className="ptq-information-group">
					<h2>{ __( 'Basic information', 'paper-to-quiz' ) }</h2>
					<div className="ptq-form-grid ptq-form-grid--compact ptq-form-grid--two">
						<TextControl
							id="ptq-title"
							label={ sprintf(
								/* translators: %s: Assessment type. */
								__( '%s title *', 'paper-to-quiz' ),
								contentName
							) }
							value={ draft.title }
							onChange={ ( value ) => update( 'title', value ) }
						/>
						<SelectControl
							id="ptq-class"
							label={ __( 'Class *', 'paper-to-quiz' ) }
							value={ String( draft.class_id ) }
							options={ [
								{
									label: __( 'Select', 'paper-to-quiz' ),
									value: '0',
								},
								...classes.map( ( term ) => ( {
									label: term.name,
									value: term.id,
								} ) ),
							] }
							onChange={ ( value ) =>
								update( 'class_id', Number( value ) )
							}
						/>
						<fieldset
							id="ptq-subject-list"
							className="ptq-subject-selector"
							tabIndex={ -1 }
						>
							<legend>
								{ __( 'Subjects *', 'paper-to-quiz' ) }
							</legend>
							<div>
								{ subjects.map( ( term ) => {
									const termId = Number( term.id );
									return (
										<CheckboxControl
											key={ term.id }
											label={ term.name }
											checked={ draft.subject_ids.includes(
												termId
											) }
											onChange={ ( checked ) =>
												update(
													'subject_ids',
													checked
														? [
																...draft.subject_ids,
																termId,
														  ]
														: draft.subject_ids.filter(
																( id ) =>
																	id !==
																	termId
														  )
												)
											}
										/>
									);
								} ) }
							</div>
						</fieldset>
					</div>
					<TextareaControl
						label={ __( 'Description', 'paper-to-quiz' ) }
						value={ draft.description }
						onChange={ ( value ) => update( 'description', value ) }
					/>
					{ createdAt && (
						<div className="ptq-readonly-date">
							<span>{ __( 'Created', 'paper-to-quiz' ) }</span>
							<strong>{ createdAt }</strong>
						</div>
					) }
				</section>

				<section className="ptq-information-group">
					<h2>{ __( 'Application settings', 'paper-to-quiz' ) }</h2>
					<div className="ptq-form-grid ptq-form-grid--compact ptq-form-grid--two">
						<SelectControl
							label={ __( 'Answer options', 'paper-to-quiz' ) }
							value={ optionPreset }
							options={ [
								{ label: 'A-B-C', value: 'ABC' },
								{ label: 'A-B-C-D', value: 'ABCD' },
								{
									label: 'A-B-C-D-E',
									value: 'ABCDE',
								},
							] }
							onChange={ ( value ) =>
								update( 'options', value.split( '' ) )
							}
						/>
						<TextControl
							id="ptq-total-points"
							type="number"
							min={ 1 }
							step={ 0.01 }
							label={ __( 'Total points', 'paper-to-quiz' ) }
							value={ String( draft.total_points / 100 ) }
							onChange={ ( value ) =>
								update(
									'total_points',
									Math.round( Number( value ) * 100 )
								)
							}
						/>
						{ draft.type === 'exam' && (
							<>
								<TextControl
									type="number"
									min={ 1 }
									label={ __(
										'Duration (minutes)',
										'paper-to-quiz'
									) }
									help={ __(
										'Leave blank for no time limit.',
										'paper-to-quiz'
									) }
									value={
										draft.duration_seconds
											? String(
													draft.duration_seconds / 60
											  )
											: ''
									}
									onChange={ ( value ) =>
										update(
											'duration_seconds',
											value ? Number( value ) * 60 : null
										)
									}
								/>
								<SelectControl
									label={ __( 'Access', 'paper-to-quiz' ) }
									value={ draft.access_mode }
									options={ [
										{
											label: __(
												'Public',
												'paper-to-quiz'
											),
											value: 'guest_allowed',
										},
										{
											label: __(
												'Membership required',
												'paper-to-quiz'
											),
											value: 'login_required',
										},
									] }
									onChange={ ( value ) =>
										updateMany( {
											access_mode: value as AccessMode,
											...( value === 'guest_allowed'
												? {
														ranking_enabled: false,
												  }
												: {} ),
										} )
									}
								/>
							</>
						) }
					</div>
				</section>

				{ draft.type === 'exam' && (
					<section className="ptq-information-group">
						<h2>{ __( 'Exam schedule', 'paper-to-quiz' ) }</h2>
						{ draft.allow_repeat ? (
							<Notice status="info" isDismissible={ false }>
								{ __(
									'The schedule and ranking are unavailable while participants can retake this exam. Any time limit restarts with each attempt.',
									'paper-to-quiz'
								) }
							</Notice>
						) : (
							<div className="ptq-form-grid">
								<TextControl
									id="ptq-window-start"
									type="datetime-local"
									label={ __( 'Start *', 'paper-to-quiz' ) }
									value={ draft.window_start_utc }
									onChange={ ( value ) =>
										update( 'window_start_utc', value )
									}
								/>
								<TextControl
									id="ptq-window-end"
									type="datetime-local"
									label={ __( 'End *', 'paper-to-quiz' ) }
									value={ draft.window_end_utc }
									onChange={ ( value ) =>
										update( 'window_end_utc', value )
									}
								/>
								<TextControl
									type="datetime-local"
									label={ __(
										'Result release',
										'paper-to-quiz'
									) }
									value={ draft.results_release_at_utc }
									onChange={ ( value ) => {
										updateMany( {
											results_release_at_utc: value,
											feedback_timing:
												feedbackTimingForRelease(
													draft.feedback_timing,
													value
												),
										} );
									} }
								/>
							</div>
						) }
					</section>
				) }

				<section className="ptq-information-group">
					<h2>
						{ __(
							'Results and participation settings',
							'paper-to-quiz'
						) }
					</h2>
					<div className="ptq-form-grid ptq-form-grid--compact ptq-form-grid--two">
						<SelectControl
							label={ __(
								'Show correct answers',
								'paper-to-quiz'
							) }
							value={ draft.feedback_timing }
							options={ feedbackOptions }
							disabled={ Boolean(
								draft.type === 'exam' &&
									draft.results_release_at_utc
							) }
							help={
								draft.type === 'exam' &&
								draft.results_release_at_utc
									? __(
											'Correct answers will be available on the result release date. Clear that date to choose a different option.',
											'paper-to-quiz'
									  )
									: undefined
							}
							onChange={ ( value ) =>
								update(
									'feedback_timing',
									value as FeedbackTiming
								)
							}
						/>
						<SelectControl
							label={ __( 'Show in results', 'paper-to-quiz' ) }
							value={ draft.result_visibility }
							options={ [
								{
									label: __( 'Hide result', 'paper-to-quiz' ),
									value: 'hidden',
								},
								{
									label: __( 'Score only', 'paper-to-quiz' ),
									value: 'score_only',
								},
								{
									label: __( 'Summary', 'paper-to-quiz' ),
									value: 'summary',
								},
								{
									label: __(
										'With answers',
										'paper-to-quiz'
									),
									value: 'detailed',
								},
							] }
							onChange={ ( value ) =>
								update(
									'result_visibility',
									value as ResultVisibility
								)
							}
						/>
					</div>
					{ draft.type === 'exam' && (
						<div className="ptq-toggle-group">
							<ToggleControl
								label={ __(
									'Allow repeat attempts',
									'paper-to-quiz'
								) }
								checked={ draft.allow_repeat }
								onChange={ ( value ) => {
									if (
										value &&
										( draft.ranking_enabled ||
											draft.window_start_utc ||
											draft.window_end_utc ||
											draft.results_release_at_utc )
									) {
										setRepeatConfirmation( { value } );
										return;
									}
									applyRepeatAttempts( value );
								} }
							/>
							<ToggleControl
								label={ __(
									'Enable member ranking',
									'paper-to-quiz'
								) }
								help={ rankingHelp }
								disabled={
									draft.allow_repeat ||
									draft.access_mode !== 'login_required'
								}
								checked={ draft.ranking_enabled }
								onChange={ ( value ) =>
									update( 'ranking_enabled', value )
								}
							/>
						</div>
					) }
					{ draft.feedback_timing === 'immediate' &&
						( draft.ranking_enabled || draft.type === 'exam' ) && (
							<Notice
								className="ptq-feedback-notice"
								status="warning"
								isDismissible={ false }
							>
								{ __(
									'Showing correct answers immediately may give other participants an advantage.',
									'paper-to-quiz'
								) }
							</Notice>
						) }
				</section>

				<section className="ptq-information-group">
					<h2>
						{ __(
							'Information requested from participants',
							'paper-to-quiz'
						) }
					</h2>
					<div className="ptq-participant-fields">
						{ Object.entries( participantLabels ).map(
							( [ key, label ] ) => {
								const config = draft.participant_fields[
									key
								] || {
									enabled: false,
									required: false,
								};
								return (
									<div key={ key }>
										<CheckboxControl
											label={ label }
											checked={ config.enabled }
											onChange={ ( enabled ) =>
												update( 'participant_fields', {
													...draft.participant_fields,
													[ key ]: {
														...config,
														enabled,
														required: enabled
															? config.required
															: false,
													},
												} )
											}
										/>
										<CheckboxControl
											label={ __(
												'Required',
												'paper-to-quiz'
											) }
											disabled={ ! config.enabled }
											checked={ config.required }
											onChange={ ( required ) =>
												update( 'participant_fields', {
													...draft.participant_fields,
													[ key ]: {
														...config,
														required,
													},
												} )
											}
										/>
									</div>
								);
							}
						) }
					</div>
				</section>
				{ repeatConfirmation && (
					<ConfirmationDialog
						isOpen={ Boolean( repeatConfirmation ) }
						title={ __(
							'Allow participants to retake this exam?',
							'paper-to-quiz'
						) }
						description={ __(
							'The current exam dates, result release date, and ranking setting will be cleared.',
							'paper-to-quiz'
						) }
						consequence={ __(
							'Participants will be able to start this exam again after completing it.',
							'paper-to-quiz'
						) }
						confirmLabel={ __( 'Allow retakes', 'paper-to-quiz' ) }
						isDestructive
						onClose={ () => setRepeatConfirmation( null ) }
						onConfirm={ () => {
							const next = repeatConfirmation.value;
							applyRepeatAttempts( next );
						} }
					/>
				) }
			</div>
		</div>
	);
}

function PdfStep( {
	record,
	assessmentId,
	onUploaded,
}: {
	record: AssessmentRecord | null;
	assessmentId: number;
	onUploaded: ( regenerate: boolean ) => void;
} ) {
	const [ progress, setProgress ] = useState( 0 );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ pendingReplacement, setPendingReplacement ] = useState< {
		label: string;
		run: ( strategy: 'preserve' | 'clear' ) => Promise< void >;
	} | null >( null );
	const uploadLock = useRef( false );
	const beginReplacement = (
		label: string,
		run: ( strategy: 'preserve' | 'clear' ) => Promise< void >
	) => {
		if ( record?.revision?.pdf_url && record.questions.length ) {
			setPendingReplacement( { label, run } );
			return;
		}
		void run( 'clear' );
	};
	const runReplacement = async ( strategy: 'preserve' | 'clear' ) => {
		const pending = pendingReplacement;
		if ( ! pending ) {
			return;
		}
		await pending.run( strategy );
		// On success the ConfirmationDialog closes itself via onClose, so the
		// dialog stays mounted (and the buttons locked) while the upload runs.
	};
	const selectFromMedia = () => {
		if ( uploadLock.current ) {
			return;
		}
		const media = window.wp?.media;
		if ( ! media ) {
			setError(
				__(
					'The Media Library could not be opened. Please try again.',
					'paper-to-quiz'
				)
			);
			return;
		}
		const frame = media( {
			title: __( 'Select a PDF', 'paper-to-quiz' ),
			button: { text: __( 'Use this PDF', 'paper-to-quiz' ) },
			library: { type: 'application/pdf' },
			multiple: false,
		} );
		frame.on( 'select', async () => {
			if ( uploadLock.current ) {
				return;
			}
			const attachment = frame
				.state()
				.get( 'selection' )
				.first()
				.toJSON();
			beginReplacement(
				attachment.filename || __( 'Selected PDF', 'paper-to-quiz' ),
				async ( strategy ) => {
					uploadLock.current = true;
					setBusy( true );
					setError( '' );
					setProgress( 20 );
					try {
						await api(
							`/admin/assessments/${ assessmentId }/source-media`,
							{
								method: 'POST',
								json: {
									attachment_id: attachment.id,
									question_strategy: strategy,
								},
							}
						);
						setProgress( 100 );
						await onUploaded( strategy === 'preserve' );
					} catch ( caught ) {
						setError(
							caught instanceof Error
								? caught.message
								: __(
										'The PDF could not be selected.',
										'paper-to-quiz'
								  )
						);
					} finally {
						uploadLock.current = false;
						setBusy( false );
					}
				}
			);
		} );
		frame.open();
	};
	return (
		<div className="ptq-form-section">
			<h2>{ __( 'PDF upload', 'paper-to-quiz' ) }</h2>
			{ record?.revision?.pdf_url && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'A PDF is already uploaded. Selecting a new PDF will replace the current source.',
						'paper-to-quiz'
					) }
				</Notice>
			) }
			{ error && <Notice status="error">{ error }</Notice> }
			<label className="ptq-dropzone" htmlFor="ptq-pdf-upload">
				<input
					id="ptq-pdf-upload"
					type="file"
					accept="application/pdf,.pdf"
					disabled={ busy }
					onChange={ async ( event ) => {
						const file = event.target.files?.[ 0 ];
						if ( ! file || uploadLock.current ) {
							return;
						}
						event.target.value = '';
						beginReplacement( file.name, async ( strategy ) => {
							uploadLock.current = true;
							setBusy( true );
							setError( '' );
							setProgress( 0 );
							try {
								await uploadPdf(
									assessmentId,
									file,
									setProgress,
									strategy
								);
								await onUploaded( strategy === 'preserve' );
							} catch ( caught ) {
								setError(
									caught instanceof Error
										? caught.message
										: __(
												'The PDF could not be uploaded.',
												'paper-to-quiz'
										  )
								);
							} finally {
								uploadLock.current = false;
								setBusy( false );
							}
						} );
					} }
				/>
				<strong>
					{ busy
						? __( 'Uploading PDF…', 'paper-to-quiz' )
						: __(
								'Select a PDF or drop it here',
								'paper-to-quiz'
						  ) }
				</strong>
				<span>
					{ sprintf(
						/* translators: %d: Maximum PDF size in megabytes. */
						__(
							'Select a PDF file. You can upload up to %d MB.',
							'paper-to-quiz'
						),
						window.paperToQuizAdmin.settings.max_pdf_mb || 50
					) }
				</span>
			</label>
			<div className="ptq-media-divider">
				<span>{ __( 'or', 'paper-to-quiz' ) }</span>
			</div>
			<Button
				variant="secondary"
				disabled={ busy }
				onClick={ selectFromMedia }
			>
				{ __( 'Select from Media Library', 'paper-to-quiz' ) }
			</Button>
			{ busy && (
				<div className="ptq-progress">
					<span style={ { width: `${ progress }%` } } />
					<strong>{ progress }%</strong>
				</div>
			) }
			<ConfirmationDialog
				isOpen={ Boolean( pendingReplacement ) }
				title={ __( 'Replace PDF?', 'paper-to-quiz' ) }
				description={ sprintf(
					/* translators: %s: Selected PDF filename. */
					__(
						'%s will replace the current PDF. How should the selected questions be handled?',
						'paper-to-quiz'
					),
					pendingReplacement?.label || ''
				) }
				confirmLabel={ __(
					'Keep selections and regenerate images',
					'paper-to-quiz'
				) }
				secondaryAction={ {
					label: __( 'Replace and clear questions', 'paper-to-quiz' ),
					isDestructive: true,
					onAction: () => runReplacement( 'clear' ),
				} }
				onClose={ () => setPendingReplacement( null ) }
				onConfirm={ () => runReplacement( 'preserve' ) }
			/>
		</div>
	);
}

function AnswerKey( {
	record,
	onSaved,
	onError,
}: {
	record: AssessmentRecord;
	onSaved: () => void;
	onError: ( message: string ) => void;
} ) {
	const [ questions, setQuestions ] = useState(
		record.questions.map( ( question ) => ( { ...question } ) )
	);
	const [ busy, setBusy ] = useState( false );
	const saveLock = useRef( false );
	const target = Number( record.revision.total_points );
	const current = questions.reduce(
		( sum, question ) => sum + Number( question.points ),
		0
	);

	function distribute() {
		const base = Math.floor( target / questions.length );
		const remainder = target % questions.length;
		setQuestions(
			questions.map( ( question, index ) => ( {
				...question,
				points: String( base + ( index < remainder ? 1 : 0 ) ),
			} ) )
		);
	}

	useEffect( () => {
		if (
			questions.length &&
			questions.every( ( question ) => Number( question.points ) === 0 )
		) {
			distribute();
		}
		// Initial equal distribution only.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	return (
		<div className="ptq-form-section">
			<div className="ptq-section-heading">
				<div>
					<h2>{ __( 'Answer key', 'paper-to-quiz' ) }</h2>
					<p>
						{ sprintf(
							/* translators: 1: Current points. 2: Target points. */
							__( 'Total: %1$s / %2$s', 'paper-to-quiz' ),
							( current / 100 ).toFixed( 2 ),
							( target / 100 ).toFixed( 2 )
						) }
					</p>
				</div>
				<Button disabled={ busy } onClick={ distribute }>
					{ __( 'Distribute points evenly', 'paper-to-quiz' ) }
				</Button>
			</div>
			{ current !== target && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'The points total does not match the target.',
						'paper-to-quiz'
					) }
				</Notice>
			) }
			<div className="ptq-answer-key">
				{ questions.map( ( question, index ) => (
					<div className="ptq-answer-row" key={ question.id }>
						<strong>{ index + 1 }</strong>
						{ question.thumb_url ? (
							<AuthenticatedImage
								src={ question.thumb_url }
								alt={ `${ index + 1 }. soru` }
							/>
						) : (
							<span>{ __( 'No image', 'paper-to-quiz' ) }</span>
						) }
						<span className="ptq-answer-row__subject">
							{ question.subject_name ||
								__( 'Subject not specified', 'paper-to-quiz' ) }
						</span>
						<RadioControl
							label={ __( 'Correct answer', 'paper-to-quiz' ) }
							disabled={ busy }
							selected={ question.correct_option || '' }
							options={ record.revision.options.map(
								( option ) => ( {
									label: option,
									value: option,
								} )
							) }
							onChange={ ( value ) =>
								setQuestions(
									questions.map( ( item ) =>
										item.id === question.id
											? { ...item, correct_option: value }
											: item
									)
								)
							}
						/>
						<TextControl
							type="number"
							min={ 0 }
							step={ 0.01 }
							label={ __( 'Points', 'paper-to-quiz' ) }
							disabled={ busy }
							value={ String( Number( question.points ) / 100 ) }
							onChange={ ( value ) =>
								setQuestions(
									questions.map( ( item ) =>
										item.id === question.id
											? {
													...item,
													points: String(
														Math.round(
															Number( value ) *
																100
														)
													),
											  }
											: item
									)
								)
							}
						/>
					</div>
				) ) }
			</div>
			<Button
				variant="primary"
				disabled={
					busy ||
					current !== target ||
					questions.some( ( question ) => ! question.correct_option )
				}
				onClick={ async () => {
					if ( saveLock.current ) {
						return;
					}
					saveLock.current = true;
					setBusy( true );
					try {
						await api(
							`/admin/revisions/${ record.revision.id }/answer-key`,
							{
								method: 'PUT',
								json: {
									questions: questions.map(
										( question ) => ( {
											id: Number( question.id ),
											correct_option:
												question.correct_option,
											points: Number( question.points ),
										} )
									),
								},
							}
						);
						onSaved();
					} catch ( caught ) {
						onError(
							caught instanceof Error
								? caught.message
								: __(
										'The answer key could not be saved.',
										'paper-to-quiz'
								  )
						);
					} finally {
						saveLock.current = false;
						setBusy( false );
					}
				} }
			>
				{ busy ? (
					<BusyLabel>{ __( 'Saving…', 'paper-to-quiz' ) }</BusyLabel>
				) : (
					__( 'Save answer key', 'paper-to-quiz' )
				) }
			</Button>
		</div>
	);
}

function PublishStep( {
	record,
	className,
	subjectName,
	onPublish,
	busy,
}: {
	record: AssessmentRecord;
	className: string;
	subjectName: string;
	onPublish: () => void;
	busy: boolean;
} ) {
	const contentName: string =
		record.assessment.type === 'exam'
			? __( 'Exam', 'paper-to-quiz' )
			: __( 'Test', 'paper-to-quiz' );
	const selectedSubjectIds = new Set(
		( record.revision.subject_ids || [] ).map( Number )
	);
	const commonChecks = [
		[
			__( 'PDF uploaded', 'paper-to-quiz' ),
			Boolean( record.revision.source_asset_id ),
		],
		[
			__( 'At least one question selected', 'paper-to-quiz' ),
			record.questions.length > 0,
		],
		[
			__( 'All question images are ready', 'paper-to-quiz' ),
			record.questions.length > 0 &&
				record.questions.every(
					( question ) =>
						question.main_asset_id && question.thumb_asset_id
				),
		],
		[
			__( 'All questions have a valid subject', 'paper-to-quiz' ),
			record.questions.length > 0 &&
				selectedSubjectIds.size > 0 &&
				record.questions.every(
					( question ) =>
						question.subject_id &&
						selectedSubjectIds.has( Number( question.subject_id ) )
				),
		],
		[
			__( 'All correct answers are entered', 'paper-to-quiz' ),
			record.questions.length > 0 &&
				record.questions.every(
					( question ) => question.correct_option
				),
		],
		[
			__( 'Points total is correct', 'paper-to-quiz' ),
			record.questions.reduce(
				( sum, question ) => sum + Number( question.points ),
				0
			) === Number( record.revision.total_points ),
		],
		[
			__( 'Class and subject selected', 'paper-to-quiz' ),
			Boolean( record.revision.class_id && selectedSubjectIds.size ),
		],
	] as const;
	const repeatableExam = record.revision.allow_repeat === '1';
	const examChecks = repeatableExam
		? ( [
				[
					__( 'Exam schedule is disabled', 'paper-to-quiz' ),
					! record.revision.window_start_utc &&
						! record.revision.window_end_utc &&
						! record.revision.results_release_at_utc,
				],
				[
					__( 'Ranking is disabled', 'paper-to-quiz' ),
					record.revision.ranking_enabled !== '1',
				],
		  ] as const )
		: ( [
				[
					__( 'Exam dates are valid', 'paper-to-quiz' ),
					Boolean(
						record.revision.window_start_utc &&
							record.revision.window_end_utc &&
							record.revision.window_start_utc <
								record.revision.window_end_utc
					),
				],
				[
					__( 'Result release date is consistent', 'paper-to-quiz' ),
					! record.revision.results_release_at_utc ||
						! record.revision.window_end_utc ||
						record.revision.results_release_at_utc >=
							record.revision.window_end_utc,
				],
				[
					__(
						'Correct-answer timing matches the result release date',
						'paper-to-quiz'
					),
					Boolean( record.revision.results_release_at_utc )
						? record.revision.feedback_timing === 'scheduled'
						: record.revision.feedback_timing !== 'scheduled',
				],
				[
					__(
						'Ranking is single-attempt and membership-based',
						'paper-to-quiz'
					),
					record.revision.ranking_enabled !== '1' ||
						( record.revision.access_mode === 'login_required' &&
							record.revision.allow_repeat !== '1' ),
				],
		  ] as const );
	const checks =
		record.assessment.type === 'exam'
			? [ ...commonChecks, ...examChecks ]
			: commonChecks;
	const valid = checks.every( ( [ , status ] ) => status );
	let publishLabel: string =
		record.assessment.type === 'exam'
			? __( 'Publish exam', 'paper-to-quiz' )
			: __( 'Publish test', 'paper-to-quiz' );
	if ( record.assessment.status === 'published' ) {
		publishLabel = __( 'Publish new version', 'paper-to-quiz' );
	}
	const durationLabel = record.revision.duration_seconds
		? sprintf(
				/* translators: %d: Number of minutes. */
				_n(
					'%d minute',
					'%d minutes',
					Math.ceil(
						Number( record.revision.duration_seconds ) / 60
					),
					'paper-to-quiz'
				),
				Math.ceil( Number( record.revision.duration_seconds ) / 60 )
		  )
		: __( 'No time limit', 'paper-to-quiz' );
	const summary: Array< [ string, string | number ] > = [
		[
			sprintf(
				/* translators: %s: Content type, such as Exam or Test. */
				__( '%s title', 'paper-to-quiz' ),
				contentName
			),
			record.revision.title,
		],
		[ __( 'Class', 'paper-to-quiz' ), className ],
		[ __( 'Subjects', 'paper-to-quiz' ), subjectName ],
		[ __( 'Question count', 'paper-to-quiz' ), record.questions.length ],
		[
			__( 'Answer options', 'paper-to-quiz' ),
			record.revision.options.join( ' – ' ),
		],
		[
			__( 'Total points', 'paper-to-quiz' ),
			( Number( record.revision.total_points ) / 100 ).toFixed( 2 ),
		],
	];
	if ( record.assessment.type === 'exam' ) {
		summary.splice(
			3,
			0,
			[ __( 'Duration', 'paper-to-quiz' ), durationLabel ],
			[
				__( 'Access', 'paper-to-quiz' ),
				record.revision.access_mode === 'login_required'
					? __( 'Membership required', 'paper-to-quiz' )
					: __( 'Public', 'paper-to-quiz' ),
			]
		);
	}
	return (
		<div className="ptq-form-section">
			<h2>{ __( 'Review and publish', 'paper-to-quiz' ) }</h2>
			<p>
				{ __(
					'Review the information and preparation status before publishing.',
					'paper-to-quiz'
				) }
			</p>
			<div className="ptq-publish-layout">
				<section>
					<h3>
						{ sprintf(
							/* translators: %s: Assessment type. */
							__( '%s summary', 'paper-to-quiz' ),
							contentName
						) }
					</h3>
					<dl className="ptq-publish-summary">
						{ summary.map( ( [ label, value ] ) => (
							<div key={ label }>
								<dt>{ label }</dt>
								<dd>{ value }</dd>
							</div>
						) ) }
					</dl>
				</section>
				<section>
					<h3>{ __( 'Publishing checks', 'paper-to-quiz' ) }</h3>
					<ul className="ptq-checklist">
						{ checks.map( ( [ label, status ] ) => (
							<li
								key={ label }
								className={ status ? 'is-valid' : 'is-invalid' }
							>
								{ status ? '✓' : '×' } { label }
							</li>
						) ) }
					</ul>
				</section>
			</div>
			<div className="ptq-publish-actions">
				<Button
					variant="primary"
					disabled={ ! valid || busy }
					onClick={ onPublish }
				>
					{ busy ? (
						<BusyLabel>
							{ __( 'Publishing…', 'paper-to-quiz' ) }
						</BusyLabel>
					) : (
						publishLabel
					) }
				</Button>
			</div>
		</div>
	);
}

function AuthenticatedImage( { src, alt }: { src: string; alt: string } ) {
	const [ url, setUrl ] = useState( '' );
	useEffect( () => {
		let objectUrl = '';
		fetch( src, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': window.paperToQuizAdmin.nonce },
		} )
			.then( ( response ) => response.blob() )
			.then( ( blob ) => {
				objectUrl = URL.createObjectURL( blob );
				setUrl( objectUrl );
			} );
		return () => {
			if ( objectUrl ) {
				URL.revokeObjectURL( objectUrl );
			}
		};
	}, [ src ] );
	return url ? <img src={ url } alt={ alt } /> : <Spinner />;
}

function fromRecord( record: AssessmentRecord ): Draft {
	const revision = record.revision;
	return {
		type: record.assessment.type,
		title: revision.title,
		description: revision.description || '',
		class_id: Number( revision.class_id || 0 ),
		subject_ids:
			revision.subject_ids?.map( Number ) ||
			( revision.subject_id ? [ Number( revision.subject_id ) ] : [] ),
		access_mode: revision.access_mode,
		options: revision.options,
		total_points: Number( revision.total_points ),
		duration_seconds: revision.duration_seconds
			? Number( revision.duration_seconds )
			: null,
		window_start_utc: localDate( revision.window_start_utc ),
		window_end_utc: localDate( revision.window_end_utc ),
		results_release_at_utc: localDate( revision.results_release_at_utc ),
		allow_repeat: revision.allow_repeat === '1',
		ranking_enabled: revision.ranking_enabled === '1',
		feedback_timing: revision.feedback_timing,
		result_visibility: revision.result_visibility,
		participant_fields: {
			...emptyDraft( record.assessment.type ).participant_fields,
			...revision.participant_fields,
		},
	};
}

function toPayload( draft: Draft ) {
	const policyDraft =
		draft.type === 'exam' ? normalizeExamPolicy( draft ) : draft;
	return {
		...policyDraft,
		window_start_utc: utcDate( policyDraft.window_start_utc ),
		window_end_utc: utcDate( policyDraft.window_end_utc ),
		results_release_at_utc: utcDate( policyDraft.results_release_at_utc ),
	};
}

function localDate( value?: string ): string {
	if ( ! value ) {
		return '';
	}
	const date = new Date( `${ value.replace( ' ', 'T' ) }Z` );
	const offset = date.getTimezoneOffset() * 60000;
	return new Date( date.getTime() - offset ).toISOString().slice( 0, 16 );
}

function utcDate( value: string ): string | null {
	return value ? new Date( value ).toISOString() : null;
}
