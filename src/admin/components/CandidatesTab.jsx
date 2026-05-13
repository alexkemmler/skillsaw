import { useState, useEffect } from '@wordpress/element';
import { Button, Modal, Notice, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

const RATING_META = {
	obvious_success:   { label: 'Clearly demonstrated', className: 'strong' },
	provided_response: { label: 'Demonstrated',         className: 'met'    },
	no_response:       { label: 'Not demonstrated',     className: 'unmet'  },
	obvious_failure:   { label: 'Below threshold',      className: 'fail'   },
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

function fmtDate( iso ) {
	if ( ! iso ) return '';
	return new Date( iso ).toLocaleDateString( undefined, { month: 'short', day: 'numeric', year: 'numeric' } );
}

function fmtDuration( start, end ) {
	if ( ! start || ! end ) return null;
	const mins = Math.round( ( new Date( end ) - new Date( start ) ) / 60000 );
	return mins < 1 ? '< 1 min' : `${ mins } min`;
}

// ─── Transcript modal ─────────────────────────────────────────────────────────

function FileCard( { file, skills } ) {
	const ext = file.name.split( '.' ).pop().toUpperCase();
	return (
		<div className="skillsaw-file-card">
			<div className="skillsaw-file-card-main">
				<span className="skillsaw-file-type">{ ext }</span>
				<span className="skillsaw-file-name">{ file.name }</span>
				{ file.size && <span className="skillsaw-file-size">{ file.size }</span> }
				{ file.url && (
					<a
						href={ file.url }
						download={ file.name }
						className="skillsaw-file-download"
						onClick={ ( e ) => e.stopPropagation() }
					>
						Download
					</a>
				) }
			</div>
			{ skills && skills.length > 0 && (
				<div className="skillsaw-file-skills">
					<span className="skillsaw-file-skills-label">Tagged skills:</span>
					{ skills.map( ( s ) => (
						<span key={ s } className="skillsaw-chip skillsaw-chip--skill-tag">{ s }</span>
					) ) }
				</div>
			) }
		</div>
	);
}

function TranscriptMessage( { msg, candidateName } ) {
	const isBot    = msg.role === 'bot';
	const who      = isBot ? 'SKILLSAW' : candidateName.split( ' ' )[ 0 ].toUpperCase();
	const showText = msg.content && ! msg.file;

	return (
		<div className={ `skillsaw-msg skillsaw-msg--${ msg.role }` }>
			<span className="skillsaw-msg-who">{ who }</span>
			<div className="skillsaw-msg-bubble">
				{ msg.file && <FileCard file={ msg.file } skills={ msg.candidate_skills } /> }
				{ showText && <p>{ msg.content }</p> }
			</div>
		</div>
	);
}

function TranscriptModal( { session, onClose } ) {
	const [ data,    setData    ] = useState( null );
	const [ loading, setLoading ] = useState( true );

	useEffect( () => {
		apiFetch( { path: `/skillsaw/v1/candidates/${ session.id }/transcript` } )
			.then( setData )
			.finally( () => setLoading( false ) );
	}, [ session.id ] );

	const duration = data ? fmtDuration( data.started_at, data.completed_at ) : null;

	return (
		<Modal
			title={ `${ session.candidate_name } — chat transcript` }
			onRequestClose={ onClose }
			size="large"
		>
			{ loading && <Spinner /> }

			{ data && (
				<>
					<div className="skillsaw-transcript-meta">
						<span>{ data.role_title }</span>
						<span className="skillsaw-pill skillsaw-pill--mode">
							{ data.mode === 'upload' ? 'Upload' : 'Critique' }
						</span>
						{ duration && <span>{ duration }</span> }
						{ data.started_at && <span>{ fmtDate( data.started_at ) }</span> }
						{ data.candidate_email && (
							<span className="skillsaw-transcript-email">{ data.candidate_email }</span>
						) }
					</div>

					<div className="skillsaw-transcript-messages">
						{ data.messages.map( ( msg ) => (
							<TranscriptMessage
								key={ msg.id }
								msg={ msg }
								candidateName={ session.candidate_name }
							/>
						) ) }
					</div>

					{ data.skill_ratings && data.skill_ratings.length > 0 && (
						<div className="skillsaw-transcript-ratings">
							<h4>Skill ratings</h4>
							<div className="skillsaw-transcript-ratings-grid">
								{ data.skill_ratings.map( ( r ) => {
									const meta = RATING_META[ r.rating ] || RATING_META.no_response;
									return (
										<div key={ r.skill_name } className="skillsaw-rating-row">
											<span className="skillsaw-rating-skill">{ r.skill_name }</span>
											<span className={ `skillsaw-chip skillsaw-chip--${ meta.className }` }>
												<span className="skillsaw-chip-dot" />
												{ meta.label }
											</span>
										</div>
									);
								} ) }
							</div>
						</div>
					) }
				</>
			) }
		</Modal>
	);
}

// ─── Candidate row ────────────────────────────────────────────────────────────

function CandidateRow( { session, onOpenTranscript } ) {
	const initials = session.candidate_name
		.split( ' ' )
		.map( ( n ) => n[ 0 ] )
		.join( '' )
		.slice( 0, 2 )
		.toUpperCase();

	const duration = fmtDuration( session.started_at, session.completed_at );
	const date     = fmtDate( session.started_at );

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
				{ session.skill_ratings.length === 0 && (
					<span className="skillsaw-no-ratings">Ratings pending…</span>
				) }
			</div>

			<div className="skillsaw-candidate-date">
				{ date || '—' }
			</div>

			<div className="skillsaw-candidate-duration">
				{ duration || '—' }
				{ session.mode && (
					<span className="skillsaw-pill skillsaw-pill--mode">
						{ session.mode === 'upload' ? 'Upload' : 'Critique' }
					</span>
				) }
			</div>

			<div className="skillsaw-candidate-actions">
				<Button
					variant="primary"
					size="small"
					onClick={ () => onOpenTranscript( session ) }
				>
					Transcript
				</Button>
			</div>
		</div>
	);
}

// ─── Main component ───────────────────────────────────────────────────────────

export default function CandidatesTab() {
	const [ sessions,   setSessions   ] = useState( [] );
	const [ roles,      setRoles      ] = useState( [] );
	const [ loading,    setLoading    ] = useState( true );
	const [ error,      setError      ] = useState( '' );
	const [ search,     setSearch     ] = useState( '' );
	const [ roleFilter, setRoleFilter ] = useState( 'all' );
	const [ modeFilter, setModeFilter ] = useState( 'all' );
	const [ openSession, setOpen      ] = useState( null );

	useEffect( () => {
		Promise.all( [
			apiFetch( { path: '/skillsaw/v1/candidates' } ),
			apiFetch( { path: '/skillsaw/v1/roles' } ),
		] )
			.then( ( [ s, r ] ) => { setSessions( s ); setRoles( r ); } )
			.catch( ( err ) => setError( err.message ) )
			.finally( () => setLoading( false ) );
	}, [] );

	const filtered = sessions.filter( ( s ) => {
		if ( search ) {
			const q = search.toLowerCase();
			if (
				! s.candidate_name.toLowerCase().includes( q ) &&
				! s.candidate_email.toLowerCase().includes( q )
			) return false;
		}
		if ( roleFilter !== 'all' && String( s.role_id ) !== roleFilter ) return false;
		if ( modeFilter !== 'all' && s.mode !== modeFilter ) return false;
		return true;
	} );

	if ( loading ) return <Spinner />;

	return (
		<div className="skillsaw-candidates-tab">
			{ error && (
				<Notice status="error" isDismissible onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }

			<div className="skillsaw-filter-bar">
				<input
					type="search"
					placeholder="Search by name or email…"
					value={ search }
					onChange={ ( e ) => setSearch( e.target.value ) }
					className="skillsaw-search"
				/>
				<select
					className="skillsaw-filter-select"
					value={ roleFilter }
					onChange={ ( e ) => setRoleFilter( e.target.value ) }
				>
					<option value="all">All roles</option>
					{ roles.map( ( r ) => (
						<option key={ r.id } value={ String( r.id ) }>{ r.title }</option>
					) ) }
				</select>
				<select
					className="skillsaw-filter-select"
					value={ modeFilter }
					onChange={ ( e ) => setModeFilter( e.target.value ) }
				>
					<option value="all">All modes</option>
					<option value="upload">Upload</option>
					<option value="critique">Critique</option>
				</select>
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
				{ filtered.length > 0 && (
					<div className="skillsaw-list-head skillsaw-candidates-list-head" aria-hidden="true">
						<span>Candidate</span>
						<span>Role</span>
						<span>Skills evaluation</span>
						<span>Date</span>
						<span>Time taken</span>
						<span>Transcript</span>
					</div>
				) }
				{ filtered.length === 0 && (
					<p className="skillsaw-empty">
						{ sessions.length === 0
							? 'No completed sessions yet.'
							: 'No candidates match the current filters.' }
					</p>
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
