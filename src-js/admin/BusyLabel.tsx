import { Spinner } from '@wordpress/components';

export function BusyLabel( { children }: { children: string } ) {
	return (
		<span className="ptq-busy-label" role="status" aria-live="polite">
			<Spinner />
			<span>{ children }</span>
		</span>
	);
}

export function LoadingRegion( { children }: { children: string } ) {
	return (
		<div
			className="ptq-loading"
			role="status"
			aria-live="polite"
			aria-busy="true"
		>
			<Spinner />
			<span>{ children }</span>
		</div>
	);
}
