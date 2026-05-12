import { createRoot } from '@wordpress/element';
import App from './App';

const container = document.getElementById( 'skillsaw-app' );
if ( container ) {
	createRoot( container ).render( <App /> );
}
