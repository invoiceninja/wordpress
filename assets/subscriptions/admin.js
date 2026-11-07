/**
 * Settings → Subscriptions: which subscriptions this site shows, which report purchases to
 * it, their provisioning relay, and when unpaid subscriptions are suspended.
 *
 * Plain JavaScript on WordPress's React (wp.element) through window.invoiceNinjaAdminUI.
 */
( function ( ui ) {
	'use strict';

	if ( ! ui ) {
		return;
	}
	const { h, Fragment, useState, useEffect, __, sprintf, api, errorText, saveSettings, Button, Notice, Pill, boot } = ui;

	function SubscriptionRow( { item, onChange, onNote, canAct } ) {
		const [ relay, setRelay ] = useState( item.relay_url || '' );
		const [ busy, setBusy ] = useState( '' );
		const [ message, setMessage ] = useState( '' );
		const run = async ( what, data ) => {
			setBusy( what );
			setMessage( '' );
			try {
				const r = await api( '/admin/subscriptions/' + encodeURIComponent( item.id ), { method: 'POST', data } );
				onChange( r );
				if ( what === 'hidden' ) {
					// The row moves out of (or into) the list, so the tab says what happened.
					onNote( sprintf( item.hidden ? /* translators: %s: plan name */ __( '“%s” is shown on this site again.', 'invoiceninja' ) : /* translators: %s: plan name */ __( '“%s” is hidden from this site. Blocks leave it out and its Subscribe link stops working; customers who have it still see it in their account.', 'invoiceninja' ), item.name ) );
				} else {
					setMessage( r.message || '' );
				}
			} catch ( e ) {
				setMessage( errorText( e ) );
			}
			setBusy( '' );
		};
		let where = null;
		if ( item.callback === 'here' ) {
			where = h( Pill, { tone: 'success' }, __( 'Reports to this site', 'invoiceninja' ) );
		} else if ( item.callback === 'other_wordpress' ) {
			where = h( Pill, { tone: 'warning' }, sprintf( /* translators: %s: host */ __( 'Reports to another WordPress site (%s)', 'invoiceninja' ), item.callback_host ) );
		} else if ( item.callback === 'other' ) {
			where = h( Pill, { tone: 'neutral' }, sprintf( /* translators: %s: host */ __( 'Has its own webhook (%s): kept and passed on', 'invoiceninja' ), item.callback_host ) );
		}
		const relayId = 'in-relay-' + item.id;
		return h( 'tr', null,
			h( 'td', null, h( 'strong', null, item.name ),
				h( 'div', { className: 'in-muted' }, [ item.price + ( item.per ? ' ' + item.per : '' ), item.trial_days ? sprintf( /* translators: %d: days */ __( '%d-day trial', 'invoiceninja' ), item.trial_days ) : '' ].filter( Boolean ).join( ' · ' ) ),
				h( 'code', null, item.shortcode ),
				item.hidden ? h( 'div', null, h( Pill, { tone: 'neutral' }, __( 'Hidden from this site', 'invoiceninja' ) ) ) : null,
				canAct ? h( 'p', null, h( Button, { busy: busy === 'hidden', onClick: () => run( 'hidden', { hidden: ! item.hidden } ) }, item.hidden ? __( 'Show on this site', 'invoiceninja' ) : __( 'Hide from this site', 'invoiceninja' ) ) ) : null ),
			h( 'td', null,
				where ? h( 'div', null, where ) : null,
				item.connected && item.callback !== 'here' ? h( Notice, { type: 'warning' }, __( 'Changed in Invoice Ninja since it was connected.', 'invoiceninja' ) ) : null,
				item.restore_error ? h( Notice, { type: 'error', title: __( 'Not restored', 'invoiceninja' ) }, item.restore_error ) : null,
				canAct ? h( 'p', { className: 'in-row' },
					item.connected
						? h( Button, { busy: busy === 'connect', onClick: () => run( 'connect', { connected: false } ) }, __( 'Stop sending purchases here', 'invoiceninja' ) )
						: h( Button, { primary: true, busy: busy === 'connect', disabled: item.callback === 'other_wordpress', onClick: () => run( 'connect', { connected: true } ) }, __( 'Send purchases to this site', 'invoiceninja' ) ) ) : null ),
			h( 'td', null,
				h( 'label', { htmlFor: relayId, className: 'screen-reader-text' }, sprintf( /* translators: %s: plan name */ __( 'Provisioning URL for %s', 'invoiceninja' ), item.name ) ),
				h( 'input', { id: relayId, type: 'url', className: 'regular-text', placeholder: 'https://panel.example.com/hooks/invoiceninja', value: relay, disabled: ! canAct, onChange: ( e ) => setRelay( e.target.value ) } ),
				canAct ? h( Button, { busy: busy === 'relay', onClick: () => run( 'relay', { relay_url: relay } ) }, __( 'Save', 'invoiceninja' ) ) : null,
				h( 'p', { className: 'in-muted', role: 'status' }, message ) ) );
	}

	function SubscriptionsTab() {
		const [ data, setData ] = useState( null );
		const [ error, setError ] = useState( '' );
		const [ busy, setBusy ] = useState( '' );
		const [ days, setDays ] = useState( ( ( boot.settings || {} ).subscriptions || {} ).suspend_days ?? 7 );
		const [ saved, setSaved ] = useState( '' );
		const [ note, setNote ] = useState( '' );
		const [ showHidden, setShowHidden ] = useState( false );
		const load = () => api( '/admin/subscriptions' ).then( setData ).catch( ( e ) => setError( errorText( e ) ) );
		useEffect( () => {
			load();
		}, [] );
		const act = async ( what, path ) => {
			setBusy( what );
			setError( '' );
			try {
				setData( await api( path, { method: 'POST' } ) );
			} catch ( e ) {
				setError( errorText( e ) );
			}
			setBusy( '' );
		};
		const saveDays = async () => {
			setSaved( '' );
			const n = parseInt( days, 10 );
			if ( ! ( n >= 0 && n <= 365 ) ) {
				setSaved( __( 'Choose between 0 and 365 days.', 'invoiceninja' ) );
				return;
			}
			try {
				await saveSettings( { subscriptions: { suspend_days: n } } );
				setSaved( __( 'Settings saved.', 'invoiceninja' ) );
			} catch ( e ) {
				setSaved( errorText( e ) );
			}
		};
		if ( ! data ) {
			return error ? h( Notice, { type: 'error' }, error ) : h( 'p', null, __( 'Loading…', 'invoiceninja' ) );
		}
		const canAct = boot.can.manage;
		const failed = data.items.filter( ( i ) => i.restore_error );
		const hiddenCount = data.items.filter( ( i ) => i.hidden ).length;
		// Hidden ones go last, and only show when asked.
		const rows = data.items.filter( ( i ) => showHidden || ! i.hidden ).sort( ( a, b ) => ( a.hidden ? 1 : 0 ) - ( b.hidden ? 1 : 0 ) );
		return h( Fragment, null,
			h( 'h2', null, __( 'Subscriptions', 'invoiceninja' ) ),
			h( 'p', { className: 'in-muted' }, __( 'Sell your Invoice Ninja subscriptions with the Pricing Table and Subscribe Button blocks. Send a subscription’s purchases to this site to get lifecycle events (purchased, trial, plan changed, paid, cancelled, suspended, resumed) and, optionally, signed updates for your provisioning system.', 'invoiceninja' ) ),
			! data.enabled ? h( Notice, { type: 'warning' }, __( 'The Subscriptions feature is off. Turn it on under Features on the Home screen to show the blocks.', 'invoiceninja' ) ) : null,
			! data.connected ? h( Notice, { type: 'warning' }, __( 'Connect Invoice Ninja first.', 'invoiceninja' ) ) : null,
			data.connected && ! data.writes_allowed ? h( Notice, { type: 'warning' }, __( 'This site looks like a copy, so it may not change your subscriptions in Invoice Ninja. Allow orders from this site in Status → Tools first.', 'invoiceninja' ) ) : null,
			failed.length ? h( Notice, { type: 'error', title: __( 'Some subscriptions still send purchases here', 'invoiceninja' ) },
				h( 'p', null, __( "Their original settings couldn't be put back in Invoice Ninja.", 'invoiceninja' ) ),
				canAct ? h( Button, { busy: busy === 'restore', onClick: () => act( 'restore', '/admin/subscriptions/restore' ) }, __( 'Try again', 'invoiceninja' ) ) : null ) : null,
			error ? h( Notice, { type: 'error' }, error ) : null,
			h( 'p', { className: 'in-row' },
				canAct ? h( Button, { busy: busy === 'sync', disabled: ! data.connected, onClick: () => act( 'sync', '/admin/subscriptions/sync' ) }, __( 'Sync now', 'invoiceninja' ) ) : null,
				h( 'span', { className: 'in-muted' }, data.synced_at ? sprintf( /* translators: %s: date and time */ __( 'Last synced %s', 'invoiceninja' ), new Date( data.synced_at * 1000 ).toLocaleString() ) : __( 'Not synced yet.', 'invoiceninja' ) ),
				data.error ? h( 'span', { className: 'in-muted' }, data.error ) : null ),
			data.items.length ? h( 'p', { className: 'in-muted' }, __( 'Every subscription in Invoice Ninja is listed here. Hide the ones this site shouldn’t offer: they stay in Invoice Ninja, and blocks and the block editor leave them out.', 'invoiceninja' ) ) : null,
			hiddenCount ? h( 'p', null, h( 'label', { className: 'in-check' },
				h( 'input', { type: 'checkbox', checked: showHidden, onChange: ( e ) => setShowHidden( e.target.checked ) } ),
				h( 'span', null, sprintf( /* translators: %d: number of subscriptions */ __( 'Show hidden subscriptions (%d)', 'invoiceninja' ), hiddenCount ) ) ) ) : null,
			note ? h( Notice, { type: 'success' }, note ) : null,
			rows.length ? h( 'table', { className: 'widefat striped' },
				h( 'thead', null, h( 'tr', null,
					h( 'th', { scope: 'col' }, __( 'Subscription', 'invoiceninja' ) ),
					h( 'th', { scope: 'col' }, __( 'Purchases', 'invoiceninja' ) ),
					h( 'th', { scope: 'col' }, __( 'Provisioning URL (optional)', 'invoiceninja' ) ) ) ),
				h( 'tbody', null, rows.map( ( item ) => h( SubscriptionRow, { key: item.id, item, canAct, onChange: setData, onNote: setNote } ) ) ) )
				: h( 'p', null, data.items.length ? __( 'Every subscription is hidden from this site.', 'invoiceninja' ) : __( 'No subscriptions in Invoice Ninja yet. Create one under Settings → Subscriptions there, then sync.', 'invoiceninja' ) ),
			h( 'p', { className: 'description' }, __( 'Buttons link to the standard purchase page, where eligibility rules apply. Invoice Ninja’s newer “v3” purchase links skip them.', 'invoiceninja' ) ),

			h( 'h2', null, __( 'Unpaid subscriptions', 'invoiceninja' ) ),
			h( 'table', { className: 'form-table', role: 'presentation' }, h( 'tbody', null,
				h( 'tr', null,
					h( 'th', { scope: 'row' }, h( 'label', { htmlFor: 'in-suspend-days' }, __( 'Suspend after', 'invoiceninja' ) ) ),
					h( 'td', null,
						h( 'input', { id: 'in-suspend-days', type: 'number', min: 0, max: 365, className: 'small-text', value: days, disabled: ! canAct, onChange: ( e ) => setDays( e.target.value ) } ),
						' ', __( 'days overdue', 'invoiceninja' ),
						h( 'p', { className: 'description' }, __( 'Checked daily. Fires the “suspended” event (and tells your provisioning system); paying the invoice fires “resumed”. 0 suspends as soon as an invoice is overdue.', 'invoiceninja' ) ) ) ) ) ),
			canAct ? h( 'p', { className: 'in-row' }, h( Button, { primary: true, onClick: saveDays }, __( 'Save changes', 'invoiceninja' ) ), h( 'span', { role: 'status' }, saved ) ) : null,

			data.relay_secret ? h( Fragment, null,
				h( 'h2', null, __( 'Provisioning signature', 'invoiceninja' ) ),
				h( 'p', { className: 'in-muted' }, __( 'Updates sent to a provisioning URL are signed with this secret (HMAC-SHA256, header X-Invoiceninja-Signature). Keep it private; see the hosting guide for receiver code.', 'invoiceninja' ) ),
				h( 'p', null, h( 'code', { className: 'in-technical' }, data.relay_secret ) ) ) : null,

			data.events && data.events.length ? h( Fragment, null,
				h( 'h2', null, __( 'Recent events', 'invoiceninja' ) ),
				h( 'ul', null, data.events.map( ( e ) => h( 'li', { key: e.id }, new Date( e.at * 1000 ).toLocaleString(), ' · ', h( 'strong', null, e.event ), ' · ', e.client ) ) ) ) : null );
	}

	ui.addTab( { id: 'subscriptions', label: __( 'Subscriptions', 'invoiceninja' ), can: ( b ) => b.can.manage, render: SubscriptionsTab } );
}( window.invoiceNinjaAdminUI ) );
