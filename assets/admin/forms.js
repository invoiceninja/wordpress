/**
 * Invoice Ninja admin: the Settings > Forms tab and the Quote requests screen.
 *
 * Plain JavaScript on window.invoiceNinjaAdminUI (WordPress's own React), no build step.
 */
( function ( ui, data ) {
	'use strict';

	if ( ! ui || ! data ) {
		return;
	}

	const { h, Fragment, useState, useEffect, useRef, __, _n, sprintf, api, errorText, Button, Notice, Pill, External, CopyField, boot } = ui;

	// ---------- Settings > Forms ----------

	const RETENTION = [ 0, 30, 90, 180, 365 ];
	const plain = ( html ) => new window.DOMParser().parseFromString( String( html || '' ), 'text/html' ).documentElement.textContent || '';

	/**
	 * Published pages for the "Go to a page" picker, from the core REST API (up to 500).
	 */
	async function loadPages() {
		const out = [];
		for ( let page = 1; page <= 5; page++ ) {
			const rows = await window.wp.apiFetch( { path: '/wp/v2/pages?per_page=100&page=' + page + '&status=publish&orderby=title&order=asc&_fields=id,title,link' } );
			( Array.isArray( rows ) ? rows : [] ).forEach( ( p ) => out.push( { id: p.id, title: plain( p.title && p.title.rendered ) || __( '(no title)', 'invoiceninja' ), url: p.link } ) );
			if ( ! Array.isArray( rows ) || rows.length < 100 ) {
				break;
			}
		}
		return out;
	}

	function FormsSettings() {
		const [ loaded, setLoaded ] = useState( null );
		const [ s, setS ] = useState( null );
		const [ text, setText ] = useState( '' );
		const [ enabled, setEnabled ] = useState( !! data.enabled );
		const [ products, setProducts ] = useState( null );
		const [ pages, setPages ] = useState( null );
		const [ after, setAfter ] = useState( 'message' );
		const [ filter, setFilter ] = useState( '' );
		const [ busy, setBusy ] = useState( false );
		const [ message, setMessage ] = useState( '' );
		const [ error, setError ] = useState( '' );
		useEffect( () => {
			api( '/admin/forms' ).then( ( r ) => {
				setLoaded( r );
				setS( r.forms );
				setAfter( r.forms.redirect_url ? 'page' : 'message' );
				setText( r.autoreply_text || '' );
				setEnabled( !! r.enabled );
			} ).catch( ( e ) => setError( errorText( e ) ) );
			api( '/admin/products' ).then( ( rows ) => setProducts( Array.isArray( rows ) ? rows : [] ) ).catch( () => setProducts( [] ) );
			loadPages().then( setPages ).catch( () => setPages( [] ) );
		}, [] );
		if ( error && ! s ) {
			return h( Notice, { type: 'error' }, h( 'p', null, error ) );
		}
		if ( ! s ) {
			return h( 'p', null, __( 'Loading…', 'invoiceninja' ) );
		}
		const set = ( key, value ) => setS( Object.assign( {}, s, { [ key ]: value } ) );
		const chosen = String( s.products || '' ).split( ',' ).filter( Boolean );
		const needsPage = after === 'page' && ! s.redirect_url;
		const save = async () => {
			if ( needsPage ) {
				setError( __( 'Choose the page people go to after sending, or show a message instead.', 'invoiceninja' ) );
				return;
			}
			setBusy( true );
			setMessage( '' );
			setError( '' );
			try {
				const forms = Object.assign( {}, s, { redirect_url: after === 'page' ? s.redirect_url : '' } );
				const r = await api( '/admin/forms', { method: 'POST', data: { forms, autoreply_text: text, enabled } } );
				setS( r.forms );
				setAfter( r.forms.redirect_url ? 'page' : 'message' );
				setText( r.autoreply_text || '' );
				boot.settings = r.settings;
				setMessage( __( 'Settings saved.', 'invoiceninja' ) );
			} catch ( e ) {
				setError( errorText( e ) );
			}
			setBusy( false );
		};
		const row = ( label, id, control, help ) => h( 'tr', null,
			h( 'th', { scope: 'row' }, id ? h( 'label', { htmlFor: id }, label ) : label ),
			h( 'td', null, control, help ? h( 'p', { className: 'description', id: id ? id + '-help' : undefined }, help ) : null ) );
		const leads = ( loaded && loaded.settings && loaded.settings.notifications && loaded.settings.notifications.leads ) || data.leadsMode;
		const leadModes = { instant: __( 'as each request arrives', 'invoiceninja' ), daily: __( 'once a day, in a digest', 'invoiceninja' ), off: __( 'never', 'invoiceninja' ) };

		// "Go to a page": a page of this site. An address saved before this picker (or a page
		// that's gone) stays selectable as it is, so saving never changes it behind your back.
		const savedUrl = s.redirect_url || '';
		const pageList = pages || [];
		const known = pageList.some( ( p ) => p.url === savedUrl );
		const pagePicker = h( 'span', { className: 'in-field in-indent' },
			h( 'label', { htmlFor: 'in-qf-page' }, __( 'Page', 'invoiceninja' ) ),
			pages === null ? h( 'span', { className: 'in-muted' }, __( 'Loading pages…', 'invoiceninja' ) )
				: h( 'select', { id: 'in-qf-page', value: savedUrl, 'aria-invalid': needsPage ? 'true' : undefined, onChange: ( e ) => set( 'redirect_url', e.target.value ) },
					h( 'option', { value: '' }, __( 'Choose a page…', 'invoiceninja' ) ),
					savedUrl && ! known ? h( 'option', { value: savedUrl }, sprintf( /* translators: %s: web address */ __( 'Saved address: %s', 'invoiceninja' ), savedUrl ) ) : null,
					pageList.map( ( p ) => h( 'option', { key: p.id, value: p.url }, p.title ) ) ) );

		// Products: a searchable grid. Select all / None act on what the search shows.
		const list = products || [];
		const q = filter.trim().toLowerCase();
		const visible = q ? list.filter( ( p ) => p.title.toLowerCase().includes( q ) ) : list;
		const listed = list.map( ( p ) => p.id );
		const selectedCount = chosen.filter( ( id ) => listed.includes( id ) ).length;
		const setChosen = ( ids ) => set( 'products', ids.join( ',' ) );
		const productsField = h( 'fieldset', null,
			h( 'legend', { className: 'screen-reader-text' }, __( 'Products offered', 'invoiceninja' ) ),
			! products ? h( 'p', null, __( 'Loading…', 'invoiceninja' ) ) : null,
			products && ! list.length ? h( 'p', { className: 'in-muted' }, __( 'No products yet. They appear here after they sync from Invoice Ninja.', 'invoiceninja' ) ) : null,
			list.length ? h( 'div', { className: 'in-picker__bar' },
				list.length > 8 ? h( 'input', { type: 'search', className: 'in-picker__search', value: filter, placeholder: __( 'Search products', 'invoiceninja' ), 'aria-label': __( 'Search products', 'invoiceninja' ), 'aria-controls': 'in-qf-products', onChange: ( e ) => setFilter( e.target.value ) } ) : null,
				h( Button, { link: true, disabled: ! visible.length, onClick: () => setChosen( chosen.concat( visible.map( ( p ) => p.id ).filter( ( id ) => ! chosen.includes( id ) ) ) ) }, q ? __( 'Select shown', 'invoiceninja' ) : __( 'Select all', 'invoiceninja' ) ),
				h( Button, { link: true, disabled: ! visible.some( ( p ) => chosen.includes( p.id ) ), onClick: () => setChosen( chosen.filter( ( id ) => ! visible.some( ( p ) => p.id === id ) ) ) }, q ? __( 'Clear shown', 'invoiceninja' ) : __( 'None', 'invoiceninja' ) ),
				h( 'span', { className: 'in-muted', role: 'status' }, sprintf( /* translators: 1: selected, 2: total */ __( '%1$d of %2$d selected', 'invoiceninja' ), selectedCount, list.length ) ) ) : null,
			list.length ? h( 'div', { id: 'in-qf-products', className: 'in-check-grid in-picker__grid' + ( list.length > 30 ? ' is-long' : '' ) },
				visible.map( ( p ) => h( 'label', { key: p.id, className: 'in-check' },
					h( 'input', { type: 'checkbox', checked: chosen.includes( p.id ), onChange: ( e ) => setChosen( e.target.checked ? chosen.concat( [ p.id ] ) : chosen.filter( ( id ) => id !== p.id ) ) } ),
					h( 'span', { className: 'in-check__inline' }, p.title ) ) ),
				! visible.length ? h( 'p', { className: 'in-muted' }, __( 'No products match.', 'invoiceninja' ) ) : null ) : null,
			h( 'p', { className: 'description' }, __( 'The default list for forms that don’t choose their own products. “Not sure yet” is always offered too.', 'invoiceninja' ) ) );

		const keep = parseInt( s.retention_days, 10 ) || 0;
		const keepOptions = RETENTION.includes( keep ) ? RETENTION : RETENTION.concat( [ keep ] ).sort( ( a, b ) => a - b );

		return h( Fragment, null,
			h( 'h2', null, __( 'Quote Request Form', 'invoiceninja' ) ),
			h( 'table', { className: 'form-table', role: 'presentation' }, h( 'tbody', null,
				row( __( 'Form', 'invoiceninja' ), null, h( Fragment, null,
					h( 'label', { className: 'in-check' },
						h( 'input', { type: 'checkbox', checked: enabled, onChange: ( e ) => setEnabled( e.target.checked ) } ),
						h( 'span', null, h( 'strong', null, __( 'Turn on the Quote Request Form', 'invoiceninja' ) ),
							h( 'span', { className: 'in-muted' }, __( 'Each request becomes a draft quote in Invoice Ninja.', 'invoiceninja' ) ) ) ),
					h( 'p', { className: 'in-row in-indent' },
						h( 'span', null, __( 'Add the Quote Request Form block, or this shortcode:', 'invoiceninja' ) ),
						h( CopyField, { value: '[invoiceninja_quote_form]', label: __( 'Shortcode', 'invoiceninja' ) } ) ) ) ),
				row( __( 'Reply time', 'invoiceninja' ), 'in-qf-days', h( 'span', { className: 'in-row' },
					h( 'span', null, __( 'We’ll reply within', 'invoiceninja' ) ),
					h( 'input', { id: 'in-qf-days', type: 'number', min: 0, max: 60, className: 'small-text', value: s.reply_days, 'aria-describedby': 'in-qf-days-help', onChange: ( e ) => set( 'reply_days', e.target.value ) } ),
					h( 'span', null, __( 'days', 'invoiceninja' ) ) ), __( 'Shown under the form and in the confirmation. 0 hides it.', 'invoiceninja' ) ),
				row( __( 'After sending', 'invoiceninja' ), null, h( 'fieldset', null,
					h( 'legend', { className: 'screen-reader-text' }, __( 'After sending', 'invoiceninja' ) ),
					h( 'label', { className: 'in-check' },
						h( 'input', { type: 'radio', name: 'in-qf-after', checked: after === 'message', onChange: () => setAfter( 'message' ) } ),
						h( 'span', null, h( 'strong', null, __( 'Show a message', 'invoiceninja' ) ), h( 'span', { className: 'in-muted' }, __( 'The confirmation takes the place of the form.', 'invoiceninja' ) ) ) ),
					h( 'label', { className: 'in-check' },
						h( 'input', { type: 'radio', name: 'in-qf-after', checked: after === 'page', onChange: () => setAfter( 'page' ) } ),
						h( 'span', null, h( 'strong', null, __( 'Go to a page', 'invoiceninja' ) ), h( 'span', { className: 'in-muted' }, __( 'For example a thank-you page.', 'invoiceninja' ) ) ) ),
					after === 'page' ? pagePicker : null ) ),
				row( __( 'Tags', 'invoiceninja' ), 'in-qf-tags', h( 'input', { id: 'in-qf-tags', className: 'regular-text', value: s.tags, 'aria-describedby': 'in-qf-tags-help', onChange: ( e ) => set( 'tags', e.target.value ) } ),
					__( 'Added to each quote in Invoice Ninja. Separate tags with commas. Needs Invoice Ninja 5.13.25 or newer.', 'invoiceninja' ) ),
				row( __( 'Products offered', 'invoiceninja' ), null, productsField ) ) ),
			h( 'h2', null, __( 'Emails', 'invoiceninja' ) ),
			h( 'table', { className: 'form-table', role: 'presentation' }, h( 'tbody', null,
				row( __( 'To you', 'invoiceninja' ), null, h( 'p', { className: 'in-flush' },
					sprintf( /* translators: %s: e.g. "once a day, in a digest" */ __( 'You’re emailed about new requests %s.', 'invoiceninja' ), leadModes[ leads ] || leads ), ' ',
					h( 'a', { href: data.advanced }, __( 'Change in Advanced', 'invoiceninja' ) ) ) ),
				row( __( 'Auto-reply', 'invoiceninja' ), null, h( 'label', { className: 'in-check' },
					h( 'input', { type: 'checkbox', checked: !! s.autoreply, onChange: ( e ) => set( 'autoreply', e.target.checked ) } ),
					h( 'span', null, h( 'strong', null, __( 'Email people a confirmation when they send a request', 'invoiceninja' ) ),
						h( 'span', { className: 'in-muted' }, __( 'Off by default. Your own words only: the email never repeats what people typed, except their first name.', 'invoiceninja' ) ) ) ) ),
				s.autoreply ? row( __( 'Subject', 'invoiceninja' ), 'in-qf-subject', h( 'input', { id: 'in-qf-subject', className: 'large-text', value: s.autoreply_subject, placeholder: __( 'We received your quote request · {site}', 'invoiceninja' ), onChange: ( e ) => set( 'autoreply_subject', e.target.value ) } ) ) : null,
				s.autoreply ? row( __( 'Message', 'invoiceninja' ), 'in-qf-text', h( 'textarea', { id: 'in-qf-text', className: 'large-text', rows: 6, value: text, 'aria-describedby': 'in-qf-text-help', placeholder: __( 'Thanks for your request. We’ll reply within {days} days.', 'invoiceninja' ), onChange: ( e ) => setText( e.target.value ) } ),
					__( 'You can use {first_name}, {site} and {days}.', 'invoiceninja' ) ) : null ) ),
			h( 'h2', null, __( 'Privacy', 'invoiceninja' ) ),
			h( 'table', { className: 'form-table', role: 'presentation' }, h( 'tbody', null,
				row( __( 'Keep requests on this site', 'invoiceninja' ), 'in-qf-keep',
					h( 'select', { id: 'in-qf-keep', value: String( keep ), 'aria-describedby': 'in-qf-keep-help', onChange: ( e ) => set( 'retention_days', parseInt( e.target.value, 10 ) ) },
						keepOptions.map( ( d ) => h( 'option', { key: d, value: String( d ) }, d === 0 ? __( 'Forever', 'invoiceninja' ) : sprintf( /* translators: %d: number of days */ _n( 'For %d day', 'For %d days', d, 'invoiceninja' ), d ) ) ) ),
					__( 'Older requests are deleted from this site. Clients and quotes in Invoice Ninja are never deleted.', 'invoiceninja' ) ) ) ),
			error ? h( Notice, { type: 'error' }, h( 'p', null, error ) ) : null,
			h( 'p', { className: 'in-row' }, h( Button, { primary: true, busy, onClick: save }, __( 'Save changes', 'invoiceninja' ) ), h( 'span', { role: 'status' }, message ) ) );
	}

	ui.addTab( { id: 'forms', label: __( 'Forms', 'invoiceninja' ), can: ( b ) => b.can.manage, render: FormsSettings } );

	// ---------- Quote requests ----------

	const LEAD_STATUS = {
		queued: [ 'info', __( 'Queued', 'invoiceninja' ) ],
		in_ninja: [ 'success', __( 'In Invoice Ninja', 'invoiceninja' ) ],
		failed: [ 'error', __( 'Failed', 'invoiceninja' ) ],
		spam: [ 'warning', __( 'Held as spam', 'invoiceninja' ) ],
	};
	const QUOTE_STATUS = {
		draft: __( 'Draft', 'invoiceninja' ),
		sent: __( 'Sent', 'invoiceninja' ),
		approved: __( 'Approved', 'invoiceninja' ),
		converted: __( 'Approved · invoiced', 'invoiceninja' ),
		rejected: __( 'Rejected', 'invoiceninja' ),
		cancelled: __( 'Cancelled', 'invoiceninja' ),
		expired: __( 'Expired', 'invoiceninja' ),
		deleted: __( 'Deleted in Invoice Ninja', 'invoiceninja' ),
	};

	function QuoteRequests() {
		const params = new URLSearchParams( window.location.search );
		const [ filters, setFilters ] = useState( { status: params.get( 'status' ) || '', search: params.get( 's' ) || '', page: 1 } );
		const [ list, setList ] = useState( null );
		const [ error, setError ] = useState( '' );
		const [ message, setMessage ] = useState( '' );
		const [ busy, setBusy ] = useState( {} );
		const [ open, setOpen ] = useState( null );
		const latest = useRef( 0 );
		const filtersRef = useRef( filters );
		filtersRef.current = filters;
		const load = async ( f ) => {
			const ticket = ++latest.current;
			setError( '' );
			try {
				const q = new URLSearchParams( { status: f.status, search: f.search, page: String( f.page ) } );
				const result = await api( '/admin/quote-requests?' + q.toString() );
				if ( ticket === latest.current ) {
					setList( result );
				}
			} catch ( e ) {
				if ( ticket === latest.current ) {
					setError( errorText( e ) );
					setList( ( prev ) => prev || { items: [], total: 0, counts: {} } );
				}
			}
		};
		useEffect( () => {
			load( filters );
		}, [ filters.status, filters.page ] );
		const setBusyFor = ( id, value ) => setBusy( ( prev ) => Object.assign( {}, prev, { [ id ]: value } ) );
		const retry = async ( lead ) => {
			setBusyFor( lead.id, 'retry' );
			setMessage( '' );
			try {
				const updated = await api( '/admin/quote-requests/' + lead.id + '/retry', { method: 'POST' } );
				setMessage( updated.status === 'in_ninja'
					? sprintf( /* translators: %s: quote number */ __( 'Sent. Quote %s is a draft in Invoice Ninja.', 'invoiceninja' ), updated.quote_number || '' )
					: ( updated.reason || __( "It couldn't be sent yet. We'll keep trying.", 'invoiceninja' ) ) );
			} catch ( e ) {
				setMessage( errorText( e ) );
			}
			setBusyFor( lead.id, '' );
			load( filtersRef.current );
		};
		const remove = async ( lead ) => {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( __( 'Delete this request from WordPress? Its client and quote in Invoice Ninja are kept.', 'invoiceninja' ) ) ) {
				return;
			}
			setBusyFor( lead.id, 'delete' );
			try {
				await api( '/admin/quote-requests/' + lead.id, { method: 'DELETE' } );
				setMessage( __( 'Request deleted.', 'invoiceninja' ) );
			} catch ( e ) {
				setMessage( errorText( e ) );
			}
			setBusyFor( lead.id, '' );
			load( filtersRef.current );
		};
		const counts = list ? list.counts : {};
		const total = Object.keys( counts ).reduce( ( n, k ) => n + counts[ k ], 0 );
		const views = [
			[ '', __( 'All', 'invoiceninja' ), total ],
			[ 'queued', __( 'Queued', 'invoiceninja' ), counts.queued || 0 ],
			[ 'in_ninja', __( 'In Invoice Ninja', 'invoiceninja' ), counts.in_ninja || 0 ],
			[ 'failed', __( 'Failed', 'invoiceninja' ), counts.failed || 0 ],
		].concat( counts.spam ? [ [ 'spam', __( 'Held as spam', 'invoiceninja' ), counts.spam ] ] : [] );
		const pages = list ? Math.max( 1, Math.ceil( list.total / 20 ) ) : 1;
		const setStatus = ( status ) => setFilters( Object.assign( {}, filters, { status, page: 1 } ) );
		const failed = counts.failed || 0;
		const cols = 5;
		return h( Fragment, null,
			h( 'h1', null, __( 'Quote requests', 'invoiceninja' ) ),
			! data.enabled ? h( Notice, { type: 'info', title: __( 'The Quote Request Form is off.', 'invoiceninja' ) },
				h( 'p', null, __( 'Earlier requests are still here.', 'invoiceninja' ), ' ', boot.can.manage ? h( 'a', { href: data.settingsUrl }, __( 'Turn it on in Settings → Forms', 'invoiceninja' ) ) : null ) ) : null,
			failed > 0 && filters.status !== 'failed' ? h( Notice, { type: 'error', title: sprintf( /* translators: %d: number of requests */ _n( "%d request couldn't be sent to Invoice Ninja.", "%d requests couldn't be sent to Invoice Ninja.", failed, 'invoiceninja' ), failed ) },
				h( 'p', { className: 'in-row' }, __( 'They are saved here. Fix the problem, then retry.', 'invoiceninja' ), ' ', h( Button, { onClick: () => setStatus( 'failed' ) }, __( 'Show them', 'invoiceninja' ) ) ) ) : null,
			h( 'ul', { className: 'subsubsub in-views' }, views.map( ( v, i ) => h( 'li', { key: v[ 0 ] || 'all' },
				h( 'a', { href: '#', className: filters.status === v[ 0 ] ? 'current' : '', 'aria-current': filters.status === v[ 0 ] ? 'page' : undefined, onClick: ( e ) => {
					e.preventDefault();
					setStatus( v[ 0 ] );
				} }, v[ 1 ], ' ', h( 'span', { className: 'count' }, '(' + v[ 2 ] + ')' ) ),
				i < views.length - 1 ? ' |' : '' ) ) ),
			h( 'form', { className: 'in-filters', role: 'search', onSubmit: ( e ) => {
				e.preventDefault();
				const f = Object.assign( {}, filters, { page: 1 } );
				setFilters( f );
				load( f );
			} },
				h( 'span', { className: 'in-field' }, h( 'label', { htmlFor: 'in-lead-q' }, __( 'Name or email', 'invoiceninja' ) ),
					h( 'input', { id: 'in-lead-q', type: 'search', value: filters.search, onChange: ( e ) => setFilters( Object.assign( {}, filters, { search: e.target.value } ) ) } ) ),
				h( 'button', { type: 'submit', className: 'button' }, __( 'Search', 'invoiceninja' ) ) ),
			h( 'div', { role: 'status', className: 'in-live' },
				error ? h( Notice, { type: 'error' }, h( 'p', null, error ) ) : null,
				message ? h( Notice, { type: 'info' }, h( 'p', null, message ) ) : null ),
			h( 'table', { className: 'wp-list-table widefat striped in-orders in-leads' },
				h( 'thead', null, h( 'tr', null,
					h( 'th', { scope: 'col' }, __( 'From', 'invoiceninja' ) ),
					h( 'th', { scope: 'col' }, __( 'Request', 'invoiceninja' ) ),
					h( 'th', { scope: 'col' }, __( 'Status', 'invoiceninja' ) ),
					h( 'th', { scope: 'col' }, __( 'Quote', 'invoiceninja' ) ),
					h( 'th', { scope: 'col' }, __( 'Received', 'invoiceninja' ) ) ) ),
				h( 'tbody', null,
					! list ? h( 'tr', null, h( 'td', { colSpan: cols }, __( 'Loading…', 'invoiceninja' ) ) ) : null,
					list && list.items.length === 0 ? h( 'tr', null, h( 'td', { colSpan: cols },
						filters.status || filters.search ? __( 'No requests match.', 'invoiceninja' ) : __( 'No quote requests yet. They appear here as soon as someone sends the Quote Request Form.', 'invoiceninja' ) ) ) : null,
					list ? list.items.map( ( l ) => {
						const [ tone, label ] = LEAD_STATUS[ l.status ] || [ 'neutral', l.status ];
						const actions = [];
						if ( boot.can.manage && ( l.status === 'failed' || l.status === 'queued' ) ) {
							actions.push( h( Button, { key: 'retry', link: true, busy: busy[ l.id ] === 'retry', onClick: () => retry( l ) }, l.status === 'failed' ? __( 'Retry', 'invoiceninja' ) : __( 'Send now', 'invoiceninja' ) ) );
						}
						if ( boot.can.manage && l.status === 'spam' ) {
							actions.push( h( Button, { key: 'retry', link: true, busy: busy[ l.id ] === 'retry', onClick: () => retry( l ) }, __( 'Not spam: send it', 'invoiceninja' ) ) );
						}
						if ( l.client_url ) {
							actions.push( h( External, { key: 'client', href: l.client_url }, __( 'Client', 'invoiceninja' ) ) );
						}
						actions.push( h( Button, { key: 'more', link: true, 'aria-expanded': open === l.id ? 'true' : 'false', 'aria-controls': 'in-lead-' + l.id, onClick: () => setOpen( open === l.id ? null : l.id ) }, open === l.id ? __( 'Hide details', 'invoiceninja' ) : __( 'Details', 'invoiceninja' ) ) );
						if ( boot.can.manage ) {
							actions.push( h( Button, { key: 'delete', link: true, className: 'button-link in-link-danger', busy: busy[ l.id ] === 'delete', onClick: () => remove( l ) }, __( 'Delete', 'invoiceninja' ) ) );
						}
						return h( Fragment, { key: l.id },
							h( 'tr', { className: l.status === 'failed' ? 'is-attention' : '' },
								h( 'td', null,
									h( 'strong', null, l.name || l.email ),
									l.name ? h( 'div', { className: 'in-muted' }, l.email ) : null,
									l.matched ? h( 'div', null, h( Pill, { tone: 'neutral' }, __( 'Matches existing client', 'invoiceninja' ) ) ) : null,
									h( 'div', { className: 'in-row-actions' }, actions.map( ( a, i ) => h( Fragment, { key: i }, i ? h( 'span', { className: 'in-sep', 'aria-hidden': 'true' }, ' | ' ) : null, a ) ) ) ),
								h( 'td', null,
									l.product ? h( 'div', null, h( 'strong', null, l.product ) ) : null,
									h( 'div', { className: 'in-muted' }, l.message.length > 140 ? l.message.slice( 0, 140 ) + '…' : l.message ) ),
								h( 'td', null,
									h( Pill, { tone }, label ),
									l.status === 'in_ninja' && l.quote_status ? h( 'div', { className: 'in-muted' }, QUOTE_STATUS[ l.quote_status ] || l.quote_status ) : null,
									l.reason ? h( 'div', { className: l.status === 'failed' ? 'in-note in-note--error' : 'in-note' }, l.reason ) : null ),
								h( 'td', null, l.quote_url
									? h( External, { href: l.quote_url }, l.quote_number ? sprintf( /* translators: %s: quote number */ __( 'Open quote %s in Invoice Ninja', 'invoiceninja' ), l.quote_number ) : __( 'Open in Invoice Ninja', 'invoiceninja' ) )
									: h( 'span', { className: 'in-muted' }, __( 'Not yet', 'invoiceninja' ) ) ),
								h( 'td', null, new Date( l.created_at.replace( ' ', 'T' ) + 'Z' ).toLocaleString() ) ),
							open === l.id ? h( 'tr', { className: 'in-confirm-row' }, h( 'td', { colSpan: cols },
								h( 'dl', { className: 'in-dl', id: 'in-lead-' + l.id },
									h( 'dt', null, __( 'Phone', 'invoiceninja' ) ), h( 'dd', null, l.phone || '—' ),
									l.quantity ? [ h( 'dt', { key: 'qt' }, __( 'Quantity', 'invoiceninja' ) ), h( 'dd', { key: 'qd' }, l.quantity ) ] : null,
									l.needed_by ? [ h( 'dt', { key: 'nt' }, __( 'Needed by', 'invoiceninja' ) ), h( 'dd', { key: 'nd' }, l.needed_by ) ] : null,
									h( 'dt', null, __( 'Message', 'invoiceninja' ) ), h( 'dd', { style: { whiteSpace: 'pre-wrap' } }, l.message || '—' ),
									l.consents.map( ( c ) => h( Fragment, { key: c.which },
										h( 'dt', null, c.title || c.which ),
										h( 'dd', null, c.agreed ? sprintf( /* translators: %s: date and time */ __( 'Agreed %s', 'invoiceninja' ), new Date( c.at * 1000 ).toLocaleString() ) : __( 'Not agreed', 'invoiceninja' ) ) ) ) ) ) ) : null );
					} ) : null ) ),
			h( 'div', { className: 'tablenav' },
				h( 'span', { className: 'in-muted' }, list ? sprintf( /* translators: %d: number */ _n( '%d request', '%d requests', list.total, 'invoiceninja' ), list.total ) : '' ),
				h( 'span', { className: 'in-row in-push' },
					h( Button, { disabled: filters.page <= 1, onClick: () => setFilters( Object.assign( {}, filters, { page: filters.page - 1 } ) ), 'aria-label': __( 'Previous page', 'invoiceninja' ) }, '‹' ),
					h( 'span', null, sprintf( /* translators: 1: page, 2: pages */ __( '%1$d of %2$d', 'invoiceninja' ), filters.page, pages ) ),
					h( Button, { disabled: filters.page >= pages, onClick: () => setFilters( Object.assign( {}, filters, { page: filters.page + 1 } ) ), 'aria-label': __( 'Next page', 'invoiceninja' ) }, '›' ) ) ) );
	}

	ui.addScreen( 'quotes', QuoteRequests );
}( window.invoiceNinjaAdminUI, window.invoiceNinjaFormsAdmin ) );
