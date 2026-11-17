/**
 * Settings > Customers: when users become Invoice Ninja clients, and tools to create clients
 * for existing users, mark emails confirmed and create the Account page. Added through
 * window.invoiceNinjaAdminUI.addTab().
 */
( function ( ui, data ) {
	'use strict';
	if ( ! ui ) {
		return;
	}
	const { h, Fragment, useState, useEffect, _n, __, sprintf, api, errorText, saveSettings, Button, Notice, Pill, boot } = ui;
	// [ { id, name, count } ] from PortalModule::adminAssets().
	const ROLES = ( data && data.roles ) || [];

	const split = ( roles ) => String( roles || '' ).split( ',' ).map( ( r ) => r.trim() ).filter( Boolean );
	const key = ( roles ) => roles.slice().sort().join( ',' );

	function Customers() {
		const saved = ( boot.settings && boot.settings.customers ) || { sync: 'purchase', roles: 'customer,subscriber' };
		const known = ROLES.map( ( r ) => r.id );
		const [ s, setS ] = useState( saved );
		const [ savedRoles, setSavedRoles ] = useState( key( split( saved.roles ) ) );
		const [ busy, setBusy ] = useState( '' );
		const [ saveMessage, setSaveMessage ] = useState( '' );
		const [ toolMessage, setToolMessage ] = useState( '' );
		// Users in the SAVED roles who aren't clients yet: what "Create clients" would queue.
		const [ pending, setPending ] = useState( null );
		const [ accountPage, setAccountPage ] = useState( ( boot.pages || {} ).account || null );
		const chosen = split( s.roles );
		const [ role, setRole ] = useState( ( known.includes( 'customer' ) && 'customer' ) || chosen.find( ( r ) => known.includes( r ) && r !== 'administrator' ) || ( known.includes( 'subscriber' ) ? 'subscriber' : known[ 0 ] || '' ) );
		const count = () => api( '/customers/export' ).then( ( r ) => setPending( r.pending ) ).catch( () => setPending( false ) );
		useEffect( () => {
			count();
		}, [] );

		const dirtyRoles = key( chosen ) !== savedRoles;
		// Administrator is left out unless it's already chosen, and then it comes with a warning.
		const shown = ROLES.filter( ( r ) => r.id !== 'administrator' || chosen.includes( 'administrator' ) );
		const adminChosen = chosen.includes( 'administrator' );
		// No role at all would make every user a client.
		const noRoles = ! chosen.some( ( r ) => known.includes( r ) );

		const run = async ( id, fn, setMsg ) => {
			setBusy( id );
			setMsg( '' );
			try {
				await fn();
			} catch ( e ) {
				setMsg( { error: errorText( e ) } );
			}
			setBusy( '' );
		};
		const toggleRole = ( id, on ) => setS( Object.assign( {}, s, { roles: ( on ? chosen.concat( [ id ] ) : chosen.filter( ( r ) => r !== id ) ).join( ',' ) } ) );
		const row = ( label, control ) => h( 'tr', null, h( 'th', { scope: 'row' }, label ), h( 'td', null, control ) );
		const statusText = ( m ) => ( m && m.error ? h( 'span', { className: 'in-error-text' }, m.error ) : m );

		const save = () => run( 'save', async () => {
			await saveSettings( { customers: s } );
			setSavedRoles( key( chosen ) );
			setSaveMessage( __( 'Settings saved.', 'invoiceninja' ) );
			setPending( null );
			count();
		}, setSaveMessage );

		const exportUsers = () => run( 'export', async () => {
			const r = await api( '/customers/export', { method: 'POST' } );
			setToolMessage( r.queued
				? sprintf( /* translators: %d: number of users */ _n( 'Creating a client for %d user in the background. Progress is in Status → Activity.', 'Creating clients for %d users in the background. Progress is in Status → Activity.', r.queued, 'invoiceninja' ), r.queued )
				: __( 'Every user in these roles is already a client.', 'invoiceninja' ) );
			setPending( 0 );
		}, setToolMessage );

		const verify = () => {
			const r = ROLES.find( ( x ) => x.id === role );
			// eslint-disable-next-line no-alert
			if ( ! role || ! window.confirm( sprintf( /* translators: %s: role name */ __( 'Mark every user with the role %s as having a confirmed email? Only do this if their emails were checked when they signed up.', 'invoiceninja' ), r ? r.name : role ) ) ) {
				return;
			}
			run( 'verify', async () => {
				const res = await api( '/customers/verify-role', { method: 'POST', data: { role } } );
				setToolMessage( sprintf( /* translators: %d: number of users */ _n( '%d user marked as confirmed.', '%d users marked as confirmed.', res.verified, 'invoiceninja' ), res.verified ) );
			}, setToolMessage );
		};

		const createAccount = () => run( 'page', async () => {
			const r = await api( '/pages', { method: 'POST', data: { pages: [ 'account' ] } } );
			const p = r.pages && r.pages.account;
			if ( p ) {
				let path = '';
				try {
					path = decodeURI( new URL( p.url ).pathname );
				} catch ( e ) {
					path = '';
				}
				setAccountPage( Object.assign( { status: 'publish', path }, p ) );
			}
			setToolMessage( __( 'The Account page is ready.', 'invoiceninja' ) );
		}, setToolMessage );

		let exportControl;
		if ( dirtyRoles ) {
			exportControl = h( Fragment, null, h( Button, { disabled: true, 'aria-describedby': 'in-export-hint' }, __( 'Create clients', 'invoiceninja' ) ),
				h( 'span', { className: 'in-muted', id: 'in-export-hint' }, __( 'Save your role changes first.', 'invoiceninja' ) ) );
		} else if ( pending === null ) {
			exportControl = h( 'span', { className: 'in-muted' }, __( 'Counting users…', 'invoiceninja' ) );
		} else if ( pending === 0 ) {
			exportControl = h( 'span', { className: 'in-muted' }, __( 'Every user in these roles is already a client.', 'invoiceninja' ) );
		} else {
			exportControl = h( Button, { busy: busy === 'export', onClick: exportUsers },
				pending === false ? __( 'Create clients', 'invoiceninja' ) : sprintf( /* translators: %d: number of users */ _n( 'Create a client for %d user', 'Create clients for %d users', pending, 'invoiceninja' ), pending ) );
		}

		const tool = ( id, title, body, control ) => h( 'li', { key: id, className: 'in-tool-row' },
			h( 'div', { className: 'in-grow' }, h( 'h3', null, title ), h( 'p', { className: 'in-muted' }, body ) ),
			h( 'div', { className: 'in-tool-row__action' }, control ) );

		return h( Fragment, null,
			h( 'h2', null, __( 'Customers', 'invoiceninja' ) ),
			h( 'table', { className: 'form-table', role: 'presentation' }, h( 'tbody', null,
				row( __( 'Create an Invoice Ninja client', 'invoiceninja' ), h( 'fieldset', null,
					h( 'legend', { className: 'screen-reader-text' }, __( 'When users become clients', 'invoiceninja' ) ),
					[
						[ 'purchase', __( 'When they buy or ask for a quote', 'invoiceninja' ), __( 'Recommended. Keeps Invoice Ninja free of inactive accounts.', 'invoiceninja' ) ],
						[ 'register', __( 'As soon as they register', 'invoiceninja' ), __( 'Every new user in the roles below becomes a client.', 'invoiceninja' ) ],
					].map( ( [ v, l, help ] ) => h( 'label', { key: v, className: 'in-check' },
						h( 'input', { type: 'radio', name: 'in-sync', checked: s.sync === v, onChange: () => setS( Object.assign( {}, s, { sync: v } ) ) } ),
						h( 'span', null, h( 'strong', null, l ), h( 'span', { className: 'in-muted' }, help ) ) ) ) ) ),
				row( __( 'Roles', 'invoiceninja' ), h( 'fieldset', null,
					h( 'legend', { className: 'in-legend' }, __( 'Users with these roles become clients.', 'invoiceninja' ) ),
					h( 'div', { className: 'in-check-grid' }, shown.map( ( r ) => h( 'label', { key: r.id, className: 'in-check' },
						h( 'input', { type: 'checkbox', checked: chosen.includes( r.id ), onChange: ( e ) => toggleRole( r.id, e.target.checked ) } ),
						h( 'span', { className: 'in-check__inline' }, r.name, ' ',
							h( 'span', { className: 'in-muted', 'aria-hidden': 'true' }, '(' + r.count + ')' ),
							h( 'span', { className: 'screen-reader-text' }, sprintf( /* translators: %d: number of users */ _n( '%d user', '%d users', r.count, 'invoiceninja' ), r.count ) ) ) ) ) ),
					adminChosen ? h( 'div', { className: 'notice notice-warning inline' }, h( 'p', null,
						h( 'strong', null, __( 'Administrators become clients too.', 'invoiceninja' ) ), ' ',
						__( 'Untick Administrator unless the people who run this site also buy from it.', 'invoiceninja' ) ) ) : null,
					noRoles ? h( 'p', { className: 'in-error-text' }, __( 'Choose at least one role.', 'invoiceninja' ) ) : null ) ) ) ),
			h( 'p', { className: 'in-row' },
				h( Button, { primary: true, busy: busy === 'save', disabled: noRoles, onClick: save }, __( 'Save changes', 'invoiceninja' ) ),
				h( 'span', { role: 'status' }, statusText( saveMessage ) ) ),

			h( 'section', { className: 'in-section-break', 'aria-labelledby': 'in-tools-title' },
				h( 'h2', { id: 'in-tools-title' }, __( 'Tools', 'invoiceninja' ) ),
				h( 'p', { className: 'in-muted' }, __( 'One-off actions. They don’t change the settings above.', 'invoiceninja' ) ),
				h( 'div', { role: 'status' }, toolMessage ? h( Notice, { type: toolMessage.error ? 'error' : 'success' }, h( 'p', null, statusText( toolMessage ) ) ) : null ),
				h( 'ul', { className: 'in-card in-tool-rows' },
					tool( 'export', __( 'Create clients for existing users', 'invoiceninja' ),
						__( 'For users in the saved roles who aren’t Invoice Ninja clients yet. Existing clients are matched by email and never changed. Runs in the background.', 'invoiceninja' ),
						exportControl ),
					boot.can.manageSite ? tool( 'verify', __( 'Mark emails as confirmed', 'invoiceninja' ),
						__( 'Customers confirm their email once before the billing portal and payment links open. If a role’s emails were already checked at sign-up, skip that step for everyone in it.', 'invoiceninja' ),
						h( 'span', { className: 'in-row' },
							h( 'label', { htmlFor: 'in-verify-role' }, __( 'Role', 'invoiceninja' ) ),
							h( 'select', { id: 'in-verify-role', value: role, onChange: ( e ) => setRole( e.target.value ) },
								ROLES.map( ( r ) => h( 'option', { key: r.id, value: r.id }, r.name + ' (' + r.count + ')' ) ) ),
							h( Button, { busy: busy === 'verify', disabled: ! role, onClick: verify }, __( 'Mark as confirmed…', 'invoiceninja' ) ) ) ) : null,
					tool( 'account', h( Fragment, null, __( 'Account page', 'invoiceninja' ), ' ', accountPage ? null : h( Pill, { tone: 'warning' }, __( 'Missing', 'invoiceninja' ) ) ),
						__( 'Where customers see their invoices, quotes, payments, credits and subscriptions.', 'invoiceninja' ),
						accountPage ? h( 'span', { className: 'in-links' },
							accountPage.path ? h( 'code', null, accountPage.path ) : h( Pill, null, __( 'Not published', 'invoiceninja' ) ),
							accountPage.edit ? h( 'a', { href: accountPage.edit, 'aria-label': __( 'Edit the Account page', 'invoiceninja' ) }, __( 'Edit', 'invoiceninja' ) ) : null,
							accountPage.url && accountPage.status === 'publish' ? h( 'a', { href: accountPage.url, 'aria-label': __( 'View the Account page', 'invoiceninja' ) }, __( 'View', 'invoiceninja' ) ) : null )
							: h( Button, { busy: busy === 'page', onClick: createAccount }, __( 'Create the Account page', 'invoiceninja' ) ) ) ) ) );
	}

	ui.addTab( { id: 'customers', label: __( 'Customers', 'invoiceninja' ), can: ( b ) => b.can.manage, render: Customers } );
}( window.invoiceNinjaAdminUI, window.invoiceNinjaPortalAdmin ) );
