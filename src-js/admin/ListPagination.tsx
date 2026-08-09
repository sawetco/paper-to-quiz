import { __, _n, sprintf } from '@wordpress/i18n';

function formatItemCount( total: number ): string {
	return sprintf(
		/* translators: %d: Number of list items. */
		_n( '%d item', '%d items', total, 'paper-to-quiz' ),
		total
	);
}

export function ListPagination( {
	page,
	pages,
	total,
	onChange,
	disabled = false,
}: {
	page: number;
	pages: number;
	total: number;
	onChange: ( page: number ) => void;
	disabled?: boolean;
} ) {
	if ( pages <= 1 ) {
		return (
			<div className="tablenav-pages one-page">
				<span className="displaying-num">
					{ formatItemCount( total ) }
				</span>
			</div>
		);
	}
	return (
		<div className="tablenav-pages">
			<span className="displaying-num">{ formatItemCount( total ) }</span>
			<span className="pagination-links">
				<button
					type="button"
					className="button"
					disabled={ disabled || page <= 1 }
					onClick={ () => onChange( 1 ) }
					aria-label={ __( 'First page', 'paper-to-quiz' ) }
				>
					«
				</button>
				<button
					type="button"
					className="button"
					disabled={ disabled || page <= 1 }
					onClick={ () => onChange( page - 1 ) }
					aria-label={ __( 'Previous page', 'paper-to-quiz' ) }
				>
					‹
				</button>
				<span className="paging-input">
					<span className="tablenav-paging-text">
						{ page } /{ ' ' }
						<span className="total-pages">{ pages }</span>
					</span>
				</span>
				<button
					type="button"
					className="button"
					disabled={ disabled || page >= pages }
					onClick={ () => onChange( page + 1 ) }
					aria-label={ __( 'Next page', 'paper-to-quiz' ) }
				>
					›
				</button>
				<button
					type="button"
					className="button"
					disabled={ disabled || page >= pages }
					onClick={ () => onChange( pages ) }
					aria-label={ __( 'Last page', 'paper-to-quiz' ) }
				>
					»
				</button>
			</span>
		</div>
	);
}
