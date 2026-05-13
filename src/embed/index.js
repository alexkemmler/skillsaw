import React from 'react';
import { createRoot } from 'react-dom/client';
import ChatPanel from './ChatPanel';

document.querySelectorAll( '.skillsaw-embed' ).forEach( ( el ) => {
	const roleId     = parseInt( el.dataset.roleId, 10 );
	const active     = el.dataset.active !== 'false';
	const hasCritique = el.dataset.hasCritique === 'true';
	const roleTitle  = el.dataset.roleTitle ?? '';
	const roleSkills = JSON.parse( el.dataset.roleSkills || '[]' );
	const nonce      = window.skillsawEmbed?.nonce ?? '';
	const rootUrl    = window.skillsawEmbed?.rootUrl ?? '';

	createRoot( el ).render(
		<ChatPanel
			roleId={ roleId }
			active={ active }
			hasCritique={ hasCritique }
			roleTitle={ roleTitle }
			roleSkills={ roleSkills }
			nonce={ nonce }
			rootUrl={ rootUrl }
		/>
	);
} );
