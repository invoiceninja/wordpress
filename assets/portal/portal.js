/**
 * Invoice Ninja Account and Pay Invoice blocks.
 *
 * Plain JavaScript, no build step. Everything is fetched after the page loads, so the
 * page itself stays cacheable; links that open the billing system go through this site's
 * ?invoiceninja=… redirects, which check the visitor first.
 */
( function () {
	'use strict';

	const cfg = window.invoiceNinjaStore;
	if ( ! cfg || ! window.fetch ) {
		return;
	}
	const t = cfg.i18n;

	function fmt( text ) {
		const args = Array.prototype.slice.call( arguments, 1 );
		let i = 0;
		return String( text ).replace( /%(\d+\$)?[sd]/g, ( m, pos ) => String( pos ? args[ parseInt( pos, 10 ) - 1 ] : args[ i++ ] ) );
	}

	function el( tag, attrs ) {
		const node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( ( key ) => {
			const value = attrs[ key ];
			if ( value === null || value === undefined || value === false ) {
				return;
			}
			if ( key === 'class' ) {
				node.className = value;
			} else if ( key === 'text' ) {
				node.textContent = value;
			} else if ( key.indexOf( 'on' ) === 0 && typeof value === 'function' ) {
				node.addEventListener( key.slice( 2 ).toLowerCase(), value );
			} else if ( value === true ) {
				node.setAttribute( key, '' );
			} else {
				node.setAttribute( key, String( value ) );
			}
		} );
		for ( let i = 2; i < arguments.length; i++ ) {
			( Array.isArray( arguments[ i ] ) ? arguments[ i ] : [ arguments[ i ] ] ).forEach( ( c ) => {
				if ( c !== null && c !== undefined && c !== false ) {
					node.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
				}
			} );
		}
		return node;
	}

	async function api( method, path, body ) {
		let response;
		try {
			response = await fetch( cfg.rest + path, {
				method,
				credentials: 'same-origin',
				headers: body ? { 'Content-Type': 'application/json' } : {},
				body: body ? JSON.stringify( body ) : undefined,
			} );
		} catch ( e ) {
			// Offline, or the site didn't answer: callers show this with a way to try again.
			return { ok: false, code: 'network', message: t.offline, httpStatus: 0 };
		}
		let data = {};
		try {
			data = await response.json();
		} catch ( e ) {
			data = {};
		}
		data.httpStatus = response.status;
		return data;
	}

	function notice( type, text ) {
		return el( 'div', { class: 'invoiceninja-notice invoiceninja-notice--' + type, role: type === 'error' ? 'alert' : 'status' }, text );
	}

	function retry( onClick ) {
		return el( 'p', null, el( 'button', { type: 'button', class: 'wp-element-button', onClick }, t.tryAgain ) );
	}

	// ---------- Account ----------

	function initAccount( root ) {
		const wanted = ( root.getAttribute( 'data-tabs' ) || '' ).split( ',' ).filter( Boolean );
		// Set by the server to a page on this site only (e.g. the product the customer wanted).
		const back = root.getAttribute( 'data-return' ) || '';
		const params = new URLSearchParams( window.location.search );
		const paint = ( nodes ) => {
			root.textContent = '';
			nodes.forEach( ( n ) => n && root.appendChild( n ) );
		};

		const verifyBox = ( me ) => {
			const box = el( 'section', { class: 'invoiceninja-panel', 'aria-labelledby': 'invoiceninja-verify-title' } );
			let current = 'start';
			let retryAfter = 0;
			let timer = null;
			const draw = ( step, message ) => {
				current = step;
				window.clearInterval( timer );
				box.textContent = '';
				box.appendChild( el( 'h2', { id: 'invoiceninja-verify-title', class: 'invoiceninja-heading', tabindex: '-1', text: t.confirmTitle } ) );
				if ( message ) {
					box.appendChild( notice( message.type, message.text ) );
				}
				if ( step === 'start' ) {
					box.appendChild( el( 'p', { text: fmt( back ? t.confirmBack : t.confirmText, me.email ) } ) );
					box.appendChild( el( 'p', { class: 'invoiceninja-actions' }, el( 'button', { type: 'button', class: 'wp-element-button', onClick: send }, t.sendCode ) ) );
					return;
				}
				const input = el( 'input', { id: 'invoiceninja-account-code', class: 'invoiceninja-code', inputmode: 'numeric', autocomplete: 'one-time-code', maxlength: '6', 'aria-describedby': 'invoiceninja-account-code-help', onInput: ( e ) => {
					e.target.value = e.target.value.replace( /\D/g, '' ).slice( 0, 6 );
					if ( e.target.value.length === 6 ) {
						confirm( e.target.value );
					}
				} } );
				box.appendChild( el( 'p', { text: fmt( t.codeSent, me.email ) } ) );
				box.appendChild( el( 'p', { class: 'invoiceninja-field' }, el( 'label', { for: 'invoiceninja-account-code', text: t.code } ), input, el( 'span', { id: 'invoiceninja-account-code-help', class: 'invoiceninja-help', text: t.accountCodeHelp || t.codeHelp } ) ) );
				// Resend only once the server will send another code, with a countdown until then.
				const resend = el( 'p', { class: 'invoiceninja-muted' } );
				const paintResend = () => {
					const left = Math.max( 0, Math.ceil( ( retryAfter - Date.now() ) / 1000 ) );
					resend.textContent = '';
					if ( left > 0 && box.isConnected ) {
						resend.appendChild( document.createTextNode( fmt( t.resendIn, Math.floor( left / 60 ) + ':' + String( left % 60 ).padStart( 2, '0' ) ) ) );
						return;
					}
					window.clearInterval( timer );
					resend.appendChild( el( 'button', { type: 'button', class: 'invoiceninja-linkbutton', onClick: send }, t.resend ) );
				};
				timer = window.setInterval( paintResend, 1000 );
				paintResend();
				box.appendChild( resend );
				window.setTimeout( () => input.focus(), 0 );
			};
			const send = async () => {
				const r = await api( 'POST', '/account/verify/start', {} );
				if ( ! r.ok ) {
					draw( current, { type: 'error', text: r.message || t.error } );
					return;
				}
				retryAfter = Date.now() + ( ( r.retry_after || 60 ) * 1000 );
				// "wait": the code sent moments ago still works, and no new one was sent.
				draw( 'code', r.sent === false ? { type: 'info', text: t.codeRecent } : null );
			};
			const confirm = async ( code ) => {
				const r = await api( 'POST', '/account/verify/confirm', { code } );
				if ( r.ok && back ) {
					paint( [ notice( 'info', t.verified ) ] );
					window.location.assign( back );
					return;
				}
				if ( r.ok ) {
					load( t.verified );
					return;
				}
				draw( 'code', { type: 'error', text: r.message || t.error } );
			};
			draw( 'start' );
			if ( params.get( 'invoiceninja_verify' ) ) {
				window.setTimeout( () => box.querySelector( 'h2' ).focus(), 0 );
			}
			return box;
		};

		const sr = ( text ) => el( 'span', { class: 'invoiceninja-sr', text } );

		// One request per tab, shared by the tab and the Balance due panel (which reads Invoices).
		const fetched = {};
		const fetchTab = ( tab ) => {
			if ( ! fetched[ tab ] ) {
				fetched[ tab ] = api( 'GET', '/account/' + tab ).then( ( r ) => {
					if ( ! r.rows ) {
						delete fetched[ tab ]; // Failed: the next look asks again.
					}
					return r;
				} );
			}
			return fetched[ tab ];
		};

		const docLabel = { invoices: t.docInvoice, quotes: t.docQuote, credits: t.docCredit, payments: t.docPayment };
		const named = ( tab, r ) => fmt( docLabel[ tab ] || '%s', r.number || r.name || '' );

		const tag = ( r ) => r.status ? el( 'span', { class: 'invoiceninja-tag invoiceninja-tag--' + ( r.tone || 'open' ), text: r.status } ) : null;

		const pdfLink = ( tab, r ) => r.links && r.links.pdf
			? el( 'a', { class: 'invoiceninja-account__action', href: r.links.pdf, target: '_blank', rel: 'noopener' }, sr( named( tab, r ) + ' ' ), t.pdf, sr( ' ' + t.opensNewTab ) )
			: null;

		// Columns per tab. "area" places a cell in the phone card; "num" right-aligns money.
		const columns = ( tab ) => {
			const number = { area: 'number', head: t.colNumber, cell: ( r ) => [
				r.links && r.links.view ? el( 'a', { class: 'invoiceninja-account__number', href: r.links.view, 'aria-label': named( tab, r ) + ' ' + t.opensBilling }, r.number ) : el( 'span', { class: 'invoiceninja-account__number', text: r.number } ),
				r.order ? el( 'span', { class: 'invoiceninja-account__order' }, r.links && r.links.order ? el( 'a', { href: r.links.order, text: fmt( t.orderRef, r.order ) } ) : fmt( t.orderRef, r.order ) ) : null,
			] };
			const date = { area: 'date', head: t.colDate, label: true, cell: ( r ) => r.date || '' };
			const due = ( head ) => ( { area: 'due', head, label: true, cell: ( r ) => r.due || '—' } );
			const amount = { area: 'amount', head: t.colAmount, label: true, num: true, cell: ( r ) => r.amount || '' };
			const balance = { area: 'balance', head: t.colBalance, label: true, num: true, cell: ( r ) => r.balance || '' };
			const status = { area: 'status', head: t.colStatus, cell: tag };
			const actions = ( fn ) => ( { area: 'actions', head: t.colActions, srHead: true, cell: fn } );
			if ( tab === 'subscriptions' ) {
				return [
					{ area: 'number', head: t.colPlan, cell: ( r ) => el( 'span', { class: 'invoiceninja-account__number', text: r.name } ) },
					status,
					{ area: 'due', head: t.colNext, label: true, cell: ( r ) => r.next_date || '—' },
					amount,
					actions( ( r ) => [
						r.links && r.links.change ? el( 'a', { class: 'invoiceninja-account__action', href: r.links.change }, t.changePlan, sr( ' ' + r.name ) ) : null,
						r.links && r.links.payment_method ? el( 'a', { class: 'invoiceninja-account__action', href: r.links.payment_method }, t.paymentMethod, sr( ' ' + r.name ) ) : null,
						r.links && r.links.cancel ? el( 'a', { class: 'invoiceninja-account__action', href: r.links.cancel }, t.cancelPlan, sr( ' ' + r.name ) ) : el( 'span', { class: 'invoiceninja-muted', text: t.cancelContact } ),
					] ),
				];
			}
			if ( tab === 'payments' ) {
				return [ number, date, amount, status ];
			}
			if ( tab === 'quotes' ) {
				return [ number, date, due( t.colValid ), amount, status, actions( ( r ) => [ pdfLink( tab, r ) ] ) ];
			}
			if ( tab === 'credits' ) {
				return [ number, date, amount, balance, status, actions( ( r ) => [ pdfLink( tab, r ) ] ) ];
			}
			return [ number, date, due( t.colDue ), amount, balance, status, actions( ( r ) => [
				pdfLink( tab, r ),
				r.links.pay ? el( 'a', { class: 'wp-element-button invoiceninja-account__pay', href: r.links.pay }, t.pay, sr( ' ' + named( tab, r ) + ' ' + t.opensBilling ) ) : null,
			] ) ];
		};

		const empty = ( tab, r ) => {
			const words = {
				invoices: [ t.emptyInvoices, t.hintInvoices ],
				quotes: [ t.emptyQuotes, t.hintQuotes ],
				payments: [ t.emptyPayments, t.hintPayments ],
				credits: [ t.emptyCredits, t.hintCredits ],
				subscriptions: [ t.emptySubs, t.hintSubs ],
			}[ tab ] || [ t.nothingTab, '' ];
			return el( 'div', { class: 'invoiceninja-empty' },
				el( 'p', { class: 'invoiceninja-empty__title', text: words[ 0 ] } ),
				words[ 1 ] ? el( 'p', { class: 'invoiceninja-muted', text: words[ 1 ] } ) : null,
				tab === 'quotes' && r.requestUrl ? el( 'p', null, el( 'a', { href: r.requestUrl, text: t.requestQuote } ) ) : null );
		};

		// A real table on wide screens; each row a stacked card on narrow ones (CSS). The
		// explicit roles keep the table meaning when the cards change its display.
		const table = ( tab, r ) => {
			const rows = r.rows || [];
			if ( ! rows.length ) {
				return empty( tab, r );
			}
			const cols = columns( tab );
			return el( 'table', { class: 'invoiceninja-table invoiceninja-table--' + tab, role: 'table' },
				el( 'thead', { role: 'rowgroup' }, el( 'tr', { role: 'row' }, cols.map( ( c ) => el( 'th', { scope: 'col', role: 'columnheader', class: 'invoiceninja-cell--' + c.area + ( c.num ? ' invoiceninja-num' : '' ) }, c.srHead ? sr( c.head ) : c.head ) ) ) ),
				el( 'tbody', { role: 'rowgroup' }, rows.map( ( row ) => el( 'tr', { role: 'row' }, cols.map( ( c ) => el( 'td', {
					role: 'cell',
					class: 'invoiceninja-cell--' + c.area + ( c.num ? ' invoiceninja-num' : '' ),
					'data-label': c.label ? c.head : null,
				}, c.cell( row ) ) ) ) ) ) );
		};

		const header = ( me, linked ) => el( 'div', { class: 'invoiceninja-account__head' },
			me.email ? el( 'p', { class: 'invoiceninja-account__who' },
				fmt( t.signedInAs, me.email ),
				me.logoutUrl ? [ ' ', el( 'a', { href: me.logoutUrl, text: t.signOut } ) ] : null ) : null,
			linked ? el( 'a', { class: 'wp-element-button invoiceninja-account__portal', href: me.portalUrl, target: '_blank', rel: 'noopener' },
				t.billingPortal, el( 'span', { 'aria-hidden': 'true', text: ' ↗' } ), sr( ' ' + t.opensNewTab ) ) : null );

		// Balance due: what the unpaid invoices add up to, and the way to pay them.
		const balancePanel = () => {
			const box = el( 'section', { class: 'invoiceninja-balance', 'aria-labelledby': 'invoiceninja-balance-title', hidden: true } );
			fetchTab( 'invoices' ).then( ( r ) => {
				const b = r.balance;
				if ( ! b || ( ! b.count && ! b.credit && ! ( r.rows || [] ).length ) ) {
					return; // Nothing billed yet: no panel.
				}
				let action = null;
				if ( b.count && b.pay ) {
					const text = b.count > 1 ? t.payInPortal : ( b.payAmount ? fmt( t.payAmount, b.payAmount ) : fmt( t.payNumber, b.number ) );
					action = el( 'p', { class: 'invoiceninja-balance__action' }, el( 'a', { class: 'wp-element-button', href: b.pay },
						text, sr( ' ' + ( b.count > 1 ? '' : fmt( t.docInvoice, b.number ) + ' ' ) + t.opensBilling ) ) );
				}
				box.appendChild( el( 'div', { class: 'invoiceninja-balance__text' },
					el( 'h2', { id: 'invoiceninja-balance-title', class: 'invoiceninja-balance__title', text: t.balanceDue } ),
					el( 'p', { class: 'invoiceninja-balance__amount' + ( b.count ? '' : ' is-paid' ), text: b.count ? b.total : t.allPaid } ),
					b.detail ? el( 'p', { class: 'invoiceninja-balance__detail' + ( b.overdue ? ' is-overdue' : '' ), text: b.detail } ) : null,
					b.credit ? el( 'p', { class: 'invoiceninja-balance__detail', text: b.credit } ) : null ) );
				if ( action ) {
					box.appendChild( action );
				}
				box.hidden = false;
			} );
			return box;
		};

		// Tabs scroll sideways when they don't fit, with a fade on the side that has more.
		const fade = ( scroller ) => {
			const update = () => {
				const max = scroller.scrollWidth - scroller.clientWidth;
				const pos = Math.abs( scroller.scrollLeft );
				const rtl = window.getComputedStyle( scroller ).direction === 'rtl';
				const fromStart = pos > 1;
				const toEnd = pos < max - 1;
				scroller.classList.toggle( 'is-more-left', rtl ? toEnd : fromStart );
				scroller.classList.toggle( 'is-more-right', rtl ? fromStart : toEnd );
			};
			scroller.addEventListener( 'scroll', update, { passive: true } );
			if ( window.ResizeObserver ) {
				new window.ResizeObserver( update ).observe( scroller );
			} else {
				window.addEventListener( 'resize', update );
			}
			window.requestAnimationFrame( update );
		};

		const tabs = ( me ) => {
			const labels = { invoices: t.tabInvoices, quotes: t.tabQuotes, payments: t.tabPayments, credits: t.tabCredits, subscriptions: t.tabSubscriptions };
			const list = el( 'div', { class: 'invoiceninja-tabs', role: 'tablist', 'aria-label': t.accountTabs } );
			const scroller = el( 'div', { class: 'invoiceninja-tabs-scroll' }, list );
			const panel = el( 'div', { class: 'invoiceninja-tabpanel', role: 'tabpanel', tabindex: '0', id: 'invoiceninja-tabpanel' } );
			const buttons = wanted.map( ( tab, i ) => el( 'button', { type: 'button', role: 'tab', id: 'invoiceninja-tab-' + tab, 'aria-controls': 'invoiceninja-tabpanel', 'aria-selected': i === 0 ? 'true' : 'false', tabindex: i === 0 ? '0' : '-1', class: 'invoiceninja-tab', 'data-tab': tab, onClick: () => open( tab, true ) }, labels[ tab ] || tab ) );
			buttons.forEach( ( b ) => list.appendChild( b ) );
			list.addEventListener( 'keydown', ( e ) => {
				const index = buttons.indexOf( document.activeElement );
				if ( index < 0 ) {
					return;
				}
				const rtl = window.getComputedStyle( list ).direction === 'rtl';
				const step = { ArrowRight: rtl ? -1 : 1, ArrowLeft: rtl ? 1 : -1 }[ e.key ];
				let next = null;
				if ( step ) {
					next = buttons[ ( index + step + buttons.length ) % buttons.length ];
				} else if ( e.key === 'Home' ) {
					next = buttons[ 0 ];
				} else if ( e.key === 'End' ) {
					next = buttons[ buttons.length - 1 ];
				}
				if ( next ) {
					e.preventDefault();
					next.focus();
					open( next.getAttribute( 'data-tab' ), true );
				}
			} );
			let latest = 0;
			const open = async ( tab, chosen ) => {
				const mine = ++latest;
				buttons.forEach( ( b ) => {
					const on = b.getAttribute( 'data-tab' ) === tab;
					b.setAttribute( 'aria-selected', on ? 'true' : 'false' );
					b.setAttribute( 'tabindex', on ? '0' : '-1' );
					if ( on && chosen && b.scrollIntoView ) {
						b.scrollIntoView( { block: 'nearest', inline: 'nearest' } );
					}
				} );
				panel.setAttribute( 'aria-labelledby', 'invoiceninja-tab-' + tab );
				panel.setAttribute( 'aria-busy', 'true' );
				panel.textContent = '';
				panel.appendChild( el( 'p', { class: 'invoiceninja-loading', text: '…' } ) );
				const r = await fetchTab( tab );
				if ( mine !== latest ) {
					return; // Another tab was chosen while this one loaded.
				}
				panel.textContent = '';
				panel.removeAttribute( 'aria-busy' );
				panel.appendChild( r.rows ? table( tab, r ) : notice( 'error', r.message || t.portalDown ) );
				if ( r.code === 'network' ) {
					panel.appendChild( retry( () => open( tab ) ) );
				}
			};
			const balanceOn = root.getAttribute( 'data-balance' ) !== '0';
			if ( wanted.length ) {
				open( wanted[ 0 ] );
			}
			fade( scroller );
			return [
				balanceOn ? balancePanel() : null,
				me.portalPassword ? el( 'p', { class: 'invoiceninja-muted', text: t.portalPassword } ) : null,
				wanted.length ? scroller : null,
				wanted.length ? panel : null,
			];
		};

		const load = async ( message ) => {
			const me = await api( 'GET', '/account' );
			if ( me.httpStatus === 401 || me.httpStatus === 403 ) {
				paint( [ el( 'p', { text: t.accountLogin } ), el( 'p', null, el( 'a', { class: 'wp-element-button', href: cfg.loginUrl, text: t.logIn } ) ) ] );
				return;
			}
			if ( me.httpStatus !== 200 ) {
				// Not "confirm your email": the site didn't answer, or failed.
				paint( [ notice( 'error', me.message || t.error ), retry( () => {
					paint( [ el( 'p', { class: 'invoiceninja-loading', text: '…' } ) ] );
					load( message );
				} ) ] );
				return;
			}
			const top = [];
			if ( message ) {
				top.push( notice( 'info', message ) );
			}
			if ( me.unavailable || params.get( 'invoiceninja_unavailable' ) ) {
				top.push( notice( 'warning', t.portalDown ) );
			}
			if ( ! me.verified ) {
				paint( [ header( me, false ) ].concat( top, [ verifyBox( me ) ] ) );
				return;
			}
			if ( back && ! message ) {
				// Already confirmed (e.g. in another tab): straight back.
				window.location.assign( back );
				return;
			}
			if ( ! me.linked ) {
				paint( [ header( me, false ) ].concat( top, [ el( 'p', { text: t.noHistory } ) ] ) );
				return;
			}
			paint( [ header( me, true ) ].concat( top, tabs( me ) ) );
		};
		load();
	}

	// ---------- Pay Invoice ----------

	function initPayInvoice( root ) {
		const started = Date.now();
		const trap = root.querySelector( '.invoiceninja-sr' );
		const problem = el( 'div', { class: 'invoiceninja-payinvoice__error' } );
		const form = el( 'form', { class: 'invoiceninja-find', onSubmit: async ( e ) => {
			e.preventDefault();
			const data = new FormData( form );
			problem.textContent = '';
			const result = await api( 'POST', '/pay-invoice', {
				number: data.get( 'number' ),
				email: data.get( 'email' ),
				// The spam trap (an old cached page's "website" field is ignored: autofill fills it).
				in_leave_empty: data.get( 'in_leave_empty' ) || '',
				form: root.getAttribute( 'data-form' ) || '',
				elapsed: Math.floor( ( Date.now() - started ) / 1000 ),
			} );
			if ( ! result.ok ) {
				problem.appendChild( notice( 'error', result.message || t.error ) );
				return;
			}
			root.textContent = '';
			const h = el( 'h2', { class: 'invoiceninja-heading', tabindex: '-1', text: t.linkOnWay } );
			root.appendChild( h );
			root.appendChild( el( 'p', { role: 'status', text: t.payInvoiceSent } ) );
			h.focus();
		} },
			el( 'h2', { class: 'invoiceninja-heading', text: t.payInvoiceTitle } ),
			problem,
			el( 'p', { class: 'invoiceninja-field' }, el( 'label', { for: 'invoiceninja-pay-number', text: t.invoiceNumber } ), el( 'input', { id: 'invoiceninja-pay-number', name: 'number', required: true } ) ),
			el( 'p', { class: 'invoiceninja-field' }, el( 'label', { for: 'invoiceninja-pay-email', text: t.email } ), el( 'input', { id: 'invoiceninja-pay-email', name: 'email', type: 'email', autocomplete: 'email', required: true } ) ),
			trap,
			el( 'p', { class: 'invoiceninja-actions' }, el( 'button', { type: 'submit', class: 'wp-element-button' }, t.payInvoiceSend ) ) );
		root.appendChild( form );
	}

	function boot() {
		document.querySelectorAll( '[data-invoiceninja-account]' ).forEach( initAccount );
		document.querySelectorAll( '[data-invoiceninja-payinvoice]' ).forEach( initPayInvoice );
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
