import { useEffect, useId, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { studentApi } from './api';
import { formatDuration, formatScore } from '../shared/format';

type RankingPage = {
	items: Array< {
		rank: number;
		display_name: string;
		score: number;
		duration_seconds: number;
	} >;
	page: number;
	total: number;
	total_pages: number;
	participant_total: number;
};

export function RankingList( {
	assessmentId,
	restRoot,
	nonce,
}: {
	assessmentId: number;
	restRoot: string;
	nonce: string;
} ) {
	const [ open, setOpen ] = useState( false );
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState< RankingPage | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ retry, setRetry ] = useState( 0 );
	const id = useId();

	useEffect( () => {
		if ( ! open ) {
			return;
		}
		const controller = new AbortController();
		setBusy( true );
		setData( null );
		setError( '' );
		const timer = window.setTimeout( () => {
			const query = new URLSearchParams( {
				page: String( page ),
				search,
			} );
			void studentApi< RankingPage >(
				restRoot,
				`/assessments/${ assessmentId }/ranking?${ query }`,
				nonce,
				'',
				{ signal: controller.signal }
			)
				.then( ( result ) => {
					if ( ! controller.signal.aborted ) {
						setData( result );
					}
				} )
				.catch( ( caught: unknown ) => {
					if ( ! controller.signal.aborted ) {
						setError(
							caught instanceof Error
								? caught.message
								: __(
										'The participant list could not be loaded.',
										'paper-to-quiz'
									)
						);
					}
				} )
				.finally( () => {
					if ( ! controller.signal.aborted ) {
						setBusy( false );
					}
				} );
		}, 300 );
		return () => {
			window.clearTimeout( timer );
			controller.abort();
		};
	}, [ open, search, page, assessmentId, restRoot, nonce, retry ] );

	return (
		<section className="ptq-ranking-list">
			<button
				type="button"
				aria-expanded={ open }
				aria-controls={ id }
				onClick={ () => setOpen( ! open ) }
			>
				{ __( 'Ranking', 'paper-to-quiz' ) }
			</button>
			<div id={ id } hidden={ ! open }>
				{ open && (
					<>
						<h2 id={ `${ id }-heading` }>
							{ __( 'Participant ranking', 'paper-to-quiz' ) }
						</h2>
						<label htmlFor={ `${ id }-search` }>
							{ __( 'Search visible names', 'paper-to-quiz' ) }
							<input
								id={ `${ id }-search` }
								type="search"
								maxLength={ 80 }
								value={ search }
								onChange={ ( event ) => {
									setSearch( event.target.value );
									setPage( 1 );
								} }
							/>
						</label>
						<div role="status" aria-live="polite">
							{ busy &&
								__( 'Loading ranking…', 'paper-to-quiz' ) }
							{ data &&
								sprintf(
									/* translators: 1: Matching entries, 2: Total ranked submissions. */
									__(
										'%1$d matches / %2$d ranked submissions',
										'paper-to-quiz'
									),
									data.total,
									data.participant_total
								) }
						</div>
						{ error && (
							<div role="alert">
								<p>{ error }</p>
								<button
									type="button"
									onClick={ () => setRetry( retry + 1 ) }
								>
									{ __( 'Try again', 'paper-to-quiz' ) }
								</button>
							</div>
						) }
						{ data && data.items.length === 0 && (
							<p>
								{ __(
									'No matching participants.',
									'paper-to-quiz'
								) }
							</p>
						) }
						{ data && data.items.length > 0 && (
							<>
								<p id={ `${ id }-notice` }>
									{ __(
										'Names are masked. Guest entries represent submissions, not verified individuals.',
										'paper-to-quiz'
									) }
								</p>
								<div
									className="ptq-ranking-list__table"
									role="region"
									aria-labelledby={ `${ id }-heading` }
									tabIndex={ 0 }
								>
									<table
										aria-labelledby={ `${ id }-heading` }
										aria-describedby={ `${ id }-notice` }
									>
										<thead>
											<tr>
												<th scope="col">
													{ __(
														'Rank',
														'paper-to-quiz'
													) }
												</th>
												<th scope="col">
													{ __(
														'Participant',
														'paper-to-quiz'
													) }
												</th>
												<th scope="col">
													{ __(
														'Score',
														'paper-to-quiz'
													) }
												</th>
												<th scope="col">
													{ __(
														'Duration',
														'paper-to-quiz'
													) }
												</th>
											</tr>
										</thead>
										<tbody>
											{ data.items.map(
												( item, index ) => (
													<tr
														key={ `${ page }-${ index }` }
													>
														<td>{ item.rank }</td>
														<td>
															{
																item.display_name
															}
														</td>
														<td>
															{ formatScore(
																item.score,
																item.score %
																	100 ===
																	0
																	? 0
																	: 2
															) }
														</td>
														<td>
															{ formatDuration(
																item.duration_seconds
															) }
														</td>
													</tr>
												)
											) }
										</tbody>
									</table>
								</div>
							</>
						) }
						{ data && data.total_pages > 1 && (
							<nav
								aria-label={ __(
									'Ranking pages',
									'paper-to-quiz'
								) }
							>
								<button
									type="button"
									disabled={ busy || page <= 1 }
									onClick={ () => setPage( page - 1 ) }
								>
									{ __( 'Previous', 'paper-to-quiz' ) }
								</button>
								<span>
									{ sprintf(
										/* translators: 1: Current page, 2: Total pages. */
										__(
											'Page %1$d of %2$d',
											'paper-to-quiz'
										),
										page,
										data.total_pages
									) }
								</span>
								<button
									type="button"
									disabled={
										busy || page >= data.total_pages
									}
									onClick={ () => setPage( page + 1 ) }
								>
									{ __( 'Next', 'paper-to-quiz' ) }
								</button>
							</nav>
						) }
					</>
				) }
			</div>
		</section>
	);
}
