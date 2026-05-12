import apiFetch from '@wordpress/api-fetch';
import { createRoot } from '@wordpress/element';
import App from './App';

apiFetch.use( apiFetch.createNonceMiddleware( window.skillsawData.nonce ) );
apiFetch.use( apiFetch.createRootURLMiddleware( window.skillsawData.rootUrl ) );

const container = document.getElementById( 'skillsaw-app' );
if ( container ) {
	createRoot( container ).render( <App /> );
}
