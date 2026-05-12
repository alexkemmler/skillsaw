import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import RolesTab from './components/RolesTab';
import CandidatesTab from './components/CandidatesTab';

export default function App() {
	const [ tab, setTab ] = useState( 'candidates' );

	return (
		<div className="skillsaw-app">
			<p className="skillsaw-subtitle">
				Candidates who interacted with the Skillsaw chatbot during their application appear below.
				Skill chips reflect the bot&rsquo;s evaluation from the chat session.
				Transcripts and uploaded files are also pushed to each candidate&rsquo;s Greenhouse profile.
			</p>
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
			</div>

			{ tab === 'candidates' ? <CandidatesTab /> : <RolesTab /> }
		</div>
	);
}
