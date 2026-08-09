import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { studentApi, StudentApiError } from './api';
import {
	deleteDraft,
	deleteReceipt,
	readDraft,
	readReceipt,
	writeDraft,
	writeReceipt,
	type AttemptDraft,
} from './draft';
import {
	formatScore,
	formatTurkishPhoneInput,
	normalizeTurkishPhone,
	reduceDraftAnswer,
	submissionAnswers,
} from './domain';
import {
	formatCountdown,
	formatDuration as formatResultDuration,
} from '../shared/format';

type Field = { key: string; label: string; required: boolean; type: string };
type Schedule = {
	state: 'scheduled' | 'open' | 'ended';
	server_time: string;
	starts_at?: string | null;
	ends_at?: string | null;
	results_release_at?: string | null;
	starts_at_display?: string;
	ends_at_display?: string;
	results_release_display?: string;
};
type Bootstrap = {
	id: number;
	type: 'exam' | 'test';
	title: string;
	description: string;
	class_name?: string;
	class_color?: string;
	access_mode: 'guest_allowed' | 'login_required';
	question_count: number;
	duration_seconds?: number;
	allow_repeat: boolean;
	ranking_enabled: boolean;
	participant_fields: Field[];
	current_user?: Record< string, string | number >;
	latest_attempt_public_id?: string | null;
	schedule: Schedule;
};
type AttemptQuestion = {
	id: number;
	ordinal: number;
	imageUrl: string;
	correctOption?: string;
};
type SavedAnswer = {
	question_id: string;
	selected_option?: string;
	is_flagged: string;
};
type Attempt = {
	public_id: string;
	revision_id: number;
	token?: string;
	status: string;
	started_at: string;
	deadline_at?: string;
	server_time: string;
	title: string;
	class_name?: string;
	class_color?: string;
	options: string[];
	feedback_timing: 'never' | 'immediate' | 'after_submit' | 'scheduled';
	questions: AttemptQuestion[];
	answers: SavedAnswer[];
	participant_type: 'member' | 'guest';
};
type AnswerState = Record<
	number,
	{ option: string | null; flagged: boolean }
>;
type Result = {
	status: string;
	submitted: boolean;
	visibility: 'hidden' | 'score_only' | 'summary' | 'detailed';
	release_at?: string;
	release_pending: boolean;
	server_time: string;
	score?: number;
	percentage?: number;
	correct?: number;
	wrong?: number;
	blank?: number;
	answers?: Array< {
		question_id: string;
		ordinal: string;
		selected_option?: string;
		correct_option?: string;
		is_correct?: string;
	} >;
	ranking?: { rank: number; total: number; percentile: number };
	can_retry: boolean;
	integrity_status: 'on_time' | 'late_recovered' | 'expired';
	ranking_eligible: boolean;
	score_precision: 0 | 2;
	answer_key_visible: boolean;
	document?: {
		assessment_type: 'exam' | 'test';
		assessment_title: string;
		class_name?: string;
		participant_name: string;
		school?: string;
		class_section?: string;
		submitted_at: string;
		duration_seconds?: number | null;
	};
	subjects?: Array< {
		subject_id: number;
		name: string;
		correct: number;
		wrong: number;
		blank: number;
		score: number;
		max_score: number;
		percentage: number;
		ranking?: { rank: number; total: number; percentile: number };
	} >;
};

function contentCopy( type?: 'exam' | 'test' ) {
	return type === 'exam'
		? {
				name: __( 'Exam', 'paper-to-quiz' ),
				start: __( 'Start exam', 'paper-to-quiz' ),
				finish: __( 'Finish exam', 'paper-to-quiz' ),
				completed: __( 'Exam completed', 'paper-to-quiz' ),
		  }
		: {
				name: __( 'Test', 'paper-to-quiz' ),
				start: __( 'Start test', 'paper-to-quiz' ),
				finish: __( 'Finish test', 'paper-to-quiz' ),
				completed: __( 'Test completed', 'paper-to-quiz' ),
		  };
}

const sectionOptions = 'ABCDEFGHIJKLMN'.split( '' );

function BusyLabel( { children }: { children: string } ) {
	return (
		<span className="ptq-busy-label" role="status" aria-live="polite">
			<span className="ptq-spinner ptq-spinner--small" />
			<span>{ children }</span>
		</span>
	);
}

export function StudentApp( {
	assessmentId,
	restRoot,
	nonce,
	mountElement,
}: {
	assessmentId: number;
	restRoot: string;
	nonce: string;
	mountElement: HTMLElement;
} ) {
	const [ bootstrap, setBootstrap ] = useState< Bootstrap | null >( null );
	const [ attempt, setAttempt ] = useState< Attempt | null >( null );
	const [ token, setToken ] = useState( '' );
	const [ participant, setParticipant ] = useState<
		Record< string, string >
	>( {} );
	const [ answers, setAnswers ] = useState< AnswerState >( {} );
	const [ active, setActive ] = useState( 0 );
	const [ loading, setLoading ] = useState( true );
	const [ starting, setStarting ] = useState( false );
	const [ submitting, setSubmitting ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ offline, setOffline ] = useState( ! navigator.onLine );
	const [ remaining, setRemaining ] = useState< number | null >( null );
	const [ finishOpen, setFinishOpen ] = useState( false );
	const [ drawerOpen, setDrawerOpen ] = useState( false );
	const [ studentClass, setStudentClass ] = useState( '' );
	const [ studentSection, setStudentSection ] = useState( '' );
	const [ result, setResult ] = useState< Result | null >( null );
	const [ focusMode, setFocusMode ] = useState( false );
	const [ startsIn, setStartsIn ] = useState< number | null >( null );
	const autoSubmitting = useRef( false );
	const startLock = useRef( false );
	const submitLock = useRef( false );
	const draftRef = useRef< AttemptDraft | null >( null );
	const nativeFullscreenWasActive = useRef( false );
	const scheduleRefreshPending = useRef( false );
	const content = contentCopy( bootstrap?.type );

	useEffect( () => {
		const classColor = attempt?.class_color || bootstrap?.class_color || '';
		if ( /^#[0-9a-f]{6}$/i.test( classColor ) ) {
			mountElement.style.setProperty( '--ptq-primary', classColor );
		} else {
			mountElement.style.removeProperty( '--ptq-primary' );
		}
	}, [ attempt?.class_color, bootstrap?.class_color, mountElement ] );

	useEffect( () => {
		if ( draftRef.current && attempt?.status === 'in_progress' ) {
			draftRef.current = { ...draftRef.current, active };
			void persistDraft( draftRef.current );
		}
	}, [ active, attempt?.status ] );

	const loadState = useCallback(
		async ( publicId: string, currentToken = '', draft?: AttemptDraft ) => {
			const state = await studentApi< Attempt >(
				restRoot,
				`/attempts/${ publicId }`,
				nonce,
				currentToken
			);
			setAttempt( state );
			setToken( currentToken );
			const restored = draft?.answers || answerMap( state.answers );
			setAnswers( restored );
			setActive(
				Math.min(
					Math.max( 0, draft?.active || 0 ),
					Math.max( 0, state.questions.length - 1 )
				)
			);
			if ( draft ) {
				draftRef.current = draft;
			}
			if ( state.status !== 'in_progress' ) {
				setResult(
					await studentApi< Result >(
						restRoot,
						`/attempts/${ publicId }/result`,
						nonce,
						currentToken
					)
				);
				try {
					await writeReceipt( {
						assessmentId,
						publicId: state.public_id,
						revisionId: state.revision_id,
					} );
					await deleteDraft( assessmentId );
					draftRef.current = null;
				} catch {
					// Keep the completed draft as a fallback if receipt storage fails.
				}
			} else if ( draft?.finishRequested ) {
				window.setTimeout( () => void submitDraft( state, draft ), 0 );
			}
		},
		// submitDraft intentionally reads the current submission state.
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ nonce, restRoot, assessmentId ]
	);

	useEffect( () => {
		( async () => {
			try {
				const meta = await studentApi< Bootstrap >(
					restRoot,
					`/assessments/${ assessmentId }/bootstrap`,
					nonce
				);
				setBootstrap( meta );
				const initial: Record< string, string > = {};
				meta.participant_fields.forEach( ( field ) => {
					initial[ field.key ] = String(
						meta.current_user?.[ field.key ] || ''
					);
				} );
				setParticipant( initial );
				const draft = await readDraft( assessmentId );
				if ( draft ) {
					await loadState( draft.publicId, '', draft );
				} else {
					const receipt = await readReceipt( assessmentId );
					const completedPublicId =
						receipt?.publicId ||
						meta.latest_attempt_public_id ||
						'';
					if ( completedPublicId ) {
						try {
							await loadState( completedPublicId );
						} catch ( caught ) {
							if ( receipt ) {
								await deleteReceipt( assessmentId );
							}
							throw caught;
						}
					}
				}
			} catch ( caught ) {
				const apiError = caught as StudentApiError;
				setError( apiError.message );
			} finally {
				setLoading( false );
			}
		} )();
	}, [ assessmentId, restRoot, nonce, loadState ] );

	useEffect( () => {
		const onFullscreenChange = () => {
			if ( document.fullscreenElement ) {
				nativeFullscreenWasActive.current = true;
				return;
			}
			if ( nativeFullscreenWasActive.current ) {
				nativeFullscreenWasActive.current = false;
				setFocusMode( false );
			}
		};
		document.addEventListener( 'fullscreenchange', onFullscreenChange );
		return () =>
			document.removeEventListener(
				'fullscreenchange',
				onFullscreenChange
			);
	}, [] );

	useEffect( () => {
		if (
			! bootstrap?.schedule.starts_at ||
			bootstrap.schedule.state !== 'scheduled'
		) {
			setStartsIn( null );
			return;
		}
		const serverAnchor = new Date(
			bootstrap.schedule.server_time
		).getTime();
		const performanceAnchor = performance.now();
		const tick = () => {
			const monotonicNow =
				serverAnchor + ( performance.now() - performanceAnchor );
			const seconds = Math.max(
				0,
				Math.ceil(
					( new Date( bootstrap.schedule.starts_at! ).getTime() -
						monotonicNow ) /
						1000
				)
			);
			setStartsIn( seconds );
			if ( seconds === 0 && ! scheduleRefreshPending.current ) {
				scheduleRefreshPending.current = true;
				void studentApi< Bootstrap >(
					restRoot,
					`/assessments/${ assessmentId }/bootstrap`,
					nonce
				)
					.then( setBootstrap )
					.catch( () => undefined )
					.finally( () => {
						scheduleRefreshPending.current = false;
					} );
			}
		};
		tick();
		const timer = window.setInterval( tick, 1000 );
		return () => window.clearInterval( timer );
	}, [
		bootstrap?.schedule.server_time,
		bootstrap?.schedule.starts_at,
		bootstrap?.schedule.state,
		assessmentId,
		nonce,
		restRoot,
	] );

	useEffect( () => {
		if ( ! attempt?.deadline_at || attempt.status !== 'in_progress' ) {
			setRemaining( null );
			return;
		}
		const serverAnchor = new Date( attempt.server_time ).getTime();
		const performanceAnchor = performance.now();
		const tick = () => {
			const monotonicNow =
				serverAnchor + ( performance.now() - performanceAnchor );
			const seconds = Math.max(
				0,
				Math.ceil(
					( new Date( attempt.deadline_at! ).getTime() -
						monotonicNow ) /
						1000
				)
			);
			setRemaining( seconds );
			if ( seconds === 0 && ! autoSubmitting.current ) {
				autoSubmitting.current = true;
				void submit( true );
			}
		};
		tick();
		const timer = window.setInterval( tick, 1000 );
		return () => window.clearInterval( timer );
		// submit is intentionally bound to current attempt/token below.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ attempt?.deadline_at, attempt?.server_time, attempt?.status ] );

	useEffect( () => {
		const online = () => {
			setOffline( false );
			if ( ! attempt ) {
				void studentApi< Bootstrap >(
					restRoot,
					`/assessments/${ assessmentId }/bootstrap`,
					nonce
				)
					.then( setBootstrap )
					.catch( () => undefined );
			}
			if ( attempt && draftRef.current?.finishRequested ) {
				void submitDraft( attempt, draftRef.current );
			}
		};
		const offlineHandler = () => setOffline( true );
		window.addEventListener( 'online', online );
		window.addEventListener( 'offline', offlineHandler );
		return () => {
			window.removeEventListener( 'online', online );
			window.removeEventListener( 'offline', offlineHandler );
		};
		// submitDraft intentionally reads the current submission state.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ attempt, assessmentId, nonce, restRoot ] );

	useEffect( () => {
		const resyncSchedule = () => {
			if (
				document.visibilityState !== 'visible' ||
				attempt ||
				bootstrap?.schedule.state !== 'scheduled'
			) {
				return;
			}
			void studentApi< Bootstrap >(
				restRoot,
				`/assessments/${ assessmentId }/bootstrap`,
				nonce
			)
				.then( setBootstrap )
				.catch( () => undefined );
		};
		document.addEventListener( 'visibilitychange', resyncSchedule );
		return () =>
			document.removeEventListener( 'visibilitychange', resyncSchedule );
	}, [ assessmentId, attempt, bootstrap?.schedule.state, nonce, restRoot ] );

	useEffect( () => {
		if ( ! result ) {
			return;
		}
		setFocusMode( false );
		if ( document.fullscreenElement ) {
			void document.exitFullscreen().catch( () => undefined );
		}
	}, [ result ] );

	useEffect( () => {
		if (
			! result ||
			result.visibility !== 'hidden' ||
			! result.release_pending ||
			! result.release_at ||
			! result.server_time ||
			! attempt
		) {
			return;
		}
		const delay = Math.max(
			0,
			new Date( result.release_at ).getTime() -
				new Date( result.server_time ).getTime()
		);
		const timer = window.setTimeout(
			() => {
				void studentApi< Result >(
					restRoot,
					`/attempts/${ attempt.public_id }/result`,
					nonce,
					token
				)
					.then( setResult )
					.catch( () => undefined );
			},
			Math.min( delay + 250, 2_147_483_647 )
		);
		return () => window.clearTimeout( timer );
	}, [ attempt, nonce, restRoot, result, token ] );

	useEffect( () => {
		const unload = ( event: BeforeUnloadEvent ) => {
			if ( attempt?.status !== 'in_progress' ) {
				return;
			}
			event.preventDefault();
		};
		window.addEventListener( 'beforeunload', unload );
		return () => window.removeEventListener( 'beforeunload', unload );
	}, [ attempt?.status ] );

	async function start() {
		if ( ! bootstrap || startLock.current ) {
			return;
		}
		for ( const field of bootstrap.participant_fields ) {
			if (
				field.key === 'class_section' &&
				field.required &&
				( ! studentClass || ! studentSection )
			) {
				setError(
					__( 'Class and section are required.', 'paper-to-quiz' )
				);
				return;
			}
			if ( field.required && ! participant[ field.key ]?.trim() ) {
				if ( field.key === 'class_section' ) {
					continue;
				}
				setError(
					sprintf(
						/* translators: %s: Participant field label. */
						__( '%s is required.', 'paper-to-quiz' ),
						field.label
					)
				);
				return;
			}
		}
		if (
			participant.phone &&
			! normalizeTurkishPhone( participant.phone )
		) {
			setError(
				__(
					'Enter a valid Turkish mobile phone number.',
					'paper-to-quiz'
				)
			);
			return;
		}
		startLock.current = true;
		setStarting( true );
		setError( '' );
		void requestNativeFullscreen();
		try {
			const state = await studentApi< Attempt >(
				restRoot,
				`/assessments/${ assessmentId }/attempts`,
				nonce,
				'',
				{
					method: 'POST',
					json: {
						participant: {
							...participant,
							class_section:
								studentClass && studentSection
									? sprintf(
											/* translators: 1: School class number. 2: Section letter. */
											__(
												'Grade %1$s, section %2$s',
												'paper-to-quiz'
											),
											studentClass,
											studentSection
									  )
									: participant.class_section || '',
							phone: participant.phone
								? normalizeTurkishPhone( participant.phone ) ||
								  participant.phone
								: '',
						},
						client: {
							timezone:
								Intl.DateTimeFormat().resolvedOptions()
									.timeZone || '',
							language: navigator.language || '',
							platform:
								(
									navigator as Navigator & {
										userAgentData?: { platform?: string };
									}
								 ).userAgentData?.platform ||
								navigator.platform ||
								'',
						},
					},
				}
			);
			const currentToken = state.token || '';
			const draft: AttemptDraft = {
				assessmentId,
				publicId: state.public_id,
				revisionId: state.revision_id,
				active: 0,
				answers: {},
				submissionId: crypto.randomUUID(),
				finishRequested: false,
				automatic: false,
				updatedAt: Date.now(),
				expiresAt: Date.now(),
			};
			draftRef.current = draft;
			await persistDraft( draft );
			setAttempt( state );
			setToken( currentToken );
			setAnswers( answerMap( state.answers ) );
			setFocusMode( true );
		} catch ( caught ) {
			if ( document.fullscreenElement ) {
				void document.exitFullscreen().catch( () => undefined );
			}
			setError(
				caught instanceof Error
					? caught.message
					: sprintf(
							/* translators: %s: Assessment type. */
							__(
								'The %s could not be started.',
								'paper-to-quiz'
							),
							content.name
					  )
			);
		} finally {
			startLock.current = false;
			setStarting( false );
		}
	}

	function choose(
		questionId: number,
		option: string | null,
		flagged = answers[ questionId ]?.flagged || false
	) {
		if (
			! attempt ||
			attempt.status !== 'in_progress' ||
			submitLock.current
		) {
			return;
		}
		setAnswers( ( current ) => {
			const next = reduceDraftAnswer(
				current,
				questionId,
				option,
				flagged
			);
			if ( draftRef.current ) {
				draftRef.current = { ...draftRef.current, answers: next };
				void persistDraft( draftRef.current );
			}
			return next;
		} );
	}

	async function submit( automatic = false ) {
		if ( ! attempt || submitLock.current ) {
			return;
		}
		submitLock.current = true;
		setSubmitting( true );
		setError( '' );
		const draft =
			draftRef.current ||
			( {
				assessmentId,
				publicId: attempt.public_id,
				revisionId: attempt.revision_id,
				active,
				answers,
				submissionId: crypto.randomUUID(),
				finishRequested: false,
				automatic,
				updatedAt: Date.now(),
				expiresAt: Date.now(),
			} satisfies AttemptDraft );
		draftRef.current = {
			...draft,
			answers,
			active,
			finishRequested: true,
			automatic,
		};
		await persistDraft( draftRef.current );
		try {
			const completed = await sendSubmission( attempt, draftRef.current );
			setResult( completed );
			setAttempt( {
				...attempt,
				status: automatic ? 'auto_submitted' : 'submitted',
			} );
			setFinishOpen( false );
			try {
				await writeReceipt( {
					assessmentId,
					publicId: attempt.public_id,
					revisionId: attempt.revision_id,
				} );
				await deleteDraft( assessmentId );
				draftRef.current = null;
			} catch {
				// Keep the completed draft as a fallback if receipt storage fails.
			}
		} catch ( caught ) {
			setError(
				caught instanceof Error
					? caught.message
					: __(
							'The submission could not be completed.',
							'paper-to-quiz'
					  )
			);
			autoSubmitting.current = false;
		} finally {
			submitLock.current = false;
			setSubmitting( false );
		}
	}

	async function submitDraft(
		state: Attempt,
		draft: AttemptDraft
	): Promise< void > {
		if ( submitLock.current || ! draft.finishRequested ) {
			return;
		}
		submitLock.current = true;
		setSubmitting( true );
		try {
			const completed = await sendSubmission( state, draft );
			setResult( completed );
			setAttempt( {
				...state,
				status: draft.automatic ? 'auto_submitted' : 'submitted',
			} );
			try {
				await writeReceipt( {
					assessmentId,
					publicId: state.public_id,
					revisionId: state.revision_id,
				} );
				await deleteDraft( assessmentId );
				draftRef.current = null;
			} catch {
				// Keep the completed draft as a fallback if receipt storage fails.
			}
		} catch ( caught ) {
			setError(
				caught instanceof Error
					? caught.message
					: __(
							'The submission could not be completed.',
							'paper-to-quiz'
					  )
			);
			autoSubmitting.current = false;
		} finally {
			submitLock.current = false;
			setSubmitting( false );
		}
	}

	async function sendSubmission(
		state: Attempt,
		draft: AttemptDraft
	): Promise< Result > {
		return studentApi< Result >(
			restRoot,
			`/attempts/${ state.public_id }/submit`,
			nonce,
			token,
			{
				method: 'POST',
				json: {
					submission_id: draft.submissionId,
					automatic: draft.automatic,
					answers: submissionAnswers(
						state.questions.map( ( question ) => question.id ),
						draft.answers
					),
				},
			}
		);
	}

	if ( loading ) {
		return (
			<div className="ptq-loading" role="status">
				<span className="ptq-spinner" />
				{ __( 'Preparing…', 'paper-to-quiz' ) }
			</div>
		);
	}
	if ( error && ! bootstrap ) {
		return (
			<div className="ptq-error" role="alert">
				{ error }
			</div>
		);
	}
	if ( result ) {
		return (
			<ResultView
				result={ result }
				attempt={ attempt }
				token={ token }
				nonce={ nonce }
				type={ bootstrap?.type || 'test' }
				onRetry={ async () => {
					await deleteDraft( assessmentId );
					await deleteReceipt( assessmentId );
					draftRef.current = null;
					setAttempt( null );
					setResult( null );
					setAnswers( {} );
					setActive( 0 );
					setFocusMode( false );
					autoSubmitting.current = false;
				} }
			/>
		);
	}
	if ( ! attempt && bootstrap ) {
		const scheduleLocked = bootstrap.schedule.state !== 'open';
		const scheduleStatus = scheduleStatusLabel( bootstrap.schedule.state );
		let startButtonLabel: React.ReactNode = content.start;
		if ( bootstrap.schedule.state === 'ended' ) {
			startButtonLabel = __( 'The exam has ended.', 'paper-to-quiz' );
		}
		if ( bootstrap.schedule.state === 'scheduled' ) {
			startButtonLabel =
				startsIn !== null
					? sprintf(
							/* translators: %s: Countdown until the exam starts. */
							__( 'Starts in %s', 'paper-to-quiz' ),
							formatCountdown( startsIn )
					  )
					: __( 'The exam has not started yet.', 'paper-to-quiz' );
		}
		if ( starting ) {
			startButtonLabel = (
				<BusyLabel>{ __( 'Starting…', 'paper-to-quiz' ) }</BusyLabel>
			);
		}
		return (
			<section className="ptq-start">
				<header>
					<span>
						{ bootstrap.type === 'exam'
							? __( 'Exam', 'paper-to-quiz' )
							: __( 'Test', 'paper-to-quiz' ) }
					</span>
					<h2>{ bootstrap.title }</h2>
				</header>
				{ bootstrap.description && (
					<div
						className="ptq-description"
						dangerouslySetInnerHTML={ {
							__html: bootstrap.description,
						} }
					/>
				) }
				<ul className="ptq-start__facts">
					<li>
						<strong>
							{ sprintf(
								/* translators: %d: Number of questions. */
								_n(
									'%d question',
									'%d questions',
									bootstrap.question_count,
									'paper-to-quiz'
								),
								bootstrap.question_count
							) }
						</strong>
					</li>
					<li>
						<strong>{ startDurationLabel( bootstrap ) }</strong>
					</li>
					<li>
						<strong>
							{ bootstrap.access_mode === 'login_required'
								? __( 'Membership required', 'paper-to-quiz' )
								: __( 'Free', 'paper-to-quiz' ) }
						</strong>
					</li>
				</ul>
				{ bootstrap.type === 'exam' &&
					( bootstrap.schedule.starts_at ||
						bootstrap.schedule.ends_at ||
						bootstrap.schedule.results_release_at ) && (
						<div
							className="ptq-schedule"
							aria-label={ __(
								'Exam schedule',
								'paper-to-quiz'
							) }
						>
							<div className="ptq-schedule__heading">
								<span
									className="ptq-schedule__icon"
									aria-hidden="true"
								>
									◷
								</span>
								<div>
									<strong>
										{ __(
											'Exam schedule',
											'paper-to-quiz'
										) }
									</strong>
									<span>{ scheduleStatus }</span>
								</div>
							</div>
							<dl>
								<div>
									<dt>{ __( 'Start', 'paper-to-quiz' ) }</dt>
									<dd>
										{ bootstrap.schedule
											.starts_at_display ||
											__( 'Not set', 'paper-to-quiz' ) }
									</dd>
								</div>
								<div>
									<dt>{ __( 'End', 'paper-to-quiz' ) }</dt>
									<dd>
										{ bootstrap.schedule.ends_at_display ||
											__( 'Not set', 'paper-to-quiz' ) }
									</dd>
								</div>
								<div>
									<dt>
										{ __(
											'Result release',
											'paper-to-quiz'
										) }
									</dt>
									<dd>
										{ bootstrap.schedule
											.results_release_display ||
											__( 'Not set', 'paper-to-quiz' ) }
									</dd>
								</div>
							</dl>
						</div>
					) }
				{ error && (
					<div className="ptq-error" role="alert">
						{ error }
					</div>
				) }
				{ bootstrap.schedule.state !== 'ended' &&
					bootstrap.participant_fields.length > 0 && (
						<div className="ptq-participant-form">
							{ bootstrap.participant_fields.map( ( field ) => {
								if ( field.key === 'class_section' ) {
									return (
										<div
											key={ field.key }
											className="ptq-class-section-field"
										>
											<label htmlFor="ptq-participant-class">
												{ __(
													'Class',
													'paper-to-quiz'
												) }
												{ field.required && ' *' }
												<select
													id="ptq-participant-class"
													required={ field.required }
													value={ studentClass }
													onChange={ ( event ) =>
														setStudentClass(
															event.target.value
														)
													}
												>
													<option value="">
														{ __(
															'Select',
															'paper-to-quiz'
														) }
													</option>
													{ [
														'1',
														'2',
														'3',
														'4',
													].map( ( value ) => (
														<option
															key={ value }
															value={ value }
														>
															{ sprintf(
																/* translators: %s: Grade number. */
																__(
																	'Grade %s',
																	'paper-to-quiz'
																),
																value
															) }
														</option>
													) ) }
												</select>
											</label>
											<label htmlFor="ptq-participant-section">
												{ __(
													'Section',
													'paper-to-quiz'
												) }
												{ field.required && ' *' }
												<select
													id="ptq-participant-section"
													required={ field.required }
													value={ studentSection }
													onChange={ ( event ) =>
														setStudentSection(
															event.target.value
														)
													}
												>
													<option value="">
														{ __(
															'Select',
															'paper-to-quiz'
														) }
													</option>
													{ sectionOptions.map(
														( value ) => (
															<option
																key={ value }
																value={ value }
															>
																{ value }
															</option>
														)
													) }
												</select>
											</label>
										</div>
									);
								}
								const fromProfile = Boolean(
									bootstrap.current_user?.[ field.key ]
								);
								return (
									<label
										key={ field.key }
										htmlFor={ `ptq-participant-${ field.key }` }
									>
										{ field.label }
										{ field.required && ' *' }
										<input
											id={ `ptq-participant-${ field.key }` }
											type={ field.type }
											inputMode={
												field.key === 'phone'
													? 'tel'
													: undefined
											}
											autoComplete={
												field.key === 'phone'
													? 'tel'
													: undefined
											}
											placeholder={
												field.key === 'phone'
													? __(
															'Enter without the leading zero',
															'paper-to-quiz'
													  )
													: undefined
											}
											pattern={
												field.key === 'phone'
													? '5\\d{2} \\d{3} \\d{2} \\d{2}'
													: undefined
											}
											required={ field.required }
											disabled={ fromProfile }
											value={
												participant[ field.key ] || ''
											}
											onChange={ ( event ) =>
												setParticipant( {
													...participant,
													[ field.key ]:
														field.key === 'phone'
															? formatTurkishPhoneInput(
																	event.target
																		.value
															  )
															: event.target
																	.value,
												} )
											}
										/>
										{ fromProfile && (
											<small>
												{ __(
													'Using information from your account.',
													'paper-to-quiz'
												) }
											</small>
										) }
									</label>
								);
							} ) }
						</div>
					) }
				<button
					type="button"
					className="ptq-primary"
					disabled={ starting || scheduleLocked }
					onClick={ () => void start() }
				>
					{ startButtonLabel }
				</button>
			</section>
		);
	}
	if ( ! attempt ) {
		return (
			<div className="ptq-error">
				{ error ||
					sprintf(
						/* translators: %s: Assessment type. */
						__( 'The %s could not be opened.', 'paper-to-quiz' ),
						content.name
					) }
			</div>
		);
	}
	if ( attempt.status === 'in_progress' && ! focusMode ) {
		return (
			<section className="ptq-resume-card">
				<span className="ptq-resume-card__eyebrow">
					{ sprintf(
						/* translators: %s: Assessment type. */
						__( '%s in progress', 'paper-to-quiz' ),
						content.name
					) }
				</span>
				<h2>{ attempt.title }</h2>
				<p>
					{ __(
						'You have exited full screen. Your answers are preserved. Use the button below to return to full screen mode.',
						'paper-to-quiz'
					) }
				</p>
				{ remaining !== null && (
					<div className="ptq-resume-card__time">
						<span>{ __( 'Time remaining', 'paper-to-quiz' ) }</span>
						<strong>{ formatTime( remaining ) }</strong>
					</div>
				) }
				<button
					type="button"
					className="ptq-primary"
					onClick={ () => {
						setFocusMode( true );
						void requestNativeFullscreen();
					} }
				>
					{ bootstrap?.type === 'test'
						? __( 'Return to test', 'paper-to-quiz' )
						: __( 'Return to exam', 'paper-to-quiz' ) }
				</button>
			</section>
		);
	}

	const question = attempt.questions[ active ];
	const answered = Object.values( answers ).filter(
		( answer ) => answer.option
	).length;
	const blank = attempt.questions.length - answered;

	return (
		<section
			className={ `ptq-exam ${ focusMode ? 'is-focus-mode' : '' }` }
			aria-busy={ submitting }
		>
			{ submitting && (
				<div className="ptq-submit-overlay">
					<div className="ptq-submit-overlay__card" role="status">
						<span className="ptq-spinner" />
						<strong>
							{ autoSubmitting.current
								? __(
										'Time is up. Your answers are being submitted…',
										'paper-to-quiz'
								  )
								: sprintf(
										/* translators: %s: Assessment type. */
										__( 'Finishing %s…', 'paper-to-quiz' ),
										content.name
								  ) }
						</strong>
						<span>{ __( 'Please wait.', 'paper-to-quiz' ) }</span>
					</div>
				</div>
			) }
			<header className="ptq-exam__header">
				<div>
					{ attempt.class_name && (
						<span>{ attempt.class_name }</span>
					) }
					<h2>{ attempt.title }</h2>
				</div>
				<div className="ptq-exam__tools">
					<div
						className={ `ptq-timer ${
							remaining !== null && remaining <= 60
								? 'is-critical'
								: ''
						}` }
						aria-live="polite"
					>
						<span>{ __( 'Time remaining', 'paper-to-quiz' ) }</span>
						<strong>
							{ remaining === null
								? __( 'Unlimited', 'paper-to-quiz' )
								: formatTime( remaining ) }
						</strong>
					</div>
					<button
						type="button"
						className="ptq-exit-focus"
						onClick={ () => {
							setFocusMode( false );
							if ( document.fullscreenElement ) {
								void document
									.exitFullscreen()
									.catch( () => undefined );
							}
						} }
					>
						{ __( 'Exit full screen', 'paper-to-quiz' ) }
					</button>
				</div>
			</header>
			{ offline && (
				<div className="ptq-offline" role="status">
					{ __(
						'There is no internet connection. Your answers are safe in this browser and will be submitted when the connection returns.',
						'paper-to-quiz'
					) }
				</div>
			) }
			{ error && (
				<div className="ptq-error" role="alert">
					<span>{ error }</span>
					<button onClick={ () => setError( '' ) }>
						{ __( 'Close', 'paper-to-quiz' ) }
					</button>
				</div>
			) }
			<nav
				className="ptq-question-nav"
				aria-label={ __( 'Questions', 'paper-to-quiz' ) }
			>
				{ attempt.questions.map( ( item, index ) => {
					const state = answers[ item.id ];
					return (
						<button
							type="button"
							key={ item.id }
							className={ [
								index === active ? 'is-active' : '',
								state?.option ? 'is-answered' : 'is-empty',
								state?.flagged ? 'is-flagged' : '',
							].join( ' ' ) }
							aria-label={ sprintf(
								/* translators: 1: Question number. 2: Answer state. 3: Flag state. */
								__( 'Question %1$d%2$s%3$s', 'paper-to-quiz' ),
								index + 1,
								state?.option
									? __( ', answered', 'paper-to-quiz' )
									: __( ', blank', 'paper-to-quiz' ),
								state?.flagged
									? __( ', flagged', 'paper-to-quiz' )
									: ''
							) }
							aria-current={
								index === active ? 'step' : undefined
							}
							onClick={ () => setActive( index ) }
						>
							{ index + 1 }
						</button>
					);
				} ) }
			</nav>
			<div className="ptq-exam__layout">
				<main className="ptq-question">
					<div className="ptq-question__heading">
						<h3>
							{ sprintf(
								/* translators: %d: Question number. */
								__( 'Question %d', 'paper-to-quiz' ),
								active + 1
							) }
						</h3>
						<button
							type="button"
							className="ptq-bookmark"
							aria-pressed={ Boolean(
								answers[ question.id ]?.flagged
							) }
							onClick={ () =>
								void choose(
									question.id,
									answers[ question.id ]?.option || null,
									! answers[ question.id ]?.flagged
								)
							}
						>
							<BookmarkIcon />
							{ answers[ question.id ]?.flagged
								? __( 'Review later', 'paper-to-quiz' )
								: __( 'Review later', 'paper-to-quiz' ) }
						</button>
					</div>
					<div className="ptq-question-image">
						<QuestionImage
							question={ question }
							token={ token }
							nonce={ nonce }
						/>
					</div>
					<div
						className="ptq-answer-buttons"
						aria-label={ sprintf(
							/* translators: %d: Question number. */
							__( 'Answer for question %d', 'paper-to-quiz' ),
							active + 1
						) }
					>
						{ attempt.options.map( ( option ) => (
							<button
								type="button"
								key={ option }
								className={ immediateOptionClass(
									question,
									option,
									answers[ question.id ]?.option || null,
									attempt.feedback_timing
								) }
								aria-pressed={
									answers[ question.id ]?.option === option
								}
								onClick={ () =>
									void choose( question.id, option )
								}
							>
								{ option }
							</button>
						) ) }
						<button
							type="button"
							className="ptq-clear-answer"
							disabled={ ! answers[ question.id ]?.option }
							onClick={ () => void choose( question.id, null ) }
						>
							{ __( 'Clear', 'paper-to-quiz' ) }
						</button>
					</div>
					{ attempt.feedback_timing === 'immediate' &&
						answers[ question.id ]?.option && (
							<p
								className={ `ptq-immediate-feedback ${
									answers[ question.id ]?.option ===
									question.correctOption
										? 'is-correct'
										: 'is-wrong'
								}` }
								role="status"
							>
								{ answers[ question.id ]?.option ===
								question.correctOption
									? __( 'Correct answer.', 'paper-to-quiz' )
									: sprintf(
											/* translators: %s: Correct answer option. */
											__(
												'Wrong answer. Correct answer: %s',
												'paper-to-quiz'
											),
											question.correctOption || ''
									  ) }
							</p>
						) }

					<div className="ptq-question__footer">
						<button
							type="button"
							disabled={ active === 0 }
							onClick={ () => setActive( active - 1 ) }
						>
							← { __( 'Previous', 'paper-to-quiz' ) }
						</button>
						<button
							type="button"
							disabled={ active === attempt.questions.length - 1 }
							onClick={ () => setActive( active + 1 ) }
						>
							{ __( 'Next', 'paper-to-quiz' ) } →
						</button>
					</div>
				</main>
				<aside
					className={ `ptq-optical ${ drawerOpen ? 'is-open' : '' }` }
				>
					<div className="ptq-optical__header">
						<h3>{ __( 'Answer form', 'paper-to-quiz' ) }</h3>
						<button
							type="button"
							onClick={ () => setDrawerOpen( false ) }
						>
							{ __( 'Close', 'paper-to-quiz' ) }
						</button>
					</div>
					<div className="ptq-optical__scroll">
						{ attempt.questions.map( ( item, index ) => (
							<div
								key={ item.id }
								className={
									index === active ? 'is-active' : ''
								}
							>
								<button
									type="button"
									className="ptq-optical__number"
									onClick={ () => {
										setActive( index );
										setDrawerOpen( false );
									} }
								>
									{ index + 1 }
								</button>
								{ attempt.options.map( ( option ) => (
									<button
										type="button"
										key={ option }
										className={
											answers[ item.id ]?.option ===
											option
												? 'is-selected'
												: ''
										}
										aria-pressed={
											answers[ item.id ]?.option ===
											option
										}
										onClick={ () =>
											void choose( item.id, option )
										}
									>
										{ option }
									</button>
								) ) }
								{ answers[ item.id ]?.flagged && (
									<span
										title={ __(
											'Marked for review',
											'paper-to-quiz'
										) }
									>
										<BookmarkIcon />
									</span>
								) }
							</div>
						) ) }
					</div>
					<button
						type="button"
						className="ptq-finish"
						disabled={ submitting }
						onClick={ () => setFinishOpen( true ) }
					>
						{ content.finish }
					</button>
				</aside>
			</div>
			<div className="ptq-mobile-bar">
				<button type="button" onClick={ () => setDrawerOpen( true ) }>
					{ sprintf(
						/* translators: 1: Answered question count. 2: Total question count. */
						__( 'Answer form (%1$d/%2$d)', 'paper-to-quiz' ),
						answered,
						attempt.questions.length
					) }
				</button>
				<button
					type="button"
					disabled={ submitting }
					onClick={ () => setFinishOpen( true ) }
				>
					{ __( 'Finish', 'paper-to-quiz' ) }
				</button>
			</div>
			{ finishOpen && (
				<Modal
					title={ content.finish }
					onClose={ () => {
						if ( ! submitLock.current ) {
							setFinishOpen( false );
						}
					} }
				>
					<div className="ptq-finish-summary">
						<div>
							<strong>{ attempt.questions.length }</strong>
							<span>{ __( 'Total', 'paper-to-quiz' ) }</span>
						</div>
						<div>
							<strong>{ answered }</strong>
							<span>{ __( 'Answered', 'paper-to-quiz' ) }</span>
						</div>
						<div className={ blank ? 'has-blank' : '' }>
							<strong>{ blank }</strong>
							<span>{ __( 'Blank', 'paper-to-quiz' ) }</span>
						</div>
					</div>
					{ blank > 0 && (
						<p className="ptq-warning">
							{ sprintf(
								/* translators: %d: Number of unanswered questions. */
								_n(
									'You have %d unanswered question.',
									'You have %d unanswered questions.',
									blank,
									'paper-to-quiz'
								),
								blank
							) }
						</p>
					) }
					<p>
						{ __(
							'You cannot change your answers after finishing.',
							'paper-to-quiz'
						) }
					</p>
					<div className="ptq-modal-actions">
						<button
							disabled={ submitting }
							onClick={ () => setFinishOpen( false ) }
						>
							{ __( 'Go back', 'paper-to-quiz' ) }
						</button>
						<button
							className="ptq-primary"
							disabled={ submitting }
							onClick={ () => void submit( false ) }
						>
							{ submitting ? (
								<BusyLabel>
									{ __( 'Finishing…', 'paper-to-quiz' ) }
								</BusyLabel>
							) : (
								__( 'Confirm and finish', 'paper-to-quiz' )
							) }
						</button>
					</div>
				</Modal>
			) }
			{ attempt.questions[ active + 1 ] && (
				<div hidden>
					<QuestionImage
						question={ attempt.questions[ active + 1 ] }
						token={ token }
						nonce={ nonce }
					/>
				</div>
			) }
		</section>
	);
}

function QuestionImage( {
	question,
	token,
	nonce,
}: {
	question: AttemptQuestion;
	token: string;
	nonce: string;
} ) {
	const [ url, setUrl ] = useState( '' );
	const [ error, setError ] = useState( false );
	useEffect( () => {
		let objectUrl = '';
		setUrl( '' );
		setError( false );
		fetch( question.imageUrl, {
			credentials: 'same-origin',
			headers: {
				...( token ? { Authorization: `Bearer ${ token }` } : {} ),
				...( nonce ? { 'X-WP-Nonce': nonce } : {} ),
			},
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
			.catch( () => setError( true ) );
		return () => {
			if ( objectUrl ) {
				URL.revokeObjectURL( objectUrl );
			}
		};
	}, [ question.imageUrl, token, nonce ] );
	if ( error ) {
		return (
			<span className="ptq-image-error">
				{ __(
					'The image could not be loaded. Try again.',
					'paper-to-quiz'
				) }
			</span>
		);
	}
	if ( ! url ) {
		return (
			<span className="ptq-image-loading">
				<span className="ptq-spinner" />
				{ __( 'Loading image…', 'paper-to-quiz' ) }
			</span>
		);
	}
	return (
		<img
			src={ url }
			alt={ sprintf(
				/* translators: %d: Question number. */
				__( 'Question %d image', 'paper-to-quiz' ),
				question.ordinal
			) }
		/>
	);
}

function ResultView( {
	result,
	attempt,
	token,
	nonce,
	type,
	onRetry,
}: {
	result: Result;
	attempt: Attempt | null;
	token: string;
	nonce: string;
	type: 'exam' | 'test';
	onRetry: () => void | Promise< void >;
} ) {
	const content = contentCopy( type );
	const documentData = result.document;
	const assessmentTitle =
		documentData?.assessment_title || attempt?.title || content.name;
	if ( result.visibility === 'hidden' ) {
		return (
			<section className="ptq-result">
				<span className="ptq-result__icon">✓</span>
				<h2>{ __( 'Your answers were saved', 'paper-to-quiz' ) }</h2>
				<p>
					{ __(
						'Results have not been released yet.',
						'paper-to-quiz'
					) }{ ' ' }
					{ result.release_at
						? sprintf(
								/* translators: %s: Result release date and time. */
								__(
									'You can view them on %s.',
									'paper-to-quiz'
								),
								new Date( result.release_at ).toLocaleString(
									'tr-TR'
								)
						  )
						: '' }
				</p>
				{ result.can_retry && (
					<button
						type="button"
						className="ptq-primary ptq-retry"
						onClick={ () => void onRetry() }
					>
						{ __( 'Try again', 'paper-to-quiz' ) }
					</button>
				) }
			</section>
		);
	}
	return (
		<section className="ptq-result">
			<div className="ptq-result-document">
				<header className="ptq-result-document__header">
					<span className="ptq-result__icon">✓</span>
					<div>
						<h2>
							{ documentData?.assessment_type === 'test'
								? __( 'Test result document', 'paper-to-quiz' )
								: __(
										'Exam result document',
										'paper-to-quiz'
								  ) }
						</h2>
						<p>{ assessmentTitle }</p>
					</div>
				</header>
				{ documentData && (
					<div className="ptq-result-document__section">
						<h3>
							{ __(
								'Participant and exam information',
								'paper-to-quiz'
							) }
						</h3>
						<table className="ptq-result-table ptq-result-table--info">
							<tbody>
								<tr>
									<th scope="row">
										{ __( 'Participant', 'paper-to-quiz' ) }
									</th>
									<td>{ documentData.participant_name }</td>
									<th scope="row">
										{ __( 'Class', 'paper-to-quiz' ) }
									</th>
									<td>
										{ documentData.class_section ||
											documentData.class_name ||
											'—' }
									</td>
								</tr>
								<tr>
									<th scope="row">
										{ __( 'School', 'paper-to-quiz' ) }
									</th>
									<td>{ documentData.school || '—' }</td>
									<th scope="row">
										{ __(
											'Submission date',
											'paper-to-quiz'
										) }
									</th>
									<td>
										{ documentData.submitted_at || '—' }
									</td>
								</tr>
								{ documentData.duration_seconds !== null &&
									documentData.duration_seconds !==
										undefined && (
										<tr>
											<th scope="row">
												{ __(
													'Duration',
													'paper-to-quiz'
												) }
											</th>
											<td colSpan={ 3 }>
												{ formatResultDuration(
													documentData.duration_seconds
												) }
											</td>
										</tr>
									) }
							</tbody>
						</table>
					</div>
				) }
				<div className="ptq-result-document__section">
					<h3>{ __( 'Result summary', 'paper-to-quiz' ) }</h3>
					<table className="ptq-result-table ptq-result-table--metrics">
						<thead>
							<tr>
								<th>{ __( 'Score', 'paper-to-quiz' ) }</th>
								{ result.visibility !== 'score_only' && (
									<>
										<th>
											{ __( 'Success', 'paper-to-quiz' ) }
										</th>
										<th>
											{ __( 'Correct', 'paper-to-quiz' ) }
										</th>
										<th>
											{ __( 'Wrong', 'paper-to-quiz' ) }
										</th>
										<th>
											{ __( 'Blank', 'paper-to-quiz' ) }
										</th>
									</>
								) }
							</tr>
						</thead>
						<tbody>
							<tr>
								<td
									data-label={ __(
										'Score',
										'paper-to-quiz'
									) }
								>
									<strong>
										{ formatScore(
											result.score || 0,
											result.score_precision || 0
										) }
									</strong>
								</td>
								{ result.visibility !== 'score_only' && (
									<>
										<td
											data-label={ __(
												'Success',
												'paper-to-quiz'
											) }
										>
											%{ result.percentage }
										</td>
										<td
											data-label={ __(
												'Correct',
												'paper-to-quiz'
											) }
										>
											{ result.correct }
										</td>
										<td
											data-label={ __(
												'Wrong',
												'paper-to-quiz'
											) }
										>
											{ result.wrong }
										</td>
										<td
											data-label={ __(
												'Blank',
												'paper-to-quiz'
											) }
										>
											{ result.blank }
										</td>
									</>
								) }
							</tr>
						</tbody>
					</table>
				</div>
				{ result.ranking && (
					<div className="ptq-result-document__section">
						<h3>{ __( 'Overall ranking', 'paper-to-quiz' ) }</h3>
						<table className="ptq-result-table ptq-result-table--metrics">
							<thead>
								<tr>
									<th>{ __( 'Rank', 'paper-to-quiz' ) }</th>
									<th>
										{ __(
											'Participants',
											'paper-to-quiz'
										) }
									</th>
									<th>
										{ __( 'Percentile', 'paper-to-quiz' ) }
									</th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td
										data-label={ __(
											'Rank',
											'paper-to-quiz'
										) }
									>
										<strong>{ result.ranking.rank }</strong>
									</td>
									<td
										data-label={ __(
											'Participants',
											'paper-to-quiz'
										) }
									>
										{ result.ranking.total }
									</td>
									<td
										data-label={ __(
											'Percentile',
											'paper-to-quiz'
										) }
									>
										%{ result.ranking.percentile }
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				) }
				{ result.subjects && result.subjects.length > 0 && (
					<div className="ptq-result-document__section">
						<h3>{ __( 'Subject results', 'paper-to-quiz' ) }</h3>
						<table className="ptq-result-table ptq-result-table--subjects">
							<thead>
								<tr>
									<th>
										{ __( 'Subject', 'paper-to-quiz' ) }
									</th>
									<th>
										{ __( 'Correct', 'paper-to-quiz' ) }
									</th>
									<th>{ __( 'Wrong', 'paper-to-quiz' ) }</th>
									<th>{ __( 'Blank', 'paper-to-quiz' ) }</th>
									<th>{ __( 'Score', 'paper-to-quiz' ) }</th>
									<th>
										{ __( 'Success', 'paper-to-quiz' ) }
									</th>
									{ result.subjects.some(
										( subject ) => subject.ranking
									) && (
										<th>
											{ __( 'Rank', 'paper-to-quiz' ) }
										</th>
									) }
								</tr>
							</thead>
							<tbody>
								{ result.subjects.map( ( subject ) => (
									<tr key={ subject.subject_id }>
										<td
											data-label={ __(
												'Subject',
												'paper-to-quiz'
											) }
										>
											<strong>{ subject.name }</strong>
										</td>
										<td
											data-label={ __(
												'Correct',
												'paper-to-quiz'
											) }
										>
											{ subject.correct }
										</td>
										<td
											data-label={ __(
												'Wrong',
												'paper-to-quiz'
											) }
										>
											{ subject.wrong }
										</td>
										<td
											data-label={ __(
												'Blank',
												'paper-to-quiz'
											) }
										>
											{ subject.blank }
										</td>
										<td
											data-label={ __(
												'Score',
												'paper-to-quiz'
											) }
										>
											{ formatScore(
												subject.score,
												result.score_precision || 0
											) }{ ' ' }
											/{ ' ' }
											{ formatScore(
												subject.max_score,
												result.score_precision || 0
											) }
										</td>
										<td
											data-label={ __(
												'Success',
												'paper-to-quiz'
											) }
										>
											%{ subject.percentage }
										</td>
										{ result.subjects?.some(
											( item ) => item.ranking
										) && (
											<td
												data-label={ __(
													'Rank',
													'paper-to-quiz'
												) }
											>
												{ subject.ranking
													? `${ subject.ranking.rank } / ${ subject.ranking.total }`
													: '—' }
											</td>
										) }
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) }
			</div>
			{ result.can_retry && (
				<button
					type="button"
					className="ptq-primary ptq-retry"
					onClick={ () => void onRetry() }
				>
					{ __( 'Try again', 'paper-to-quiz' ) }
				</button>
			) }

			{ result.visibility === 'detailed' && (
				<div className="ptq-review">
					<h3>{ __( 'Review your answers', 'paper-to-quiz' ) }</h3>
					{ result.answers?.map( ( answer ) => {
						const question = attempt?.questions.find(
							( item ) =>
								Number( item.id ) ===
								Number( answer.question_id )
						);
						return (
							<div
								key={ answer.question_id }
								className={
									result.answer_key_visible
										? reviewClass( answer )
										: 'is-neutral'
								}
							>
								{ question && (
									<div className="ptq-review__image">
										<QuestionImage
											question={ question }
											token={ token }
											nonce={ nonce }
										/>
									</div>
								) }
								<div className="ptq-review__answer">
									<strong>
										{ sprintf(
											/* translators: %s: Question number. */
											__(
												'Question %s',
												'paper-to-quiz'
											),
											answer.ordinal
										) }
									</strong>
									<span>
										{ sprintf(
											/* translators: %s: Selected answer option. */
											__(
												'Your answer: %s',
												'paper-to-quiz'
											),
											answer.selected_option ||
												__( 'Blank', 'paper-to-quiz' )
										) }
									</span>
									{ result.answer_key_visible && (
										<span>
											{ sprintf(
												/* translators: %s: Correct answer option. */
												__(
													'Correct: %s',
													'paper-to-quiz'
												),
												answer.correct_option || ''
											) }
										</span>
									) }
								</div>
							</div>
						);
					} ) }
				</div>
			) }
		</section>
	);
}

function reviewClass( answer: {
	is_correct?: string;
	selected_option?: string;
} ): string {
	if ( answer.is_correct === '1' ) {
		return 'is-correct';
	}
	return answer.selected_option ? 'is-wrong' : 'is-blank';
}

function Modal( {
	title,
	onClose,
	wide = false,
	children,
}: {
	title: string;
	onClose: () => void;
	wide?: boolean;
	children: React.ReactNode;
} ) {
	const modalRef = useRef< HTMLDivElement | null >( null );
	useEffect( () => {
		const ownerDocument = modalRef.current?.ownerDocument || document;
		const previouslyFocused =
			ownerDocument.activeElement as HTMLElement | null;
		const focusable = () =>
			Array.from(
				modalRef.current?.querySelectorAll< HTMLElement >(
					'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
				) || []
			);
		window.requestAnimationFrame( () => focusable()[ 0 ]?.focus() );
		const listener = ( event: KeyboardEvent ) => {
			if ( event.key === 'Escape' ) {
				onClose();
				return;
			}
			if ( event.key === 'Tab' ) {
				const items = focusable();
				if ( ! items.length ) {
					event.preventDefault();
					return;
				}
				const first = items[ 0 ];
				const last = items[ items.length - 1 ];
				if ( event.shiftKey && ownerDocument.activeElement === first ) {
					event.preventDefault();
					last.focus();
				} else if (
					! event.shiftKey &&
					ownerDocument.activeElement === last
				) {
					event.preventDefault();
					first.focus();
				}
			}
		};
		window.addEventListener( 'keydown', listener );
		return () => {
			window.removeEventListener( 'keydown', listener );
			previouslyFocused?.focus();
		};
	}, [ onClose ] );
	return (
		<div
			className="ptq-modal-backdrop"
			role="presentation"
			onMouseDown={ ( event ) => {
				if ( event.target === event.currentTarget ) {
					onClose();
				}
			} }
		>
			<div
				ref={ modalRef }
				className={ `ptq-modal ${ wide ? 'ptq-modal--wide' : '' }` }
				role="dialog"
				aria-modal="true"
				aria-label={ title }
			>
				<header>
					<h2>{ title }</h2>
					<button
						aria-label={ __( 'Close', 'paper-to-quiz' ) }
						onClick={ onClose }
					>
						×
					</button>
				</header>
				{ children }
			</div>
		</div>
	);
}

function answerMap( items: SavedAnswer[] ): AnswerState {
	return Object.fromEntries(
		items.map( ( answer ) => [
			Number( answer.question_id ),
			{
				option: answer.selected_option || null,
				flagged: answer.is_flagged === '1',
			},
		] )
	);
}

function startDurationLabel( bootstrap: Bootstrap ): string {
	if ( bootstrap.duration_seconds ) {
		const totalMinutes = Math.ceil( bootstrap.duration_seconds / 60 );
		const hours = Math.floor( totalMinutes / 60 );
		const minutes = totalMinutes % 60;
		if ( hours ) {
			const hourLabel = sprintf(
				/* translators: %d: Number of hours. */
				_n( '%d hour', '%d hours', hours, 'paper-to-quiz' ),
				hours
			);
			const minuteLabel = minutes
				? sprintf(
						/* translators: %d: Number of minutes. */
						_n(
							'%d minute',
							'%d minutes',
							minutes,
							'paper-to-quiz'
						),
						minutes
				  )
				: '';
			return [ hourLabel, minuteLabel ].filter( Boolean ).join( ' ' );
		}
		return sprintf(
			/* translators: %d: Number of minutes. */
			_n( '%d minute', '%d minutes', totalMinutes, 'paper-to-quiz' ),
			totalMinutes
		);
	}
	if ( bootstrap.type === 'exam' ) {
		return __( 'Until the end date', 'paper-to-quiz' );
	}
	return __( 'No time limit', 'paper-to-quiz' );
}

// formatCountdown moved to ../shared/format (plan 017).

// formatResultDuration moved to ../shared/format (plan 017).

function immediateOptionClass(
	question: AttemptQuestion,
	option: string,
	selected: string | null,
	timing: Attempt[ 'feedback_timing' ]
): string {
	const classes = selected === option ? [ 'is-selected' ] : [];
	if ( timing === 'immediate' && selected ) {
		if ( option === question.correctOption ) {
			classes.push( 'is-correct' );
		} else if ( option === selected ) {
			classes.push( 'is-wrong' );
		}
	}
	return classes.join( ' ' );
}

function scheduleStatusLabel( state: Schedule[ 'state' ] ): string {
	if ( state === 'scheduled' ) {
		return __( 'The exam has not started yet.', 'paper-to-quiz' );
	}
	if ( state === 'ended' ) {
		return __( 'The exam has ended.', 'paper-to-quiz' );
	}
	return __( 'The exam is open for participation.', 'paper-to-quiz' );
}

async function requestNativeFullscreen(): Promise< void > {
	if (
		document.fullscreenElement ||
		! document.documentElement.requestFullscreen
	) {
		return;
	}
	try {
		await document.documentElement.requestFullscreen();
	} catch {
		// CSS focus mode remains active when the browser blocks the native API.
	}
}

function BookmarkIcon() {
	return (
		<svg
			viewBox="0 0 24 24"
			width="18"
			height="18"
			aria-hidden="true"
			focusable="false"
		>
			<path
				d="M6.75 3.75h10.5v16.5L12 16.8l-5.25 3.45V3.75Z"
				fill="none"
				stroke="currentColor"
				strokeWidth="1.8"
				strokeLinejoin="round"
			/>
		</svg>
	);
}

async function persistDraft( draft: AttemptDraft ): Promise< void > {
	const {
		updatedAt: _updatedAt,
		expiresAt: _expiresAt,
		...persisted
	} = draft;
	await writeDraft( persisted );
}

function formatTime( seconds: number ) {
	const hours = Math.floor( seconds / 3600 );
	const minutes = Math.floor( ( seconds % 3600 ) / 60 );
	const rest = seconds % 60;
	return `${ hours ? `${ hours }:` : '' }${ String( minutes ).padStart(
		2,
		'0'
	) }:${ String( rest ).padStart( 2, '0' ) }`;
}
