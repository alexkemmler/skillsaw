import React, { useState, useEffect, useRef, useCallback } from 'react';
import ReactMarkdown from 'react-markdown';
import { useDropzone } from 'react-dropzone';

const API_TIMEOUT = 90000;

async function apiFetch( url, options, nonce ) {
	const headers = { 'X-WP-Nonce': nonce, ...( options.headers || {} ) };
	const res = await fetch( url, { ...options, headers, signal: AbortSignal.timeout( API_TIMEOUT ) } );
	const data = await res.json();
	if ( ! res.ok ) {
		const err = new Error( data?.message || 'Request failed.' );
		err.code   = data?.code;
		err.status = res.status;
		throw err;
	}
	return data;
}

// ─── Small reusable pieces ────────────────────────────────────────────────────

function BotAvatar() {
	return <span className="sw-avatar sw-avatar--bot" aria-hidden="true">S</span>;
}

function UserAvatar() {
	return <span className="sw-avatar sw-avatar--user" aria-hidden="true">✦</span>;
}

function MsgRow( { who, children } ) {
	return (
		<div className={ `sw-msg-row${ who === 'user' ? ' sw-msg-row--user' : '' }` }>
			{ who === 'bot' && <BotAvatar /> }
			{ children }
			{ who === 'user' && <UserAvatar /> }
		</div>
	);
}

function PdfIcon( { large = false } ) {
	return large
		? <span className="sw-pdf-ico-lg">PDF</span>
		: <span className="sw-pdf-ico">PDF</span>;
}

function TypingIndicator() {
	return (
		<MsgRow who="bot">
			<div className="sw-typing">
				<span /><span /><span />
			</div>
		</MsgRow>
	);
}

// ─── Skill picker (interactive chips inside a bot message) ────────────────────

function SkillPickerMessage( { roleSkills, onConfirm } ) {
	const selectOptions = [ ...roleSkills, 'Other' ];
	const [ selected,    setSelected    ] = useState( new Set() );
	const [ otherText,   setOtherText   ] = useState( '' );
	const [ submitted,   setSubmitted   ] = useState( false );

	function toggle( skill ) {
		if ( submitted ) return;
		setSelected( ( prev ) => {
			const next = new Set( prev );
			next.has( skill ) ? next.delete( skill ) : next.add( skill );
			return next;
		} );
	}

	function confirm() {
		if ( submitted || selected.size === 0 ) return;
		const skills = [ ...selected ].filter( ( s ) => s !== 'Other' );
		if ( selected.has( 'Other' ) && otherText.trim() ) {
			skills.push( ...otherText.trim().split( ',' ).map( ( s ) => s.trim() ).filter( Boolean ) );
		}
		setSubmitted( true );
		onConfirm( skills );
	}

	return (
		<MsgRow who="bot">
			<div className="sw-bubble sw-bubble--bot">
				<p>Thanks, I can see this document.</p>
				<p>Select the skills you feel are most relevant:</p>
				<div className="sw-chips">
					{ selectOptions.map( ( skill ) => (
						<button
							key={ skill }
							type="button"
							className={
								`sw-chip sw-chip--interactive` +
								( selected.has( skill ) ? ' sw-chip--selected' : '' ) +
								( submitted ? ' sw-chip--disabled' : '' )
							}
							onClick={ () => toggle( skill ) }
							disabled={ submitted }
						>
							{ skill }
						</button>
					) ) }
				</div>

				{ selected.has( 'Other' ) && ! submitted && (
					<div className="sw-other-skill-input">
						<input
							type="text"
							placeholder="Name the other skills, comma-separated"
							value={ otherText }
							onChange={ ( e ) => setOtherText( e.target.value ) }
							onKeyDown={ ( e ) => { if ( e.key === 'Enter' ) confirm(); } }
						/>
					</div>
				) }

				{ ! submitted && selected.size > 0 && (
					<button
						type="button"
						className="sw-confirm-skills-btn"
						onClick={ confirm }
					>
						Confirm skills
					</button>
				) }
			</div>
		</MsgRow>
	);
}

// ─── Document critique card ───────────────────────────────────────────────────

function CritiqueDocCard( { docName, critiqueText, instructions } ) {
	const [ expanded, setExpanded ] = useState( false );

	const handleDownload = () => {
		const blob = new Blob( [ critiqueText ], { type: 'text/plain' } );
		const url  = URL.createObjectURL( blob );
		const a    = document.createElement( 'a' );
		a.href     = url;
		a.download = ( docName || 'document' ) + '.txt';
		a.click();
		URL.revokeObjectURL( url );
	};

	return (
		<MsgRow who="bot">
			<div className="sw-bubble sw-bubble--bot" style={ { maxWidth: '82%' } }>
				<p>Here's a document to review. Take your time reading it, then share your thoughts.</p>
				<div className="sw-doc-card">
					<div className="sw-doc-card-head">
						<PdfIcon large />
						<div className="sw-doc-meta">
							<p className="sw-doc-title">{ docName }</p>
						</div>
					</div>
					<div className="sw-doc-card-actions">
						<button
							type="button"
							className="sw-doc-action-btn"
							onClick={ () => setExpanded( ( v ) => ! v ) }
						>
							{ expanded ? 'Collapse' : 'Read document' }
						</button>
						{ critiqueText && (
							<button
								type="button"
								className="sw-doc-action-btn"
								onClick={ handleDownload }
							>
								Download .txt
							</button>
						) }
					</div>
					{ expanded && critiqueText && (
						<div className="sw-doc-full-text">
							<pre>{ critiqueText }</pre>
						</div>
					) }
					{ instructions && (
						<div className="sw-manager-note">
							<span className="sw-manager-note-label">A note from the hiring manager</span>
							{ instructions }
						</div>
					) }
				</div>
			</div>
		</MsgRow>
	);
}

// ─── Opening template message (shown before session starts) ───────────────────

function OpeningMessage( { roleTitle, roleSkills, hasCritique, onChoose, disabled } ) {
	return (
		<MsgRow who="bot">
			<div className="sw-bubble sw-bubble--bot">
				<p>
					Hi — welcome. I'm Skillsaw, here to help you show what you can do
					{ roleTitle ? <> for the <strong>{ roleTitle }</strong> role</> : '' }.
				</p>
				{ roleSkills.length > 0 && (
					<>
						<p>For this role, we are prioritising the following skills:</p>
						<div className="sw-chips">
							{ roleSkills.map( ( skill ) => (
								<span key={ skill } className="sw-chip">{ skill }</span>
							) ) }
						</div>
					</>
				) }
				<p style={ { marginTop: 14 } }>
					You can upload your work that demonstrates these skills
					{ hasCritique ? ', or I can give you a document to critique.' : '.' }
				</p>
				<div className="sw-actions">
					<button
						type="button"
						className="sw-action-btn"
						onClick={ () => onChoose( 'upload' ) }
						disabled={ disabled }
					>
						Upload work sample
					</button>
					{ hasCritique && (
						<button
							type="button"
							className="sw-action-btn"
							onClick={ () => onChoose( 'critique' ) }
							disabled={ disabled }
						>
							Critique a document
						</button>
					) }
				</div>
			</div>
		</MsgRow>
	);
}

// ─── Main component ───────────────────────────────────────────────────────────

export default function ChatPanel( { roleId, active, hasCritique, roleTitle, roleSkills, nonce, rootUrl } ) {
	const base = rootUrl.replace( /\/$/, '' ) + '/skillsaw/v1';

	// phase: 'idle' | 'starting' | 'chatting' | 'complete' | 'expired' | 'error'
	const [ phase,        setPhase        ] = useState( 'idle' );
	const [ mode,         setMode         ] = useState( null );
	const [ sessionToken, setSessionToken ] = useState( null );
	const [ messages,     setMessages     ] = useState( [] );
	const [ input,        setInput        ] = useState( '' );
	const [ isSending,    setIsSending    ] = useState( false );
	const [ isUploading,  setIsUploading  ] = useState( false );
	const [ chosenMode,   setChosenMode   ] = useState( null ); // tracks which button was pressed
	const [ errorMsg,     setErrorMsg     ] = useState( null );

	// pendingSkillPicker: { messageId } — non-null while waiting for skill selection
	const [ pendingSkillPicker, setPendingSkillPicker ] = useState( null );

	const scrollRef   = useRef( null );
	const textareaRef = useRef( null );
	const fileInputRef = useRef( null );

	// ── Scroll to bottom ──────────────────────────────────────────────────────
	useEffect( () => {
		scrollRef.current?.scrollTo( { top: scrollRef.current.scrollHeight, behavior: 'smooth' } );
	}, [ messages, isSending, isUploading, pendingSkillPicker ] );

	// ── Auto-resize textarea ──────────────────────────────────────────────────
	useEffect( () => {
		if ( textareaRef.current ) {
			textareaRef.current.style.height = 'auto';
			textareaRef.current.style.height = textareaRef.current.scrollHeight + 'px';
		}
	}, [ input ] );

	// ── Form hook (Greenhouse / similar) ─────────────────────────────────────
	useEffect( () => {
		if ( ! sessionToken ) return;
		const form = document.getElementById( 'application_form' );
		if ( ! form ) return;

		let hidden = form.querySelector( 'input[name="skillsaw_session_token"]' );
		if ( ! hidden ) {
			hidden = document.createElement( 'input' );
			hidden.type = 'hidden';
			hidden.name = 'skillsaw_session_token';
			form.appendChild( hidden );
		}
		hidden.value = sessionToken;

		const onSubmit = async ( e ) => {
			e.preventDefault();
			const email     = ( form.querySelector( '#email' ) || form.querySelector( '[name="email"]' ) )?.value ?? '';
			const firstName = ( form.querySelector( '#first_name' ) || form.querySelector( '[name="first_name"]' ) )?.value ?? '';
			const lastName  = ( form.querySelector( '#last_name' ) || form.querySelector( '[name="last_name"]' ) )?.value ?? '';
			const name      = [ firstName, lastName ].filter( Boolean ).join( ' ' );
			try {
				await fetch( `${ base }/sessions/${ sessionToken }/finalize`, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
					body: JSON.stringify( { name, email } ),
				} );
			} catch ( _ ) {}
			form.removeEventListener( 'submit', onSubmit );
			form.submit();
		};

		form.addEventListener( 'submit', onSubmit );
		return () => form.removeEventListener( 'submit', onSubmit );
	}, [ sessionToken ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// ── Session lifecycle ─────────────────────────────────────────────────────

	async function chooseMode( selectedMode ) {
		setChosenMode( selectedMode );
		setPhase( 'starting' );
		setErrorMsg( null );

		try {
			const data = await apiFetch(
				`${ base }/sessions/start`,
				{
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( { role_id: roleId, mode: selectedMode } ),
				},
				nonce
			);

			setSessionToken( data.session_token );
			setMode( selectedMode );

			if ( selectedMode === 'critique' ) {
				setMessages( [ {
					type:         'critique_doc',
					docName:      data.critique_doc_name,
					critiqueText: data.critique_text,
					instructions: data.candidate_note,
				} ] );
			}

			setPhase( 'chatting' );
		} catch ( err ) {
			setErrorMsg( err.message || 'Could not start session. Please refresh and try again.' );
			setPhase( 'error' );
		}
	}

	// ── Send message ──────────────────────────────────────────────────────────

	async function sendMessage() {
		const text = input.trim();
		if ( ! text || isSending || isUploading || pendingSkillPicker ) return;

		setInput( '' );
		setIsSending( true );
		setMessages( ( prev ) => [ ...prev, { type: 'text', role: 'user', content: text } ] );

		try {
			const data = await apiFetch(
				`${ base }/sessions/${ sessionToken }/message`,
				{
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( { message: text } ),
				},
				nonce
			);
			setMessages( ( prev ) => [ ...prev, { type: 'text', role: 'bot', content: data.message } ] );
		} catch ( err ) {
			if ( err.code === 'session_expired' ) {
				setPhase( 'expired' );
			} else {
				setMessages( ( prev ) => [
					...prev,
					{ type: 'text', role: 'bot', content: `Sorry, something went wrong: ${ err.message }` },
				] );
			}
		}

		setIsSending( false );
	}

	// ── File upload ───────────────────────────────────────────────────────────

	async function uploadFile( file ) {
		if ( ! file || isUploading || isSending || pendingSkillPicker ) return;

		setIsUploading( true );
		setMessages( ( prev ) => [ ...prev, {
			type:     'file',
			role:     'user',
			filename: file.name,
			fileSize: ( file.size / 1024 < 1024 )
				? `${ ( file.size / 1024 ).toFixed( 1 ) } KB`
				: `${ ( file.size / 1024 / 1024 ).toFixed( 1 ) } MB`,
		} ] );

		const formData = new FormData();
		formData.append( 'file', file );

		try {
			const data = await apiFetch(
				`${ base }/sessions/${ sessionToken }/upload`,
				{ method: 'POST', body: formData },
				nonce
			);
			// Show skill picker instead of bot text reply — skill picker will
			// send a follow-up message once the user confirms.
			setPendingSkillPicker( { messageId: data.message_id } );
		} catch ( err ) {
			if ( err.code === 'session_expired' ) {
				setPhase( 'expired' );
			} else {
				setMessages( ( prev ) => [
					...prev,
					{ type: 'text', role: 'bot', content: `File upload failed: ${ err.message }` },
				] );
			}
		}

		setIsUploading( false );
	}

	// ── Skill confirmation ────────────────────────────────────────────────────

	async function handleSkillConfirm( skills ) {
		const { messageId } = pendingSkillPicker;
		setPendingSkillPicker( null );

		// Save skills to DB (best-effort, non-blocking)
		apiFetch(
			`${ base }/sessions/${ sessionToken }/messages/${ messageId }/skills`,
			{
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { skills } ),
			},
			nonce
		).catch( () => {} );

		// Tell Claude which skills were tagged so it can acknowledge
		const skillList = skills.join( ', ' );
		const ackText   = `I've tagged this document for the following skills: ${ skillList }.`;

		setIsSending( true );
		setMessages( ( prev ) => [ ...prev, { type: 'text', role: 'user', content: ackText } ] );

		try {
			const data = await apiFetch(
				`${ base }/sessions/${ sessionToken }/message`,
				{
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( { message: ackText } ),
				},
				nonce
			);
			setMessages( ( prev ) => [ ...prev, { type: 'text', role: 'bot', content: data.message } ] );
		} catch ( err ) {
			setMessages( ( prev ) => [
				...prev,
				{ type: 'text', role: 'bot', content: `Something went wrong: ${ err.message }` },
			] );
		}

		setIsSending( false );
	}

	// ── End session ───────────────────────────────────────────────────────────

	async function endSession() {
		if ( ! sessionToken ) return;
		try {
			await fetch( `${ base }/sessions/${ sessionToken }/finalize`, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
				body: JSON.stringify( { name: '', email: '' } ),
			} );
		} catch ( _ ) {}
		setPhase( 'complete' );
	}

	// ── File picker ───────────────────────────────────────────────────────────

	function triggerFilePicker() {
		fileInputRef.current?.click();
	}

	// ── Drag-and-drop ─────────────────────────────────────────────────────────

	const { getRootProps, getInputProps, isDragActive } = useDropzone( {
		onDrop:     ( files ) => { if ( files[ 0 ] ) uploadFile( files[ 0 ] ); },
		accept:     { 'application/pdf': [ '.pdf' ], 'application/vnd.openxmlformats-officedocument.wordprocessingml.document': [ '.docx' ], 'text/plain': [ '.txt' ], 'text/markdown': [ '.md' ] },
		maxSize:    25 * 1024 * 1024,
		multiple:   false,
		noClick:    true,
		noKeyboard: true,
		disabled:   phase !== 'chatting' || mode !== 'upload',
	} );

	function handleKeyDown( e ) {
		if ( e.key === 'Enter' && ! e.shiftKey ) {
			e.preventDefault();
			sendMessage();
		}
	}

	// ── Render helpers ────────────────────────────────────────────────────────

	function renderMessage( msg, i ) {
		if ( msg.type === 'critique_doc' ) {
			return (
				<CritiqueDocCard
					key={ i }
					docName={ msg.docName }
					critiqueText={ msg.critiqueText }
					instructions={ msg.instructions }
				/>
			);
		}

		if ( msg.type === 'file' ) {
			return (
				<MsgRow key={ i } who="user">
					<div className="sw-attach-card">
						<PdfIcon />
						<div className="sw-pdf-meta">
							<span className="sw-pdf-name">{ msg.filename }</span>
							{ msg.fileSize && <span className="sw-pdf-size">{ msg.fileSize }</span> }
						</div>
					</div>
				</MsgRow>
			);
		}

		// type === 'text'
		return (
			<MsgRow key={ i } who={ msg.role }>
				<div className={ `sw-bubble sw-bubble--${ msg.role }` }>
					{ msg.role === 'bot'
						? <ReactMarkdown>{ msg.content }</ReactMarkdown>
						: <p>{ msg.content }</p>
					}
				</div>
			</MsgRow>
		);
	}

	// ── Status label ─────────────────────────────────────────────────────────

	function statusLabel() {
		if ( phase === 'complete' ) return 'Conversation complete';
		if ( phase === 'chatting' ) return 'In progress';
		return 'Skillsaw assessment';
	}

	// ── Composer state ────────────────────────────────────────────────────────

	const composerDisabled = phase !== 'chatting' || isSending || isUploading || !! pendingSkillPicker;
	const showUploadBtn    = phase === 'chatting' && mode === 'upload';
	const canSend          = !! input.trim() && ! composerDisabled;

	// ── Inactive role ─────────────────────────────────────────────────────────

	if ( ! active ) {
		return (
			<section className="skillsaw-chat-panel skillsaw-chat-panel--inactive" aria-label="Skillsaw conversation">
				<div className="sw-full-state">
					<p className="sw-inactive-msg">We are currently not accepting new applications for this role.</p>
				</div>
			</section>
		);
	}

	// ── Early loading / error renders ─────────────────────────────────────────

	if ( phase === 'starting' ) {
		return (
			<section className="skillsaw-chat-panel" aria-label="Skillsaw conversation">
				<div className="sw-full-state">
					<div className="sw-spinner" />
					<p>Starting your session…</p>
				</div>
			</section>
		);
	}

	if ( phase === 'expired' ) {
		return (
			<section className="skillsaw-chat-panel" aria-label="Skillsaw conversation">
				<div className="sw-full-state">
					<p className="sw-expired-msg">Your session has expired.</p>
					<p className="sw-expired-sub">Sessions are valid for 4 hours. Please refresh the page to start a new one.</p>
					<button className="sw-action-btn" onClick={ () => window.location.reload() }>
						Start over
					</button>
				</div>
			</section>
		);
	}

	if ( phase === 'error' ) {
		return (
			<section className="skillsaw-chat-panel" aria-label="Skillsaw conversation">
				<div className="sw-full-state">
					<p className="sw-error-text">{ errorMsg || 'Something went wrong. Please refresh the page.' }</p>
				</div>
			</section>
		);
	}

	// ── Main render ───────────────────────────────────────────────────────────

	const dropzoneProps = showUploadBtn ? getRootProps( { style: { position: 'relative' } } ) : { style: { position: 'relative' } };

	return (
		<section className="skillsaw-chat-panel" aria-label="Skillsaw conversation" { ...dropzoneProps }>
			{ showUploadBtn && <input { ...getInputProps() } /> }
			{ isDragActive && (
				<div className="sw-dropzone-overlay">Drop your file here</div>
			) }

			{ /* Hidden file input for the attach button */ }
			<input
				ref={ fileInputRef }
				type="file"
				accept=".pdf,.docx,.txt,.md"
				style={ { display: 'none' } }
				onChange={ ( e ) => { if ( e.target.files[ 0 ] ) uploadFile( e.target.files[ 0 ] ); e.target.value = ''; } }
			/>

			{ /* Header */ }
			<header className="sw-chat-header">
				<span className="sw-ch-mark" aria-hidden="true">S</span>
				<div>
					<div className="sw-ch-title">Skillsaw</div>
					{ roleTitle && (
						<div className="sw-ch-sub">{ roleTitle } · Application chat</div>
					) }
				</div>
				{ phase === 'complete' && (
					<span className="sw-ch-status">
						<span className="sw-ch-status-dot" />
						{ statusLabel() }
					</span>
				) }
			</header>

			{ /* Messages */ }
			<div className="sw-chat-scroll" ref={ scrollRef }>
				<OpeningMessage
					roleTitle={ roleTitle }
					roleSkills={ roleSkills }
					hasCritique={ hasCritique }
					onChoose={ chooseMode }
					disabled={ phase !== 'idle' }
				/>

				{ messages.map( renderMessage ) }

				{ pendingSkillPicker && (
					<SkillPickerMessage
						roleSkills={ roleSkills }
						onConfirm={ handleSkillConfirm }
					/>
				) }

				{ ( isSending || isUploading ) && <TypingIndicator /> }

				<div ref={ ( el ) => { if ( el ) el.scrollIntoView( { behavior: 'smooth' } ); } } />
			</div>

			{ /* Complete strip */ }
			{ phase === 'complete' && (
				<div className="sw-complete-strip" role="status">
					<span className="sw-complete-dot" />
					<span>
						Thanks — your conversation has been saved. You can keep filling out the rest of the form below.
					</span>
				</div>
			) }

			{ /* Composer */ }
			<div className="sw-composer">
				{ showUploadBtn && (
					<button
						type="button"
						className="sw-attach-btn"
						onClick={ triggerFilePicker }
						disabled={ composerDisabled }
						aria-label="Attach file (PDF, TXT, MD — max 25 MB)"
					>
						<svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true">
							<path
								d="M13.5 8.5l-4 4a2 2 0 01-2.83-2.83l5.66-5.66a3.5 3.5 0 014.95 4.95l-6.36 6.36a5 5 0 11-7.07-7.07l5.66-5.66"
								stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round"
							/>
						</svg>
					</button>
				) }

				<textarea
					ref={ textareaRef }
					className="sw-composer-input"
					value={ phase === 'complete' ? '' : input }
					onChange={ ( e ) => setInput( e.target.value ) }
					onKeyDown={ handleKeyDown }
					placeholder={
						phase === 'complete'
							? 'Conversation complete — refresh the page to start over'
							: pendingSkillPicker
								? 'Please confirm the skills above first…'
								: 'Type your message… (Enter to send)'
					}
					rows={ 1 }
					disabled={ composerDisabled || phase === 'complete' || phase === 'idle' }
				/>

				<button
					type="button"
					className="sw-send-btn"
					onClick={ phase === 'chatting' && ! pendingSkillPicker ? sendMessage : endSession }
					disabled={
						phase === 'complete' || phase === 'idle' || phase === 'starting'
							? true
							: isSending || isUploading || !! pendingSkillPicker
								? true
								: phase === 'chatting'
									? ! canSend
									: false
					}
				>
					{ phase === 'chatting' ? 'Send' : 'Send' }
				</button>
			</div>

			{ phase === 'chatting' && (
				<div style={ { display: 'flex', justifyContent: 'flex-end', paddingRight: 14, marginTop: -4, marginBottom: 2 } }>
					<button
						type="button"
						onClick={ endSession }
						disabled={ isSending || isUploading }
						style={ {
							background: 'none', border: 'none', color: '#8b8b8b',
							fontSize: 12, cursor: 'pointer', padding: '2px 4px',
							fontFamily: 'inherit',
						} }
					>
						End session
					</button>
				</div>
			) }

			<div className="sw-composer-footnote">
				Skillsaw helps surface your concrete skills. It does not judge your application or replace an interview.
			</div>
		</section>
	);
}
