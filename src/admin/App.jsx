import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import RolesTab from './components/RolesTab';
import CandidatesTab from './components/CandidatesTab';
import AboutTab from './components/AboutTab';

export default function App() {
	const [ tab, setTab ] = useState( 'candidates' );

	return (
		<div className="skillsaw-app">
			<hr className="wp-header-end" />

			<div className="nav-tab-wrapper" role="tablist">
				<button
					className={ `nav-tab ${ tab === 'candidates' ? 'nav-tab-active' : '' }` }
					role="tab"
					aria-selected={ tab === 'candidates' }
					onClick={ () => setTab( 'candidates' ) }
				>
					Candidates
				</button>
				<button
					className={ `nav-tab ${ tab === 'roles' ? 'nav-tab-active' : '' }` }
					role="tab"
					aria-selected={ tab === 'roles' }
					onClick={ () => setTab( 'roles' ) }
				>
					Roles
				</button>
				<button
					className={ `nav-tab ${ tab === 'about' ? 'nav-tab-active' : '' }` }
					role="tab"
					aria-selected={ tab === 'about' }
					onClick={ () => setTab( 'about' ) }
				>
					About
				</button>
			</div>

			{ tab === 'candidates' && <CandidatesTab /> }
			{ tab === 'roles' && <RolesTab /> }
			{ tab === 'about' && <AboutTab /> }
		</div>
	);
}
