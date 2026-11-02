/**
 * Invoice Ninja admin screens: Home, Setup, Orders, Settings and Status.
 *
 * Plain JavaScript on WordPress's own React (wp.element), so there is no build step.
 * Text never goes through innerHTML; React escapes everything.
 */
( function ( wp, boot ) {
	'use strict';

	if ( ! wp || ! boot ) {
		return;
	}

	const { createElement: h, Fragment, useState, useEffect, useRef, createRoot } = wp.element;
	const { __, _n, sprintf } = wp.i18n;

	const api = ( path, options ) =>
		wp.apiFetch( Object.assign( { path: '/invoiceninja/v1' + path }, options || {} ) );

	const errorText = ( e ) =>
		( e && ( e.message || ( e.data && e.data.message ) ) ) || __( 'Something went wrong. Try again.', 'invoiceninja' );

	// ---------- Small parts ----------

	const ICONS = {
		pass: [ '#008a20', 'M6 10.2l2.6 2.6L14 7.4' ],
		fail: [ '#d63638', 'M7 7l6 6M13 7l-6 6' ],
		info: [ '#2271b1', 'M10 9v5' ],
	};

	function StatusIcon( { kind } ) {
		const labels = {
			pass: __( 'Passed', 'invoiceninja' ),
			warn: __( 'Warning', 'invoiceninja' ),
			fail: __( 'Problem', 'invoiceninja' ),
			info: __( 'Information', 'invoiceninja' ),
			running: __( 'In progress', 'invoiceninja' ),
			skip: __( 'Skipped', 'invoiceninja' ),
			todo: __( 'Not done yet', 'invoiceninja' ),
		};
		let shape;
		if ( kind === 'warn' ) {
			shape = h( Fragment, null,
				h( 'path', { d: 'M10 1.8l8.6 15.4H1.4z', fill: '#dba617', stroke: '#996800', strokeWidth: 1.4, strokeLinejoin: 'round' } ),
				h( 'path', { d: 'M10 7.5v4.2', stroke: '#1d2327', strokeWidth: 2, strokeLinecap: 'round' } ),
				h( 'circle', { cx: 10, cy: 14.4, r: 1.1, fill: '#1d2327' } ) );
		} else if ( kind === 'running' ) {
			shape = h( Fragment, null,
				h( 'circle', { cx: 10, cy: 10, r: 7.5, fill: 'none', stroke: '#c3c4c7', strokeWidth: 2.5 } ),
				h( 'path', { d: 'M10 2.5a7.5 7.5 0 0 1 7.5 7.5', fill: 'none', stroke: '#2271b1', strokeWidth: 2.5, strokeLinecap: 'round', className: 'in-spin' } ) );
		} else if ( kind === 'skip' || kind === 'todo' ) {
			shape = h( Fragment, null,
				h( 'circle', { cx: 10, cy: 10, r: 8.5, fill: '#fff', stroke: '#646970', strokeWidth: 1.5 } ),
				kind === 'skip' ? h( 'path', { d: 'M6.5 10h7', stroke: '#50575e', strokeWidth: 2, strokeLinecap: 'round' } ) : null );
		} else {
			const [ fill, path ] = ICONS[ kind ] || ICONS.info;
			shape = h( Fragment, null,
				h( 'circle', { cx: 10, cy: 10, r: 9, fill } ),
				h( 'path', { d: path, fill: 'none', stroke: '#fff', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round' } ),
				kind === 'info' ? h( 'circle', { cx: 10, cy: 6, r: 1.2, fill: '#fff' } ) : null );
		}
		return h( 'span', { className: 'in-icon', role: 'img', 'aria-label': labels[ kind ] || kind },
			h( 'svg', { width: 20, height: 20, viewBox: '0 0 20 20', 'aria-hidden': 'true' }, shape ) );
	}

	function Pill( { tone, children } ) {
		return h( 'span', { className: 'in-pill in-pill--' + ( tone || 'neutral' ) }, children );
	}

	function Notice( { type, title, children } ) {
		return h( 'div', { className: 'in-notice in-notice--' + ( type || 'info' ) },
			title ? h( 'strong', null, title ) : null,
			children );
	}

	function External( { href, children, className } ) {
		return h( 'a', { href, target: '_blank', rel: 'noopener noreferrer', className },
			children, ' ', h( 'span', { 'aria-hidden': 'true' }, '↗' ),
			h( 'span', { className: 'screen-reader-text' }, __( '(opens in a new tab)', 'invoiceninja' ) ) );
	}

	function Button( props ) {
		const { primary, danger, link, busy, children, ...rest } = props;
		const cls = [ 'button', primary ? 'button-primary' : '', danger ? 'in-button-danger' : '', link ? 'button-link' : '' ].join( ' ' ).trim();
		return h( 'button', Object.assign( { type: 'button', className: cls }, rest, { disabled: !! ( busy || rest.disabled ), 'aria-busy': busy ? 'true' : undefined } ), children );
	}

	function ErrorDetails( { error } ) {
		if ( ! error ) {
			return null;
		}
		return h( Notice, { type: 'error', title: error.title },
			h( 'p', null, error.fix ),
			error.technical ? h( 'details', null,
				h( 'summary', null, __( 'Technical details', 'invoiceninja' ) ),
				h( 'code', { className: 'in-technical' }, error.technical ) ) : null );
	}

	function Card( { title, children, className, headerExtra } ) {
		return h( 'section', { className: 'in-card ' + ( className || '' ) },
			title ? h( 'div', { className: 'in-card__head' }, h( 'h2', null, title ), headerExtra || null ) : null,
			children );
	}

	// ---------- Connection card ----------

	function ConnectionCard( { connection } ) {
		const state = connection.state;
		const company = state.company || {};
		const tones = { connected: [ 'success', __( 'Connected', 'invoiceninja' ) ], error: [ 'error', __( 'Problem', 'invoiceninja' ) ], disconnected: [ 'neutral', __( 'Not connected', 'invoiceninja' ) ] };
		const [ tone, label ] = tones[ state.status ] || tones.disconnected;
		const initials = ( company.name || 'IN' ).split( /\s+/ ).map( ( w ) => w[ 0 ] ).join( '' ).slice( 0, 2 ).toUpperCase();
		return h( Card, { title: __( 'Connection', 'invoiceninja' ), headerExtra: h( Pill, { tone }, label ), className: 'in-connection' },
			h( 'div', { className: 'in-company' },
				h( 'div', { className: 'in-company__logo', 'aria-hidden': 'true' }, initials ),
				h( 'div', null,
					h( 'strong', null, company.name || __( 'Invoice Ninja', 'invoiceninja' ) ),
					h( 'div', { className: 'in-muted' }, [ state.version ? sprintf( /* translators: %s: version */ __( 'Invoice Ninja %s', 'invoiceninja' ), state.version ) : '', connection.url.replace( /^https?:\/\//, '' ) ].filter( Boolean ).join( ' · ' ) ) ) ),
			h( 'dl', { className: 'in-dl' },
				h( 'dt', null, __( 'Payments', 'invoiceninja' ) ),
				h( 'dd', null, state.gateways && state.gateways.length ? state.gateways.join( ', ' ) : __( 'No gateway yet', 'invoiceninja' ) ),
				h( 'dt', null, __( 'Orders', 'invoiceninja' ) ),
				h( 'dd', null, connection.environment.allowed ? __( 'Allowed (live site)', 'invoiceninja' ) : __( 'Paused on this site', 'invoiceninja' ) ),
				h( 'dt', null, __( 'Checked', 'invoiceninja' ) ),
				h( 'dd', null, state.checked_at ? new Date( state.checked_at * 1000 ).toLocaleString() : '—' ) ),
			h( 'p', { className: 'in-links' },
				h( External, { href: connection.url }, __( 'Open Invoice Ninja', 'invoiceninja' ) ),
				boot.can.manage ? h( 'a', { href: boot.urls.settings }, __( 'Connection settings', 'invoiceninja' ) ) : null ) );
	}

	// ---------- Home ----------

	function Home() {
		const connection = boot.connection;
		const connected = connection.source !== 'none';
		if ( ! connected ) {
			return h( Fragment, null,
				h( 'h1', null, __( 'Invoice Ninja', 'invoiceninja' ) ),
				h( Card, { className: 'in-hero' },
					h( 'h2', { className: 'in-hero__title' }, __( 'Connect Invoice Ninja to start selling', 'invoiceninja' ) ),
					h( 'ul', null,
						h( 'li', null, __( 'Sell your products. Every order becomes an invoice with a payment link.', 'invoiceninja' ) ),
						h( 'li', null, __( 'Give customers a page with their invoices, quotes and payments.', 'invoiceninja' ) ),
						h( 'li', null, __( 'Take quote requests and subscriptions, or invoice WooCommerce orders.', 'invoiceninja' ) ) ),
					h( 'p', { className: 'in-row' },
						h( 'a', { className: 'button button-primary button-hero', href: boot.urls.setup }, __( 'Start setup', 'invoiceninja' ) ),
						h( 'span', { className: 'in-muted' }, __( 'About 5 minutes', 'invoiceninja' ) ) ),
					h( 'p', null, h( 'strong', null, __( "You'll need", 'invoiceninja' ) ), ' ',
						__( 'your Invoice Ninja address and an API token from an admin. Hosted Invoice Ninja needs a Pro or Enterprise plan to connect; self-hosted works on any plan.', 'invoiceninja' ) ) ),
				h( Card, { className: 'in-row-card' },
					h( 'strong', null, __( 'New to Invoice Ninja?', 'invoiceninja' ) ),
					h( 'span', { className: 'in-muted' }, __( "It's free and open source.", 'invoiceninja' ) ),
					h( External, { className: 'button', href: 'https://invoiceninja.github.io/en/self-host-installation/' }, __( 'Self-host it for free', 'invoiceninja' ) ),
					h( External, { className: 'button', href: 'https://invoiceninja.com/pricing-plans/' }, __( 'Hosted, Pro plan', 'invoiceninja' ) ) ) );
		}

		const diag = boot.diagnostics;
		const problems = diag && diag.checks ? diag.checks.filter( ( c ) => c.status === 'fail' || c.status === 'warn' ) : [];
		const mail = connection.mail_test;
		const todo = [];
		if ( ! mail || mail.confirmed === false ) {
			todo.push( { kind: mail ? 'warn' : 'todo', title: __( 'Make sure checkout emails arrive', 'invoiceninja' ), body: __( 'Buyers need these emails for their code and payment link.', 'invoiceninja' ), href: boot.urls.status, action: __( 'Send a test', 'invoiceninja' ) } );
		}
		if ( ! connection.environment.allowed ) {
			todo.push( { kind: 'warn', title: __( 'Orders are paused on this site', 'invoiceninja' ), body: __( 'Allow orders when this is your live site.', 'invoiceninja' ), href: boot.urls.status + '&tab=tools', action: __( 'Review', 'invoiceninja' ) } );
		}
		if ( ! connection.state.gateways || ! connection.state.gateways.length ) {
			todo.push( { kind: 'warn', title: __( 'Add a payment gateway', 'invoiceninja' ), body: __( "Customers can't pay online until a gateway is set up in Invoice Ninja.", 'invoiceninja' ), href: connection.url + '/settings/online_payments', action: __( 'Set up payments', 'invoiceninja' ), external: true } );
		}

		return h( Fragment, null,
			h( 'h1', null, __( 'Invoice Ninja', 'invoiceninja' ) ),
			boot.upgradedFrom === '1.x' ? h( Notice, { type: 'info', title: __( 'Welcome to Invoice Ninja 2.0', 'invoiceninja' ) },
				h( 'p', null, __( 'Your shop looks the same: the 1.x products and settings are under Products & Settings (legacy) until the new store arrives.', 'invoiceninja' ), ' ',
					h( 'a', { href: boot.urls.legacy }, __( 'Open Products & Settings', 'invoiceninja' ) ) ) ) : null,
			h( 'div', { className: 'in-columns' },
				h( 'div', { className: 'in-main' },
					problems.length ? h( Card, { title: sprintf( /* translators: %d: number */ __( 'Needs your attention (%d)', 'invoiceninja' ), problems.length ), headerExtra: h( 'a', { href: boot.urls.status }, __( 'View checks', 'invoiceninja' ) ) },
						h( 'ul', { className: 'in-list' }, problems.map( ( p ) => h( 'li', { key: p.id, className: 'in-list__item' },
							h( StatusIcon, { kind: p.status } ),
							h( 'div', null, h( 'strong', null, p.label ), h( 'div', null, p.detail ) ) ) ) ) )
						: diag ? h( Card, { className: 'in-row-card' }, h( StatusIcon, { kind: 'pass' } ), h( 'strong', null, __( 'No problems found', 'invoiceninja' ) ), h( 'a', { href: boot.urls.status }, __( 'View checks', 'invoiceninja' ) ) ) : null,
					todo.length ? h( Card, { title: __( 'Finish setting up', 'invoiceninja' ) },
						h( 'ul', { className: 'in-list' }, todo.map( ( t, i ) => h( 'li', { key: i, className: 'in-list__item' },
							h( StatusIcon, { kind: t.kind } ),
							h( 'div', { className: 'in-grow' }, h( 'strong', null, t.title ), h( 'div', { className: 'in-muted' }, t.body ) ),
							t.external ? h( External, { className: 'button', href: t.href }, t.action ) : h( 'a', { className: 'button', href: t.href }, t.action ) ) ) ) ) : null,
					h( Card, { title: __( 'Coming next', 'invoiceninja' ) },
						h( 'p', { className: 'in-muted' }, __( 'This alpha connects your site and checks it. The store, customer account, quotes and subscriptions arrive in the next alphas.', 'invoiceninja' ) ) ) ),
				h( 'aside', { className: 'in-aside' }, h( ConnectionCard, { connection } ) ) ) );
	}

	// ---------- Setup wizard ----------

	function Stepper( { steps, index, onJump } ) {
		return h( 'nav', { 'aria-label': __( 'Setup progress', 'invoiceninja' ), className: 'in-stepper' },
			h( 'ol', { style: { gridTemplateColumns: 'repeat(' + steps.length + ', minmax(0, 1fr))' } },
				steps.map( ( s, i ) => {
					const done = i < index;
					const now = i === index;
					return h( 'li', { key: s.id, 'aria-current': now ? 'step' : undefined, className: done ? 'is-done' : now ? 'is-now' : '' },
						h( 'button', { type: 'button', disabled: ! done, onClick: () => onJump( i ) },
							h( 'span', { className: 'in-stepper__dot', 'aria-hidden': 'true' }, done ? '✓' : String( i + 1 ) ),
							h( 'span', { className: 'in-stepper__label' }, s.label ),
							h( 'span', { className: 'screen-reader-text' }, done ? __( '(completed)', 'invoiceninja' ) : now ? __( '(current step)', 'invoiceninja' ) : '' ) ) );
				} ) ),
			h( 'div', { className: 'in-stepper__compact' },
				h( 'strong', null, steps[ Math.min( index, steps.length - 1 ) ].label ), ' ',
				h( 'span', { className: 'in-muted' }, sprintf( /* translators: 1: step, 2: total */ __( '· Step %1$d of %2$d', 'invoiceninja' ), Math.min( index + 1, steps.length ), steps.length ) ) ) );
	}

	function ChoiceCards( { name, value, onChange, options } ) {
		return h( 'fieldset', { className: 'in-choices' },
			h( 'legend', { className: 'screen-reader-text' }, name ),
			options.map( ( o ) => h( 'label', { key: o.id, className: 'in-choice' + ( value === o.id ? ' is-selected' : '' ) },
				h( 'span', { className: 'in-choice__title' },
					h( 'input', { type: 'radio', name, checked: value === o.id, onChange: () => onChange( o.id ) } ),
					h( 'strong', null, o.title ) ),
				h( 'span', { className: 'in-muted' }, o.desc ),
				o.note ? h( 'span', { className: 'in-choice__note in-choice__note--' + o.noteKind }, h( StatusIcon, { kind: o.noteKind } ), o.note ) : null ) ) );
	}

	function hostOf( raw ) {
		try {
			return new URL( normalizePreview( raw ) ).hostname.toLowerCase();
		} catch ( e ) {
			return '';
		}
	}

	function normalizePreview( raw ) {
		let u = String( raw || '' ).trim().replace( /\/+$/, '' ).replace( /\/api\/v1$/i, '' ).replace( /\/+$/, '' );
		if ( ! u ) {
			return '';
		}
		if ( ! /^https?:\/\//i.test( u ) ) {
			u = 'https://' + u;
		}
		return u;
	}

	function Setup() {
		const existing = boot.connection;
		const env = existing.environment;
		const [ where, setWhere ] = useState( existing.url ? ( existing.hosted ? 'hosted' : 'self' ) : null );
		const [ url, setUrl ] = useState( existing.hosted ? '' : existing.url );
		const [ token, setToken ] = useState( '' );
		const [ showToken, setShowToken ] = useState( false );
		// A private-network permission only ever applies to the host it was given for.
		const [ allowPrivateHost, setAllowPrivateHost ] = useState( existing.state.private_host || '' );
		const [ test, setTest ] = useState( null );
		const [ busy, setBusy ] = useState( false );
		const [ error, setError ] = useState( '' );
		const [ switchInfo, setSwitchInfo ] = useState( null );
		const [ woo, setWoo ] = useState( 'woo' );
		const [ features, setFeatures ] = useState( Object.assign( {}, ( boot.settings || {} ).modules || {} ) );
		const [ mail, setMail ] = useState( 'idle' );
		const [ mailTo, setMailTo ] = useState( boot.userEmail );
		const [ paused, setPaused ] = useState( false );
		const [ step, setStep ] = useState( 0 );
		// Decided once, so allowing orders doesn't remove the step and shift the steps after it.
		const [ askEnvironment ] = useState( ! env.allowed && ! env.looks_live );
		const head = useRef( null );

		const steps = [ { id: 'where', label: __( 'Connect', 'invoiceninja' ) }, { id: 'token', label: __( 'Token', 'invoiceninja' ) }, { id: 'company', label: __( 'Company', 'invoiceninja' ) } ];
		if ( boot.wooActive ) {
			steps.push( { id: 'woo', label: __( 'WooCommerce', 'invoiceninja' ) } );
		}
		if ( askEnvironment ) {
			steps.push( { id: 'environment', label: __( 'This site', 'invoiceninja' ) } );
		}
		steps.push( { id: 'features', label: __( 'Features', 'invoiceninja' ) }, { id: 'email', label: __( 'Email', 'invoiceninja' ) } );
		const done = step >= steps.length;
		const current = done ? 'done' : steps[ step ].id;

		useEffect( () => {
			if ( head.current ) {
				head.current.focus();
			}
		}, [ step ] );

		const go = ( n ) => {
			setError( '' );
			setStep( Math.max( 0, Math.min( steps.length, n ) ) );
		};

		const payload = () => ( where === 'hosted' ? { hosted: true } : { url } );
		const allowPrivate = where === 'self' && !! allowPrivateHost && allowPrivateHost === hostOf( url );

		const runTest = async () => {
			setBusy( true );
			setError( '' );
			setTest( { running: true } );
			try {
				setTest( await api( '/connection/test', { method: 'POST', data: Object.assign( payload(), { token, allow_private: allowPrivate } ) } ) );
			} catch ( e ) {
				setTest( null );
				setError( errorText( e ) );
			}
			setBusy( false );
		};

		const save = async ( confirmSwitch ) => {
			setBusy( true );
			setError( '' );
			try {
				const result = await api( '/connection', { method: 'POST', data: Object.assign( payload(), { token, allow_private: allowPrivate, confirm_switch: !! confirmSwitch } ) } );
				if ( result.needs_switch_confirmation ) {
					setSwitchInfo( result.needs_switch_confirmation );
				} else if ( result.saved ) {
					boot.connection = result.connection;
					setSwitchInfo( null );
					go( step + 1 );
				} else if ( result.error ) {
					setError( result.error.title + '. ' + result.error.fix );
				}
			} catch ( e ) {
				setError( errorText( e ) );
			}
			setBusy( false );
		};

		const saveFeatures = async () => {
			setBusy( true );
			const modules = Object.assign( {}, features );
			if ( boot.wooActive ) {
				modules.woo = woo === 'woo';
				if ( woo === 'woo' ) {
					modules.store = false;
				}
			}
			try {
				boot.settings = await api( '/settings', { method: 'POST', data: { modules } } );
				go( step + 1 );
			} catch ( e ) {
				setError( errorText( e ) );
			}
			setBusy( false );
		};

		const environment = async ( action ) => {
			setBusy( true );
			try {
				boot.connection = await api( '/environment', { method: 'POST', data: { action } } );
				setPaused( action === 'pause' );
				go( step + 1 );
			} catch ( e ) {
				setError( errorText( e ) );
			}
			setBusy( false );
		};

		const sendMail = async () => {
			setBusy( true );
			try {
				const r = await api( '/mail/test', { method: 'POST', data: { to: mailTo } } );
				setMail( r.sent ? 'sent' : 'failed' );
			} catch ( e ) {
				setError( errorText( e ) );
			}
			setBusy( false );
		};

		const answerMail = async ( arrived ) => {
			setMail( arrived ? 'yes' : 'no' );
			try {
				await api( '/mail/confirm', { method: 'POST', data: { arrived } } );
			} catch ( e ) {
				setError( errorText( e ) );
			}
		};

		const titles = {
			where: [ __( 'Where is your Invoice Ninja?', 'invoiceninja' ), __( 'Connect the Invoice Ninja account that holds your products, clients and invoices.', 'invoiceninja' ) ],
			token: [ __( 'Paste your API token', 'invoiceninja' ), __( 'The token lets this site read your products and create invoices. We test it before going on.', 'invoiceninja' ) ],
			company: [ __( 'Is this the right company?', 'invoiceninja' ), __( 'Products, clients and invoices will come from this company.', 'invoiceninja' ) ],
			woo: [ __( 'Who runs checkout?', 'invoiceninja' ), __( 'WooCommerce is active on this site. You can change this later.', 'invoiceninja' ) ],
			environment: [ __( 'Is this your live site?', 'invoiceninja' ), __( 'This looks like a staging or development copy, so orders are paused until you say otherwise.', 'invoiceninja' ) ],
			features: [ __( 'What would you like to use?', 'invoiceninja' ), __( 'Turn on what you need now. Everything else stays out of the way.', 'invoiceninja' ) ],
			email: [ __( 'Check that email works', 'invoiceninja' ), __( 'Shoppers get their 6-digit code and payment link by email, so it has to arrive.', 'invoiceninja' ) ],
			done: [ __( 'All set', 'invoiceninja' ), '' ],
		};

		let body = null;
		let canContinue = true;
		let hint = '';
		let continueLabel = __( 'Continue', 'invoiceninja' );
		let onContinue = () => go( step + 1 );
		const tokenError = test && test.error;

		if ( current === 'where' ) {
			const normalized = normalizePreview( url );
			canContinue = where === 'hosted' || ( where === 'self' && !! normalized );
			hint = ! where ? __( 'Choose where your Invoice Ninja is.', 'invoiceninja' ) : where === 'none' ? __( 'Come back when you have Invoice Ninja.', 'invoiceninja' ) : where === 'self' && ! normalized ? __( 'Enter your Invoice Ninja address.', 'invoiceninja' ) : '';
			body = h( Fragment, null,
				h( ChoiceCards, { name: __( 'Where is your Invoice Ninja?', 'invoiceninja' ), value: where, onChange: setWhere, options: [
					{ id: 'hosted', title: __( 'Hosted', 'invoiceninja' ), desc: __( 'Run by Invoice Ninja at invoicing.co', 'invoiceninja' ), note: __( 'Needs a Pro or Enterprise plan', 'invoiceninja' ), noteKind: 'warn' },
					{ id: 'self', title: __( 'Self-hosted', 'invoiceninja' ), desc: __( 'Installed on your own server', 'invoiceninja' ), note: __( 'Works on any plan', 'invoiceninja' ), noteKind: 'pass' },
					{ id: 'none', title: __( "I don't have it yet", 'invoiceninja' ), desc: __( 'Self-host it for free, or start a hosted Pro account', 'invoiceninja' ) },
				] } ),
				where === 'self' ? h( 'div', { className: 'in-field' },
					h( 'label', { htmlFor: 'in-url' }, __( 'Invoice Ninja address', 'invoiceninja' ) ),
					h( 'input', { id: 'in-url', className: 'regular-text', type: 'text', inputMode: 'url', autoComplete: 'url', spellCheck: false, value: url, placeholder: 'billing.example.com', onChange: ( e ) => setUrl( e.target.value ) } ),
					h( 'p', { className: 'description' }, __( "The address you use to log in to Invoice Ninja. Paste it as it is; we'll fix the format.", 'invoiceninja' ) ),
					normalized ? h( 'p', null, __( "We'll connect to", 'invoiceninja' ), ' ', h( 'strong', null, normalized ) ) : null ) : null,
				where === 'none' ? h( Notice, { type: 'info', title: __( 'Get Invoice Ninja first', 'invoiceninja' ) },
					h( 'p', null, __( 'Invoice Ninja is free, open-source invoicing. Self-hosted connects on any plan. Hosted accounts need a Pro or Enterprise plan to connect.', 'invoiceninja' ) ),
					h( 'p', { className: 'in-row' },
						h( External, { className: 'button', href: 'https://invoiceninja.github.io/en/self-host-installation/' }, __( 'Self-host it for free', 'invoiceninja' ) ),
						h( External, { className: 'button', href: 'https://invoiceninja.com/pricing-plans/' }, __( 'Create a hosted account (Pro plan)', 'invoiceninja' ) ) ),
					h( 'p', { className: 'in-muted' }, __( "We'll keep your place here. Come back with an API token.", 'invoiceninja' ) ) ) : null );
		}

		if ( current === 'token' ) {
			canContinue = !! ( test && test.ok );
			hint = canContinue ? '' : __( 'Test the connection to continue.', 'invoiceninja' );
			const base = where === 'hosted' ? 'https://invoicing.co' : normalizePreview( url );
			const checks = test && test.checks ? test.checks : null;
			body = h( Fragment, null,
				h( 'div', { className: 'in-two' },
					h( 'ol', null,
						h( 'li', null, __( 'Log in to Invoice Ninja as an admin.', 'invoiceninja' ) ),
						h( 'li', null, __( 'Go to Settings → Account Management → API Tokens.', 'invoiceninja' ), ' ', h( External, { href: base + '/settings/account_management' }, __( 'Open it', 'invoiceninja' ) ) ),
						h( 'li', null, __( 'Click New Token, name it “WordPress”, and copy it.', 'invoiceninja' ) ) ),
					h( Notice, { type: 'info' }, h( 'p', null, __( 'Each company in Invoice Ninja has its own token. Use the company this shop sells for.', 'invoiceninja' ) ) ) ),
				h( 'div', { className: 'in-field' },
					h( 'label', { htmlFor: 'in-token' }, __( 'API token', 'invoiceninja' ) ),
					h( 'div', { className: 'in-row' },
						h( 'input', { id: 'in-token', className: 'regular-text code', type: showToken ? 'text' : 'password', autoComplete: 'off', spellCheck: false, value: token, placeholder: existing.token_hint ? sprintf( /* translators: %s: last characters */ __( 'Saved token ending in %s', 'invoiceninja' ), existing.token_hint ) : '', onChange: ( e ) => { setToken( e.target.value ); setTest( null ); } } ),
						h( Button, { 'aria-pressed': showToken ? 'true' : 'false', onClick: () => setShowToken( ! showToken ) }, showToken ? __( 'Hide', 'invoiceninja' ) : __( 'Show', 'invoiceninja' ) ) ),
					h( 'p', { className: 'description' }, __( 'Stored encrypted and never shown again.', 'invoiceninja' ) ) ),
				h( 'p', { className: 'in-row' },
					h( Button, { primary: ! canContinue, busy, onClick: runTest, disabled: ! token && ! existing.token_hint }, test && test.running ? __( 'Testing…', 'invoiceninja' ) : test ? __( 'Test again', 'invoiceninja' ) : __( 'Test connection', 'invoiceninja' ) ),
					h( 'span', { role: 'status', className: 'in-muted' }, test && test.running ? __( 'Checking…', 'invoiceninja' ) : test && test.ok ? __( 'All checks passed.', 'invoiceninja' ) : test && test.error ? __( 'A problem was found.', 'invoiceninja' ) : '' ) ),
				checks ? h( 'ul', { className: 'in-checks' }, checks.map( ( c ) => h( 'li', { key: c.id, className: 'is-' + c.status },
					h( StatusIcon, { kind: c.status } ),
					h( 'strong', null, c.label ),
					h( 'span', { className: 'in-muted' }, c.detail ) ) ) ) : null,
				tokenError ? h( ErrorDetails, { error: tokenError } ) : null,
				tokenError && tokenError.kind === 'private_host' ? h( 'p', null, h( Button, { onClick: () => { setAllowPrivateHost( hostOf( url ) ); setTest( null ); } }, sprintf( /* translators: %s: host */ __( 'Allow %s', 'invoiceninja' ), hostOf( url ) ) ) ) : null,
				tokenError && tokenError.kind === 'redirect' && tokenError.location ? h( 'p', null, h( Button, { onClick: () => { setWhere( 'self' ); setUrl( tokenError.location.replace( /\/api\/v1.*$/, '' ) ); setTest( null ); go( 0 ); } }, sprintf( /* translators: %s: host */ __( 'Check the address it redirects to (%s)', 'invoiceninja' ), hostOf( tokenError.location ) ) ) ) : null,
				tokenError && tokenError.kind === 'plan_required' ? h( 'p', { className: 'in-row' },
					h( External, { className: 'button button-primary', href: 'https://invoicing.co' }, __( 'Upgrade in Invoice Ninja', 'invoiceninja' ) ),
					h( Button, { onClick: runTest }, __( "I've upgraded, check again", 'invoiceninja' ) ) ) : null );
		}

		if ( current === 'company' && test && test.company ) {
			const c = test.company;
			continueLabel = __( 'Yes, use this company', 'invoiceninja' );
			onContinue = () => save( false );
			body = h( Fragment, null,
				h( 'div', { className: 'in-company in-company--large' },
					h( 'div', { className: 'in-company__logo', 'aria-hidden': 'true' }, ( c.name || 'IN' ).slice( 0, 2 ).toUpperCase() ),
					h( 'div', null,
						h( 'strong', null, c.name ),
						h( 'div', { className: 'in-muted' }, [ c.email, sprintf( /* translators: %s: version */ __( 'Invoice Ninja %s', 'invoiceninja' ), test.version ) ].filter( Boolean ).join( ' · ' ) ),
						h( 'div', { className: 'in-row' }, h( StatusIcon, { kind: test.gateways.length ? 'pass' : 'warn' } ),
							test.gateways.length ? sprintf( /* translators: %s: gateway names */ __( 'Online payments: %s', 'invoiceninja' ), test.gateways.join( ', ' ) ) : __( 'No payment gateway yet. You can finish setup and add one later.', 'invoiceninja' ) ),
						c.portal_password ? h( 'div', { className: 'in-row' }, h( StatusIcon, { kind: 'info' } ), __( 'Customers will be asked for their Invoice Ninja portal password.', 'invoiceninja' ) ) : null ) ),
				switchInfo ? h( Notice, { type: 'warning', title: sprintf( /* translators: %s: company */ __( 'Switch to %s?', 'invoiceninja' ), switchInfo.to ) },
					h( 'p', null, sprintf( /* translators: %s: company */ __( 'This site is linked to %s. Switching starts fresh with the new company: products from the old company move to Drafts, and customers are matched again on their next order.', 'invoiceninja' ), switchInfo.from ) ),
					h( 'p', { className: 'in-row' },
						h( Button, { onClick: () => setSwitchInfo( null ) }, __( 'Cancel', 'invoiceninja' ) ),
						h( Button, { danger: true, busy, onClick: () => save( true ) }, sprintf( /* translators: %s: company */ __( 'Switch to %s', 'invoiceninja' ), switchInfo.to ) ) ) ) : null,
				h( Button, { link: true, onClick: () => { setTest( null ); go( 1 ); } }, __( 'Not this company? Use a different token', 'invoiceninja' ) ) );
		} else if ( current === 'company' ) {
			canContinue = false;
			body = h( 'p', null, __( 'Test the connection first.', 'invoiceninja' ) );
		}

		if ( current === 'woo' ) {
			body = h( ChoiceCards, { name: __( 'Who runs checkout?', 'invoiceninja' ), value: woo, onChange: setWoo, options: [
				{ id: 'woo', title: __( 'WooCommerce runs checkout', 'invoiceninja' ), desc: __( 'Keep your WooCommerce cart and checkout. Each order becomes an Invoice Ninja invoice and payment, so your books stay in one place.', 'invoiceninja' ), note: __( 'Recommended', 'invoiceninja' ), noteKind: 'pass' },
				{ id: 'in', title: __( "Invoice Ninja's store blocks", 'invoiceninja' ), desc: __( 'Sell Invoice Ninja products with our cart and checkout. Your site will have two separate carts.', 'invoiceninja' ) },
			] } );
		}

		if ( current === 'environment' ) {
			body = h( Fragment, null,
				h( Notice, { type: 'warning', title: __( 'Why we think so', 'invoiceninja' ) },
					h( 'p', null, sprintf( /* translators: 1: environment type, 2: host */ __( 'WordPress says this site is a %1$s site, and its address (%2$s) is new to us.', 'invoiceninja' ), env.type, boot.siteHost ) ) ),
				h( 'p', { className: 'in-row' },
					h( Button, { primary: true, busy, onClick: () => environment( 'pause' ) }, __( 'Keep orders paused', 'invoiceninja' ) ),
					h( Button, { busy, onClick: () => environment( 'allow' ) }, __( 'Allow orders from this site', 'invoiceninja' ) ) ),
				h( 'p', { className: 'in-muted' }, __( 'Allowing orders means checkout creates real invoices and takes real payments from this site.', 'invoiceninja' ) ),
				h( 'details', null, h( 'summary', null, __( 'For developers', 'invoiceninja' ) ), h( 'p', null, __( 'Change it later in Status → Tools, or run', 'invoiceninja' ), ' ', h( 'code', null, 'wp invoiceninja environment confirm' ) ) ) );
		}

		if ( current === 'features' ) {
			onContinue = saveFeatures;
			const defs = [];
			if ( ! boot.wooActive || woo === 'in' ) {
				defs.push( [ 'store', __( 'Store', 'invoiceninja' ), __( 'Product, cart and checkout blocks. Buyers get an invoice and pay online.', 'invoiceninja' ) ] );
			}
			defs.push( [ 'account', __( 'Customer account', 'invoiceninja' ), __( 'Customers see their invoices, quotes, payments and subscriptions on your site.', 'invoiceninja' ) ] );
			defs.push( [ 'quote_form', __( 'Quote Request Form', 'invoiceninja' ), __( 'A form that creates a draft quote in Invoice Ninja.', 'invoiceninja' ) ] );
			defs.push( [ 'subs', __( 'Subscriptions', 'invoiceninja' ), __( 'Pricing tables and subscribe buttons for your Invoice Ninja subscriptions.', 'invoiceninja' ) ] );
			body = h( 'fieldset', { className: 'in-features' },
				h( 'legend', { className: 'screen-reader-text' }, __( 'Features to turn on', 'invoiceninja' ) ),
				defs.map( ( [ id, title, desc ] ) => h( 'label', { key: id },
					h( 'input', { type: 'checkbox', checked: !! features[ id ], onChange: () => setFeatures( Object.assign( {}, features, { [ id ]: ! features[ id ] } ) ) } ),
					h( 'span', null, h( 'strong', null, title ), h( 'span', { className: 'in-muted' }, desc ) ) ) ),
				h( 'p', { className: 'in-muted' }, __( 'The store, account, form and subscription blocks arrive in the next alphas; your choices are saved now.', 'invoiceninja' ) ) );
		}

		if ( current === 'email' ) {
			continueLabel = __( 'Finish', 'invoiceninja' );
			body = h( Fragment, null,
				h( 'div', { className: 'in-row in-row--end' },
					h( 'span', { className: 'in-field' },
						h( 'label', { htmlFor: 'in-mail' }, __( 'Send a test to', 'invoiceninja' ) ),
						h( 'input', { id: 'in-mail', type: 'email', className: 'regular-text', value: mailTo, onChange: ( e ) => setMailTo( e.target.value ) } ) ),
					h( Button, { busy, onClick: sendMail }, __( 'Send test email', 'invoiceninja' ) ) ),
				mail === 'sent' ? h( 'p', { role: 'status', className: 'in-row' }, __( 'Sent. Did it reach your inbox?', 'invoiceninja' ),
					h( Button, { onClick: () => answerMail( true ) }, __( 'Yes', 'invoiceninja' ) ),
					h( Button, { onClick: () => answerMail( false ) }, __( 'No', 'invoiceninja' ) ) ) : null,
				mail === 'yes' ? h( 'p', { role: 'status', className: 'in-row' }, h( StatusIcon, { kind: 'pass' } ), __( 'Email works. Codes and payment links will arrive.', 'invoiceninja' ) ) : null,
				mail === 'no' || mail === 'failed' ? h( Notice, { type: 'warning', title: mail === 'failed' ? __( "WordPress couldn't send the email", 'invoiceninja' ) : __( 'Check spam first', 'invoiceninja' ) },
					h( 'p', null, __( "Many hosts don't deliver WordPress email reliably. An SMTP plugin that sends through your email provider fixes it.", 'invoiceninja' ) ),
					h( 'p', { className: 'in-muted' }, __( 'You can still finish. Home keeps a reminder until a test arrives.', 'invoiceninja' ) ) ) : null );
		}

		if ( current === 'done' ) {
			const gateways = ( boot.connection.state.gateways || [] ).length > 0;
			const isPaused = paused || ! boot.connection.environment.allowed;
			body = h( Fragment, null,
				isPaused ? h( Notice, { type: 'warning', title: __( 'Set up, but orders are paused on this site', 'invoiceninja' ) }, h( 'p', null, __( 'This looks like a staging copy, so checkout stays closed. Allow orders in Status → Tools when this is your live site.', 'invoiceninja' ) ) )
					: ! gateways ? h( Notice, { type: 'warning', title: __( "Connected, but customers can't pay online yet", 'invoiceninja' ) }, h( 'p', null, __( 'Add a payment gateway in Invoice Ninja so payment links can take cards.', 'invoiceninja' ) ) )
						: h( Notice, { type: 'success', title: __( 'Connected to Invoice Ninja', 'invoiceninja' ) }, h( 'p', null, __( 'Everything checks out.', 'invoiceninja' ) ) ),
				h( 'p', { className: 'in-row' },
					h( 'a', { className: 'button button-primary', href: boot.urls.home }, __( 'Go to Home', 'invoiceninja' ) ),
					h( 'a', { className: 'button', href: boot.urls.status }, __( 'View checks', 'invoiceninja' ) ) ) );
		}

		const [ title, sub ] = titles[ current ];
		const showNav = current !== 'done' && current !== 'environment';

		return h( Fragment, null,
			h( 'div', { className: 'in-row in-row--baseline' },
				h( 'h1', null, __( 'Set up Invoice Ninja', 'invoiceninja' ) ),
				h( 'a', { href: boot.urls.home, className: 'in-push' }, __( 'Exit setup', 'invoiceninja' ) ) ),
			boot.connection.locked ? h( Notice, { type: 'info', title: __( 'The connection is set in wp-config.php', 'invoiceninja' ) }, h( 'p', null, __( 'It can only be changed there. Status → Checks shows whether it works.', 'invoiceninja' ) ) ) : null,
			h( 'div', { className: 'in-wizard' },
				h( Stepper, { steps, index: done ? steps.length : step, onJump: go } ),
				h( 'section', { className: 'in-card in-wizard__card', 'aria-labelledby': 'in-step-title' },
					h( 'h2', { id: 'in-step-title', tabIndex: -1, ref: head }, title ),
					sub ? h( 'p', { className: 'in-muted' }, sub ) : null,
					error ? h( Notice, { type: 'error' }, h( 'p', null, error ) ) : null,
					body ),
				showNav ? h( 'p', { className: 'in-row' },
					step > 0 ? h( Button, { onClick: () => go( step - 1 ) }, __( 'Back', 'invoiceninja' ) ) : null,
					h( Button, { primary: true, busy, disabled: ! canContinue, onClick: onContinue, 'aria-describedby': 'in-continue-hint' }, continueLabel ),
					h( 'span', { id: 'in-continue-hint', className: 'in-muted' }, hint ) ) : null ) );
	}

	// ---------- Settings ----------

	function Settings() {
		const params = new URLSearchParams( window.location.search );
		const [ tab, setTab ] = useState( [ 'store', 'advanced' ].includes( params.get( 'tab' ) ) ? params.get( 'tab' ) : 'connection' );
		const tabs = [ [ 'connection', __( 'Connection', 'invoiceninja' ) ], [ 'store', __( 'Store', 'invoiceninja' ) ], [ 'advanced', __( 'Advanced', 'invoiceninja' ) ] ];
		return h( Fragment, null,
			h( 'h1', null, __( 'Settings', 'invoiceninja' ) ),
			h( Tabs, { tabs, tab, setTab } ),
			tab === 'connection' ? h( ConnectionSettings ) : h( AdvancedSettings, { key: tab, section: tab } ) );
	}

	function Tabs( { tabs, tab, setTab } ) {
		return h( 'nav', { className: 'nav-tab-wrapper', 'aria-label': __( 'Sections', 'invoiceninja' ) },
			tabs.map( ( [ id, label ] ) => h( 'a', {
				key: id, href: '#' + id, className: 'nav-tab' + ( tab === id ? ' nav-tab-active' : '' ), 'aria-current': tab === id ? 'page' : undefined,
				onClick: ( e ) => {
					e.preventDefault();
					setTab( id );
					const u = new URL( window.location.href );
					u.searchParams.set( 'tab', id );
					window.history.replaceState( null, '', u );
				},
			}, label ) ) );
	}

	function ConnectionSettings() {
		const [ conn, setConn ] = useState( boot.connection );
		const [ url, setUrl ] = useState( conn.url );
		const [ token, setToken ] = useState( '' );
		const [ busy, setBusy ] = useState( false );
		const [ result, setResult ] = useState( null );
		const [ message, setMessage ] = useState( '' );
		const [ confirmDisconnect, setConfirmDisconnect ] = useState( false );
		if ( ! boot.can.manageSite ) {
			return h( ConnectionCard, { connection: conn } );
		}
		if ( conn.locked ) {
			return h( Fragment, null,
				h( Notice, { type: 'info', title: __( 'The connection is set in wp-config.php', 'invoiceninja' ) }, h( 'p', null, __( 'INVOICENINJA_API_URL and INVOICENINJA_API_TOKEN are defined there, so they can only be changed there.', 'invoiceninja' ) ) ),
				h( ConnectionCard, { connection: conn } ) );
		}
		const urlChanged = normalizePreview( url ) !== conn.url;
		const save = async ( confirmSwitch ) => {
			setBusy( true );
			setMessage( '' );
			try {
				const r = await api( '/connection', { method: 'POST', data: { url, token, allow_private: !! conn.state.private_host && conn.state.private_host === hostOf( url ), confirm_switch: !! confirmSwitch } } );
				setResult( r );
				if ( r.saved ) {
					setConn( r.connection );
					boot.connection = r.connection;
					setToken( '' );
					setMessage( __( 'Saved. The connection works.', 'invoiceninja' ) );
				}
			} catch ( e ) {
				setMessage( errorText( e ) );
			}
			setBusy( false );
		};
		const disconnect = async () => {
			setBusy( true );
			try {
				const c = await api( '/connection', { method: 'DELETE' } );
				setConn( c );
				boot.connection = c;
				setConfirmDisconnect( false );
				setMessage( __( 'Disconnected. Products, orders and settings stay.', 'invoiceninja' ) );
			} catch ( e ) {
				setMessage( errorText( e ) );
			}
			setBusy( false );
		};
		return h( 'div', { className: 'in-columns' },
			h( 'div', { className: 'in-main' },
				h( Card, { title: __( 'Invoice Ninja connection', 'invoiceninja' ) },
					h( 'div', { className: 'in-field' },
						h( 'label', { htmlFor: 'in-url' }, __( 'Invoice Ninja address', 'invoiceninja' ) ),
						h( 'input', { id: 'in-url', className: 'regular-text', type: 'text', inputMode: 'url', value: url, onChange: ( e ) => setUrl( e.target.value ) } ),
						urlChanged ? h( 'p', { className: 'description' }, __( 'Changing the address needs the token again.', 'invoiceninja' ) ) : null ),
					h( 'div', { className: 'in-field' },
						h( 'label', { htmlFor: 'in-token' }, __( 'API token', 'invoiceninja' ) ),
						h( 'input', { id: 'in-token', className: 'regular-text code', type: 'password', autoComplete: 'off', value: token, placeholder: conn.token_hint ? sprintf( /* translators: %s: last characters */ __( 'Saved token ending in %s', 'invoiceninja' ), conn.token_hint ) : __( 'Paste a token', 'invoiceninja' ), onChange: ( e ) => setToken( e.target.value ) } ),
						h( 'p', { className: 'description' }, __( 'Leave empty to keep the saved token. A token for another company switches this site to it.', 'invoiceninja' ) ) ),
					h( 'p', { className: 'in-row' },
						h( Button, { primary: true, busy, onClick: () => save( false ), disabled: urlChanged && ! token }, __( 'Test and save', 'invoiceninja' ) ),
						h( 'span', { role: 'status' }, message ) ),
					result && result.error ? h( ErrorDetails, { error: result.error } ) : null,
					result && result.needs_switch_confirmation ? h( Notice, { type: 'warning', title: sprintf( /* translators: %s: company */ __( 'Switch to %s?', 'invoiceninja' ), result.needs_switch_confirmation.to ) },
						h( 'p', null, sprintf( /* translators: %s: company */ __( 'This token is for a different company. Switching starts fresh: products from %s move to Drafts, and customers are matched again on their next order.', 'invoiceninja' ), result.needs_switch_confirmation.from ) ),
						h( 'p', { className: 'in-row' },
							h( Button, { onClick: () => setResult( null ) }, __( 'Cancel', 'invoiceninja' ) ),
							h( Button, { danger: true, busy, onClick: () => save( true ) }, sprintf( /* translators: %s: company */ __( 'Switch to %s', 'invoiceninja' ), result.needs_switch_confirmation.to ) ) ) ) : null ),
				conn.source !== 'none' ? h( 'section', { className: 'in-danger' },
					h( 'h2', null, __( 'Danger zone', 'invoiceninja' ) ),
					h( 'p', null, h( 'strong', null, __( 'Disconnect from Invoice Ninja.', 'invoiceninja' ) ), ' ', __( 'Removes the token and stops syncing. Products, orders and settings stay.', 'invoiceninja' ) ),
					confirmDisconnect ? h( 'p', { className: 'in-row' },
						h( Button, { onClick: () => setConfirmDisconnect( false ) }, __( 'Keep connected', 'invoiceninja' ) ),
						h( Button, { danger: true, busy, onClick: disconnect }, __( 'Disconnect', 'invoiceninja' ) ) )
						: h( Button, { danger: true, onClick: () => setConfirmDisconnect( true ) }, __( 'Disconnect…', 'invoiceninja' ) ) ) : null ),
			h( 'aside', { className: 'in-aside' }, h( ConnectionCard, { connection: conn } ) ) );
	}

	function AdvancedSettings( { section } ) {
		const [ s, setS ] = useState( boot.settings );
		const [ busy, setBusy ] = useState( false );
		const [ message, setMessage ] = useState( '' );
		if ( ! s ) {
			return h( 'p', null, __( "You don't have permission to change these settings.", 'invoiceninja' ) );
		}
		const set = ( group, key, value ) => setS( Object.assign( {}, s, group ? { [ group ]: Object.assign( {}, s[ group ], { [ key ]: value } ) } : { [ key ]: value } ) );
		const save = async () => {
			setBusy( true );
			setMessage( '' );
			try {
				// Only this tab's settings: the other tab may have changed meanwhile, and shop managers can't send email settings.
				// A hidden, cleared days field isn't the admin's change: send the saved value back.
				const store = Object.assign( {}, s.store );
				if ( ! store.abandoned_enabled && ! ( parseInt( store.abandoned_days, 10 ) >= 1 ) ) {
					store.abandoned_days = ( boot.settings.store && boot.settings.store.abandoned_days ) || 14;
				}
				const data = section === 'store' ? { store } : Object.assign( {}, s, { store: undefined } );
				if ( section !== 'store' && ! boot.can.manageSite ) {
					// Only site administrators may send these; leave them out so the rest saves.
					delete data.email;
					delete data.delete_data_on_uninstall;
					data.notifications = Object.assign( {}, data.notifications, { recipients: undefined } );
				}
				const saved = await api( '/settings', { method: 'POST', data } );
				setS( saved );
				boot.settings = saved;
				setMessage( __( 'Settings saved.', 'invoiceninja' ) );
			} catch ( e ) {
				setMessage( errorText( e ) );
			}
			setBusy( false );
		};
		const row = ( label, id, control, help ) => h( 'tr', null,
			h( 'th', { scope: 'row' }, id ? h( 'label', { htmlFor: id }, label ) : label ),
			h( 'td', null, control, help ? h( 'p', { className: 'description' }, help ) : null ) );
		const check = ( group, key, label, help ) => h( 'label', { className: 'in-check' },
			h( 'input', { type: 'checkbox', checked: !! s[ group ][ key ], onChange: ( e ) => set( group, key, e.target.checked ) } ),
			h( 'span', null, h( 'strong', null, label ), help ? h( 'span', { className: 'in-muted' }, help ) : null ) );
		const radios = ( group, key, name, options ) => h( 'fieldset', null,
			h( 'legend', { className: 'screen-reader-text' }, name ),
			options.map( ( [ v, l, help ] ) => h( 'label', { key: v, className: 'in-check' },
				h( 'input', { type: 'radio', name: 'in-' + key, checked: s[ group ][ key ] === v, onChange: () => set( group, key, v ) } ),
				h( 'span', null, h( 'strong', null, l ), help ? h( 'span', { className: 'in-muted' }, help ) : null ) ) ) );
		const saveRow = h( 'p', { className: 'in-row' }, h( Button, { primary: true, busy, onClick: save }, __( 'Save changes', 'invoiceninja' ) ), h( 'span', { role: 'status' }, message ) );
		if ( section === 'store' ) {
			return h( Fragment, null,
				h( 'h2', null, __( 'Selling', 'invoiceninja' ) ),
				h( 'table', { className: 'form-table', role: 'presentation' }, h( 'tbody', null,
					row( __( 'Online ordering', 'invoiceninja' ), null, radios( 'store', 'mode', __( 'Online ordering', 'invoiceninja' ), [
						[ 'cart', __( 'Cart', 'invoiceninja' ), __( 'Shoppers add several products, then check out.', 'invoiceninja' ) ],
						[ 'buy_now', __( 'Buy Now', 'invoiceninja' ), __( 'One product per order, straight to checkout.', 'invoiceninja' ) ],
						[ 'off', __( 'Off', 'invoiceninja' ), __( 'Show products and prices only.', 'invoiceninja' ) ],
					] ) ),
					row( __( 'Who can order', 'invoiceninja' ), null, check( 'store', 'require_login', __( 'Require customers to log in', 'invoiceninja' ), __( 'Off: guests confirm their email with a 6-digit code instead.', 'invoiceninja' ) ) ),
					null ) ),
				h( 'h2', null, __( 'Unpaid orders', 'invoiceninja' ) ),
			h( 'table', { className: 'form-table', role: 'presentation' }, h( 'tbody', null,
				row( __( 'Clean up', 'invoiceninja' ), null, h( Fragment, null,
					check( 'store', 'abandoned_enabled', __( 'Close orders that stay unpaid', 'invoiceninja' ), __( 'Off by default. Each invoice is checked again first: anything with a payment or credit is left alone.', 'invoiceninja' ) ),
					s.store.abandoned_enabled ? h( 'p', { className: 'in-row' },
						h( 'label', { htmlFor: 'in-ab-days' }, __( 'After', 'invoiceninja' ) ),
						h( 'input', { id: 'in-ab-days', type: 'number', min: 1, max: 365, className: 'small-text', value: s.store.abandoned_days, onChange: ( e ) => set( 'store', 'abandoned_days', e.target.value ) } ),
						h( 'span', null, __( 'days unpaid', 'invoiceninja' ) ) ) : null ) ),
				s.store.abandoned_enabled ? row( __( 'What to do', 'invoiceninja' ), null, h( 'fieldset', null,
					h( 'legend', { className: 'screen-reader-text' }, __( 'What to do with unpaid orders', 'invoiceninja' ) ),
					h( 'label', { className: 'in-check' },
						h( 'input', { type: 'radio', name: 'in-ab-action', checked: s.store.abandoned_action === 'cancel', onChange: () => set( 'store', 'abandoned_action', 'cancel' ) } ),
						h( 'span', null, h( 'strong', null, __( 'Cancel the invoice (recommended)', 'invoiceninja' ) ), h( 'span', { className: 'in-muted' }, __( 'The payment link stops working. The invoice stays in Invoice Ninja, so invoice numbers have no gaps.', 'invoiceninja' ) ) ) ),
					h( 'label', { className: 'in-check' },
						h( 'input', { type: 'radio', name: 'in-ab-action', checked: s.store.abandoned_action === 'delete', onChange: () => {
							// eslint-disable-next-line no-alert
							if ( window.confirm( __( 'Delete unpaid invoices? This gives their stock back, but leaves gaps in your invoice numbers, which many countries do not allow.', 'invoiceninja' ) ) ) {
								set( 'store', 'abandoned_action', 'delete' );
							}
						} } ),
						h( 'span', null, h( 'strong', null, __( 'Delete the invoice', 'invoiceninja' ) ), h( 'span', { className: 'in-muted' }, __( 'Gives the stock back. Leaves gaps in invoice numbers: check that this is allowed where you are.', 'invoiceninja' ) ) ) ) ) ) : null ) ),
				saveRow );
		}
		return h( Fragment, null,
			h( 'h2', null, __( 'Email sending', 'invoiceninja' ) ),
			h( 'table', { className: 'form-table', role: 'presentation' }, h( 'tbody', null,
				row( __( 'Sender name', 'invoiceninja' ), 'in-sender', h( 'input', { id: 'in-sender', className: 'regular-text', value: s.email.sender_name, placeholder: boot.siteHost, onChange: ( e ) => set( 'email', 'sender_name', e.target.value ) } ) ),
				row( __( 'From address', 'invoiceninja' ), 'in-from', h( 'input', { id: 'in-from', type: 'email', className: 'regular-text', value: s.email.from, onChange: ( e ) => set( 'email', 'from', e.target.value ) } ), __( "Use an address at your own domain so emails aren't marked as spam. Empty uses WordPress's default.", 'invoiceninja' ) ),
				row( __( 'Reply-to', 'invoiceninja' ), 'in-reply', h( 'input', { id: 'in-reply', type: 'email', className: 'regular-text', value: s.email.reply_to, onChange: ( e ) => set( 'email', 'reply_to', e.target.value ) } ), __( "Where shoppers' replies go.", 'invoiceninja' ) ) ) ),
			h( 'h2', null, __( 'Notifications to you', 'invoiceninja' ) ),
			h( 'p', { className: 'in-muted' }, __( "Invoice Ninja already emails you about invoices and payments, so we don't repeat those.", 'invoiceninja' ) ),
			h( 'table', { className: 'form-table', role: 'presentation' }, h( 'tbody', null,
				row( __( 'Send to', 'invoiceninja' ), 'in-rcpt', h( 'input', { id: 'in-rcpt', className: 'large-text', value: s.notifications.recipients, placeholder: boot.userEmail, onChange: ( e ) => set( 'notifications', 'recipients', e.target.value ) } ), __( 'Separate addresses with commas. Empty means the site admin email.', 'invoiceninja' ) ),
				row( __( 'Problems', 'invoiceninja' ), null, h( 'fieldset', null,
					h( 'legend', { className: 'screen-reader-text' }, __( 'Problems', 'invoiceninja' ) ),
					check( 'notifications', 'incidents', __( 'Invoice Ninja unreachable or connection lost', 'invoiceninja' ), __( 'One email when it starts and one when it recovers.', 'invoiceninja' ) ),
					check( 'notifications', 'plan', __( 'Plan ending or token needs pasting again', 'invoiceninja' ) ),
					check( 'notifications', 'attention', __( 'Orders that need attention', 'invoiceninja' ) ),
					check( 'notifications', 'failed_jobs', __( 'Background tasks that keep failing', 'invoiceninja' ), __( 'Batched: at most one email an hour.', 'invoiceninja' ) ) ) ),
				row( __( 'Quote requests', 'invoiceninja' ), null, h( 'fieldset', null,
					h( 'legend', { className: 'screen-reader-text' }, __( 'Quote requests', 'invoiceninja' ) ),
					[ [ 'instant', __( 'As each request arrives', 'invoiceninja' ) ], [ 'daily', __( 'Once a day, in a digest', 'invoiceninja' ) ], [ 'off', __( "Don't email me", 'invoiceninja' ) ] ].map( ( [ v, l ] ) => h( 'label', { key: v, className: 'in-check' },
						h( 'input', { type: 'radio', name: 'in-leads', checked: s.notifications.leads === v, onChange: () => set( 'notifications', 'leads', v ) } ), h( 'span', null, l ) ) ) ) ),
				row( __( 'New orders', 'invoiceninja' ), null, check( 'notifications', 'new_orders', __( 'Email me about every new order', 'invoiceninja' ), __( "Off by default: Invoice Ninja's own notifications cover new invoices and payments.", 'invoiceninja' ) ) ),
				row( __( 'Weekly summary', 'invoiceninja' ), null, check( 'notifications', 'weekly', __( 'Send a summary every Monday', 'invoiceninja' ) ) ) ) ),
			h( 'h2', null, __( 'Logs', 'invoiceninja' ) ),
			h( 'table', { className: 'form-table', role: 'presentation' }, h( 'tbody', null,
				row( __( 'Log detail', 'invoiceninja' ), 'in-level', h( 'select', { id: 'in-level', value: s.log_level, onChange: ( e ) => set( null, 'log_level', e.target.value ) },
					h( 'option', { value: 'warning' }, __( 'Warnings and errors', 'invoiceninja' ) ),
					h( 'option', { value: 'info' }, __( 'Activity, warnings and errors', 'invoiceninja' ) ),
					h( 'option', { value: 'debug' }, __( 'Everything (for troubleshooting)', 'invoiceninja' ) ) ), __( '“Everything” records more personal data and switches back after 24 hours.', 'invoiceninja' ) ),
				row( __( 'Keep logs for', 'invoiceninja' ), 'in-ret', h( 'span', { className: 'in-row' }, h( 'input', { id: 'in-ret', type: 'number', min: 1, max: 365, className: 'small-text', value: s.log_retention_days, onChange: ( e ) => set( null, 'log_retention_days', parseInt( e.target.value, 10 ) || 30 ) } ), __( 'days', 'invoiceninja' ) ) ) ) ),
			h( 'section', { className: 'in-danger' },
				h( 'h2', null, __( 'Danger zone', 'invoiceninja' ) ),
				h( 'label', { className: 'in-check' },
					h( 'input', { type: 'checkbox', checked: !! s.delete_data_on_uninstall, onChange: ( e ) => {
						// eslint-disable-next-line no-alert
						if ( ! e.target.checked || window.confirm( __( 'Delete all plugin data when the plugin is deleted? Nothing in Invoice Ninja is touched.', 'invoiceninja' ) ) ) {
							set( null, 'delete_data_on_uninstall', e.target.checked );
						}
					} } ),
					h( 'span', null, h( 'strong', null, __( 'Delete all plugin data when uninstalling', 'invoiceninja' ) ), h( 'span', null, __( 'Removes products, orders, quote requests and settings from WordPress. Nothing in Invoice Ninja is touched.', 'invoiceninja' ) ) ) ) ),
			saveRow );
	}

	// ---------- Status ----------

	function Status() {
		const params = new URLSearchParams( window.location.search );
		const initial = [ 'checks', 'activity', 'tools' ].includes( params.get( 'tab' ) ) ? params.get( 'tab' ) : 'checks';
		const [ tab, setTab ] = useState( initial );
		const tabs = [ [ 'checks', __( 'Checks', 'invoiceninja' ) ], [ 'activity', __( 'Activity', 'invoiceninja' ) ], [ 'tools', __( 'Tools', 'invoiceninja' ) ] ];
		return h( Fragment, null,
			h( 'h1', null, __( 'Status', 'invoiceninja' ) ),
			h( Tabs, { tabs, tab, setTab } ),
			tab === 'checks' ? h( Checks ) : tab === 'activity' ? h( Activity ) : h( Tools ) );
	}

	function Checks() {
		const [ diag, setDiag ] = useState( boot.diagnostics );
		const [ running, setRunning ] = useState( false );
		const [ showPassed, setShowPassed ] = useState( false );
		const [ message, setMessage ] = useState( '' );
		const [ review, setReview ] = useState( null );
		const applySync = async () => {
			try {
				const r = await api( '/sync/products', { method: 'POST', data: { force: true } } );
				setReview( null );
				setMessage( syncMessage( r.result ) );
				await run();
			} catch ( e ) {
				setMessage( errorText( e ) );
			}
		};
		const run = async () => {
			setRunning( true );
			setMessage( '' );
			try {
				const d = await api( '/diagnostics' );
				setDiag( d );
				boot.diagnostics = d;
			} catch ( e ) {
				setMessage( errorText( e ) );
			}
			setRunning( false );
		};
		useEffect( () => {
			if ( ! diag || Date.now() / 1000 - diag.ran_at > 600 ) {
				run();
			}
		}, [] );
		const repair = async () => {
			setRunning( true );
			try {
				const r = await api( '/diagnostics/repair', { method: 'POST' } );
				setDiag( r.diagnostics );
				setMessage( r.done.join( ' · ' ) );
			} catch ( e ) {
				setMessage( errorText( e ) );
			}
			setRunning( false );
		};
		const act = async ( action ) => {
			try {
				if ( action === 'repair' || action === 'run_jobs' ) {
					return await repair();
				}
				if ( action === 'allow_orders' ) {
					// eslint-disable-next-line no-alert
					if ( window.confirm( __( 'Allow orders from this site? Checkout will create real invoices and take real payments.', 'invoiceninja' ) ) ) {
						boot.connection = await api( '/environment', { method: 'POST', data: { action: 'allow' } } );
						return await run();
					}
					return;
				}
				if ( action === 'review_sync' ) {
					setReview( await api( '/sync/products' ) );
					return;
				}
				if ( action === 'sync_now' ) {
					const r = await api( '/sync/products', { method: 'POST', data: {} } );
					setMessage( syncMessage( r.result ) );
					return await run();
				}
				if ( action === 'send_test' ) {
					const r = await api( '/mail/test', { method: 'POST', data: {} } );
					setMessage( r.sent ? sprintf( /* translators: %s: email */ __( 'Test email sent to %s.', 'invoiceninja' ), r.to ) : __( "WordPress couldn't send the email.", 'invoiceninja' ) );
				}
			} catch ( e ) {
				setMessage( errorText( e ) );
			}
		};
		const canAct = ( action ) => ( action === 'allow_orders' ? boot.can.manageSite : boot.can.manage );
		const actionLabels = {
			setup: [ __( 'Start setup', 'invoiceninja' ), boot.urls.setup ],
			reconnect: [ __( 'Fix connection', 'invoiceninja' ), boot.urls.settings ],
			gateways: [ __( 'Set up payments', 'invoiceninja' ), boot.connection.url + '/settings/online_payments' ],
			docs_salts: [ __( 'Show how', 'invoiceninja' ), 'https://developer.wordpress.org/advanced-administration/security/hardening/#security-keys' ],
			repair: [ __( 'Repair', 'invoiceninja' ) ],
			run_jobs: [ __( 'Repair', 'invoiceninja' ) ],
			allow_orders: [ __( 'Allow orders…', 'invoiceninja' ) ],
			send_test: [ __( 'Send a test email', 'invoiceninja' ) ],
			review_sync: [ __( 'Review changes', 'invoiceninja' ) ],
			sync_now: [ __( 'Sync now', 'invoiceninja' ) ],
		};
		const checks = diag ? diag.checks : [];
		const open = checks.filter( ( c ) => c.status !== 'pass' );
		const passed = checks.filter( ( c ) => c.status === 'pass' );
		const repairable = open.some( ( c ) => c.action === 'repair' || c.action === 'run_jobs' );
		const counts = diag ? diag.counts : null;
		return h( Fragment, null,
			h( Card, { className: 'in-checks-card', title: __( 'Checks', 'invoiceninja' ), headerExtra: h( 'span', { className: 'in-row in-push' },
				counts && counts.fail ? h( Pill, { tone: 'error' }, sprintf( /* translators: %d: number */ _n( '%d problem', '%d problems', counts.fail, 'invoiceninja' ), counts.fail ) ) : null,
				counts && counts.warn ? h( Pill, { tone: 'warning' }, sprintf( /* translators: %d: number */ _n( '%d warning', '%d warnings', counts.warn, 'invoiceninja' ), counts.warn ) ) : null,
				counts ? h( Pill, { tone: 'success' }, sprintf( /* translators: %d: number */ __( '%d passed', 'invoiceninja' ), counts.pass ) ) : null,
				h( 'span', { role: 'status', className: 'in-muted' }, running ? __( 'Running…', 'invoiceninja' ) : diag ? sprintf( /* translators: %s: time */ __( 'Last run %s', 'invoiceninja' ), new Date( diag.ran_at * 1000 ).toLocaleTimeString() ) : '' ),
				h( Button, { busy: running, onClick: run }, __( 'Run checks again', 'invoiceninja' ) ),
				repairable && boot.can.manage ? h( Button, { primary: true, busy: running, onClick: repair }, __( 'Fix problems', 'invoiceninja' ) ) : null ) },
				message ? h( Notice, { type: 'info' }, h( 'p', { role: 'status' }, message ) ) : null,
				review && review.paused ? h( Notice, { type: 'warning', title: sprintf( /* translators: 1: changes, 2: total */ __( '%1$d of %2$d products would change', 'invoiceninja' ), review.paused.changes, review.paused.total ) },
					h( 'p', null, __( "That's more than one sync normally changes, so nothing was changed. If you just edited products in bulk in Invoice Ninja, it's safe to apply.", 'invoiceninja' ) ),
					h( 'ul', { className: 'in-sample' }, ( review.paused.sample || [] ).map( ( x, i ) => h( 'li', { key: i }, ( { create: __( 'Add', 'invoiceninja' ), update: __( 'Update', 'invoiceninja' ), draft: __( 'Unpublish', 'invoiceninja' ) }[ x.action ] || x.action ) + ': ' + x.title ) ) ),
					h( 'p', { className: 'in-row' },
						h( Button, { onClick: () => setReview( null ) }, __( 'Not now', 'invoiceninja' ) ),
						boot.can.manage ? h( Button, { primary: true, onClick: applySync }, __( 'Sync anyway', 'invoiceninja' ) ) : null ) ) : null,
				h( 'ul', { className: 'in-checks in-checks--status' },
					open.map( ( c ) => {
						const a = actionLabels[ c.action ];
						return h( 'li', { key: c.id, className: 'is-' + c.status },
							h( StatusIcon, { kind: c.status } ),
							h( 'strong', null, c.label ),
							h( 'span', null, c.detail ),
							h( 'span', { className: 'in-checks__action' }, a && ( a[ 1 ] || canAct( c.action ) ) ? ( a[ 1 ] ? ( /^https?:/.test( a[ 1 ] ) && a[ 1 ].indexOf( window.location.host ) < 0 ? h( External, { className: 'button', href: a[ 1 ] }, a[ 0 ] ) : h( 'a', { className: 'button', href: a[ 1 ] }, a[ 0 ] ) ) : h( Button, { onClick: () => act( c.action ) }, a[ 0 ] ) ) : null ) );
					} ),
					passed.length ? h( 'li', { className: 'in-checks__toggle' },
						h( 'button', { type: 'button', 'aria-expanded': showPassed ? 'true' : 'false', onClick: () => setShowPassed( ! showPassed ) },
							h( StatusIcon, { kind: 'pass' } ),
							h( 'strong', null, sprintf( /* translators: %d: number */ __( '%d passed', 'invoiceninja' ), passed.length ) ),
							h( 'span', { className: 'in-push in-link' }, showPassed ? __( 'Hide', 'invoiceninja' ) : __( 'Show', 'invoiceninja' ) ) ) ) : null,
					showPassed ? passed.map( ( c ) => h( 'li', { key: c.id, className: 'is-pass' }, h( StatusIcon, { kind: 'pass' } ), h( 'strong', null, c.label ), h( 'span', { className: 'in-muted' }, c.detail ), h( 'span', null ) ) ) : null,
					! diag && running ? h( 'li', null, h( StatusIcon, { kind: 'running' } ), h( 'span', null, __( 'Running checks…', 'invoiceninja' ) ) ) : null ) ),
			h( 'p', { className: 'in-muted' }, __( 'The same checks appear in Tools → Site Health and as', 'invoiceninja' ), ' ', h( 'code', null, 'wp invoiceninja doctor' ), '.' ) );
	}

	function Activity() {
		const [ filters, setFilters ] = useState( { level: 'warning', channel: '', search: '', page: 1 } );
		const [ data, setData ] = useState( null );
		const [ openId, setOpenId ] = useState( null );
		const [ confirmClear, setConfirmClear ] = useState( false );
		const [ error, setError ] = useState( '' );
		const load = async ( f ) => {
			setError( '' );
			try {
				const q = new URLSearchParams( { level: f.level, channel: f.channel, search: f.search, page: String( f.page ), per_page: '50' } );
				setData( await api( '/logs?' + q.toString() ) );
			} catch ( e ) {
				setError( errorText( e ) );
			}
		};
		useEffect( () => {
			load( filters );
		}, [ filters.level, filters.channel, filters.page ] );
		const pages = data ? Math.max( 1, Math.ceil( data.total / 50 ) ) : 1;
		const clear = async () => {
			try {
				await api( '/logs', { method: 'DELETE' } );
				setConfirmClear( false );
				load( filters );
			} catch ( e ) {
				setError( errorText( e ) );
			}
		};
		const levelTone = { error: 'error', warning: 'warning' };
		const areas = [ '', 'connection', 'sync', 'checkout', 'webhooks', 'email', 'upgrade', 'queue' ];
		return h( Fragment, null,
			h( 'form', { className: 'in-filters', role: 'search', onSubmit: ( e ) => { e.preventDefault(); setFilters( Object.assign( {}, filters, { page: 1 } ) ); load( Object.assign( {}, filters, { page: 1 } ) ); } },
				h( 'span', { className: 'in-field' }, h( 'label', { htmlFor: 'in-lvl' }, __( 'Level', 'invoiceninja' ) ),
					h( 'select', { id: 'in-lvl', value: filters.level, onChange: ( e ) => setFilters( Object.assign( {}, filters, { level: e.target.value, page: 1 } ) ) },
						h( 'option', { value: 'warning' }, __( 'Warnings and errors', 'invoiceninja' ) ),
						h( 'option', { value: 'info' }, __( 'Everything', 'invoiceninja' ) ),
						h( 'option', { value: 'error' }, __( 'Errors only', 'invoiceninja' ) ) ) ),
				h( 'span', { className: 'in-field' }, h( 'label', { htmlFor: 'in-area' }, __( 'Area', 'invoiceninja' ) ),
					h( 'select', { id: 'in-area', value: filters.channel, onChange: ( e ) => setFilters( Object.assign( {}, filters, { channel: e.target.value, page: 1 } ) ) },
						areas.map( ( a ) => h( 'option', { key: a, value: a }, a ? a.charAt( 0 ).toUpperCase() + a.slice( 1 ) : __( 'All areas', 'invoiceninja' ) ) ) ) ),
				h( 'span', { className: 'in-field' }, h( 'label', { htmlFor: 'in-q' }, __( 'Search', 'invoiceninja' ) ),
					h( 'input', { id: 'in-q', type: 'search', value: filters.search, onChange: ( e ) => setFilters( Object.assign( {}, filters, { search: e.target.value } ) ) } ) ),
				h( 'button', { type: 'submit', className: 'button' }, __( 'Filter', 'invoiceninja' ) ) ),
			error ? h( Notice, { type: 'error' }, h( 'p', null, error ) ) : null,
			h( 'table', { className: 'wp-list-table widefat striped in-log' },
				h( 'thead', null, h( 'tr', null,
					h( 'th', { scope: 'col' }, __( 'Time', 'invoiceninja' ) ),
					h( 'th', { scope: 'col' }, __( 'Level', 'invoiceninja' ) ),
					h( 'th', { scope: 'col' }, __( 'Area', 'invoiceninja' ) ),
					h( 'th', { scope: 'col' }, __( 'What happened', 'invoiceninja' ) ) ) ),
				h( 'tbody', null,
					data && data.items.length === 0 ? h( 'tr', null, h( 'td', { colSpan: 4 }, __( 'Nothing to show yet.', 'invoiceninja' ) ) ) : null,
					data ? data.items.map( ( r ) => h( 'tr', { key: r.id, className: r.level === 'error' ? 'is-error' : '' },
						h( 'td', null, new Date( r.created_at.replace( ' ', 'T' ) + 'Z' ).toLocaleString() ),
						h( 'td', null, levelTone[ r.level ] ? h( Pill, { tone: levelTone[ r.level ] }, r.level ) : h( 'span', { className: 'in-muted' }, r.level ) ),
						h( 'td', null, r.channel ),
						h( 'td', null, r.message,
							r.context ? h( 'div', null,
								h( 'button', { type: 'button', className: 'button-link', 'aria-expanded': openId === r.id ? 'true' : 'false', onClick: () => setOpenId( openId === r.id ? null : r.id ) }, openId === r.id ? __( 'Hide details', 'invoiceninja' ) : __( 'Details', 'invoiceninja' ) ),
								openId === r.id ? h( 'pre', { className: 'in-technical' }, JSON.stringify( r.context, null, 2 ) ) : null ) : null ) ) ) : h( 'tr', null, h( 'td', { colSpan: 4 }, __( 'Loading…', 'invoiceninja' ) ) ) ) ),
			h( 'div', { className: 'tablenav' },
				h( 'span', { className: 'in-muted' }, data ? sprintf( /* translators: %d: number */ _n( '%d entry', '%d entries', data.total, 'invoiceninja' ), data.total ) + ' · ' + __( 'Emails and links are masked; tokens are never written.', 'invoiceninja' ) : '' ),
				h( 'span', { className: 'in-row in-push' },
					h( Button, { disabled: filters.page <= 1, onClick: () => setFilters( Object.assign( {}, filters, { page: filters.page - 1 } ) ), 'aria-label': __( 'Previous page', 'invoiceninja' ) }, '‹' ),
					h( 'span', null, sprintf( /* translators: 1: page, 2: pages */ __( '%1$d of %2$d', 'invoiceninja' ), filters.page, pages ) ),
					h( Button, { disabled: filters.page >= pages, onClick: () => setFilters( Object.assign( {}, filters, { page: filters.page + 1 } ) ), 'aria-label': __( 'Next page', 'invoiceninja' ) }, '›' ) ) ),
			boot.can.manage ? h( 'p', { className: 'in-row in-separated' },
				confirmClear ? h( Fragment, null,
					h( 'span', null, __( 'Delete every log entry?', 'invoiceninja' ) ),
					h( Button, { onClick: () => setConfirmClear( false ) }, __( 'Keep', 'invoiceninja' ) ),
					h( Button, { danger: true, onClick: clear }, __( 'Clear log', 'invoiceninja' ) ) )
					: h( Button, { danger: true, onClick: () => setConfirmClear( true ) }, __( 'Clear log…', 'invoiceninja' ) ) ) : null );
	}

	function Tools() {
		const [ conn, setConn ] = useState( boot.connection );
		const [ busy, setBusy ] = useState( '' );
		const [ message, setMessage ] = useState( '' );
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
		const env = conn.environment;
		const tool = ( title, body, meta, button ) => h( 'section', { className: 'in-card in-tool' }, h( 'h3', null, title ), h( 'p', null, body ), meta ? h( 'p', { className: 'in-muted' }, meta ) : null, h( 'div', { className: 'in-tool__action' }, button ) );
		return h( Fragment, null,
			message ? h( Notice, { type: 'info' }, h( 'p', { role: 'status' }, message ) ) : null,
			h( 'h2', null, __( 'Fix and sync', 'invoiceninja' ) ),
			h( 'div', { className: 'in-tools' },
				tool( __( 'Fix problems', 'invoiceninja' ), __( "Runs every check and repairs what's safe to repair automatically, such as scheduled jobs and database tables.", 'invoiceninja' ), null,
					h( Button, { primary: true, busy: busy === 'repair', disabled: ! boot.can.manage, onClick: () => run( 'repair', async () => { const r = await api( '/diagnostics/repair', { method: 'POST' } ); setMessage( r.done.join( ' · ' ) ); } ) }, __( 'Fix problems', 'invoiceninja' ) ) ),
				tool( __( 'Sync products now', 'invoiceninja' ), __( 'Fetch products from Invoice Ninja straight away instead of waiting for the hourly sync. Products are updated in place; nothing is removed because of an outage.', 'invoiceninja' ), null,
					h( Button, { busy: busy === 'sync', disabled: conn.source === 'none' || ! boot.can.manage, onClick: () => run( 'sync', async () => { const r = await api( '/sync/products', { method: 'POST', data: {} } ); setMessage( syncMessage( r.result ) ); } ) }, __( 'Sync now', 'invoiceninja' ) ) ),
				tool( __( 'Refresh company details', 'invoiceninja' ), __( 'Reload the company name, currency and payment gateways.', 'invoiceninja' ), conn.state.checked_at ? sprintf( /* translators: %s: time */ __( 'Last checked %s', 'invoiceninja' ), new Date( conn.state.checked_at * 1000 ).toLocaleString() ) : null,
					h( Button, { busy: busy === 'refresh', disabled: conn.source === 'none' || ! boot.can.manage, onClick: () => run( 'refresh', async () => { const c = await api( '/connection/refresh', { method: 'POST' } ); setConn( c ); boot.connection = c; setMessage( c.state.status === 'connected' ? __( 'Refreshed just now.', 'invoiceninja' ) : __( "Couldn't refresh. See Checks.", 'invoiceninja' ) ); } ) }, __( 'Refresh', 'invoiceninja' ) ) ) ),
			h( 'h2', null, __( 'Orders', 'invoiceninja' ) ),
			h( 'div', { className: 'in-tools' },
				tool( h( Fragment, null, __( 'Orders from this site', 'invoiceninja' ), ' ', env.allowed ? h( Pill, { tone: 'success' }, __( 'Allowed', 'invoiceninja' ) ) : h( Pill, { tone: 'warning' }, __( 'Paused', 'invoiceninja' ) ) ),
					env.allowed ? __( 'This is your live site, so checkout creates real invoices.', 'invoiceninja' ) : __( 'Orders are paused, so a staging copy never creates real invoices. Allow them when this is your live site.', 'invoiceninja' ),
					env.state && env.state.at ? sprintf( /* translators: %s: date */ __( 'Changed %s', 'invoiceninja' ), new Date( env.state.at * 1000 ).toLocaleString() ) : null,
					boot.can.manageSite ? h( Button, { busy: busy === 'env', onClick: () => {
						const action = env.allowed ? 'pause' : 'allow';
						// eslint-disable-next-line no-alert
						if ( window.confirm( action === 'allow' ? __( 'Allow orders from this site? Checkout will create real invoices and take real payments.', 'invoiceninja' ) : __( 'Pause orders from this site? Shoppers will see that online ordering is temporarily unavailable.', 'invoiceninja' ) ) ) {
							run( 'env', async () => { const c = await api( '/environment', { method: 'POST', data: { action } } ); setConn( c ); boot.connection = c; } );
						}
					} }, env.allowed ? __( 'Pause orders…', 'invoiceninja' ) : __( 'Allow orders…', 'invoiceninja' ) ) : null ) ),
			h( 'details', { className: 'in-card in-cli' },
				h( 'summary', null, __( 'WP-CLI commands for developers', 'invoiceninja' ) ),
				h( 'pre', null, [
					'wp invoiceninja status',
					'wp invoiceninja doctor [--repair]',
					'wp invoiceninja connect <url> < token.txt',
					'wp invoiceninja disconnect',
					'wp invoiceninja environment confirm',
				].join( '\n' ) ) ) );
	}

	function syncMessage( r ) {
		if ( ! r ) {
			return '';
		}
		if ( r.status === 'paused' ) {
			return __( 'The sync stopped for review: too many products would change.', 'invoiceninja' );
		}
		if ( r.status === 'failed' ) {
			return sprintf( /* translators: %s: reason */ __( 'The sync failed: %s', 'invoiceninja' ), r.message );
		}
		return sprintf( /* translators: 1: added, 2: updated, 3: unpublished */ __( 'Products synced: %1$d added, %2$d updated, %3$d unpublished.', 'invoiceninja' ), r.created, r.updated, r.drafted );
	}

	// ---------- Orders ----------

	const ORDER_STATUS = {
		queued: [ 'info', __( 'Queued', 'invoiceninja' ) ],
		placing: [ 'info', __( 'Queued', 'invoiceninja' ) ],
		awaiting_payment: [ 'warning', __( 'Awaiting payment', 'invoiceninja' ) ],
		paid: [ 'success', __( 'Paid', 'invoiceninja' ) ],
		cancelled: [ 'neutral', __( 'Cancelled', 'invoiceninja' ) ],
		attention: [ 'error', __( 'Needs attention', 'invoiceninja' ) ],
	};

	function money( amount, currency ) {
		try {
			if ( currency ) {
				return new Intl.NumberFormat( undefined, { style: 'currency', currency } ).format( amount );
			}
		} catch ( e ) {
			// Unknown currency code: fall through.
		}
		return Number( amount ).toFixed( 2 );
	}

	function Orders() {
		const params = new URLSearchParams( window.location.search );
		const [ filters, setFilters ] = useState( { status: params.get( 'status' ) || '', search: params.get( 's' ) || '', page: 1 } );
		const [ data, setData ] = useState( null );
		const [ error, setError ] = useState( '' );
		const [ message, setMessage ] = useState( '' );
		const [ busy, setBusy ] = useState( {} );
		const [ confirming, setConfirming ] = useState( null );
		const [ notify, setNotify ] = useState( true );
		const latest = useRef( 0 );
		const filtersRef = useRef( filters );
		filtersRef.current = filters;
		const keepRef = useRef( null );
		const triggerRef = useRef( null );
		const load = async ( f ) => {
			const ticket = ++latest.current;
			setError( '' );
			try {
				const q = new URLSearchParams( { status: f.status, search: f.search, page: String( f.page ) } );
				const result = await api( '/admin/orders?' + q.toString() );
				if ( ticket === latest.current ) {
					setData( result );
				}
			} catch ( e ) {
				if ( ticket === latest.current ) {
					setError( errorText( e ) );
					setData( ( prev ) => prev || { items: [], total: 0, counts: {} } );
				}
			}
		};
		useEffect( () => {
			load( filters );
		}, [ filters.status, filters.page ] );
		useEffect( () => {
			if ( confirming && keepRef.current ) {
				keepRef.current.focus();
			}
		}, [ confirming ] );
		const closeConfirm = () => {
			setConfirming( null );
			if ( triggerRef.current ) {
				triggerRef.current.focus();
			}
		};
		const setBusyFor = ( id, value ) => setBusy( ( prev ) => Object.assign( {}, prev, { [ id ]: value } ) );
		const act = async ( order, action, extra ) => {
			setBusyFor( order.id, action );
			setMessage( '' );
			try {
				const updated = await api( '/admin/orders/' + order.id + '/' + action, { method: 'POST', data: extra || {} } );
				const done = {
					resend: sprintf( /* translators: %s: order number */ __( 'Payment email sent again for order %s.', 'invoiceninja' ), order.number ),
					retry: updated.status === 'awaiting_payment' ? sprintf( /* translators: %s: order number */ __( 'Order %s is in Invoice Ninja now.', 'invoiceninja' ), order.number ) : sprintf( /* translators: %s: order number */ __( "Order %s couldn't be sent yet. See its note.", 'invoiceninja' ), order.number ),
					cancel: sprintf( /* translators: %s: order number */ __( 'Order %s cancelled.', 'invoiceninja' ), order.number ),
				};
				setMessage( done[ action ] );
				if ( action === 'cancel' ) {
					setConfirming( null );
				}
			} catch ( e ) {
				setMessage( errorText( e ) );
				if ( action === 'cancel' ) {
					setConfirming( null );
				}
			}
			setBusyFor( order.id, '' );
			// Counts, the banner and filtered views all change: reload what's on screen now.
			load( filtersRef.current );
		};
		const copy = async ( order ) => {
			try {
				await window.navigator.clipboard.writeText( order.status_url );
				setMessage( sprintf( /* translators: %s: order number */ __( 'Status link for order %s copied.', 'invoiceninja' ), order.number ) );
			} catch ( e ) {
				// eslint-disable-next-line no-alert
				window.prompt( __( 'Copy the status link:', 'invoiceninja' ), order.status_url );
			}
		};
		const counts = data ? data.counts : {};
		const total = Object.keys( counts ).reduce( ( n, k ) => n + counts[ k ], 0 );
		const views = [
			[ '', __( 'All', 'invoiceninja' ), total ],
			[ 'queued', __( 'Queued', 'invoiceninja' ), ( counts.queued || 0 ) + ( counts.placing || 0 ) ],
			[ 'awaiting_payment', __( 'Awaiting payment', 'invoiceninja' ), counts.awaiting_payment || 0 ],
			[ 'paid', __( 'Paid', 'invoiceninja' ), counts.paid || 0 ],
			[ 'cancelled', __( 'Cancelled', 'invoiceninja' ), counts.cancelled || 0 ],
			[ 'attention', __( 'Needs attention', 'invoiceninja' ), counts.attention || 0 ],
		];
		const attention = counts.attention || 0;
		const showBanner = attention > 0 && filters.status !== 'attention';
		const pages = data ? Math.max( 1, Math.ceil( data.total / 20 ) ) : 1;
		const setStatus = ( status ) => setFilters( Object.assign( {}, filters, { status, page: 1 } ) );
		return h( Fragment, null,
			h( 'h1', null, __( 'Orders', 'invoiceninja' ) ),
			showBanner ? h( Notice, { type: 'error', title: sprintf( /* translators: %d: number of orders */ _n( '%d order needs attention.', '%d orders need attention.', attention, 'invoiceninja' ), attention ) },
				h( 'p', { className: 'in-row' }, _n( 'Its buyer is waiting for an invoice.', 'Their buyers are waiting for an invoice.', attention, 'invoiceninja' ), ' ',
					h( Button, { onClick: () => setStatus( 'attention' ) }, __( 'Show them', 'invoiceninja' ) ) ) ) : null,
			h( 'ul', { className: 'subsubsub in-views' }, views.map( ( v, i ) => h( 'li', { key: v[ 0 ] || 'all' },
				h( 'a', { href: '#', className: filters.status === v[ 0 ] ? 'current' : '', 'aria-current': filters.status === v[ 0 ] ? 'page' : undefined, onClick: ( e ) => { e.preventDefault(); setStatus( v[ 0 ] ); } },
					v[ 1 ], ' ', h( 'span', { className: 'count' }, '(' + v[ 2 ] + ')' ) ),
				i < views.length - 1 ? ' |' : '' ) ) ),
			h( 'form', { className: 'in-filters in-filters--orders', role: 'search', onSubmit: ( e ) => { e.preventDefault(); const f = Object.assign( {}, filters, { page: 1 } ); setFilters( f ); load( f ); } },
				h( 'span', { className: 'in-field' }, h( 'label', { htmlFor: 'in-order-q' }, __( 'Order, invoice or email', 'invoiceninja' ) ),
					h( 'input', { id: 'in-order-q', type: 'search', value: filters.search, onChange: ( e ) => setFilters( Object.assign( {}, filters, { search: e.target.value } ) ) } ) ),
				h( 'button', { type: 'submit', className: 'button' }, __( 'Search', 'invoiceninja' ) ) ),
			h( 'div', { role: 'status', className: 'in-live' },
				error ? h( Notice, { type: 'error' }, h( 'p', null, error ) ) : null,
				message ? h( Notice, { type: 'info' }, h( 'p', null, message ) ) : null ),
			h( 'table', { className: 'wp-list-table widefat striped in-orders' },
				h( 'thead', null, h( 'tr', null,
					h( 'th', { scope: 'col' }, __( 'Order', 'invoiceninja' ) ),
					h( 'th', { scope: 'col' }, __( 'Customer', 'invoiceninja' ) ),
					h( 'th', { scope: 'col' }, __( 'Status', 'invoiceninja' ) ),
					h( 'th', { scope: 'col', className: 'in-num' }, __( 'Total', 'invoiceninja' ) ),
					h( 'th', { scope: 'col' }, __( 'Invoice', 'invoiceninja' ) ),
					h( 'th', { scope: 'col' }, __( 'Placed', 'invoiceninja' ) ) ) ),
				h( 'tbody', null,
					! data ? h( 'tr', null, h( 'td', { colSpan: 6 }, __( 'Loading…', 'invoiceninja' ) ) ) : null,
					data && data.items.length === 0 ? h( 'tr', null, h( 'td', { colSpan: 6 },
						filters.status || filters.search ? __( 'No orders match.', 'invoiceninja' ) : __( 'No orders yet. They appear here as soon as someone checks out.', 'invoiceninja' ) ) ) : null,
					data ? data.items.map( ( o ) => {
						const [ tone, label ] = ORDER_STATUS[ o.status ] || [ 'neutral', o.status ];
						const actions = [];
						if ( boot.can.manage && o.status === 'queued' ) {
							actions.push( h( Button, { key: 'retry', link: true, busy: busy[ o.id ] === 'retry', onClick: () => act( o, 'retry' ) }, __( 'Send now', 'invoiceninja' ) ) );
						}
						if ( boot.can.manage && o.status === 'attention' ) {
							actions.push( h( Button, { key: 'retry', link: true, busy: busy[ o.id ] === 'retry', onClick: () => act( o, 'retry' ) }, __( 'Retry', 'invoiceninja' ) ) );
						}
						if ( boot.can.manage && o.status === 'awaiting_payment' ) {
							actions.push( h( Button, { key: 'resend', link: true, busy: busy[ o.id ] === 'resend', onClick: () => act( o, 'resend' ) }, __( 'Resend payment email', 'invoiceninja' ) ) );
						}
						if ( o.status_url && o.status === 'awaiting_payment' ) {
							actions.push( h( Button, { key: 'copy', link: true, onClick: () => copy( o ) }, __( 'Copy status link', 'invoiceninja' ) ) );
						}
						if ( o.status === 'attention' && o.client_url ) {
							actions.push( h( External, { key: 'client', href: o.client_url }, __( 'Open client in Invoice Ninja', 'invoiceninja' ) ) );
						}
						if ( boot.can.manage && [ 'queued', 'awaiting_payment', 'attention' ].includes( o.status ) ) {
							actions.push( h( Button, { key: 'cancel', link: true, className: 'button-link in-link-danger', 'aria-expanded': confirming === o.id ? 'true' : 'false', 'aria-controls': 'in-confirm-' + o.id, onClick: ( e ) => { triggerRef.current = e.currentTarget; setNotify( true ); setConfirming( o.id ); } }, __( 'Cancel…', 'invoiceninja' ) ) );
						}
						return h( Fragment, { key: o.id },
							h( 'tr', { className: o.status === 'attention' ? 'is-attention' : '' },
								h( 'td', null,
									h( 'strong', null, o.number ),
									h( 'div', { className: 'in-row-actions' }, actions.map( ( a, i ) => h( Fragment, { key: i }, i ? h( 'span', { className: 'in-sep', 'aria-hidden': 'true' }, ' | ' ) : null, a ) ) ) ),
								h( 'td', null,
									h( 'div', null, o.name || o.email ),
									o.name ? h( 'div', { className: 'in-muted' }, o.email ) : null,
									h( 'div', { className: 'in-muted' }, o.items.map( ( i ) => i.quantity + ' × ' + i.title ).join( ', ' ) ),
									o.note ? h( 'div', { className: o.status === 'attention' ? 'in-note in-note--error' : 'in-note' }, o.note ) : null ),
								h( 'td', null, h( Pill, { tone }, label ) ),
								h( 'td', { className: 'in-num' }, money( o.total, o.currency ) ),
								h( 'td', null, o.invoice_url ? h( External, { href: o.invoice_url }, o.invoice_number || __( 'Invoice', 'invoiceninja' ) ) : h( 'span', { className: 'in-muted' }, __( 'Not yet', 'invoiceninja' ) ) ),
								h( 'td', null, new Date( o.created_at.replace( ' ', 'T' ) + 'Z' ).toLocaleString() ) ),
							confirming === o.id ? h( 'tr', { className: 'in-confirm-row' }, h( 'td', { colSpan: 6 },
								h( 'div', { className: 'in-confirm', id: 'in-confirm-' + o.id, role: 'group', 'aria-labelledby': 'in-confirm-q-' + o.id, onKeyDown: ( e ) => { if ( e.key === 'Escape' ) { e.stopPropagation(); closeConfirm(); } } },
									h( 'p', null, h( 'strong', { id: 'in-confirm-q-' + o.id }, sprintf( /* translators: %s: order number */ __( 'Cancel order %s?', 'invoiceninja' ), o.number ) ), ' ',
										o.invoice_number ? sprintf( /* translators: %s: invoice number */ __( 'Invoice %s is cancelled in Invoice Ninja, and its payment link stops working.', 'invoiceninja' ), o.invoice_number ) : __( "It hasn't reached Invoice Ninja, so nothing is created there.", 'invoiceninja' ) ),
									o.invoice_number ? h( 'p', { className: 'in-muted' }, __( 'Stock is not put back. Delete the invoice in Invoice Ninja if you need that.', 'invoiceninja' ) ) : null,
									h( 'label', null, h( 'input', { type: 'checkbox', checked: notify, onChange: ( e ) => setNotify( e.target.checked ) } ), ' ', sprintf( /* translators: %s: buyer name or email */ __( 'Email %s that the order was cancelled', 'invoiceninja' ), o.name || o.email ) ),
									h( 'p', { className: 'in-row' },
										h( 'button', { type: 'button', className: 'button', ref: keepRef, onClick: closeConfirm }, __( 'Keep order', 'invoiceninja' ) ),
										h( Button, { danger: true, busy: busy[ o.id ] === 'cancel', onClick: () => act( o, 'cancel', { email: notify } ) }, __( 'Cancel order', 'invoiceninja' ) ) ) ) ) ) : null );
					} ) : null ) ),
			h( 'div', { className: 'tablenav' },
				h( 'span', { className: 'in-muted' }, data ? sprintf( /* translators: %d: number */ _n( '%d order', '%d orders', data.total, 'invoiceninja' ), data.total ) : '' ),
				h( 'span', { className: 'in-row in-push' },
					h( Button, { disabled: filters.page <= 1, onClick: () => setFilters( Object.assign( {}, filters, { page: filters.page - 1 } ) ), 'aria-label': __( 'Previous page', 'invoiceninja' ) }, '‹' ),
					h( 'span', null, sprintf( /* translators: 1: page, 2: pages */ __( '%1$d of %2$d', 'invoiceninja' ), filters.page, pages ) ),
					h( Button, { disabled: filters.page >= pages, onClick: () => setFilters( Object.assign( {}, filters, { page: filters.page + 1 } ) ), 'aria-label': __( 'Next page', 'invoiceninja' ) }, '›' ) ) ) );
	}

	// ---------- Mount ----------

	const SCREENS = { home: Home, setup: Setup, settings: Settings, status: Status, orders: Orders };

	wp.domReady( () => {
		const root = document.getElementById( 'invoiceninja-app' );
		const Screen = root && SCREENS[ root.dataset.screen ];
		if ( Screen ) {
			createRoot( root ).render( h( Screen ) );
		}
	} );
}( window.wp, window.invoiceNinjaAdmin ) );
