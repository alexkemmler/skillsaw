import { useState, useEffect } from '@wordpress/element';
import { Button, Modal, Notice, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

const RATING_META = {
	obvious_success:   { label: 'Clearly demonstrated', className: 'strong' },
	provided_response: { label: 'Demonstrated',         className: 'met'    },
	no_response:       { label: 'Not demonstrated',     className: 'unmet'  },
	obvious_failure:   { label: 'Below threshold',      className: 'fail'   },
};

const RATING_RANK = {
	obvious_success:   4,
	provided_response: 3,
	no_response:       2,
	obvious_failure:   1,
};

// ─── Pure helpers ─────────────────────────────────────────────────────────────

/**
 * Merge multiple skill-rating arrays, keeping the best (highest-ranked) rating
 * per skill name across all sessions.
 */
function mergeRatings( ratingsArrays ) {
	const best = {};
	for ( const ratings of ratingsArrays ) {
		for ( const r of ratings ) {
			const existing = best[ r.skill_name ];
			if ( ! existing || ( RATING_RANK[ r.rating ] ?? 0 ) > ( RATING_RANK[ existing.rating ] ?? 0 ) ) {
				best[ r.skill_name ] = r;
			}
		}
	}
	return Object.values( best );
}

/**
 * Group a flat sessions array into candidate groups.
 * Sessions with the same email + role_id are combined into one group.
 * Sessions with a blank email each form their own solo group.
 */
function groupSessions( sessions ) {
	const map = new Map();

	for ( const session of sessions ) {
		const email = session.candidate_email?.trim() || '';
		const key   = email ? `${ email }::${ session.role_id }` : `solo::${ session.id }`;

		if ( ! map.has( key ) ) {
			map.set( key, { ...session, sessions: [ session ] } );
		} else {
			map.get( key ).sessions.push( session );
		}
	}

	const groups = [];
	for ( const group of map.values() ) {
		if ( group.sessions.length === 1 ) {
			groups.push( group );
			continue;
		}

		// Sort sessions oldest-first so transcript tabs appear in order.
		const sorted = [ ...group.sessions ].sort(
			( a, b ) => new Date( a.started_at ) - new Date( b.started_at )
		);

		// Use the name from the session that has one (prefer most recent).
		const withName = [ ...sorted ].reverse().find( ( s ) => s.candidate_name );

		groups.push( {
			// Spread the most-recent session's fields as defaults.
			...sorted[ sorted.length - 1 ],
			sessions:        sorted,
			candidate_name:  withName?.candidate_name  || '',
			candidate_email: sorted[ 0 ].candidate_email,
			// Earliest start, latest completion.
			started_at:      sorted[ 0 ].started_at,
			completed_at:    sorted[ sorted.length - 1 ].completed_at,
			// Merged skill ratings.
			skill_ratings:   mergeRatings( sorted.map( ( s ) => s.skill_ratings ) ),
			// Archived only when every session is archived.
			archived_at:     sorted.every( ( s ) => s.archived_at ) ? sorted[ 0 ].archived_at : null,
			// Greenhouse: flag error if any session has one; ok if any was pushed.
			gh_push_error:   sorted.find( ( s ) => s.gh_push_error )?.gh_push_error || '',
			gh_pushed_at:    sorted.find( ( s ) => s.gh_pushed_at )?.gh_pushed_at   || null,
		} );
	}

	// Sort groups newest-first by their primary started_at.
	return groups.sort( ( a, b ) => new Date( b.started_at ) - new Date( a.started_at ) );
}

// ─── Icons ────────────────────────────────────────────────────────────────────

function TrashIcon() {
	return (
		<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="14" height="14" fill="currentColor" aria-hidden="true">
			<path d="M6 2a1 1 0 0 0-1 1v.5H2.5a.5.5 0 0 0 0 1H3v8a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1v-8h.5a.5.5 0 0 0 0-1H11V3a1 1 0 0 0-1-1H6zm0 1h4v.5H6V3zM4 4.5h8v8H4v-8zm2 1.5a.5.5 0 0 0-.5.5v4a.5.5 0 0 0 1 0v-4A.5.5 0 0 0 6 6zm4 0a.5.5 0 0 0-.5.5v4a.5.5 0 0 0 1 0v-4A.5.5 0 0 0 10 6z"/>
		</svg>
	);
}

function RestoreIcon() {
	return (
		<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="14" height="14" fill="currentColor" aria-hidden="true">
			<path d="M8 3a5 5 0 1 0 4.546 2.914.5.5 0 0 1 .908-.417A6 6 0 1 1 8 2v1z"/>
			<path d="M8 4.466V.534a.25.25 0 0 1 .41-.192l2.36 1.966c.12.1.12.284 0 .384L8.41 4.658A.25.25 0 0 1 8 4.466z"/>
		</svg>
	);
}

// ─── Shared sub-components ────────────────────────────────────────────────────

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

/** Sum durations across all sessions in a group. */
function fmtTotalDuration( sessions ) {
	let totalMins = 0;
	for ( const s of sessions ) {
		if ( s.started_at && s.completed_at ) {
			totalMins += ( new Date( s.completed_at ) - new Date( s.started_at ) ) / 60000;
		}
	}
	if ( totalMins === 0 ) return null;
	const rounded = Math.round( totalMins );
	return rounded < 1 ? '< 1 min' : `${ rounded } min`;
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

function SingleTranscript( { sessionId, candidateName } ) {
	const [ data,    setData    ] = useState( null );
	const [ loading, setLoading ] = useState( true );

	useEffect( () => {
		setData( null );
		setLoading( true );
		apiFetch( { path: `/skillsaw/v1/candidates/${ sessionId }/transcript` } )
			.then( setData )
			.finally( () => setLoading( false ) );
	}, [ sessionId ] );

	if ( loading ) return <Spinner />;
	if ( ! data )  return <p>Could not load transcript.</p>;

	const duration = fmtDuration( data.started_at, data.completed_at );

	return (
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
						candidateName={ candidateName }
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
	);
}

function TranscriptModal( { group, onClose } ) {
	const sessions = group.sessions || [ group ];
	const [ activeIdx, setActiveIdx ] = useState( 0 );
	const activeSession = sessions[ activeIdx ];

	return (
		<Modal
			title={ `${ group.candidate_name || group.candidate_email || 'Candidate' } — chat transcript` }
			onRequestClose={ onClose }
			size="large"
		>
			{ sessions.length > 1 && (
				<div className="skillsaw-session-tabs">
					{ sessions.map( ( s, i ) => (
						<button
							key={ s.id }
							type="button"
							className={ `skillsaw-session-tab${ i === activeIdx ? ' skillsaw-session-tab--active' : '' }` }
							onClick={ () => setActiveIdx( i ) }
						>
							Session { i + 1 }
							<span className="skillsaw-pill skillsaw-pill--mode" style={ { marginLeft: 6 } }>
								{ s.mode === 'upload' ? 'Upload' : 'Critique' }
							</span>
						</button>
					) ) }
				</div>
			) }

			<SingleTranscript
				sessionId={ activeSession.id }
				candidateName={ group.candidate_name }
			/>
		</Modal>
	);
}

// ─── Candidate row ────────────────────────────────────────────────────────────

function ModeDisplay( { sessions } ) {
	const modes = sessions.map( ( s ) => s.mode );
	const counts = modes.reduce( ( acc, m ) => { acc[ m ] = ( acc[ m ] || 0 ) + 1; return acc; }, {} );
	return (
		<>
			{ Object.entries( counts ).map( ( [ mode, count ] ) => (
				<span key={ mode } className="skillsaw-pill skillsaw-pill--mode">
					{ mode === 'upload' ? 'Upload' : 'Critique' }
					{ count > 1 && <span style={ { marginLeft: 3, opacity: 0.75 } }>×{ count }</span> }
				</span>
			) ) }
		</>
	);
}

function CandidateRow( { group, onOpenTranscript, onDownloadPdf, onArchive, isArchivedView } ) {
	const initials = ( group.candidate_name || group.candidate_email || '?' )
		.split( ' ' )
		.map( ( n ) => n[ 0 ] )
		.join( '' )
		.slice( 0, 2 )
		.toUpperCase();

	const sessions     = group.sessions || [ group ];
	const duration     = fmtTotalDuration( sessions );
	const date         = fmtDate( group.started_at );
	const sessionCount = sessions.length;

	// For PDF download, use the primary (most recent) session.
	const primarySessionId = sessions[ sessions.length - 1 ].id;

	return (
		<div className="skillsaw-candidate-row">
			<div className="skillsaw-candidate-identity">
				<span className="skillsaw-avatar">{ initials }</span>
				<div>
					<div className="skillsaw-candidate-name">
						{ group.candidate_name || <em style={ { color: '#999' } }>No name</em> }
					</div>
					<div className="skillsaw-candidate-email">{ group.candidate_email }</div>
				</div>
			</div>

			<div className="skillsaw-candidate-role">
				<div>{ group.role_title }</div>
				{ group.team && <small>{ group.team }</small> }
			</div>

			<div className="skillsaw-candidate-skills">
				{ group.skill_ratings.map( ( r ) => (
					<SkillChip key={ r.skill_name } name={ r.skill_name } rating={ r.rating } />
				) ) }
				{ group.skill_ratings.length === 0 && (
					<span className="skillsaw-no-ratings">Ratings pending…</span>
				) }
			</div>

			<div className="skillsaw-candidate-date">
				{ date || '—' }
			</div>

			<div className="skillsaw-candidate-duration">
				{ duration || '—' }
				<ModeDisplay sessions={ sessions } />
				{ sessionCount > 1 && (
					<span
						className="skillsaw-pill"
						style={ { background: '#e8e8e8', color: '#555', marginLeft: 2 } }
						title={ `${ sessionCount } sessions combined` }
					>
						{ sessionCount } sessions
					</span>
				) }
			</div>

			<div className="skillsaw-candidate-actions">
				{ group.gh_push_error && (
					<span className="skillsaw-gh-error" title={ group.gh_push_error }>
						⚠ GH: { group.gh_push_error }
					</span>
				) }
				{ group.gh_pushed_at && ! group.gh_push_error && (
					<span className="skillsaw-gh-ok" title={ `Pushed ${ group.gh_pushed_at }` }>✓ Greenhouse</span>
				) }
				<div className="skillsaw-candidate-btns">
					<Button
						variant="primary"
						size="small"
						onClick={ () => onOpenTranscript( group ) }
					>
						{ sessionCount > 1 ? 'Transcripts' : 'Transcript' }
					</Button>
					<button
						className="skillsaw-pdf-btn"
						title="Download assessment PDF"
						onClick={ () => onDownloadPdf( primarySessionId ) }
					>
						↓ <span className="skillsaw-pdf-badge">PDF</span>
					</button>
				</div>
			</div>

			<div className="skillsaw-candidate-archive-col">
				<button
					className={ `skillsaw-archive-btn${ isArchivedView ? ' skillsaw-archive-btn--restore' : '' }` }
					title={ isArchivedView ? 'Restore candidate' : 'Archive candidate' }
					onClick={ () => onArchive( group, ! group.archived_at ) }
				>
					{ isArchivedView ? <RestoreIcon /> : <TrashIcon /> }
					<span>{ isArchivedView ? 'Restore' : 'Archive' }</span>
				</button>
			</div>
		</div>
	);
}

// ─── Main component ───────────────────────────────────────────────────────────

export default function CandidatesTab() {
	const [ sessions,      setSessions      ] = useState( [] );
	const [ roles,         setRoles         ] = useState( [] );
	const [ loading,       setLoading       ] = useState( true );
	const [ error,         setError         ] = useState( '' );
	const [ search,        setSearch        ] = useState( '' );
	const [ roleFilter,    setRoleFilter    ] = useState( 'all' );
	const [ modeFilter,    setModeFilter    ] = useState( 'all' );
	const [ archiveFilter, setArchiveFilter ] = useState( 'active' );
	const [ openGroup,     setOpenGroup     ] = useState( null );

	const handleDownloadPdf = async ( id ) => {
		try {
			const { filename, data } = await apiFetch( { path: `/skillsaw/v1/candidates/${ id }/pdf` } );
			const blob = new Blob( [ Uint8Array.from( atob( data ), c => c.charCodeAt( 0 ) ) ], { type: 'application/pdf' } );
			const url  = URL.createObjectURL( blob );
			const a    = document.createElement( 'a' );
			a.href = url; a.download = filename; a.click();
			URL.revokeObjectURL( url );
		} catch ( err ) {
			// eslint-disable-next-line no-alert
			window.alert( 'PDF generation failed: ' + err.message );
		}
	};

	/** Archive or unarchive all sessions belonging to a group. */
	const handleArchive = async ( group, archive ) => {
		const ids    = ( group.sessions || [ group ] ).map( ( s ) => s.id );
		const action = archive ? 'archive' : 'unarchive';
		try {
			await Promise.all(
				ids.map( ( id ) =>
					apiFetch( { path: `/skillsaw/v1/candidates/${ id }/${ action }`, method: 'POST' } )
				)
			);
			const ts = archive ? new Date().toISOString() : null;
			setSessions( ( prev ) =>
				prev.map( ( s ) => ids.includes( s.id ) ? { ...s, archived_at: ts } : s )
			);
		} catch ( err ) {
			// eslint-disable-next-line no-alert
			window.alert( `Failed to ${ action } candidate: ` + err.message );
		}
	};

	useEffect( () => {
		Promise.all( [
			apiFetch( { path: '/skillsaw/v1/candidates' } ),
			apiFetch( { path: '/skillsaw/v1/roles' } ),
		] )
			.then( ( [ s, r ] ) => { setSessions( s ); setRoles( r ); } )
			.catch( ( err ) => setError( err.message ) )
			.finally( () => setLoading( false ) );
	}, [] );

	// Derive grouped view from flat sessions state.
	const groups = groupSessions( sessions );

	// Filter on grouped objects.
	const filtered = groups.filter( ( g ) => {
		const groupSessions = g.sessions || [ g ];

		if ( archiveFilter === 'active'   && g.archived_at )  return false;
		if ( archiveFilter === 'archived' && ! g.archived_at ) return false;

		if ( search ) {
			const q = search.toLowerCase();
			if (
				! ( g.candidate_name  || '' ).toLowerCase().includes( q ) &&
				! ( g.candidate_email || '' ).toLowerCase().includes( q )
			) return false;
		}

		if ( roleFilter !== 'all' && String( g.role_id ) !== roleFilter ) return false;

		// Mode filter: include group if any of its sessions match.
		if ( modeFilter !== 'all' && ! groupSessions.some( ( s ) => s.mode === modeFilter ) ) return false;

		return true;
	} );

	const totalVisible = groups.filter( ( g ) =>
		archiveFilter === 'all' ||
		( archiveFilter === 'active' ? ! g.archived_at : g.archived_at )
	).length;

	const isArchivedView = archiveFilter === 'archived';

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
				<select
					className="skillsaw-filter-select"
					value={ archiveFilter }
					onChange={ ( e ) => setArchiveFilter( e.target.value ) }
				>
					<option value="active">Active</option>
					<option value="archived">Archived</option>
					<option value="all">All candidates</option>
				</select>
			</div>

			<div className="skillsaw-results-meta">
				<span>
					<strong>{ filtered.length }</strong> of { totalVisible } candidates
					{ isArchivedView && <span className="skillsaw-archived-badge"> — archived</span> }
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
						<span></span>
					</div>
				) }
				{ filtered.length === 0 && (
					<p className="skillsaw-empty">
						{ sessions.length === 0
							? 'No completed sessions yet.'
							: 'No candidates match the current filters.' }
					</p>
				) }
				{ filtered.map( ( group ) => (
					<CandidateRow
						key={ group.id }
						group={ group }
						onOpenTranscript={ setOpenGroup }
						onDownloadPdf={ handleDownloadPdf }
						onArchive={ handleArchive }
						isArchivedView={ isArchivedView }
					/>
				) ) }
			</div>

			{ openGroup && (
				<TranscriptModal
					group={ openGroup }
					onClose={ () => setOpenGroup( null ) }
				/>
			) }
		</div>
	);
}
