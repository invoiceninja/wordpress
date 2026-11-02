/**
 * Settings → WooCommerce: invoicing, customers, catalog and backfill.
 *
 * Plain JavaScript on wp.element through window.invoiceNinjaAdminUI (no build step, no
 * innerHTML).
 */
( function ( ui, woo ) {
	'use strict';

	if ( ! ui || ! woo ) {
		return;
	}

	const { h, Fragment, useState, useEffect, __, sprintf, api, errorText, saveSettings, Button, Notice, Pill, boot } = ui;

	const today = () => new Date().toISOString().slice( 0, 10 );
	const daysAgo = ( n ) => new Date( Date.now() - n * 86400000 ).toISOString().slice( 0, 10 );

	function row( label, id, control, help ) {
		return h( 'tr', null,
			h( 'th', { scope: 'row' }, id ? h( 'label', { htmlFor: id }, label ) : label ),
			h( 'td', null, control, help ? h( 'p', { className: 'description' }, help ) : null ) );
	}

	function Check( { checked, onChange, label, help } ) {
		return h( 'label', { className: 'in-check' },
			h( 'input', { type: 'checkbox', checked: !! checked, onChange: ( e ) => onChange( e.target.checked ) } ),
			h( 'span', null, h( 'strong', null, label ), help ? h( 'span', { className: 'in-muted' }, help ) : null ) );
	}

	function Backfill( { enabled } ) {
		const [ from, setFrom ] = useState( daysAgo( 30 ) );
		const [ to, setTo ] = useState( today() );
		const [ busy, setBusy ] = useState( '' );
		const [ dry, setDry ] = useState( null );
		const [ run, setRun ] = useState( woo.backfill || { status: 'none' } );
		const [ message, setMessage ] = useState( '' );
		const running = run && ( run.status === 'running' || run.status === 'queued' );

		useEffect( () => {
			if ( ! running ) {
				return undefined;
			}
			const timer = window.setInterval( async () => {
				try {
					const s = await api( '/woo/status' );
					setRun( s.backfill );
				} catch ( e ) {
					// Keep the last progress; the next tick tries again.
				}
			}, 5000 );
			return () => window.clearInterval( timer );
		}, [ running ] );

		const go = async ( dryRun ) => {
			setBusy( dryRun ? 'dry' : 'start' );
			setMessage( '' );
			try {
				const r = await api( '/woo/backfill', { method: 'POST', data: { from, to, dry_run: dryRun } } );
				if ( dryRun ) {
					setDry( r );
				} else {
					setRun( r );
					setDry( null );
				}
			} catch ( e ) {
				setMessage( errorText( e ) );
			}
			setBusy( '' );
		};
		const cancel = async () => {
			setBusy( 'cancel' );
			try {
				setRun( await api( '/woo/backfill/cancel', { method: 'POST' } ) );
			} catch ( e ) {
				setMessage( errorText( e ) );
			}
			setBusy( '' );
		};

		const queued = run.queued || 0;
		const done = run.done || 0;
		return h( Fragment, null,
			h( 'h2', null, __( 'Invoice past orders', 'invoiceninja' ) ),
			h( 'p', null, __( 'Orders in the date range that have no invoice yet are sent to Invoice Ninja in the background, with their payments and refunds. Nobody is emailed, by WooCommerce or Invoice Ninja.', 'invoiceninja' ) ),
			! enabled ? h( Notice, { type: 'info' }, h( 'p', null, __( 'Turn on WooCommerce invoicing and save first.', 'invoiceninja' ) ) ) : null,
			h( 'p', { className: 'in-row' },
				h( 'label', { htmlFor: 'in-woo-from' }, __( 'From', 'invoiceninja' ) ),
				h( 'input', { id: 'in-woo-from', type: 'date', value: from, max: to, onChange: ( e ) => setFrom( e.target.value ) } ),
				h( 'label', { htmlFor: 'in-woo-to' }, __( 'To', 'invoiceninja' ) ),
				h( 'input', { id: 'in-woo-to', type: 'date', value: to, min: from, onChange: ( e ) => setTo( e.target.value ) } ) ),
			h( 'p', { className: 'in-row' },
				h( Button, { busy: busy === 'dry', disabled: ! enabled || running, onClick: () => go( true ) }, __( 'Dry run', 'invoiceninja' ) ),
				h( Button, { primary: true, busy: busy === 'start', disabled: ! enabled || running || ! dry, onClick: () => go( false ) }, __( 'Start backfill', 'invoiceninja' ) ),
				! dry && ! running ? h( 'span', { className: 'in-muted' }, __( 'Run a dry run first to see what would happen.', 'invoiceninja' ) ) : null ),
			dry ? h( Notice, { type: 'info', title: __( 'Dry run: nothing was changed', 'invoiceninja' ) },
				h( 'ul', null,
					h( 'li', null, sprintf( /* translators: %d: number */ __( '%d orders in the range', 'invoiceninja' ), dry.total ) ),
					h( 'li', null, sprintf( /* translators: %d: number */ __( '%d would be invoiced', 'invoiceninja' ), dry.invoice ) ),
					h( 'li', null, sprintf( /* translators: %d: number */ __( '%d already have an invoice', 'invoiceninja' ), dry.invoiced ) ),
					h( 'li', null, sprintf( /* translators: %d: number */ __( '%d payments would be recorded', 'invoiceninja' ), dry.payment ) ),
					h( 'li', null, sprintf( /* translators: %d: number */ __( '%d refunds would be mirrored', 'invoiceninja' ), dry.refunds ) ) ),
				dry.truncated ? h( 'p', null, __( 'Only the first 5,000 orders were checked; the backfill handles them all.', 'invoiceninja' ) ) : null ) : null,
			run && run.status !== 'none' ? h( 'div', { className: 'in-card' },
				h( 'p', null,
					h( Pill, { tone: run.status === 'done' ? 'success' : run.status === 'cancelled' ? 'neutral' : 'info' }, {
						running: __( 'Queuing orders', 'invoiceninja' ),
						queued: __( 'Sending to Invoice Ninja', 'invoiceninja' ),
						done: __( 'Done', 'invoiceninja' ),
						cancelled: __( 'Stopped', 'invoiceninja' ),
					}[ run.status ] || run.status ), ' ',
					sprintf( /* translators: 1: start date, 2: end date */ __( '%1$s to %2$s', 'invoiceninja' ), run.from, run.to ) ),
				h( 'progress', { max: Math.max( 1, queued ), value: done, 'aria-label': __( 'Backfill progress', 'invoiceninja' ) } ),
				h( 'p', { role: 'status', className: 'in-muted' }, sprintf( /* translators: 1: done, 2: queued, 3: orders checked, 4: total */ __( '%1$d of %2$d invoiced · %3$d of %4$d orders checked', 'invoiceninja' ), done, queued, run.scanned || 0, run.total || 0 ) ),
				run.status === 'running' ? h( Button, { busy: busy === 'cancel', onClick: cancel }, __( 'Stop queuing', 'invoiceninja' ) ) : null ) : null,
			message ? h( 'p', { role: 'alert' }, message ) : null,
			h( 'p', { className: 'in-muted' }, __( 'Also from the command line: wp invoiceninja woo backfill --from=YYYY-MM-DD --to=YYYY-MM-DD --dry-run', 'invoiceninja' ) ) );
	}

	function Catalog( { enabled } ) {
		const [ busy, setBusy ] = useState( '' );
		const [ result, setResult ] = useState( null );
		const [ message, setMessage ] = useState( '' );
		const push = async ( dryRun ) => {
			setBusy( dryRun ? 'dry' : 'push' );
			setMessage( '' );
			try {
				setResult( await api( '/woo/catalog', { method: 'POST', data: { dry_run: dryRun } } ) );
			} catch ( e ) {
				setMessage( errorText( e ) );
			}
			setBusy( '' );
		};
		return h( Fragment, null,
			h( 'p', { className: 'in-row' },
				h( Button, { busy: busy === 'dry', disabled: ! enabled, onClick: () => push( true ) }, __( 'Dry run', 'invoiceninja' ) ),
				h( Button, { busy: busy === 'push', disabled: ! enabled || ! result || ! result.dry_run, onClick: () => push( false ) }, __( 'Push products now', 'invoiceninja' ) ) ),
			result ? h( Notice, { type: result.failed ? 'warning' : 'info', title: result.dry_run ? __( 'Dry run: nothing was changed', 'invoiceninja' ) : __( 'Products pushed', 'invoiceninja' ) },
				h( 'p', null, sprintf( /* translators: 1: created, 2: updated, 3: already linked */ __( '%1$d to create · %2$d to update · %3$d already in WooCommerce', 'invoiceninja' ), result.create.length, result.update.length, result.linked ) ),
				result.create.length ? h( 'p', { className: 'in-muted' }, result.create.slice( 0, 20 ).join( ', ' ) + ( result.create.length > 20 ? ' …' : '' ) ) : null,
				result.failed ? h( 'p', null, sprintf( /* translators: %d: number */ __( '%d could not be created; see Status → Activity.', 'invoiceninja' ), result.failed ) ) : null ) : null,
			message ? h( 'p', { role: 'alert' }, message ) : null );
	}

	function WooTab() {
		const [ s, setS ] = useState( boot.settings );
		const [ busy, setBusy ] = useState( false );
		const [ message, setMessage ] = useState( '' );
		if ( ! s || ! s.woo ) {
			return h( 'p', null, __( "You don't have permission to change these settings.", 'invoiceninja' ) );
		}
		const w = s.woo;
		const set = ( key, value ) => setS( Object.assign( {}, s, { woo: Object.assign( {}, w, { [ key ]: value } ) } ) );
		const setOn = ( value ) => setS( Object.assign( {}, s, { modules: Object.assign( {}, s.modules, { woo: value } ) } ) );
		const save = async () => {
			setBusy( true );
			setMessage( '' );
			try {
				const saved = await saveSettings( { modules: { woo: !! s.modules.woo }, woo: w } );
				setS( saved );
				setMessage( __( 'Settings saved. Reload the page to see the backfill and catalog tools change.', 'invoiceninja' ) );
			} catch ( e ) {
				setMessage( errorText( e ) );
			}
			setBusy( false );
		};
		const on = !! s.modules.woo;
		const running = !! woo.active;
		const roles = woo.roles || {};
		return h( Fragment, null,
			h( 'h2', null, __( 'WooCommerce', 'invoiceninja' ) ),
			h( 'p', null, __( 'WooCommerce is your store; Invoice Ninja keeps the books. Each order becomes an invoice, payments and refunds are recorded against it, and customers get a link to their invoice.', 'invoiceninja' ) ),
			h( 'p', { className: 'in-muted' }, sprintf( /* translators: 1: WooCommerce version, 2: order storage */ __( 'WooCommerce %1$s · %2$s', 'invoiceninja' ), woo.version, woo.hpos ? __( 'order tables (HPOS)', 'invoiceninja' ) : __( 'orders stored as posts', 'invoiceninja' ) ) ),
			h( 'table', { className: 'form-table', role: 'presentation' }, h( 'tbody', null,
				row( __( 'Enable', 'invoiceninja' ), null, h( Check, { checked: on, onChange: setOn, label: __( 'Use WooCommerce as the store', 'invoiceninja' ), help: __( 'The plugin’s own cart and checkout are hidden, and its product pages redirect to WooCommerce.', 'invoiceninja' ) } ) ),
				row( __( 'Invoices', 'invoiceninja' ), null, h( Fragment, null,
					h( Check, { checked: w.invoices, onChange: ( v ) => set( 'invoices', v ), label: __( 'Create an invoice for every order', 'invoiceninja' ), help: __( 'Marked sent without Invoice Ninja’s email; payments are recorded once WooCommerce has the money (cash on delivery when the order is completed).', 'invoiceninja' ) } ),
					h( Check, { checked: w.email_link, onChange: ( v ) => set( 'email_link', v ), label: __( 'Add the invoice link to customer emails', 'invoiceninja' ) } ),
					h( Check, { checked: w.account_tab, onChange: ( v ) => set( 'account_tab', v ), label: __( 'Add “Invoices & billing” to My Account', 'invoiceninja' ) } ) ) ),
				row( __( 'Customers', 'invoiceninja' ), null, h( 'fieldset', null,
					h( 'legend', { className: 'screen-reader-text' }, __( 'Customer sync', 'invoiceninja' ) ),
					[
						[ 'orders', __( 'On purchase only', 'invoiceninja' ), __( 'A client is created for each buyer when they order.', 'invoiceninja' ) ],
						[ 'accounts', __( 'Customer accounts too', 'invoiceninja' ), __( 'Also when a customer registers or saves their address.', 'invoiceninja' ) ],
						[ 'off', __( 'Only with invoices', 'invoiceninja' ), __( 'Clients are created only when an order is invoiced.', 'invoiceninja' ) ],
					].map( ( [ v, l, help ] ) => h( 'label', { key: v, className: 'in-check' },
						h( 'input', { type: 'radio', name: 'in-woo-sync', checked: w.customer_sync === v, onChange: () => set( 'customer_sync', v ) } ),
						h( 'span', null, h( 'strong', null, l ), h( 'span', { className: 'in-muted' }, help ) ) ) ),
					h( Check, { checked: w.customer_update, onChange: ( v ) => set( 'customer_update', v ), label: __( 'Update clients that already exist', 'invoiceninja' ), help: __( 'Off: an existing client’s name, phone and address are never changed.', 'invoiceninja' ) } ) ) ),
				w.customer_sync === 'accounts' ? row( __( 'Roles', 'invoiceninja' ), 'in-woo-roles', h( 'input', { id: 'in-woo-roles', type: 'text', className: 'regular-text', value: w.customer_roles, placeholder: 'customer', onChange: ( e ) => set( 'customer_roles', e.target.value ) } ),
					sprintf( /* translators: %s: role names */ __( 'Comma-separated role keys. Available: %s', 'invoiceninja' ), Object.keys( roles ).join( ', ' ) ) ) : null,
				row( __( 'Catalog', 'invoiceninja' ), null, h( Fragment, null,
					h( Check, { checked: w.catalog_push, onChange: ( v ) => set( 'catalog_push', v ), label: __( 'Push Invoice Ninja products into WooCommerce', 'invoiceninja' ), help: __( 'After each product sync, products missing from WooCommerce are created as simple products (SKU = product key). Stock stays with WooCommerce.', 'invoiceninja' ) } ),
					h( Check, { checked: w.catalog_update, onChange: ( v ) => set( 'catalog_update', v ), label: __( 'Keep prices in step', 'invoiceninja' ), help: __( 'Only for products the plugin created.', 'invoiceninja' ) } ),
					h( Catalog, { enabled: running } ) ) ) ) ),
			h( 'p', { className: 'in-row' }, h( Button, { primary: true, busy, onClick: save }, __( 'Save changes', 'invoiceninja' ) ), h( 'span', { role: 'status' }, message ) ),
			h( Backfill, { enabled: running && w.invoices } ) );
	}

	ui.addTab( { id: 'woocommerce', label: __( 'WooCommerce', 'invoiceninja' ), can: ( b ) => b.can.manage, render: WooTab } );
}( window.invoiceNinjaAdminUI, window.invoiceNinjaWoo ) );
