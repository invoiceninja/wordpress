/**
 * Settings > Customers: when users become Invoice Ninja clients, export, verification and
 * the Account page. Added through window.invoiceNinjaAdminUI.addTab().
 */
( function ( ui ) {
	'use strict';
	if ( ! ui ) {
		return;
	}
	const { h, Fragment, useState, __, sprintf, api, errorText, saveSettings, Button, Notice, boot } = ui;

	function Customers() {
		const [ accountPage, setAccountPage ] = useState( boot.settings && boot.settings.pages && boot.settings.pages.account ? {} : null );
		const [ s, setS ] = useState( ( boot.settings && boot.settings.customers ) || { sync: 'purchase', roles: 'customer,subscriber' } );
		const [ busy, setBusy ] = useState( '' );
		const [ message, setMessage ] = useState( '' );
		const [ role, setRole ] = useState( 'customer' );
		const run = async ( id, fn ) => {
			setBusy( id );
			setMessage( '' );
			try {
				await fn();
			} catch ( e ) {
				setMessage( errorText( e ) );
			}
			setBusy( '' );
		};
		const row = ( label, control, help ) => h( 'tr', null, h( 'th', { scope: 'row' }, label ), h( 'td', null, control, help ? h( 'p', { className: 'description' }, help ) : null ) );
		return h( Fragment, null,
			message ? h( Notice, { type: 'info' }, h( 'p', { role: 'status' }, message ) ) : null,
			h( 'h2', null, __( 'Customers', 'invoiceninja' ) ),
			h( 'table', { className: 'form-table', role: 'presentation' }, h( 'tbody', null,
				row( __( 'Create clients', 'invoiceninja' ), h( 'fieldset', null,
					h( 'legend', { className: 'screen-reader-text' }, __( 'When users become clients', 'invoiceninja' ) ),
					[ [ 'purchase', __( 'When they buy or ask for a quote (recommended)', 'invoiceninja' ) ], [ 'register', __( 'As soon as they register', 'invoiceninja' ) ] ].map( ( [ v, l ] ) => h( 'label', { key: v, className: 'in-check' },
						h( 'input', { type: 'radio', name: 'in-sync', checked: s.sync === v, onChange: () => setS( Object.assign( {}, s, { sync: v } ) ) } ), h( 'span', null, l ) ) ) ) ),
				row( h( 'label', { htmlFor: 'in-roles' }, __( 'Roles', 'invoiceninja' ) ),
					h( 'input', { id: 'in-roles', className: 'regular-text', value: s.roles, onChange: ( e ) => setS( Object.assign( {}, s, { roles: e.target.value } ) ) } ),
					__( 'Comma-separated role names whose users become clients, e.g. customer, subscriber.', 'invoiceninja' ) ) ) ),
			h( 'p', { className: 'in-row' }, h( Button, { primary: true, busy: busy === 'save', onClick: () => run( 'save', async () => {
				await saveSettings( { customers: s } );
				setMessage( __( 'Settings saved.', 'invoiceninja' ) );
			} ) }, __( 'Save changes', 'invoiceninja' ) ) ),
			h( 'h2', null, __( 'Export existing users', 'invoiceninja' ) ),
			h( 'p', null, __( 'Create Invoice Ninja clients for existing users in these roles, in the background. Existing clients are matched by email and never changed.', 'invoiceninja' ) ),
			h( 'p', null, h( Button, { busy: busy === 'export', onClick: () => run( 'export', async () => {
				const r = await api( '/customers/export', { method: 'POST' } );
				setMessage( sprintf( /* translators: %d: number of users */ __( 'Exporting %d users in the background. Progress is in Status > Activity.', 'invoiceninja' ), r.queued ) );
			} ) }, __( 'Export users', 'invoiceninja' ) ) ),
			h( 'h2', null, __( 'Confirmed emails', 'invoiceninja' ) ),
			h( 'p', null, __( 'Customers confirm their email once before the billing portal and payment links open from your site. If emails in a role were already checked when people signed up, you can mark them all as confirmed.', 'invoiceninja' ) ),
			boot.can.manageSite ? h( 'p', { className: 'in-row' },
				h( 'label', { htmlFor: 'in-verify-role' }, __( 'Role', 'invoiceninja' ) ),
				h( 'input', { id: 'in-verify-role', className: 'small-text', value: role, onChange: ( e ) => setRole( e.target.value ) } ),
				h( Button, { busy: busy === 'verify', onClick: () => {
					// eslint-disable-next-line no-alert
					if ( window.confirm( sprintf( /* translators: %s: role */ __( 'Mark every user with the role %s as having a confirmed email? Only do this if their emails were checked when they signed up.', 'invoiceninja' ), role ) ) ) {
						run( 'verify', async () => {
							const r = await api( '/customers/verify-role', { method: 'POST', data: { role } } );
							setMessage( sprintf( /* translators: %d: number of users */ __( '%d users marked as confirmed.', 'invoiceninja' ), r.verified ) );
						} );
					}
				} }, __( 'Mark as confirmed…', 'invoiceninja' ) ) ) : null,
			h( 'h2', null, __( 'Account page', 'invoiceninja' ) ),
			h( 'p', null, __( 'A page with the Account block, where customers see their invoices, quotes, payments, credits and subscriptions.', 'invoiceninja' ) ),
			accountPage ? h( 'p', null, accountPage.url ? h( Fragment, null, __( 'Your Account page:', 'invoiceninja' ), ' ', h( 'a', { href: accountPage.url }, __( 'View', 'invoiceninja' ) ), ' · ', h( 'a', { href: accountPage.edit }, __( 'Edit', 'invoiceninja' ) ) ) : __( 'Your Account page exists. Find it under Pages.', 'invoiceninja' ) )
				: h( 'p', null, h( Button, { busy: busy === 'page', onClick: () => run( 'page', async () => {
					const r = await api( '/pages', { method: 'POST', data: { pages: [ 'account' ] } } );
					setAccountPage( r.pages && r.pages.account ? r.pages.account : {} );
					setMessage( __( 'The Account page is ready.', 'invoiceninja' ) );
				} ) }, __( 'Create the Account page', 'invoiceninja' ) ) ) );
	}

	ui.addTab( { id: 'customers', label: __( 'Customers', 'invoiceninja' ), can: ( b ) => b.can.manage, render: Customers } );
}( window.invoiceNinjaAdminUI ) );
