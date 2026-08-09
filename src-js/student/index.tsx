import { createRoot } from '@wordpress/element';
import { StudentApp } from './StudentApp';
import './style.scss';

document
	.querySelectorAll< HTMLElement >( '.ptq-student-root' )
	.forEach( ( element ) => {
		const shadow =
			element.shadowRoot || element.attachShadow( { mode: 'open' } );
		shadow.replaceChildren();
		const style = document.createElement( 'link' );
		style.rel = 'stylesheet';
		style.href = element.dataset.styleUrl || '';
		const mount = document.createElement( 'div' );
		mount.className = 'ptq-student-root';
		shadow.append( style, mount );
		createRoot( mount ).render(
			<StudentApp
				assessmentId={ Number( element.dataset.assessmentId ) }
				restRoot={ element.dataset.restRoot || '/wp-json/ptq/v1/' }
				nonce={ element.dataset.nonce || '' }
				mountElement={ mount }
			/>
		);
	} );
