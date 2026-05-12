import { useState, useEffect } from '@wordpress/element';
import { Button, Modal, Notice, Spinner, SelectControl } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

const RATING_META = {
	obvious_success:  { label: 'Clearly demonstrated', className: 'strong' },
	provided_response: { label: 'Demonstrated',         className: 'met' },
	no_response:      { label: 'Not demonstrated',      className: 'unmet' },
	obvious_failure:  { label: 'Below threshold',       className: 'fail' },
};

function SkillChip( { name, rating } ) {
	const meta = RATING_META[ rating ] || RATING_META.no_response;
	return (
		<span className={ `skillsaw-chip skillsaw-chip--${ meta.className }` } title={ meta.label }>
			<span className="skillsaw-chip-dot" />
			{ name }
		</span>
	);
}

function TranscriptModal( { session, onClose } ) {
	const [ data, setData ]     = useState( null );
	const [ loading, setLoading ] = useState( true );

	useEffect( () => {
		apiFetch( { path: `/skillsaw/v1/candidates/${ session.id }/transcript` } )
			.then( setData )
			.finally( () => setLoading( false ) );
	}, [ session.id ] );

	return (
		<Modal
			title={ `${ session.candidate_name } — chat transcript` }
			onRequestClose={ onClose }
			size="large"
		>
			{ loading && <Spinner /> }
			{ data && (
				<div className="skillsaw-transcript">
					<div className="skillsaw-transcript-meta">
						<span>{ data.role_title }</span>
						<span>{ data.mode === 'upload' ? 'Upload mode' : 'Critique mode' }</span>
						{ data.completed_at && (
							<span>
								{ Math.round(
									( new Date( data.completed_at ) - new Date( data.started_at ) ) / 60000
								) } min session
							</span>
						) }
					</div>
					<div className="skillsaw-transcript-messages">
						{ data.messages.map( ( msg ) => (
							<div key={ msg.id } className={ `skillsaw-msg skillsaw-msg--${ msg.role }` }>
								<span className="skillsaw-msg-who">{ msg.role === 'bot' ? 'SKILLSAW' : session.candidate_name.split( ' ' )[ 0 ].toUpperCase() }</span>
								<div className="skillsaw-msg-bubble">
									{ msg.file && (
										<div className="skillsaw-file-card">
											<span className="skillsaw-file-type">{ msg.file.name.split( '.' ).pop().toUpperCase() }</span>
											<span className="skillsaw-file-name">{ msg.file.name }</span>
											<span className="skillsaw-file-size">{ msg.file.size }</span>
										</div>
									) }
									{ msg.content && <p>{ msg.content }</p> }
								</div>
							</div>
						) ) }
					</div>
				</div>
			) }
		</Modal>
	);
}

function CandidateRow( { session, onOpenTranscript } ) {
	const initials = session.candidate_name
		.split( ' ' )
		.map( ( n ) => n[ 0 ] )
		.join( '' )
		.slice( 0, 2 )
		.toUpperCase();

	const duration = session.completed_at
		? Math.round( ( new Date( session.completed_at ) - new Date( session.started_at ) ) / 60000 )
		: null;

	return (
		<div className="skillsaw-candidate-row">
			<div className="skillsaw-candidate-identity">
				<span className="skillsaw-avatar">{ initials }</span>
				<div>
					<div className="skillsaw-candidate-name">{ session.candidate_name }</div>
					<div className="skillsaw-candidate-email">{ session.candidate_email }</div>
				</div>
			</div>

			<div className="skillsaw-candidate-role">
				<div>{ session.role_title }</div>
				{ session.team && <small>{ session.team }</small> }
			</div>

			<div className="skillsaw-candidate-skills">
				{ session.skill_ratings.map( ( r ) => (
					<SkillChip key={ r.skill_name } name={ r.skill_name } rating={ r.rating } />
				) ) }
			</div>

			<div className="skillsaw-candidate-actions">
				<Button
					variant="primary"
					size="small"
					onClick={ () => onOpenTranscript( session ) }
				>
					Transcript
				</Button>
				{ duration && (
					<span className="skillsaw-duration">{ duration }m</span>
				) }
			</div>
		</div>
	);
}

export default function CandidatesTab() {
	const [ sessions, setSessions ]   = useState( [] );
	const [ loading, setLoading ]     = useState( true );
	const [ error, setError ]         = useState( '' );
	const [ search, setSearch ]       = useState( '' );
	const [ openSession, setOpen ]    = useState( null );

	useEffect( () => {
		apiFetch( { path: '/skillsaw/v1/candidates' } )
			.then( setSessions )
			.catch( ( err ) => setError( err.message ) )
			.finally( () => setLoading( false ) );
	}, [] );

	const filtered = sessions.filter( ( s ) => {
		if ( ! search ) return true;
		const q = search.toLowerCase();
		return (
			s.candidate_name.toLowerCase().includes( q ) ||
			s.candidate_email.toLowerCase().includes( q )
		);
	} );

	if ( loading ) return <Spinner />;

	return (
		<div className="skillsaw-candidates-tab">
			{ error && <Notice status="error" isDismissible onRemove={ () => setError( '' ) }>{ error }</Notice> }

			<div className="skillsaw-filter-bar">
				<input
					type="search"
					placeholder="Search candidates by name or email…"
					value={ search }
					onChange={ ( e ) => setSearch( e.target.value ) }
					className="skillsaw-search"
				/>
			</div>

			<div className="skillsaw-results-meta">
				<span>
					<strong>{ filtered.length }</strong> of { sessions.length } candidates
				</span>
				<div className="skillsaw-legend">
					{ Object.values( RATING_META ).map( ( m ) => (
						<span key={ m.className } className={ `skillsaw-chip skillsaw-chip--${ m.className }` }>
							<span className="skillsaw-chip-dot" />
							{ m.label }
						</span>
					) ) }
				</div>
			</div>

			<div className="skillsaw-candidates-list">
				{ filtered.length === 0 && (
					<p className="skillsaw-empty">No completed sessions yet.</p>
				) }
				{ filtered.map( ( session ) => (
					<CandidateRow
						key={ session.id }
						session={ session }
						onOpenTranscript={ setOpen }
					/>
				) ) }
			</div>

			{ openSession && (
				<TranscriptModal
					session={ openSession }
					onClose={ () => setOpen( null ) }
				/>
			) }
		</div>
	);
}
