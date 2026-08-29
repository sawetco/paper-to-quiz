import { createRoot } from '@wordpress/element';
import { App } from './App';
import { AdminErrorBoundary } from './AdminErrorBoundary';
import './style.scss';

const root = document.getElementById( 'ptq-admin-root' );
if ( root ) {
	createRoot( root ).render(
		<AdminErrorBoundary>
			<App />
		</AdminErrorBoundary>
	);
}
