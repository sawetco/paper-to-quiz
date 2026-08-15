import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { Button, Notice, Spinner } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	DndContext,
	type DragEndEvent,
	PointerSensor,
	useSensor,
	useSensors,
} from '@dnd-kit/core';
import {
	arrayMove,
	SortableContext,
	useSortable,
	verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Layer, Rect, Stage, Transformer } from 'react-konva';
import type Konva from 'konva';
import {
	GlobalWorkerOptions,
	getDocument,
	type PDFDocumentProxy,
	type PDFPageProxy,
} from 'pdfjs-dist';
import type {
	AssessmentRecord,
	ListResponse,
	Question,
	Selection,
	Term,
} from '../types';
import { api, fetchBinary } from './api';
import { BusyLabel } from './BusyLabel';

GlobalWorkerOptions.workerSrc = new URL(
	'pdfjs-dist/build/pdf.worker.min.mjs',
	import.meta.url
).toString();

export function PdfEditor( {
	record,
	regenerateAll = false,
	onSaved,
	onError,
}: {
	record: AssessmentRecord;
	regenerateAll?: boolean;
	onSaved: () => void;
	onError: ( message: string ) => void;
} ) {
	const [ pdf, setPdf ] = useState< PDFDocumentProxy | null >( null );
	const [ pageNumber, setPageNumber ] = useState( 1 );
	const [ zoom, setZoom ] = useState( 1.15 );
	const [ canvasSize, setCanvasSize ] = useState( { width: 0, height: 0 } );
	const [ page, setPage ] = useState< PDFPageProxy | null >( null );
	const [ mainReady, setMainReady ] = useState( false );
	const [ thumbs, setThumbs ] = useState< Record< number, string > >( {} );
	const [ selectionTool, setSelectionTool ] = useState( true );
	const [ selectedKey, setSelectedKey ] = useState< string | null >( null );
	const [ drawing, setDrawing ] = useState< { x: number; y: number } | null >(
		null
	);
	const [ temp, setTemp ] = useState< {
		x: number;
		y: number;
		width: number;
		height: number;
	} | null >( null );
	const [ saving, setSaving ] = useState( false );
	const [ saveProgress, setSaveProgress ] = useState( '' );
	const [ deletingKey, setDeletingKey ] = useState< string | null >( null );
	const [ warning, setWarning ] = useState( '' );
	const [ recoveryReady, setRecoveryReady ] = useState( false );
	const [ subjects, setSubjects ] = useState< Term[] >( [] );
	const canvasRef = useRef< HTMLCanvasElement | null >( null );
	const renderTask = useRef< any >( null );
	const thumbsRef = useRef< Record< number, string > >( {} );
	const saveLock = useRef( false );
	const deleteLock = useRef( false );
	const sensors = useSensors(
		useSensor( PointerSensor, { activationConstraint: { distance: 5 } } )
	);

	const [ selections, setSelections ] = useState< Selection[] >( () =>
		record.questions.map( ( question, index ) => ( {
			...questionToSelection( question, index ),
			dirty: regenerateAll,
		} ) )
	);

	useEffect( () => {
		api< ListResponse< Term > >(
			'/admin/subjects?status=active&page=1&per_page=100'
		)
			.then( ( response ) => {
				const allowedSubjectIds = new Set( [
					...( record.revision.subject_ids || [] ).map( Number ),
					...record.questions
						.map( ( question ) =>
							Number( question.subject_id || 0 )
						)
						.filter( Boolean ),
				] );
				setSubjects(
					allowedSubjectIds.size
						? response.items.filter( ( subject ) =>
								allowedSubjectIds.has( Number( subject.id ) )
						  )
						: response.items
				);
			} )
			.catch( () =>
				onError(
					__(
						'Subjects could not be loaded. Try again before saving questions.',
						'paper-to-quiz'
					)
				)
			);
	}, [ onError, record.questions, record.revision.subject_ids ] );

	useEffect( () => {
		let cancelled = false;
		loadRecovery( Number( record.revision.id ) )
			.then( ( recovered ) => {
				if ( cancelled ) {
					return;
				}
				if ( recovered?.length ) {
					setSelections(
						reconcileRecovery( recovered, record.questions ).map(
							( selection ) => ( {
								...selection,
								dirty: regenerateAll || selection.dirty,
							} )
						)
					);
					setWarning(
						__(
							'The unsaved selection draft was restored.',
							'paper-to-quiz'
						)
					);
				}
				setRecoveryReady( true );
			} )
			.catch( () => setRecoveryReady( true ) );
		return () => {
			cancelled = true;
		};
	}, [ record.questions, record.revision.id, regenerateAll ] );

	useEffect( () => {
		if (
			! recoveryReady ||
			! selections.some( ( selection ) => selection.dirty )
		) {
			return;
		}
		const timer = window.setTimeout( () => {
			saveRecovery( Number( record.revision.id ), selections ).catch(
				() => undefined
			);
		}, 250 );
		return () => window.clearTimeout( timer );
	}, [ record.revision.id, recoveryReady, selections ] );

	useEffect( () => {
		const warnBeforeUnload = ( event: BeforeUnloadEvent ) => {
			if ( ! selections.some( ( selection ) => selection.dirty ) ) {
				return;
			}
			event.preventDefault();
			event.returnValue = '';
		};
		window.addEventListener( 'beforeunload', warnBeforeUnload );
		return () =>
			window.removeEventListener( 'beforeunload', warnBeforeUnload );
	}, [ selections ] );

	useEffect( () => {
		let cancelled = false;
		fetchBinary( record.revision.pdf_url! )
			.then( ( data ) => getDocument( { data } ).promise )
			.then( ( document ) => {
				if ( ! cancelled ) {
					setPdf( document );
					if ( document.numPages > 200 ) {
						setWarning(
							__(
								'This PDF has more than 200 pages. Performance may decrease while thumbnails are prepared.',
								'paper-to-quiz'
							)
						);
					}
				}
			} )
			.catch( ( caught ) => onError( pdfError( caught ) ) );
		return () => {
			cancelled = true;
		};
	}, [ record.revision.pdf_url, onError ] );

	useEffect( () => {
		if ( ! pdf ) {
			return;
		}
		let cancelled = false;
		pdf.getPage( pageNumber )
			.then( ( loaded ) => {
				if ( cancelled ) {
					return;
				}
				setPage( loaded );
			} )
			.catch( ( caught ) => {
				if ( caught?.name !== 'RenderingCancelledException' ) {
					onError( pdfError( caught ) );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ pdf, pageNumber, onError ] );

	useEffect( () => {
		if ( ! page ) {
			return;
		}
		const viewport = page.getViewport( { scale: zoom } );
		setCanvasSize( { width: viewport.width, height: viewport.height } );
		const canvas = canvasRef.current;
		if ( ! canvas ) {
			return;
		}
		const context = canvas.getContext( '2d' );
		if ( ! context ) {
			return;
		}
		setMainReady( false );
		const ratio = Math.min( window.devicePixelRatio || 1, 2 );
		canvas.width = Math.floor( viewport.width * ratio );
		canvas.height = Math.floor( viewport.height * ratio );
		canvas.style.width = `${ viewport.width }px`;
		canvas.style.height = `${ viewport.height }px`;
		renderTask.current?.cancel();
		const task = page.render( {
			canvas,
			canvasContext: context,
			viewport,
			transform: ratio !== 1 ? [ ratio, 0, 0, ratio, 0, 0 ] : undefined,
		} );
		renderTask.current = task;
		let cancelled = false;
		task.promise
			.then( () => {
				if ( ! cancelled ) {
					setMainReady( true );
				}
			} )
			.catch( ( caught: any ) => {
				if ( caught?.name !== 'RenderingCancelledException' ) {
					onError( pdfError( caught ) );
				}
			} );
		return () => {
			cancelled = true;
			task.cancel();
		};
	}, [ page, zoom, onError ] );

	useEffect( () => {
		if ( ! pdf || ! mainReady ) {
			return;
		}
		let cancelled = false;
		( async () => {
			for ( let number = 1; number <= pdf.numPages; number += 1 ) {
				if ( cancelled ) {
					return;
				}
				if ( thumbsRef.current[ number ] ) {
					continue;
				}
				const loaded = await pdf.getPage( number );
				const viewport = loaded.getViewport( { scale: 0.18 } );
				const canvas = document.createElement( 'canvas' );
				canvas.width = viewport.width;
				canvas.height = viewport.height;
				const context = canvas.getContext( '2d' );
				if ( ! context ) {
					continue;
				}
				await loaded.render( {
					canvas,
					canvasContext: context,
					viewport,
				} ).promise;
				const image = canvas.toDataURL( 'image/jpeg', 0.65 );
				thumbsRef.current[ number ] = image;
				setThumbs( ( current ) => ( {
					...current,
					[ number ]: image,
				} ) );
				await new Promise< void >( ( resolve ) =>
					window.requestAnimationFrame( () => resolve() )
				);
			}
		} )().catch( () => undefined );
		return () => {
			cancelled = true;
		};
	}, [ pdf, mainReady ] );

	const pageSelections = selections.filter(
		( selection ) => selection.page === pageNumber
	);

	const updateSelection = useCallback(
		( key: string, patch: Partial< Selection > ) => {
			setSelections( ( current ) =>
				current.map( ( selection ) =>
					selection.key === key
						? { ...selection, ...patch, dirty: true }
						: selection
				)
			);
		},
		[]
	);

	function pointerPosition( stage: Konva.Stage ) {
		const position = stage.getPointerPosition();
		return position
			? {
					x: position.x / canvasSize.width,
					y: position.y / canvasSize.height,
			  }
			: null;
	}

	function onPointerDown(
		event: Konva.KonvaEventObject< MouseEvent | TouchEvent >
	) {
		if ( ! selectionTool || event.target !== event.target.getStage() ) {
			return;
		}
		const point = pointerPosition( event.target.getStage()! );
		if ( ! point ) {
			return;
		}
		setSelectedKey( null );
		setDrawing( point );
		setTemp( { ...point, width: 0, height: 0 } );
	}

	function onPointerMove(
		event: Konva.KonvaEventObject< MouseEvent | TouchEvent >
	) {
		if ( ! drawing ) {
			return;
		}
		const point = pointerPosition( event.target.getStage()! );
		if ( ! point ) {
			return;
		}
		setTemp( {
			x: Math.min( drawing.x, point.x ),
			y: Math.min( drawing.y, point.y ),
			width: Math.abs( point.x - drawing.x ),
			height: Math.abs( point.y - drawing.y ),
		} );
	}

	function onPointerUp() {
		if ( ! temp || temp.width < 0.005 || temp.height < 0.005 ) {
			setDrawing( null );
			setTemp( null );
			return;
		}
		const duplicate = selections.some(
			( selection ) =>
				selection.page === pageNumber &&
				intersectionOverUnion( selection, temp ) >= 0.9
		);
		const key = crypto.randomUUID();
		setSelections( ( current ) => [
			...current,
			{
				key,
				page: pageNumber,
				...temp,
				rotation: page?.rotate || 0,
				ordinal: current.length + 1,
				subjectId:
					subjects.length === 1 ? Number( subjects[ 0 ].id ) : 0,
				dirty: true,
			},
		] );
		setSelectedKey( key );
		setDrawing( null );
		setTemp( null );
		if ( duplicate ) {
			setWarning(
				__(
					'This area substantially overlaps an existing selection. Check it before saving.',
					'paper-to-quiz'
				)
			);
		} else if (
			temp.width * canvasSize.width * ( 240 / 72 ) < 480 ||
			temp.height * canvasSize.height * ( 240 / 72 ) < 120
		) {
			setWarning(
				__(
					'This selection is small; check its readability in the student preview.',
					'paper-to-quiz'
				)
			);
		} else {
			setWarning( '' );
		}
	}

	async function remove( selection: Selection ) {
		if ( deleteLock.current || saving ) {
			return;
		}
		deleteLock.current = true;
		setDeletingKey( selection.key );
		onError( '' );
		try {
			if ( selection.id ) {
				await api( `/admin/questions/${ selection.id }`, {
					method: 'DELETE',
				} );
			}
			setSelections( ( current ) =>
				current
					.filter( ( item ) => item.key !== selection.key )
					.map( ( item, index ) => ( {
						...item,
						ordinal: index + 1,
						dirty: true,
					} ) )
			);
		} catch ( caught ) {
			onError(
				caught instanceof Error
					? caught.message
					: __(
							'The question could not be deleted.',
							'paper-to-quiz'
					  )
			);
		} finally {
			deleteLock.current = false;
			setDeletingKey( null );
		}
	}

	function dragEnd( event: DragEndEvent ) {
		if ( event.active.id === event.over?.id ) {
			return;
		}
		setSelections( ( current ) => {
			const oldIndex = current.findIndex(
				( item ) => item.key === event.active.id
			);
			const newIndex = current.findIndex(
				( item ) => item.key === event.over?.id
			);
			return arrayMove( current, oldIndex, newIndex ).map(
				( item, index ) => ( {
					...item,
					ordinal: index + 1,
					dirty: true,
				} )
			);
		} );
	}

	async function save() {
		if ( ! pdf || ! selections.length || saveLock.current ) {
			return;
		}
		saveLock.current = true;
		setSaving( true );
		setSaveProgress( __( 'Preparing questions…', 'paper-to-quiz' ) );
		onError( '' );
		if ( selections.some( ( selection ) => ! selection.subjectId ) ) {
			onError(
				__( 'Select a subject for every question.', 'paper-to-quiz' )
			);
			saveLock.current = false;
			setSaving( false );
			setSaveProgress( '' );
			return;
		}
		let savedCount = 0;
		try {
			const savedByKey = new Map<
				string,
				AssessmentRecord[ 'questions' ][ number ]
			>();
			let workingSelections = [ ...selections ];
			for ( const selection of workingSelections ) {
				if ( ! selection.dirty ) {
					const existing = record.questions.find(
						( question ) => Number( question.id ) === selection.id
					);
					if ( existing ) {
						savedByKey.set( selection.key, existing );
					}
					continue;
				}
				setSaveProgress(
					sprintf(
						/* translators: %d: Question number. */
						__( 'Saving question %d…', 'paper-to-quiz' ),
						selection.ordinal
					)
				);
				const rendered = await renderCrop( pdf, selection );
				const form = new FormData();
				form.append(
					'metadata',
					JSON.stringify( {
						id: selection.id,
						client_key: selection.key,
						ordinal: selection.ordinal,
						page: selection.page,
						rotation: selection.rotation,
						subject_id: selection.subjectId,
						crop: {
							x: selection.x,
							y: selection.y,
							width: selection.width,
							height: selection.height,
						},
					} )
				);
				form.append(
					'main',
					rendered.main,
					`soru-${ selection.ordinal }.png`
				);
				form.append(
					'thumb',
					rendered.thumb,
					`soru-${ selection.ordinal }.webp`
				);
				const saved = await api<
					AssessmentRecord[ 'questions' ][ number ]
				>( `/admin/revisions/${ record.revision.id }/questions`, {
					method: 'POST',
					body: form,
				} );
				savedByKey.set( selection.key, saved );
				savedCount += 1;
				workingSelections = workingSelections.map( ( item ) =>
					item.key === selection.key
						? {
								...item,
								id: Number( saved.id ),
								dirty: false,
								thumbUrl: saved.thumb_url,
						  }
						: item
				);
				setSelections( workingSelections );
				await saveRecovery(
					Number( record.revision.id ),
					workingSelections
				);
			}
			setSaveProgress(
				__( 'Saving order and answer key…', 'paper-to-quiz' )
			);
			await api( `/admin/revisions/${ record.revision.id }/answer-key`, {
				method: 'PUT',
				json: {
					prune_missing: true,
					questions: workingSelections.map( ( selection ) => {
						const saved = savedByKey.get( selection.key );
						if ( ! saved ) {
							throw new Error(
								__(
									'The saved question order could not be created.',
									'paper-to-quiz'
								)
							);
						}
						return {
							id: Number( saved.id ),
							correct_option: saved.correct_option || '',
							points: Number( saved.points || 0 ),
						};
					} ),
				},
			} );
			await clearRecovery( Number( record.revision.id ) );
			onSaved();
		} catch ( caught ) {
			const detail =
				caught instanceof Error
					? caught.message
					: __(
							'The selections could not be saved.',
							'paper-to-quiz'
					  );
			onError(
				savedCount
					? sprintf(
							/* translators: 1: Number of saved questions. 2: Remaining error detail. */
							__(
								'%1$d questions were saved successfully. You can try the remaining questions again. %2$s',
								'paper-to-quiz'
							),
							savedCount,
							detail
					  )
					: detail
			);
		} finally {
			saveLock.current = false;
			setSaving( false );
			setSaveProgress( '' );
		}
	}

	if ( ! pdf || ! page ) {
		return (
			<div className="ptq-pdf-loading">
				<Spinner />
				<p>{ __( 'Preparing PDF…', 'paper-to-quiz' ) }</p>
			</div>
		);
	}

	return (
		<div className="ptq-pdf-editor">
			<div className="ptq-editor-toolbar">
				<Button
					variant={ selectionTool ? 'primary' : 'secondary' }
					onClick={ () => setSelectionTool( ! selectionTool ) }
				>
					{ __( 'Selection tool', 'paper-to-quiz' ) }
				</Button>
				<Button
					disabled={ pageNumber <= 1 }
					onClick={ () => setPageNumber( pageNumber - 1 ) }
				>
					{ __( 'Previous page', 'paper-to-quiz' ) }
				</Button>
				<strong>
					{ pageNumber } / { pdf.numPages }
				</strong>
				<Button
					disabled={ pageNumber >= pdf.numPages }
					onClick={ () => setPageNumber( pageNumber + 1 ) }
				>
					{ __( 'Next page', 'paper-to-quiz' ) }
				</Button>
				<Button
					onClick={ () => setZoom( Math.max( 0.5, zoom - 0.15 ) ) }
				>
					−
				</Button>
				<span>{ Math.round( zoom * 100 ) }%</span>
				<Button onClick={ () => setZoom( Math.min( 3, zoom + 0.15 ) ) }>
					+
				</Button>
				<Button onClick={ () => setZoom( 1 ) }>
					{ __( 'Fit to screen', 'paper-to-quiz' ) }
				</Button>
			</div>
			{ warning && (
				<Notice status="warning" onRemove={ () => setWarning( '' ) }>
					{ warning }
				</Notice>
			) }
			<div className="ptq-editor-layout">
				<aside className="ptq-page-thumbs">
					{ Array.from(
						{ length: pdf.numPages },
						( _, index ) => index + 1
					).map( ( number ) => (
						<button
							key={ number }
							className={
								number === pageNumber ? 'is-active' : ''
							}
							onClick={ () => setPageNumber( number ) }
						>
							{ thumbs[ number ] ? (
								<img
									src={ thumbs[ number ] }
									alt={ sprintf(
										/* translators: %d: Page number. */
										__( 'Page %d', 'paper-to-quiz' ),
										number
									) }
								/>
							) : (
								<span className="ptq-thumb-placeholder" />
							) }
							<strong>{ number }</strong>
						</button>
					) ) }
				</aside>
				<main className="ptq-page-stage">
					<div
						style={ {
							width: canvasSize.width,
							height: canvasSize.height,
						} }
					>
						<canvas ref={ canvasRef } />
						<Stage
							width={ canvasSize.width }
							height={ canvasSize.height }
							onMouseDown={ onPointerDown }
							onMouseMove={ onPointerMove }
							onMouseUp={ onPointerUp }
							onTouchStart={ onPointerDown }
							onTouchMove={ onPointerMove }
							onTouchEnd={ onPointerUp }
						>
							<Layer>
								{ pageSelections.map( ( selection ) => (
									<SelectableRect
										key={ selection.key }
										selection={ selection }
										width={ canvasSize.width }
										height={ canvasSize.height }
										selected={
											selectedKey === selection.key
										}
										onSelect={ () =>
											setSelectedKey( selection.key )
										}
										onChange={ ( patch ) =>
											updateSelection(
												selection.key,
												patch
											)
										}
									/>
								) ) }
								{ temp && (
									<Rect
										x={ temp.x * canvasSize.width }
										y={ temp.y * canvasSize.height }
										width={ temp.width * canvasSize.width }
										height={
											temp.height * canvasSize.height
										}
										fill="rgba(34,113,177,.15)"
										stroke="#2271b1"
										dash={ [ 6, 4 ] }
									/>
								) }
							</Layer>
						</Stage>
					</div>
				</main>
				<aside className="ptq-selection-list">
					<div className="ptq-selection-list__heading">
						<h3>
							{ sprintf(
								/* translators: %d: Number of questions. */
								_n(
									'%d question',
									'%d questions',
									selections.length,
									'paper-to-quiz'
								),
								selections.length
							) }
						</h3>
						<Button
							variant="primary"
							disabled={ saving || ! selections.length }
							onClick={ () => void save() }
						>
							{ saving ? (
								<BusyLabel>
									{ saveProgress ||
										__( 'Preparing…', 'paper-to-quiz' ) }
								</BusyLabel>
							) : (
								__( 'Save selections', 'paper-to-quiz' )
							) }
						</Button>
						<Button
							variant="secondary"
							disabled={ saving || ! selections.length }
							onClick={ () =>
								setSelections( ( current ) =>
									current.map( ( selection ) => ( {
										...selection,
										dirty: true,
									} ) )
								)
							}
						>
							{ __(
								'Regenerate all images at high quality',
								'paper-to-quiz'
							) }
						</Button>
						<p>
							{ __(
								'This regenerates all crops from the source PDF using the current high-quality settings. Use “Save selections” to complete the process.',
								'paper-to-quiz'
							) }
						</p>
					</div>
					<DndContext sensors={ sensors } onDragEnd={ dragEnd }>
						<SortableContext
							items={ selections.map( ( item ) => item.key ) }
							strategy={ verticalListSortingStrategy }
						>
							{ selections.map( ( selection ) => (
								<SortableSelection
									key={ selection.key }
									selection={ selection }
									active={ selection.key === selectedKey }
									onOpen={ () => {
										setPageNumber( selection.page );
										setSelectedKey( selection.key );
									} }
									onDelete={ () => void remove( selection ) }
									deleting={ deletingKey === selection.key }
									disabled={ saving || deletingKey !== null }
									subjects={ subjects }
									onSubjectChange={ ( subjectId ) =>
										updateSelection( selection.key, {
											subjectId,
										} )
									}
								/>
							) ) }
						</SortableContext>
					</DndContext>
				</aside>
			</div>
		</div>
	);
}

function SelectableRect( {
	selection,
	width,
	height,
	selected,
	onSelect,
	onChange,
}: {
	selection: Selection;
	width: number;
	height: number;
	selected: boolean;
	onSelect: () => void;
	onChange: ( patch: Partial< Selection > ) => void;
} ) {
	const shape = useRef< Konva.Rect >( null );
	const transformer = useRef< Konva.Transformer >( null );
	useEffect( () => {
		if ( selected && shape.current && transformer.current ) {
			transformer.current.nodes( [ shape.current ] );
			transformer.current.getLayer()?.batchDraw();
		}
	}, [ selected ] );
	return (
		<>
			<Rect
				ref={ shape }
				x={ selection.x * width }
				y={ selection.y * height }
				width={ selection.width * width }
				height={ selection.height * height }
				fill="rgba(34,113,177,.14)"
				stroke={ selected ? '#d63638' : '#2271b1' }
				strokeWidth={ selected ? 3 : 2 }
				draggable
				onClick={ onSelect }
				onTap={ onSelect }
				onDragEnd={ ( event ) =>
					onChange( {
						x: event.target.x() / width,
						y: event.target.y() / height,
					} )
				}
				onTransformEnd={ () => {
					const node = shape.current!;
					const scaleX = node.scaleX();
					const scaleY = node.scaleY();
					node.scaleX( 1 );
					node.scaleY( 1 );
					onChange( {
						x: node.x() / width,
						y: node.y() / height,
						width: Math.max(
							0.005,
							( node.width() * scaleX ) / width
						),
						height: Math.max(
							0.005,
							( node.height() * scaleY ) / height
						),
					} );
				} }
			/>
			{ selected && (
				<Transformer
					ref={ transformer }
					rotateEnabled={ false }
					flipEnabled={ false }
					boundBoxFunc={ ( oldBox, newBox ) =>
						newBox.width < 12 || newBox.height < 12
							? oldBox
							: newBox
					}
				/>
			) }
		</>
	);
}

function SortableSelection( {
	selection,
	active,
	onOpen,
	onDelete,
	deleting,
	disabled,
	subjects,
	onSubjectChange,
}: {
	selection: Selection;
	active: boolean;
	onOpen: () => void;
	onDelete: () => void;
	deleting: boolean;
	disabled: boolean;
	subjects: Term[];
	onSubjectChange: ( subjectId: number ) => void;
} ) {
	const { attributes, listeners, setNodeRef, transform, transition } =
		useSortable( { id: selection.key } );
	return (
		<div
			ref={ setNodeRef }
			className={ `ptq-selection-item ${ active ? 'is-active' : '' }` }
			style={ {
				transform: CSS.Transform.toString( transform ),
				transition,
			} }
		>
			<button
				className="ptq-drag-handle"
				type="button"
				{ ...attributes }
				{ ...listeners }
				aria-label={ __( 'Reorder question', 'paper-to-quiz' ) }
				disabled={ disabled }
			>
				⋮⋮
			</button>
			<button
				type="button"
				className="ptq-selection-open"
				onClick={ onOpen }
				disabled={ disabled }
			>
				<strong>
					{ sprintf(
						/* translators: %d: Question number. */
						__( 'Question %d', 'paper-to-quiz' ),
						selection.ordinal
					) }
				</strong>
				<span>
					{ sprintf(
						/* translators: %d: Page number. */
						__( 'Page %d', 'paper-to-quiz' ),
						selection.page
					) }
				</span>
				{ selection.dirty && (
					<em>{ __( 'Changed', 'paper-to-quiz' ) }</em>
				) }
			</button>
			<Button
				isDestructive
				variant="tertiary"
				disabled={ disabled }
				onClick={ onDelete }
			>
				{ deleting ? (
					<BusyLabel>
						{ __( 'Deleting…', 'paper-to-quiz' ) }
					</BusyLabel>
				) : (
					__( 'Delete', 'paper-to-quiz' )
				) }
			</Button>
			<label
				className="ptq-selection-subject"
				htmlFor={ `ptq-subject-${ selection.key }` }
			>
				<span>{ __( 'Subject', 'paper-to-quiz' ) }</span>
				<select
					id={ `ptq-subject-${ selection.key }` }
					value={ selection.subjectId || '' }
					disabled={ disabled }
					required
					onChange={ ( event ) =>
						onSubjectChange( Number( event.target.value ) )
					}
				>
					<option value="">
						{ __( 'Select a subject', 'paper-to-quiz' ) }
					</option>
					{ subjects.map( ( subject ) => (
						<option key={ subject.id } value={ subject.id }>
							{ subject.name }
						</option>
					) ) }
				</select>
			</label>
		</div>
	);
}

function questionToSelection( question: Question, index: number ): Selection {
	return {
		key: isUuid( question.client_key )
			? String( question.client_key )
			: crypto.randomUUID(),
		id: Number( question.id ),
		page: Number( question.source_page ),
		x: Number( question.crop_x ),
		y: Number( question.crop_y ),
		width: Number( question.crop_width ),
		height: Number( question.crop_height ),
		rotation: Number( question.source_rotation ),
		ordinal: index + 1,
		dirty: ! question.main_asset_id || ! question.thumb_asset_id,
		thumbUrl: question.thumb_url,
		subjectId: Number( question.subject_id || 0 ),
	};
}

function reconcileRecovery(
	recovered: Selection[],
	questions: Question[]
): Selection[] {
	return recovered.map( ( selection, index ) => {
		const serverQuestion =
			questions.find(
				( question ) =>
					selection.id &&
					Number( question.id ) === Number( selection.id )
			) ||
			questions.find(
				( question ) =>
					isUuid( question.client_key ) &&
					question.client_key === selection.key
			);
		let key: string = crypto.randomUUID();
		if ( isUuid( selection.key ) ) {
			key = selection.key;
		}
		if ( isUuid( serverQuestion?.client_key ) ) {
			key = String( serverQuestion?.client_key );
		}
		let id: number | undefined;
		if ( selection.id ) {
			id = Number( selection.id );
		}
		if ( serverQuestion ) {
			id = Number( serverQuestion.id );
		}

		return {
			...selection,
			key,
			id,
			ordinal: index + 1,
			dirty: serverQuestion ? Boolean( selection.dirty ) : true,
			thumbUrl: serverQuestion?.thumb_url || selection.thumbUrl,
			subjectId:
				selection.subjectId ||
				Number( serverQuestion?.subject_id || 0 ),
		};
	} );
}

function isUuid( value: unknown ): boolean {
	return (
		typeof value === 'string' &&
		/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(
			value
		)
	);
}

async function renderCrop(
	pdf: PDFDocumentProxy,
	selection: Selection
): Promise< { main: Blob; thumb: Blob } > {
	const page = await pdf.getPage( selection.page );
	const base = page.getViewport( { scale: 1 } );
	const dpiScale =
		Number( window.paperToQuizAdmin.settings.crop_dpi || 300 ) / 72;
	const maxEdge = Number(
		window.paperToQuizAdmin.settings.max_image_edge || 4000
	);
	const cropBaseWidth = base.width * selection.width;
	const cropBaseHeight = base.height * selection.height;
	const scale = Math.min(
		dpiScale,
		maxEdge / Math.max( cropBaseWidth, cropBaseHeight )
	);
	const viewport = page.getViewport( { scale } );
	const full = document.createElement( 'canvas' );
	full.width = Math.ceil( viewport.width );
	full.height = Math.ceil( viewport.height );
	const context = full.getContext( '2d', { alpha: false } );
	if ( ! context ) {
		throw new Error(
			__( 'The PDF canvas could not be created.', 'paper-to-quiz' )
		);
	}
	await page.render( { canvas: full, canvasContext: context, viewport } )
		.promise;

	const source = {
		x: Math.round( selection.x * full.width ),
		y: Math.round( selection.y * full.height ),
		width: Math.max( 1, Math.round( selection.width * full.width ) ),
		height: Math.max( 1, Math.round( selection.height * full.height ) ),
	};
	const crop = document.createElement( 'canvas' );
	crop.width = source.width;
	crop.height = source.height;
	const cropContext = crop.getContext( '2d', { alpha: false } );
	if ( ! cropContext ) {
		throw new Error(
			__( 'The crop canvas could not be created.', 'paper-to-quiz' )
		);
	}
	cropContext.fillStyle = '#fff';
	cropContext.fillRect( 0, 0, crop.width, crop.height );
	cropContext.drawImage(
		full,
		source.x,
		source.y,
		source.width,
		source.height,
		0,
		0,
		crop.width,
		crop.height
	);
	const main = await canvasBlob( crop, 'image/png' );

	const thumb = document.createElement( 'canvas' );
	thumb.width = Math.min( 320, crop.width );
	thumb.height = Math.max(
		1,
		Math.round( crop.height * ( thumb.width / crop.width ) )
	);
	const thumbContext = thumb.getContext( '2d', { alpha: false } );
	if ( ! thumbContext ) {
		throw new Error(
			__( 'The thumbnail canvas could not be created.', 'paper-to-quiz' )
		);
	}
	thumbContext.fillStyle = '#fff';
	thumbContext.fillRect( 0, 0, thumb.width, thumb.height );
	thumbContext.drawImage( crop, 0, 0, thumb.width, thumb.height );
	const thumbBlob = await canvasBlob( thumb, 'image/webp', 0.82 );
	return { main, thumb: thumbBlob };
}

function canvasBlob(
	canvas: HTMLCanvasElement,
	type: string,
	quality?: number
): Promise< Blob > {
	return new Promise( ( resolve, reject ) =>
		canvas.toBlob(
			( blob ) =>
				blob
					? resolve( blob )
					: reject(
							new Error(
								__(
									'The image could not be generated.',
									'paper-to-quiz'
								)
							)
					  ),
			type,
			quality
		)
	);
}

function intersectionOverUnion(
	a: Pick< Selection, 'x' | 'y' | 'width' | 'height' >,
	b: { x: number; y: number; width: number; height: number }
) {
	const x1 = Math.max( a.x, b.x );
	const y1 = Math.max( a.y, b.y );
	const x2 = Math.min( a.x + a.width, b.x + b.width );
	const y2 = Math.min( a.y + a.height, b.y + b.height );
	const intersection = Math.max( 0, x2 - x1 ) * Math.max( 0, y2 - y1 );
	const union = a.width * a.height + b.width * b.height - intersection;
	return union ? intersection / union : 0;
}

function pdfError( error: any ): string {
	if ( error?.name === 'PasswordException' ) {
		return __(
			'Encrypted PDF files are not supported. Remove the password and upload again.',
			'paper-to-quiz'
		);
	}
	if ( error?.name === 'InvalidPDFException' ) {
		return __(
			'The PDF file is corrupt or invalid. Check the file and try again.',
			'paper-to-quiz'
		);
	}
	if ( error?.name === 'MissingPDFException' ) {
		return __(
			'The PDF file could not be reached. Refresh the page and try again.',
			'paper-to-quiz'
		);
	}
	return __(
		'The PDF could not be opened. Check the file and try again.',
		'paper-to-quiz'
	);
}

function recoveryDatabase(): Promise< IDBDatabase > {
	return new Promise( ( resolve, reject ) => {
		const request = indexedDB.open( 'ptq-admin-recovery', 1 );
		request.onupgradeneeded = () => {
			if ( ! request.result.objectStoreNames.contains( 'selections' ) ) {
				request.result.createObjectStore( 'selections', {
					keyPath: 'revisionId',
				} );
			}
		};
		request.onsuccess = () => resolve( request.result );
		request.onerror = () => reject( request.error );
	} );
}

async function loadRecovery(
	revisionId: number
): Promise< Selection[] | null > {
	const database = await recoveryDatabase();
	return new Promise( ( resolve, reject ) => {
		const transaction = database.transaction( 'selections', 'readonly' );
		const request = transaction
			.objectStore( 'selections' )
			.get( revisionId );
		request.onsuccess = () =>
			resolve(
				Array.isArray( request.result?.items )
					? request.result.items
					: null
			);
		request.onerror = () => reject( request.error );
		transaction.oncomplete = () => database.close();
	} );
}

async function saveRecovery(
	revisionId: number,
	selections: Selection[]
): Promise< void > {
	const database = await recoveryDatabase();
	return new Promise( ( resolve, reject ) => {
		const transaction = database.transaction( 'selections', 'readwrite' );
		transaction.objectStore( 'selections' ).put( {
			revisionId,
			items: selections,
			updatedAt: new Date().toISOString(),
		} );
		transaction.oncomplete = () => {
			database.close();
			resolve();
		};
		transaction.onerror = () => {
			database.close();
			reject( transaction.error );
		};
	} );
}

async function clearRecovery( revisionId: number ): Promise< void > {
	const database = await recoveryDatabase();
	return new Promise( ( resolve, reject ) => {
		const transaction = database.transaction( 'selections', 'readwrite' );
		transaction.objectStore( 'selections' ).delete( revisionId );
		transaction.oncomplete = () => {
			database.close();
			resolve();
		};
		transaction.onerror = () => {
			database.close();
			reject( transaction.error );
		};
	} );
}
