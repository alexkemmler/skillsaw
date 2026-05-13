import { useState, useEffect } from '@wordpress/element';
import { Button, TextControl, TextareaControl, SelectControl, Notice, Spinner, Modal } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

const STATUS_OPTIONS = [
	{ label: 'Draft', value: 'draft' },
	{ label: 'Active', value: 'active' },
	{ label: 'Inactive', value: 'inactive' },
];

function SkillChips( { skills, onRemove } ) {
	return (
		<div className="skillsaw-chips">
			{ skills.map( ( skill ) => (
				<span key={ skill } className="skillsaw-chip">
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

function DocumentRow( { doc, role, onCritiqueGenerated, onDelete } ) {
	const [ generating,      setGenerating      ] = useState( false );
	const [ error,           setError           ] = useState( '' );
	const [ critiqueExpanded, setCritiqueExpanded ] = useState( false );

	const handleGenerateCritique = async () => {
		setGenerating( true );
		setError( '' );
		try {
			const result = await apiFetch( {
				path: `/skillsaw/v1/roles/${ role.id }/documents/${ doc.id }/generate-critique`,
				method: 'POST',
			} );
			onCritiqueGenerated( doc.id, result );
		} catch ( err ) {
			setError( err.message || 'Failed to generate critique.' );
		} finally {
			setGenerating( false );
		}
	};

	return (
		<>
			<div className="skillsaw-doc-row">
				<span className="skillsaw-doc-type">{ doc.type.toUpperCase() }</span>
				<span className="skillsaw-doc-name">{ doc.name }</span>
				<SkillChips skills={ doc.skills } />
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
					{ ! doc.critique && (
						<Button
							variant="secondary"
							size="small"
							onClick={ handleGenerateCritique }
							disabled={ generating }
						>
							{ generating ? <Spinner /> : 'Generate critique' }
						</Button>
					) }
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
			{ error && <p className="skillsaw-error">{ error }</p> }
			{ doc.critique && (
				<>
					<div className="skillsaw-doc-row skillsaw-doc-row--critique">
						<span className="skillsaw-pill skillsaw-pill--critique">Critique</span>
						<span className="skillsaw-doc-name">{ doc.critique.name }</span>
						<SkillChips skills={ doc.critique.skills } />
						<div className="skillsaw-doc-actions">
							<Button
								variant="tertiary"
								size="small"
								onClick={ () => setCritiqueExpanded( ( v ) => ! v ) }
							>
								{ critiqueExpanded ? 'Hide' : 'View critique' }
							</Button>
						</div>
					</div>
					{ critiqueExpanded && (
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
	const [ newSkill, setNewSkill ] = useState( '' );
	const [ saving, setSaving ]     = useState( false );
	const [ error, setError ]       = useState( '' );
	const [ uploading, setUploading ] = useState( false );

	const set = ( key ) => ( val ) => setForm( ( f ) => ( { ...f, [ key ]: val } ) );

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
				<SkillChips skills={ form.skills } onRemove={ removeSkill } />
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
						onCritiqueGenerated={ handleCritiqueGenerated }
						onDelete={ handleDeleteDoc }
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
				<Button
					variant="tertiary"
					size="small"
					onClick={ ( e ) => { e.stopPropagation(); onDuplicate( role.id ); } }
				>
					Duplicate
				</Button>
				<Button
					variant="tertiary"
					size="small"
					isDestructive
					onClick={ ( e ) => { e.stopPropagation(); onDelete( role.id ); } }
				>
					Delete
				</Button>
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
