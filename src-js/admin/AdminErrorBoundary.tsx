import { Component } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

type AdminErrorBoundaryProps = {
	children: ReactNode;
	onReload?: () => void;
};

type AdminErrorBoundaryState = {
	hasError: boolean;
};

export class AdminErrorBoundary extends Component<
	AdminErrorBoundaryProps,
	AdminErrorBoundaryState
> {
	state: AdminErrorBoundaryState = { hasError: false };

	static getDerivedStateFromError(): AdminErrorBoundaryState {
		return { hasError: true };
	}

	private reload = () => {
		if ( this.props.onReload ) {
			this.props.onReload();
			return;
		}
		window.location.reload();
	};

	render() {
		if ( ! this.state.hasError ) {
			return this.props.children;
		}

		return (
			<div className="ptq-page ptq-fatal-error" role="alert">
				<div className="notice notice-error">
					<p>
						<strong>
							{ __(
								'The editor could not be displayed.',
								'paper-to-quiz'
							) }
						</strong>
					</p>
					<p>
						{ __(
							'Your saved changes are safe. Reload the page to continue.',
							'paper-to-quiz'
						) }
					</p>
					<p>
						<button
							type="button"
							className="button button-primary"
							onClick={ this.reload }
						>
							{ __( 'Reload page', 'paper-to-quiz' ) }
						</button>
					</p>
				</div>
			</div>
		);
	}
}
