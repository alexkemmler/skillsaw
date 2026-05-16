import { useState, useEffect, useRef } from '@wordpress/element';
import { Button, TextControl, TextareaControl, SelectControl, Notice, Spinner, Modal } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

const STATUS_OPTIONS = [
	{ label: 'Draft', value: 'draft' },
	{ label: 'Active', value: 'active' },
	{ label: 'Inactive', value: 'inactive' },
];

function SkillChips( { skills, onRemove, unassigned = [] } ) {
	return (
		<div className="skillsaw-chips">
			{ skills.map( ( skill ) => (
				<span
					key={ skill }
					className={ `skillsaw-chip ${ unassigned.includes( skill ) ? 'skillsaw-chip--unassigned' : '' }` }
				>
					{ skill }
					{ onRemove && (
						<button
							className="skillsaw-chip-remove"
							onClick={ () => onRemove( skill ) }
							aria-label={ `Remove ${ skill }` }
						>
							&times;
						</button>
					) }
				</span>
			) ) }
		</div>
	);
}

function StatusToggle( { role, onChange } ) {
	// Show a static pill only while the role has no skills configured yet.
	if ( ! role.botConfigured ) {
		return <span className="skillsaw-pill skillsaw-pill--draft">Draft</span>;
	}

	// Once configured, show the toggle — treat 'draft' the same as 'inactive'.
	const active = role.status === 'active';

	return (
		<button
			className={ `skillsaw-toggle ${ active ? 'skillsaw-toggle--on' : '' }` }
			onClick={ ( e ) => {
				e.stopPropagation();
				onChange( { ...role, status: active ? 'inactive' : 'active' } );
			} }
			aria-label={ active ? 'Deactivate role' : 'Activate role' }
		>
			<span className="skillsaw-toggle-thumb" />
			<span className="skillsaw-toggle-label">{ active ? 'Active' : 'Inactive' }</span>
		</button>
	);
}

function CopyEmbedButton( { role } ) {
	const [ copied, setCopied ] = useState( false );
	const configured = !! ( role.skills?.length );

	const handleCopy = () => {
		const code = `[skillsaw role="${ role.id }"]`;
		navigator.clipboard.writeText( code ).then( () => {
			setCopied( true );
			setTimeout( () => setCopied( false ), 1400 );
		} );
	};

	return (
		<button
			className={ `skillsaw-embed-btn ${ copied ? 'skillsaw-embed-btn--copied' : '' }` }
			onClick={ handleCopy }
			disabled={ ! configured }
			title={ configured ? 'Copy shortcode' : 'Configure the role before embedding' }
		>
			{ copied ? '✓ Copied' : 'Copy embed' }
		</button>
	);
}

function DocSkillCheckboxes( { docId, roleId, currentSkills, allSkills, onChange } ) {
	// Empty currentSkills = legacy "all skills" — show all checked.
	const [ selected, setSelected ] = useState(
		currentSkills && currentSkills.length > 0 ? currentSkills : [ ...allSkills ]
	);

	const toggle = async ( skill ) => {
		const next = selected.includes( skill )
			? selected.filter( ( s ) => s !== skill )
			: [ ...selected, skill ];
		setSelected( next );
		onChange( next );
		try {
			await apiFetch( {
				path:   `/skillsaw/v1/roles/${ roleId }/documents/${ docId }`,
				method: 'PUT',
				data:   { skills: next },
			} );
		} catch {}
	};

	if ( ! allSkills.length ) return null;

	return (
		<div className="skillsaw-doc-skill-checkboxes">
			{ allSkills.map( ( skill ) => (
				<label key={ skill } className="skillsaw-skill-checkbox-label">
					<input
						type="checkbox"
						checked={ selected.includes( skill ) }
						onChange={ () => toggle( skill ) }
					/>
					{ skill }
				</label>
			) ) }
		</div>
	);
}

function DocumentRow( { doc, role, allSkills, onCritiqueUploaded, onDelete, onSkillsChange, onCritiqueSkillsChange } ) {
	const [ suggesting,        setSuggesting        ] = useState( false );
	const [ suggestions,       setSuggestions       ] = useState( '' );
	const [ showSuggestions,   setShowSuggestions   ] = useState( false );
	const [ uploadingCritique, setUploadingCritique ] = useState( false );
	const [ error,             setError             ] = useState( '' );
	const [ critiqueExpanded,  setCritiqueExpanded  ] = useState( false );
	const critiqueInputRef = useRef( null );

	const handleSuggestMistakes = async () => {
		setSuggesting( true );
		setError( '' );
		try {
			const result = await apiFetch( {
				path:   `/skillsaw/v1/roles/${ role.id }/documents/${ doc.id }/suggest-mistakes`,
				method: 'POST',
			} );
			setSuggestions( result.suggestions );
			setShowSuggestions( true );
		} catch ( err ) {
			setError( err.message || 'Failed to generate suggestions.' );
		} finally {
			setSuggesting( false );
		}
	};

	const handleCritiqueUpload = async ( e ) => {
		const file = e.target.files?.[ 0 ];
		if ( ! file ) return;
		setUploadingCritique( true );
		setError( '' );
		const formData = new FormData();
		formData.append( 'file', file );
		try {
			const result = await apiFetch( {
				path:   `/skillsaw/v1/roles/${ role.id }/documents/${ doc.id }/critique-upload`,
				method: 'POST',
				body:   formData,
			} );
			onCritiqueUploaded( doc.id, result );
		} catch ( err ) {
			setError( err.message || 'Upload failed.' );
		} finally {
			setUploadingCritique( false );
			if ( critiqueInputRef.current ) critiqueInputRef.current.value = '';
		}
	};

	return (
		<>
			<div className="skillsaw-doc-row">
				<span className="skillsaw-doc-type">{ doc.type.toUpperCase() }</span>
				<span className="skillsaw-doc-name">{ doc.name }</span>
				<div className="skillsaw-doc-actions">
					{ doc.url && (
						<a
							href={ doc.url }
							target="_blank"
							rel="noreferrer"
							className="skillsaw-doc-view-link"
						>
							View ↗
						</a>
					) }
					<Button
						variant="secondary"
						size="small"
						onClick={ handleSuggestMistakes }
						disabled={ suggesting }
					>
						{ suggesting ? <Spinner /> : 'Suggest mistakes' }
					</Button>
					<label className="skillsaw-critique-upload-label">
						{ uploadingCritique ? <Spinner /> : ( doc.critique ? 'Replace critique doc' : 'Upload critique doc' ) }
						<input
							ref={ critiqueInputRef }
							type="file"
							accept=".pdf,.docx,.txt,.md"
							className="skillsaw-hidden-file-input"
							onChange={ handleCritiqueUpload }
							disabled={ uploadingCritique }
						/>
					</label>
					<Button
						variant="tertiary"
						size="small"
						isDestructive
						onClick={ () => onDelete( doc.id ) }
					>
						Delete
					</Button>
				</div>
			</div>

			{ allSkills.length > 0 && (
				<div className="skillsaw-doc-skills-row">
					<span className="skillsaw-doc-skills-label">Skills evaluated:</span>
					<DocSkillCheckboxes
						docId={ doc.id }
						roleId={ role.id }
						currentSkills={ doc.skills }
						allSkills={ allSkills }
						onChange={ ( skills ) => onSkillsChange( doc.id, skills ) }
					/>
				</div>
			) }

			{ error && <p className="skillsaw-error">{ error }</p> }

			{ showSuggestions && (
				<Modal
					title={ `Suggested weaknesses — ${ doc.name }` }
					onRequestClose={ () => setShowSuggestions( false ) }
					size="medium"
				>
					<div className="skillsaw-suggestions-body">
						<p className="skillsaw-suggestions-intro">
							Edit your document to introduce some of these weaknesses, then upload it as the critique document.
						</p>
						<pre className="skillsaw-suggestions-text">{ suggestions }</pre>
					</div>
					<div className="skillsaw-suggestions-footer">
						<Button
							variant="secondary"
							onClick={ () => {
								navigator.clipboard?.writeText( suggestions );
							} }
						>
							Copy to clipboard
						</Button>
						<Button variant="primary" onClick={ () => setShowSuggestions( false ) }>
							Close
						</Button>
					</div>
				</Modal>
			) }

			{ doc.critique && (
				<>
					<div className="skillsaw-doc-row skillsaw-doc-row--critique">
						<span className="skillsaw-pill skillsaw-pill--critique">Critique</span>
						<span className="skillsaw-doc-name">{ doc.critique.name }</span>
						<div className="skillsaw-doc-actions">
							{ doc.critique.url ? (
								<a
									href={ doc.critique.url }
									target="_blank"
									rel="noreferrer"
									className="skillsaw-doc-view-link"
								>
									View ↗
								</a>
							) : (
								<Button
									variant="tertiary"
									size="small"
									onClick={ () => setCritiqueExpanded( ( v ) => ! v ) }
								>
									{ critiqueExpanded ? 'Hide' : 'View text' }
								</Button>
							) }
						</div>
					</div>

					{ allSkills.length > 0 && (
						<div className="skillsaw-doc-skills-row skillsaw-doc-skills-row--critique">
							<span className="skillsaw-doc-skills-label">Skills evaluated:</span>
							<DocSkillCheckboxes
								docId={ doc.critique.id }
								roleId={ role.id }
								currentSkills={ doc.critique.skills }
								allSkills={ allSkills }
								onChange={ ( skills ) => onCritiqueSkillsChange( doc.id, skills ) }
							/>
						</div>
					) }

					{ critiqueExpanded && ! doc.critique.url && (
						<div className="skillsaw-critique-preview">
							{ doc.critique.critique_text
								? <pre className="skillsaw-critique-text">{ doc.critique.critique_text }</pre>
								: <p className="skillsaw-error">Critique text not available.</p>
							}
						</div>
					) }
				</>
			) }
		</>
	);
}

function RoleConfig( { role, onSave, onClose } ) {
	const [ form, setForm ] = useState( {
		title:             role.title,
		division:          role.division,
		team:              role.team,
		candidate_note:    role.candidate_note || '',
		instructions:      role.instructions,
		greenhouse_job_id: role.greenhouse_job_id || '',
		skills:            [ ...( role.skills || [] ) ],
		documents:         [ ...( role.documents || [] ) ],
	} );
	const [ newSkill,  setNewSkill  ] = useState( '' );
	const [ saving,    setSaving    ] = useState( false );
	const [ error,     setError     ] = useState( '' );
	const [ uploading, setUploading ] = useState( false );

	const set = ( key ) => ( val ) => setForm( ( f ) => ( { ...f, [ key ]: val } ) );

	// Compute which skills have no reference document assigned.
	const assignedByRefDocs = new Set();
	form.documents.forEach( ( doc ) => {
		const docSkills = doc.skills && doc.skills.length > 0 ? doc.skills : form.skills;
		docSkills.forEach( ( s ) => assignedByRefDocs.add( s ) );
	} );
	const unassignedSkills = form.skills.filter( ( s ) => ! assignedByRefDocs.has( s ) );

	const addSkill = () => {
		const trimmed = newSkill.trim();
		if ( trimmed && ! form.skills.includes( trimmed ) ) {
			setForm( ( f ) => ( { ...f, skills: [ ...f.skills, trimmed ] } ) );
		}
		setNewSkill( '' );
	};

	const removeSkill = ( skill ) => {
		setForm( ( f ) => ( { ...f, skills: f.skills.filter( ( s ) => s !== skill ) } ) );
	};

	const handleDocSkillsChange = ( docId, skills ) => {
		setForm( ( f ) => ( {
			...f,
			documents: f.documents.map( ( d ) =>
				d.id === docId ? { ...d, skills } : d
			),
		} ) );
	};

	const handleCritiqueSkillsChange = ( docId, skills ) => {
		setForm( ( f ) => ( {
			...f,
			documents: f.documents.map( ( d ) =>
				d.id === docId
					? { ...d, critique: d.critique ? { ...d.critique, skills } : d.critique }
					: d
			),
		} ) );
	};

	const handleSave = async () => {
		setSaving( true );
		setError( '' );
		try {
			const updated = await apiFetch( {
				path:   `/skillsaw/v1/roles/${ role.id }`,
				method: 'PUT',
				data:   {
					title:             form.title,
					division:          form.division,
					team:              form.team,
					status:            form.skills.length > 0 && role.status === 'draft' ? 'active' : undefined,
					candidate_note:    form.candidate_note,
					instructions:      form.instructions,
					greenhouse_job_id: form.greenhouse_job_id,
					skills:            form.skills,
				},
			} );
			onSave( updated );
		} catch ( err ) {
			setError( err.message || 'Failed to save.' );
		} finally {
			setSaving( false );
		}
	};

	const handleUpload = async ( e ) => {
		const file = e.target.files[ 0 ];
		if ( ! file ) return;

		if ( file.size > 25 * 1024 * 1024 ) {
			setError( 'File exceeds 25 MB limit.' );
			return;
		}

		setUploading( true );
		setError( '' );

		const body = new FormData();
		body.append( 'file', file );

		try {
			const doc = await apiFetch( {
				path:   `/skillsaw/v1/roles/${ role.id }/documents`,
				method: 'POST',
				body,
			} );
			setForm( ( f ) => ( { ...f, documents: [ ...f.documents, doc ] } ) );
		} catch ( err ) {
			setError( err.message || 'Upload failed.' );
		} finally {
			setUploading( false );
		}
	};

	const handleDeleteDoc = async ( docId ) => {
		try {
			await apiFetch( {
				path:   `/skillsaw/v1/roles/${ role.id }/documents/${ docId }`,
				method: 'DELETE',
			} );
			setForm( ( f ) => ( { ...f, documents: f.documents.filter( ( d ) => d.id !== docId ) } ) );
		} catch ( err ) {
			setError( err.message || 'Delete failed.' );
		}
	};

	const handleCritiqueGenerated = ( docId, critique ) => {
		setForm( ( f ) => ( {
			...f,
			documents: f.documents.map( ( d ) =>
				d.id === docId ? { ...d, critique } : d
			),
		} ) );
	};

	return (
		<div className="skillsaw-role-config">
			{ error && (
				<Notice status="error" isDismissible={ false }>{ error }</Notice>
			) }

			<div className="skillsaw-config-section">
				<h4>Role details</h4>
				<TextControl label="Title" value={ form.title } onChange={ set( 'title' ) } />
				<div className="skillsaw-two-col">
					<TextControl label="Division" value={ form.division } onChange={ set( 'division' ) } />
					<TextControl label="Team" value={ form.team } onChange={ set( 'team' ) } />
				</div>
				<TextControl
					label="Greenhouse Job ID"
					value={ form.greenhouse_job_id }
					onChange={ set( 'greenhouse_job_id' ) }
					placeholder="e.g. 4567890"
					help="Numeric job ID from Greenhouse. Candidates will be linked to this job when their session is evaluated."
				/>
			</div>

			<div className="skillsaw-config-section">
				<h4>Skills evaluated</h4>
				<SkillChips
					skills={ form.skills }
					onRemove={ removeSkill }
					unassigned={ form.documents.length > 0 ? unassignedSkills : [] }
				/>
				{ form.documents.length > 0 && unassignedSkills.length > 0 && (
					<p className="skillsaw-unassigned-notice">
						Skills highlighted in red have no reference document assigned. The chatbot will attempt to evaluate these using any available document. It is recommended to assign a reference document for each skill.
					</p>
				) }
				<div className="skillsaw-add-skill">
					<TextControl
						placeholder="Add a skill…"
						value={ newSkill }
						onChange={ setNewSkill }
						onKeyDown={ ( e ) => e.key === 'Enter' && addSkill() }
					/>
					<Button variant="secondary" onClick={ addSkill } disabled={ ! newSkill.trim() }>
						Add
					</Button>
				</div>
			</div>

			<div className="skillsaw-config-section">
				<h4>Reference documents</h4>
				{ form.documents.map( ( doc ) => (
					<DocumentRow
						key={ doc.id }
						doc={ doc }
						role={ role }
						allSkills={ form.skills }
						onCritiqueUploaded={ handleCritiqueGenerated }
						onDelete={ handleDeleteDoc }
						onSkillsChange={ handleDocSkillsChange }
						onCritiqueSkillsChange={ handleCritiqueSkillsChange }
					/>
				) ) }
				<div className="skillsaw-upload-area">
					<label className="skillsaw-upload-label">
						{ uploading ? <Spinner /> : '+ Upload document' }
						<input
							type="file"
							accept=".pdf,.doc,.docx,.txt,.md"
							onChange={ handleUpload }
							disabled={ uploading }
							style={ { display: 'none' } }
						/>
					</label>
				</div>
			</div>

			<div className="skillsaw-config-section">
				<h4>Note to the candidate</h4>
				<TextareaControl
					label=""
					value={ form.candidate_note }
					onChange={ set( 'candidate_note' ) }
					placeholder="Shown to the candidate in the chat alongside the critique document — e.g. 'Focus on the strategic recommendations in section 2.'"
					rows={ 4 }
				/>
			</div>

			<div className="skillsaw-config-section">
				<h4>Additional instructions</h4>
				<TextareaControl
					label=""
					value={ form.instructions }
					onChange={ set( 'instructions' ) }
					placeholder="Private guidance for the bot — never shown to the candidate. E.g. 'Push back if the candidate hand-waves testing strategy.'"
					rows={ 4 }
				/>
			</div>

			<div className="skillsaw-config-footer">
				<Button variant="primary" onClick={ handleSave } disabled={ saving }>
					{ saving ? <Spinner /> : 'Save chatbot settings' }
				</Button>
				<Button variant="tertiary" onClick={ onClose }>
					Cancel
				</Button>
			</div>
		</div>
	);
}

function RoleRow( { role, expanded, onToggle, onUpdate, onDelete, onDuplicate } ) {
	const configured = !! ( role.skills?.length );

	const handleStatusChange = async ( updatedRole ) => {
		try {
			const result = await apiFetch( {
				path:   `/skillsaw/v1/roles/${ role.id }`,
				method: 'PUT',
				data:   { status: updatedRole.status },
			} );
			onUpdate( result );
		} catch {}
	};

	return (
		<div className={ `skillsaw-role-row ${ expanded ? 'skillsaw-role-row--expanded' : '' }` }>
			<div className="skillsaw-role-row-header" onClick={ onToggle }>
				<span className="skillsaw-chevron">{ expanded ? '▾' : '▸' }</span>
				<span className="skillsaw-role-title">
					{ role.title }
					<small className="skillsaw-role-id">ID { role.id }</small>
				</span>
				<span className="skillsaw-role-team">{ role.team || '—' }</span>
				<span className="skillsaw-role-division">{ role.division || '—' }</span>
				<StatusToggle role={ { ...role, botConfigured: configured } } onChange={ handleStatusChange } />
				<span className="skillsaw-role-bot">
					{ configured
						? <span className="skillsaw-ok">✓ Configured</span>
						: <span className="skillsaw-err">✗ Not configured</span>
					}
				</span>
				<span className="skillsaw-role-applicants">{ role.applicants ?? 0 }</span>
				<CopyEmbedButton role={ role } />
				<div className="skillsaw-role-actions" onClick={ ( e ) => e.stopPropagation() }>
					<Button
						variant="tertiary"
						size="small"
						onClick={ () => onDuplicate( role.id ) }
					>
						Duplicate
					</Button>
					<Button
						variant="tertiary"
						size="small"
						isDestructive
						onClick={ () => onDelete( role.id ) }
					>
						Delete
					</Button>
				</div>
			</div>
			{ expanded && (
				<RoleConfig
					role={ role }
					onSave={ onUpdate }
					onClose={ onToggle }
				/>
			) }
		</div>
	);
}

export default function RolesTab() {
	const [ roles, setRoles ]         = useState( [] );
	const [ loading, setLoading ]     = useState( true );
	const [ error, setError ]         = useState( '' );
	const [ expandedId, setExpanded ] = useState( null );
	const [ creating, setCreating ]   = useState( false );
	const [ newTitle, setNewTitle ]   = useState( '' );
	const [ search, setSearch ]       = useState( '' );

	useEffect( () => {
		apiFetch( { path: '/skillsaw/v1/roles' } )
			.then( setRoles )
			.catch( ( err ) => setError( err.message ) )
			.finally( () => setLoading( false ) );
	}, [] );

	const handleCreate = async () => {
		if ( ! newTitle.trim() ) return;
		setCreating( true );
		try {
			const role = await apiFetch( {
				path:   '/skillsaw/v1/roles',
				method: 'POST',
				data:   { title: newTitle.trim() },
			} );
			setRoles( ( rs ) => [ role, ...rs ] );
			setExpanded( role.id );
			setNewTitle( '' );
		} catch ( err ) {
			setError( err.message );
		} finally {
			setCreating( false );
		}
	};

	const handleUpdate = ( updated ) => {
		setRoles( ( rs ) => rs.map( ( r ) => r.id === updated.id ? updated : r ) );
	};

	const handleDelete = async ( id ) => {
		if ( ! window.confirm( 'Delete this role? This cannot be undone.' ) ) return;
		try {
			await apiFetch( { path: `/skillsaw/v1/roles/${ id }`, method: 'DELETE' } );
			setRoles( ( rs ) => rs.filter( ( r ) => r.id !== id ) );
			if ( expandedId === id ) setExpanded( null );
		} catch ( err ) {
			setError( err.message );
		}
	};

	const handleDuplicate = async ( id ) => {
		try {
			const copy = await apiFetch( {
				path:   `/skillsaw/v1/roles/${ id }/duplicate`,
				method: 'POST',
			} );
			setRoles( ( rs ) => [ copy, ...rs ] );
			setExpanded( copy.id );
		} catch ( err ) {
			setError( err.message );
		}
	};

	const filtered = roles.filter( ( r ) =>
		! search || r.title.toLowerCase().includes( search.toLowerCase() )
	);

	if ( loading ) return <Spinner />;

	return (
		<div className="skillsaw-roles-tab">
			{ error && <Notice status="error" isDismissible onRemove={ () => setError( '' ) }>{ error }</Notice> }

			<div className="skillsaw-filter-bar">
				<input
					type="search"
					placeholder="Search roles…"
					value={ search }
					onChange={ ( e ) => setSearch( e.target.value ) }
					className="skillsaw-search"
				/>
				<div className="skillsaw-add-role">
					<TextControl
						placeholder="New role title…"
						value={ newTitle }
						onChange={ setNewTitle }
						onKeyDown={ ( e ) => e.key === 'Enter' && handleCreate() }
					/>
					<Button variant="primary" onClick={ handleCreate } disabled={ creating || ! newTitle.trim() }>
						{ creating ? <Spinner /> : 'Add new role' }
					</Button>
				</div>
			</div>

			<div className="skillsaw-roles-list">
				{ filtered.length > 0 && (
					<div className="skillsaw-list-head skillsaw-roles-list-head" aria-hidden="true">
						<span />
						<span>Role</span>
						<span>Team</span>
						<span>Division</span>
						<span>Status</span>
						<span>Bot</span>
						<span>Apps</span>
						<span>Embed</span>
						<span />
					</div>
				) }
				{ filtered.length === 0 && (
					<p className="skillsaw-empty">No roles yet. Add one above.</p>
				) }
				{ filtered.map( ( role ) => (
					<RoleRow
						key={ role.id }
						role={ role }
						expanded={ expandedId === role.id }
						onToggle={ () => setExpanded( expandedId === role.id ? null : role.id ) }
						onUpdate={ handleUpdate }
						onDelete={ handleDelete }
						onDuplicate={ handleDuplicate }
					/>
				) ) }
			</div>
		</div>
	);
}
