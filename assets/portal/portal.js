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
				box.appendChild( el( 'p', { class: 'invoiceninja-field' }, el( 'label', { for: 'invoiceninja-account-code', text: t.code } ), input, el( 'span', { id: 'invoiceninja-account-code-help', class: 'invoiceninja-help', text: t.codeHelp } ) ) );
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

		const table = ( tab, rows ) => {
			if ( ! rows.length ) {
				return el( 'p', { text: t.nothingTab } );
			}
			if ( tab === 'subscriptions' ) {
				return el( 'table', { class: 'invoiceninja-table' },
					el( 'thead', null, el( 'tr', null, [ t.colPlan, t.colStatus, t.colNext, t.colAmount, '' ].map( ( h ) => el( 'th', { scope: 'col', text: h } ) ) ) ),
					el( 'tbody', null, rows.map( ( r ) => el( 'tr', null,
						el( 'td', { text: r.name } ), el( 'td', { text: r.status } ), el( 'td', { text: r.next_date || '—' } ), el( 'td', { text: r.amount || '' } ),
						el( 'td', { class: 'invoiceninja-table__actions' },
							r.links && r.links.change ? el( 'a', { href: r.links.change, text: t.changePlan } ) : null, ' ',
							r.links && r.links.payment_method ? el( 'a', { href: r.links.payment_method, text: t.paymentMethod } ) : null, ' ',
							r.links && r.links.cancel ? el( 'a', { href: r.links.cancel, text: t.cancelPlan } ) : el( 'span', { class: 'invoiceninja-muted', text: t.cancelContact } ) ) ) ) ) );
			}
			const money = tab !== 'payments';
			const head = [ t.colNumber, t.colDate ].concat( money ? [ t.colDue ] : [] ).concat( [ t.colAmount ] ).concat( money ? [ t.colBalance ] : [] ).concat( [ t.colStatus, '' ] );
			return el( 'table', { class: 'invoiceninja-table' },
				el( 'thead', null, el( 'tr', null, head.map( ( h ) => el( 'th', { scope: 'col', text: h } ) ) ) ),
				el( 'tbody', null, rows.map( ( r ) => el( 'tr', null,
					el( 'td', { text: r.number } ), el( 'td', { text: r.date } ), money ? el( 'td', { text: r.due || '—' } ) : null,
					el( 'td', { text: r.amount } ), money ? el( 'td', { text: r.balance } ) : null, el( 'td', { text: r.status } ),
					el( 'td', { class: 'invoiceninja-table__actions' },
						r.links.pay ? el( 'a', { class: 'wp-element-button', href: r.links.pay }, t.pay, el( 'span', { class: 'invoiceninja-sr', text: ' ' + r.number + ' ' + t.opensBilling } ) ) : null, ' ',
						r.links.view ? el( 'a', { href: r.links.view }, t.view, el( 'span', { class: 'invoiceninja-sr', text: ' ' + r.number } ) ) : null, ' ',
						r.links.pdf ? el( 'a', { href: r.links.pdf, target: '_blank', rel: 'noopener' }, t.pdf, el( 'span', { class: 'invoiceninja-sr', text: ' ' + r.number + ' ' + t.opensNewTab } ) ) : null ) ) ) ) );
		};

		const tabs = ( me ) => {
			const labels = { invoices: t.tabInvoices, quotes: t.tabQuotes, payments: t.tabPayments, credits: t.tabCredits, subscriptions: t.tabSubscriptions };
			const list = el( 'div', { class: 'invoiceninja-tabs', role: 'tablist', 'aria-label': t.yourOrder } );
			const panel = el( 'div', { class: 'invoiceninja-tabpanel', role: 'tabpanel', tabindex: '0' } );
			const buttons = wanted.map( ( tab, i ) => el( 'button', { type: 'button', role: 'tab', id: 'invoiceninja-tab-' + tab, 'aria-controls': 'invoiceninja-tabpanel', 'aria-selected': i === 0 ? 'true' : 'false', tabindex: i === 0 ? '0' : '-1', class: 'invoiceninja-tab', onClick: () => open( tab ) }, labels[ tab ] || tab ) );
			panel.id = 'invoiceninja-tabpanel';
			buttons.forEach( ( b ) => list.appendChild( b ) );
			list.addEventListener( 'keydown', ( e ) => {
				const index = buttons.indexOf( document.activeElement );
				if ( index < 0 || ( e.key !== 'ArrowRight' && e.key !== 'ArrowLeft' ) ) {
					return;
				}
				const next = buttons[ ( index + ( e.key === 'ArrowRight' ? 1 : buttons.length - 1 ) ) % buttons.length ];
				next.focus();
				next.click();
			} );
			let latest = 0;
			const open = async ( tab ) => {
				const mine = ++latest;
				buttons.forEach( ( b ) => {
					const on = b.id === 'invoiceninja-tab-' + tab;
					b.setAttribute( 'aria-selected', on ? 'true' : 'false' );
					b.setAttribute( 'tabindex', on ? '0' : '-1' );
				} );
				panel.setAttribute( 'aria-labelledby', 'invoiceninja-tab-' + tab );
				panel.setAttribute( 'aria-busy', 'true' );
				panel.textContent = '';
				panel.appendChild( el( 'p', { class: 'invoiceninja-loading', text: '…' } ) );
				const r = await api( 'GET', '/account/' + tab );
				if ( mine !== latest ) {
					return; // Another tab was chosen while this one loaded.
				}
				panel.textContent = '';
				panel.removeAttribute( 'aria-busy' );
				panel.appendChild( r.rows ? table( tab, r.rows ) : notice( 'error', r.message || t.portalDown ) );
				if ( r.code === 'network' ) {
					panel.appendChild( retry( () => open( tab ) ) );
				}
			};
			if ( wanted.length ) {
				open( wanted[ 0 ] );
			}
			return [
				me.portalPassword ? el( 'p', { class: 'invoiceninja-muted', text: t.portalPassword } ) : null,
				el( 'p', null, el( 'a', { href: me.portalUrl, target: '_blank', rel: 'noopener' }, t.openPortal, ' ', el( 'span', { 'aria-hidden': 'true', text: '↗' } ), el( 'span', { class: 'invoiceninja-sr', text: ' ' + t.opensNewTab } ) ) ),
				list,
				panel,
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
				paint( top.concat( [ verifyBox( me ) ] ) );
				return;
			}
			if ( back && ! message ) {
				// Already confirmed (e.g. in another tab): straight back.
				window.location.assign( back );
				return;
			}
			if ( ! me.linked ) {
				paint( top.concat( [ el( 'p', { text: t.noHistory } ) ] ) );
				return;
			}
			paint( top.concat( tabs( me ) ) );
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
