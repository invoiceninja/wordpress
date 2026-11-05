/**
 * Invoice Ninja store: cart, Mini Cart, checkout, Order Status and the last-order banner.
 *
 * Plain JavaScript, no build step and no dependencies. Pages stay cacheable: everything
 * that depends on the shopper is fetched from the plugin's REST API after the page loads.
 * Text is set with textContent, never innerHTML.
 */
( function () {
	'use strict';

	const cfg = window.invoiceNinjaStore;
	if ( ! cfg || ! window.fetch ) {
		return;
	}
	const t = cfg.i18n;

	// Whether checkout asks for the tax address. The page may be cached from before taxes were
	// turned on or off, so the server's answer (with the cart, or a tax_address error) wins.
	const tax = { fields: !! cfg.taxFields, countries: cfg.countries || [], country: cfg.country || '', postcodeRequired: [] };
	function applyTax( data ) {
		if ( ! data || typeof data.fields !== 'boolean' ) {
			return;
		}
		tax.fields = data.fields;
		tax.countries = Array.isArray( data.countries ) ? data.countries : [];
		tax.country = data.country || '';
		// Countries whose tax can't be worked out without the postcode.
		tax.postcodeRequired = Array.isArray( data.postcode_required ) ? data.postcode_required.map( String ) : [];
	}

	// ---------- Helpers ----------

	function fmt( text ) {
		const args = Array.prototype.slice.call( arguments, 1 );
		let i = 0;
		return String( text ).replace( /%(\d+\$)?[sd]/g, ( m, pos ) => String( pos ? args[ parseInt( pos, 10 ) - 1 ] : args[ i++ ] ) );
	}

	function money( amount ) {
		const m = cfg.money || {};
		const precision = typeof m.precision === 'number' ? m.precision : 2;
		const negative = amount < 0;
		const fixed = Math.abs( Number( amount ) || 0 ).toFixed( precision );
		const parts = fixed.split( '.' );
		parts[ 0 ] = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, m.thousand === undefined ? ',' : m.thousand );
		const number = parts.length > 1 ? parts[ 0 ] + ( m.decimal || '.' ) + parts[ 1 ] : parts[ 0 ];
		const symbol = m.symbol || '';
		let out = m.swap ? number + ( symbol ? ' ' + symbol.trim() : '' ) : symbol + number;
		if ( ! symbol && ! m.swap ) {
			out = number;
		}
		return negative ? '-' + out : out;
	}

	function taxText( inclusive ) {
		if ( inclusive === undefined ? cfg.taxIncluded : inclusive ) {
			return t.taxIncluded;
		}
		return tax.fields ? t.taxAddress : t.taxLater;
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
			const child = arguments[ i ];
			( Array.isArray( child ) ? child : [ child ] ).forEach( ( c ) => {
				if ( c === null || c === undefined || c === false ) {
					return;
				}
				node.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
			} );
		}
		return node;
	}

	function hidden( text ) {
		return el( 'span', { class: 'invoiceninja-sr', text } );
	}

	function button( label, onClick, extra ) {
		return el( 'button', Object.assign( { type: 'button', class: 'wp-element-button', onClick }, extra || {} ), label );
	}

	function linkButton( label, href, extra ) {
		return el( 'a', Object.assign( { class: 'wp-element-button', href }, extra || {} ), label );
	}

	function payButton( href ) {
		return el( 'a', { class: 'wp-element-button invoiceninja-pay', href },
			lockIcon(), t.payButton, hidden( ' ' + t.opensBilling ) );
	}

	function lockIcon() {
		const ns = 'http://www.w3.org/2000/svg';
		const svg = document.createElementNS( ns, 'svg' );
		svg.setAttribute( 'width', '18' );
		svg.setAttribute( 'height', '18' );
		svg.setAttribute( 'viewBox', '0 0 24 24' );
		svg.setAttribute( 'fill', 'none' );
		svg.setAttribute( 'stroke', 'currentColor' );
		svg.setAttribute( 'stroke-width', '2' );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.setAttribute( 'focusable', 'false' );
		const rect = document.createElementNS( ns, 'rect' );
		rect.setAttribute( 'x', '5' );
		rect.setAttribute( 'y', '11' );
		rect.setAttribute( 'width', '14' );
		rect.setAttribute( 'height', '9' );
		rect.setAttribute( 'rx', '2' );
		const path = document.createElementNS( ns, 'path' );
		path.setAttribute( 'd', 'M8 11V8a4 4 0 0 1 8 0v3' );
		svg.appendChild( rect );
		svg.appendChild( path );
		return svg;
	}

	function notice( type, title, body ) {
		return el( 'div', { class: 'invoiceninja-notice invoiceninja-notice--' + type, role: type === 'error' ? 'alert' : 'status' },
			title ? el( 'strong', { text: title } ) : null, body ? el( 'span', null, ' ', body ) : null );
	}

	function cookie( name ) {
		const match = document.cookie.match( new RegExp( '(?:^|; )' + name + '=([^;]*)' ) );
		return match ? decodeURIComponent( match[ 1 ] ) : '';
	}

	async function api( method, path, body, headers ) {
		let response;
		try {
			response = await fetch( cfg.rest + path, {
				method,
				credentials: 'same-origin',
				headers: Object.assign( body ? { 'Content-Type': 'application/json' } : {}, headers || {} ),
				body: body ? JSON.stringify( body ) : undefined,
			} );
		} catch ( e ) {
			// Offline, or the site didn't answer: every caller shows this and lets the shopper try again.
			return { ok: false, code: 'network', message: t.offline, httpStatus: 0 };
		}
		let data = {};
		try {
			data = await response.json();
		} catch ( e ) {
			data = {};
		}
		if ( ! response.ok && ! data.code ) {
			data.code = 'http_' + response.status;
		}
		data.httpStatus = response.status;
		return data;
	}

	// One polite live region for the whole page.
	let live = null;
	function announce( text ) {
		if ( ! live ) {
			live = el( 'div', { class: 'invoiceninja-sr', 'aria-live': 'polite', role: 'status' } );
			document.body.appendChild( live );
		}
		live.textContent = '';
		window.setTimeout( () => {
			live.textContent = text;
		}, 50 );
	}

	// ---------- Cart ----------

	const cart = {
		data: null,
		listeners: [],
		// The cart, or (without lines) the error: the cart shown so far is kept.
		async load( forCheckout ) {
			// The REST root may already have a query (plain permalinks: ?rest_route=).
			const data = await api( 'GET', '/cart' + ( forCheckout ? ( cfg.rest.indexOf( '?' ) < 0 ? '?' : '&' ) + 'checkout=1' : '' ) );
			if ( ! Array.isArray( data.lines ) ) {
				return Object.assign( {}, data, { ok: false, message: data.message || t.error } );
			}
			this.data = data;
			if ( this.data.tax_address ) {
				applyTax( this.data.tax_address );
				delete this.data.tax_address;
			}
			this.emit();
			return this.data;
		},
		async set( product, quantity ) {
			const result = await api( 'POST', '/cart/items', { product, quantity } );
			if ( result.cart ) {
				this.data = result.cart;
				this.emit();
			}
			return result;
		},
		quantity( product ) {
			const line = this.data && this.data.lines ? this.data.lines.find( ( l ) => l.product === product ) : null;
			return line ? line.quantity : 0;
		},
		emit() {
			this.listeners.forEach( ( fn ) => fn( this.data ) );
		},
		count() {
			if ( this.data ) {
				return this.data.count || 0;
			}
			return parseInt( cookie( 'invoiceninja_cart_count' ), 10 ) || 0;
		},
	};

	// ---------- Add to cart ----------

	function initAddForms() {
		document.querySelectorAll( '[data-invoiceninja-add]' ).forEach( ( form ) => {
			form.addEventListener( 'submit', async ( event ) => {
				event.preventDefault();
				const product = form.getAttribute( 'data-invoiceninja-add' );
				const title = form.getAttribute( 'data-title' ) || '';
				const qtyField = form.querySelector( '[data-invoiceninja-qty]' );
				const qty = Math.max( 1, parseInt( qtyField ? qtyField.value : '1', 10 ) || 1 );
				const submit = form.querySelector( 'button' );
				submit.disabled = true;
				submit.setAttribute( 'aria-busy', 'true' );
				try {
					if ( cfg.mode === 'buy_now' ) {
						const result = await cart.set( product, qty );
						// Sold out since the page was cached: say so here, not on an empty checkout.
						if ( result.ok && result.quantity > 0 && cfg.pages.checkout ) {
							window.location.href = cfg.pages.checkout;
							return;
						}
						showAddResult( form, result, title, 0 );
					} else {
						const loaded = cart.data ? cart.data : await cart.load();
						if ( ! Array.isArray( loaded.lines ) ) {
							// Without the cart, the quantity sent would replace what's in it.
							showAddResult( form, loaded, title, 0 );
						} else {
							const before = cart.quantity( product );
							showAddResult( form, await cart.set( product, before + qty ), title, before );
						}
					}
				} catch ( e ) {
					showAddResult( form, { ok: false, message: t.error }, title, 0 );
				}
				submit.disabled = false;
				submit.removeAttribute( 'aria-busy' );
				// Disabling the focused button sent focus to the page; put it back for keyboard users.
				if ( ! document.activeElement || document.activeElement === document.body ) {
					submit.focus();
				}
			} );
		} );
	}

	// The cart holds `before` of the product; the server says how many it holds now and
	// whether that's fewer than asked for (stock or the product's limit).
	function showAddResult( form, result, title, before ) {
		const old = form.parentNode.querySelector( '.invoiceninja-toast' );
		if ( old ) {
			old.remove();
		}
		const now = typeof result.quantity === 'number' ? result.quantity : before + 1;
		const limit = result.limited ? ( result.max > 0 ? fmt( t.onlyAvailable, result.max ) : t.soldOut ) : '';
		let box;
		if ( result.ok && now > before ) {
			const text = fmt( t.added, title );
			const note = [ limit, result.message && result.message !== limit ? result.message : '' ].filter( Boolean ).join( ' ' );
			box = el( 'p', { class: 'invoiceninja-toast' }, text, ' ',
				cfg.pages.checkout ? el( 'a', { href: cfg.pages.checkout, text: t.viewCart } ) : null,
				note ? el( 'span', { class: 'invoiceninja-toast__note', text: ' ' + note } ) : null );
			announce( text + '. ' + ( note ? note + ' ' : '' ) + t.viewCart + '.' );
		} else if ( result.ok ) {
			// Nothing was added: sold out, or the cart already holds all there is.
			box = el( 'p', { class: 'invoiceninja-toast is-error', role: 'alert' }, limit || result.message || t.soldOut,
				now > 0 && cfg.pages.checkout ? ' ' : null, now > 0 && cfg.pages.checkout ? el( 'a', { href: cfg.pages.checkout, text: t.viewCart } ) : null );
		} else {
			box = el( 'p', { class: 'invoiceninja-toast is-error', role: 'alert', text: result.message || t.error } );
		}
		form.parentNode.insertBefore( box, form.nextSibling );
	}

	// ---------- Mini Cart ----------

	function initMiniCarts() {
		document.querySelectorAll( '[data-invoiceninja-minicart]' ).forEach( ( root ) => {
			const toggle = root.querySelector( 'button' );
			const panel = root.querySelector( '.invoiceninja-minicart__panel' );
			const badge = root.querySelector( '[data-invoiceninja-count]' );
			// The button's own words, without the count badge.
			const name = Array.prototype.filter.call( toggle.childNodes, ( node ) => node !== badge && ! ( node.contains && node.contains( badge ) ) )
				.map( ( node ) => node.textContent ).join( '' ).trim() || t.cart;
			const paint = () => {
				const count = cart.count();
				badge.textContent = count ? String( count ) : '';
				badge.hidden = ! count;
				toggle.setAttribute( 'aria-label', count ? name + ', ' + fmt( 1 === count ? t.oneItem : t.manyItems, count ) : name );
			};
			paint();
			cart.listeners.push( () => {
				paint();
				if ( ! panel.hidden ) {
					fill();
				}
			} );
			const close = () => {
				panel.hidden = true;
				toggle.setAttribute( 'aria-expanded', 'false' );
			};
			const head = () => el( 'div', { class: 'invoiceninja-minicart__head' },
				el( 'strong', { text: t.yourCart } ),
				el( 'button', { type: 'button', class: 'invoiceninja-iconbutton', 'aria-label': t.closeCart, onClick: () => {
					close();
					toggle.focus();
				} }, '×' ) );
			const fill = () => {
				panel.textContent = '';
				const data = cart.data || { lines: [] };
				panel.appendChild( head() );
				if ( ! data.lines || ! data.lines.length ) {
					panel.appendChild( el( 'p', { text: t.cartEmpty } ) );
					return;
				}
				panel.appendChild( el( 'ul', { class: 'invoiceninja-minicart__lines' }, data.lines.map( ( line ) =>
					el( 'li', null, el( 'span', { text: line.quantity + ' × ' + line.title } ), el( 'span', { text: money( line.line_total ) } ) ) ) ) );
				panel.appendChild( el( 'p', { class: 'invoiceninja-minicart__total' }, el( 'span', { text: t.subtotal } ), el( 'strong', { text: money( data.subtotal ) } ) ) );
				if ( cfg.pages.checkout ) {
					panel.appendChild( linkButton( t.checkout, cfg.pages.checkout ) );
				}
			};
			const open = async () => {
				panel.hidden = false;
				toggle.setAttribute( 'aria-expanded', 'true' );
				panel.textContent = '';
				panel.appendChild( el( 'p', { class: 'invoiceninja-loading', text: '…' } ) );
				const result = await cart.load();
				if ( panel.hidden ) {
					return; // Closed while loading.
				}
				if ( Array.isArray( result.lines ) ) {
					fill();
				} else {
					// Not the (possibly old) cart: what went wrong, and a way to try again.
					panel.textContent = '';
					panel.appendChild( head() );
					panel.appendChild( el( 'p', { class: 'invoiceninja-toast is-error', role: 'alert', text: result.message } ) );
					panel.appendChild( button( t.tryAgain, ( e ) => {
						e.stopPropagation(); // The button is replaced, so the page's click-outside check would close the panel.
						open();
					} ) );
				}
				const first = panel.querySelector( 'a, button' );
				if ( first ) {
					first.focus();
				}
			};
			toggle.addEventListener( 'click', () => {
				if ( ! panel.hidden ) {
					close();
					return;
				}
				open();
			} );
			root.addEventListener( 'keydown', ( event ) => {
				if ( event.key === 'Escape' && ! panel.hidden ) {
					close();
					toggle.focus();
				}
			} );
			document.addEventListener( 'click', ( event ) => {
				if ( ! root.contains( event.target ) ) {
					close();
				}
			} );
		} );
	}

	// ---------- Checkout ----------

	function initCheckout( root ) {
		const state = {
			step: 'cart',
			details: { email: '', first_name: '', last_name: '', phone: '' },
			remember: true,
			code: '',
			message: null,
			order: null,
			retryAfter: 0,
			trap: '',
			// With login required, orders use the account's email (the server says which).
			account: '',
			failed: '',
		};
		let timer = null;

		// What the server checks besides the details: the spam trap and the total the shopper saw.
		const checks = () => ( { in_leave_empty: state.trap, total: cart.data ? cart.data.total : undefined } );

		const field = ( name, label, attrs, help ) => {
			const id = 'invoiceninja-' + name;
			return el( 'p', { class: 'invoiceninja-field' },
				el( 'label', { for: id }, label, attrs && attrs.optional ? el( 'span', { class: 'invoiceninja-muted', text: ' ' + t.optional } ) : null ),
				el( 'input', Object.assign( { id, name, type: 'text', value: state.details[ name ] || '', onInput: ( e ) => {
					state.details[ name ] = e.target.value;
				} }, attrs && attrs.input ? attrs.input : {} ) ),
				help ? el( 'span', { id: id + '-help', class: 'invoiceninja-help', text: help } ) : null );
		};

		const summary = ( data, editable ) => {
			const rows = ( data.lines || [] ).map( ( line ) => {
				const qty = editable ? el( 'span', { class: 'invoiceninja-stepper' },
					el( 'button', { type: 'button', class: 'invoiceninja-iconbutton', 'data-action': 'fewer', 'aria-label': fmt( t.oneFewer, line.title ), onClick: () => change( line, line.quantity - 1, 'fewer' ) }, '−' ),
					el( 'span', { class: 'invoiceninja-stepper__value' }, hidden( t.quantity + ' ' ), String( line.quantity ) ),
					el( 'button', { type: 'button', class: 'invoiceninja-iconbutton', 'data-action': 'more', 'aria-label': fmt( t.oneMore, line.title ), disabled: line.quantity >= line.max, onClick: () => change( line, line.quantity + 1, 'more' ) }, '+' ),
					line.quantity >= line.max ? el( 'span', { class: 'invoiceninja-muted', text: fmt( t.max, line.max ) } ) : null,
					el( 'button', { type: 'button', class: 'invoiceninja-linkbutton', 'data-action': 'remove', onClick: () => change( line, 0, 'remove' ) }, t.remove, hidden( ' ' + line.title ) ) ) : el( 'span', { class: 'invoiceninja-muted', text: '× ' + line.quantity } );
				return el( 'li', { class: 'invoiceninja-line', 'data-product': line.product },
					el( 'span', { class: 'invoiceninja-line__title' }, el( 'strong', { text: line.title } ), el( 'span', { class: 'invoiceninja-muted', text: fmt( t.each, money( line.price ) ) } ) ),
					qty,
					el( 'span', { class: 'invoiceninja-line__total', text: money( line.line_total ) } ) );
			} );
			return el( 'div', { class: 'invoiceninja-summary' },
				el( 'ul', { class: 'invoiceninja-lines' }, rows ),
				el( 'p', { class: 'invoiceninja-summary__row' }, el( 'span', { text: t.subtotal } ), el( 'strong', { text: money( data.subtotal ) } ) ),
				el( 'p', { class: 'invoiceninja-summary__row invoiceninja-muted' }, el( 'span', { text: t.tax } ), el( 'span', { text: taxText() } ) ) );
		};

		const taxFields = () => {
			if ( ! tax.fields ) {
				return null;
			}
			if ( state.details.country_id === undefined ) {
				state.details.country_id = tax.countries.some( ( c ) => c[ 0 ] === tax.country ) ? tax.country : '';
				state.details.postal_code = '';
			}
			// The postcode is optional (many countries have none), except where the server says
			// the tax can't be worked out without it.
			const postcode = field( 'postal_code', t.postcode, { optional: true, input: { autocomplete: 'postal-code', maxlength: '16' } } );
			const syncPostcode = () => {
				const required = tax.postcodeRequired.indexOf( String( state.details.country_id ) ) >= 0;
				const optional = postcode.querySelector( 'label .invoiceninja-muted' );
				postcode.querySelector( 'input' ).required = required;
				if ( optional ) {
					optional.hidden = required;
				}
			};
			syncPostcode();
			const select = el( 'select', { id: 'invoiceninja-country_id', name: 'country_id', required: true, autocomplete: 'country', 'aria-describedby': 'invoiceninja-tax-help', onChange: ( e ) => {
				state.details.country_id = e.target.value;
				syncPostcode();
			} }, el( 'option', { value: '', text: t.chooseCountry } ), tax.countries.map( ( c ) => el( 'option', { value: c[ 0 ], text: c[ 1 ], selected: c[ 0 ] === state.details.country_id } ) ) );
			return [
				el( 'div', { class: 'invoiceninja-row' },
					el( 'p', { class: 'invoiceninja-field' }, el( 'label', { for: 'invoiceninja-country_id', text: t.country } ), select ),
					postcode ),
				el( 'p', { id: 'invoiceninja-tax-help', class: 'invoiceninja-help', text: t.taxHelp } ),
			];
		};

		// Back on the control that was used (re-rendering would drop focus to the page): the same
		// button on the same line, another of its buttons when that one is now disabled, or the
		// cart heading when the line is gone.
		const refocus = ( product, action ) => {
			const row = Array.prototype.find.call( root.querySelectorAll( '[data-product]' ), ( node ) => node.getAttribute( 'data-product' ) === product );
			const same = row ? row.querySelector( '[data-action="' + action + '"]' ) : null;
			const target = same && ! same.disabled ? same : ( row ? row.querySelector( '[data-action]:not([disabled])' ) : null );
			const fallback = root.querySelector( '.invoiceninja-heading' );
			( target || fallback || root ).focus();
		};

		const change = async ( line, quantity, action ) => {
			const result = await cart.set( line.product, Math.max( 0, quantity ) );
			if ( result.ok ) {
				if ( state.message && state.message.type === 'error' ) {
					state.message = null; // An earlier failed change.
				}
				const now = typeof result.quantity === 'number' ? result.quantity : Math.max( 0, quantity );
				announce( line.title + ': ' + now + ( result.limited && result.max > 0 ? '. ' + fmt( t.onlyAvailable, result.max ) : '' ) );
			} else {
				state.message = { type: 'error', text: result.message || t.error };
			}
			render();
			if ( state.step === 'cart' ) {
				refocus( line.product, action );
			}
		};

		const handle = ( result ) => {
			if ( result.ok ) {
				return true;
			}
			state.message = { type: 'error', text: result.message || t.error };
			if ( result.code === 'cart_changed' ) {
				if ( result.cart ) {
					cart.data = result.cart;
					cart.emit();
				}
				state.message = { type: 'warning', text: result.message };
				state.step = 'cart';
			} else if ( result.code === 'login_required' ) {
				state.step = 'login';
				state.loginUrl = result.login_url || cfg.loginUrl;
			} else if ( result.code === 'unavailable' || result.code === 'store_off' ) {
				state.step = 'unavailable';
			} else if ( result.code === 'empty_cart' ) {
				state.step = 'cart';
				state.message = null;
			} else if ( result.code === 'tax_address' ) {
				// The server needs the tax address even if this (cached) page didn't know.
				applyTax( result.tax_address );
				state.step = 'details';
			}
			return false;
		};

		const start = async ( again ) => {
			state.message = null;
			render( true );
			const result = await api( 'POST', '/checkout/start', Object.assign( {}, state.details, checks() ) );
			// Sent within a moment of the first add: wait those seconds out once, then go on.
			if ( result.code === 'too_fast' && ! again ) {
				window.setTimeout( () => start( true ), Math.min( 10, result.retry_after || 3 ) * 1000 );
				return;
			}
			if ( ! handle( result ) ) {
				render();
				return;
			}
			if ( result.email ) {
				state.details.email = result.email; // The address the server used (the account's, with login required).
			}
			if ( result.next === 'confirm' ) {
				state.step = 'remembered';
			} else {
				state.step = 'code';
				state.code = '';
				state.retryAfter = Date.now() + ( ( result.retry_after || 60 ) * 1000 );
			}
			render();
		};

		const confirm = async () => {
			const from = state.step;
			state.step = 'placing';
			state.message = null;
			render();
			const result = await api( 'POST', '/checkout/confirm', Object.assign( {}, state.details, checks(), { code: state.code, remember: state.remember } ) );
			if ( ! handle( result ) ) {
				if ( result.code === 'network' ) {
					// Back where the shopper was, code kept: sending again can't place the order twice.
					state.step = from === 'remembered' ? 'remembered' : 'code';
				} else if ( result.code && result.code.indexOf( 'code_' ) === 0 ) {
					state.step = 'code';
					state.code = '';
				} else if ( state.step === 'placing' ) {
					state.step = 'details'; // With the message, never a "Placing…" that doesn't end.
				}
				render();
				return;
			}
			state.order = result;
			state.step = 'done';
			cart.data = { lines: [], count: 0, subtotal: 0, total: 0 };
			cart.emit();
			render();
		};

		const heading = ( text ) => el( 'h2', { class: 'invoiceninja-heading', tabindex: '-1', text } );

		const views = {
			cart( data ) {
				if ( ! data.lines || ! data.lines.length ) {
					return [ heading( t.yourCart ), el( 'p', { text: t.cartEmpty } ), cfg.pages.shop ? linkButton( t.browse, cfg.pages.shop ) : null ];
				}
				return [
					heading( t.yourCart ),
					summary( data, true ),
					el( 'p', { class: 'invoiceninja-actions' },
						button( t.checkout, () => {
							state.step = cfg.requireLogin && ! cfg.loggedIn ? 'login' : 'details';
							render();
						} ),
						cfg.pages.shop ? el( 'a', { href: cfg.pages.shop, text: t.continueShop } ) : null ),
				];
			},
			login() {
				return [ heading( t.checkout ), notice( 'info', t.loginRequired ), el( 'p', null, linkButton( t.logIn, state.loginUrl || cfg.loginUrl ) ) ];
			},
			unavailable() {
				return [ heading( t.checkout ), notice( 'info', t.unavailable, t.unavailableText ) ];
			},
			loading() {
				return [ el( 'p', { class: 'invoiceninja-loading', role: 'status', text: t.loadingCart } ) ];
			},
			failed() {
				return [ heading( t.checkout ), notice( 'error', null, state.failed ), el( 'p', { class: 'invoiceninja-actions' }, button( t.tryAgain, () => {
					state.step = 'loading';
					render();
					boot();
				} ) ) ];
			},
			details( data ) {
				const form = el( 'form', { class: 'invoiceninja-details', onSubmit: ( e ) => {
					e.preventDefault();
					const trap = form.querySelector( '[name="in_leave_empty"]' );
					state.trap = trap ? trap.value : '';
					start();
				} },
					cfg.loggedIn ? null : el( 'p', { class: 'invoiceninja-muted' }, t.haveAccount + ' ', el( 'a', { href: cfg.loginUrl, text: t.logIn } ) ),
					state.account
						? field( 'email', t.email, { input: { type: 'email', autocomplete: 'email', required: true, readonly: true, 'aria-describedby': 'invoiceninja-email-help' } }, t.accountEmail )
						: field( 'email', t.email, { input: { type: 'email', autocomplete: 'email', required: true } } ),
					el( 'div', { class: 'invoiceninja-row' },
						field( 'first_name', t.firstName, { input: { autocomplete: 'given-name', required: true } } ),
						field( 'last_name', t.lastName, { input: { autocomplete: 'family-name', required: true } } ) ),
					field( 'phone', t.phone, { optional: true, input: { type: 'tel', autocomplete: 'tel' } } ),
					taxFields(),
					// Spam trap: people never see or fill it, and its name is one autofill leaves alone.
					el( 'p', { class: 'invoiceninja-sr', 'aria-hidden': 'true' }, el( 'input', { type: 'text', name: 'in_leave_empty', tabindex: '-1', autocomplete: 'off', 'data-1p-ignore': true, 'data-lpignore': 'true', 'data-bwignore': true, value: state.trap, onInput: ( e ) => {
						state.trap = e.target.value;
					} } ) ),
					el( 'p', { class: 'invoiceninja-actions' }, el( 'button', { type: 'submit', class: 'wp-element-button' }, t.continue ) ),
					el( 'p', { class: 'invoiceninja-muted', text: t.nextCode } ) );
				return [ heading( t.checkout ), el( 'div', { class: 'invoiceninja-columns' }, form, el( 'aside', { class: 'invoiceninja-aside', 'aria-label': t.orderSummary }, el( 'h3', { text: t.orderSummary } ), summary( data, false ), el( 'button', { type: 'button', class: 'invoiceninja-linkbutton', onClick: () => {
					state.step = 'cart';
					render();
				} }, t.editCart ) ) ) ];
			},
			code() {
				const input = el( 'input', { id: 'invoiceninja-code', type: 'text', inputmode: 'numeric', autocomplete: 'one-time-code', maxlength: '6', 'aria-describedby': 'invoiceninja-code-help', class: 'invoiceninja-code', value: state.code, onInput: ( e ) => {
					state.code = e.target.value.replace( /\D/g, '' ).slice( 0, 6 );
					e.target.value = state.code;
					if ( state.code.length === 6 ) {
						confirm();
					}
				} } );
				const resend = el( 'p', { class: 'invoiceninja-muted', 'data-resend': '1' } );
				const paintResend = () => {
					const left = Math.max( 0, Math.ceil( ( state.retryAfter - Date.now() ) / 1000 ) );
					resend.textContent = '';
					if ( left > 0 ) {
						resend.appendChild( document.createTextNode( fmt( t.resendIn, Math.floor( left / 60 ) + ':' + String( left % 60 ).padStart( 2, '0' ) ) ) );
					} else {
						window.clearInterval( timer );
						resend.appendChild( document.createTextNode( t.resendNow + ' ' ) );
						resend.appendChild( el( 'button', { type: 'button', class: 'invoiceninja-linkbutton', onClick: start }, t.resend ) );
						if ( ! resend.getAttribute( 'data-announced' ) ) {
							resend.setAttribute( 'data-announced', '1' );
							announce( t.resendNow );
						}
					}
				};
				window.clearInterval( timer );
				timer = window.setInterval( paintResend, 1000 );
				paintResend();
				window.setTimeout( () => input.focus(), 0 );
				return [
					heading( t.checkEmail ),
					el( 'p', null, fmt( t.codeSent, state.details.email ) + ' ', el( 'button', { type: 'button', class: 'invoiceninja-linkbutton', onClick: () => {
						state.step = 'details';
						render();
					} }, t.change ), ' ' + t.codeWhy ),
					el( 'p', { class: 'invoiceninja-check' }, el( 'label', null, el( 'input', { type: 'checkbox', checked: state.remember, onChange: ( e ) => {
						state.remember = e.target.checked;
					} } ), ' ' + t.remember + ' ', el( 'span', { class: 'invoiceninja-muted', text: t.rememberHint } ) ) ),
					el( 'p', { class: 'invoiceninja-field' }, el( 'label', { for: 'invoiceninja-code', text: t.code } ), input, el( 'span', { id: 'invoiceninja-code-help', class: 'invoiceninja-help', text: t.codeHelp } ) ),
					el( 'p', { class: 'invoiceninja-actions' }, button( t.confirmOrder, confirm ) ),
					resend,
				];
			},
			remembered() {
				return [
					heading( t.welcomeBack ),
					el( 'p', { text: fmt( t.remembered, state.details.email ) } ),
					el( 'p', { class: 'invoiceninja-actions' }, button( t.placeOrder, confirm ), el( 'button', { type: 'button', class: 'invoiceninja-linkbutton', onClick: async () => {
						// "Not you?": this device stops skipping the code for that email.
						const result = await api( 'POST', '/checkout/forget', {} );
						if ( ! result.ok ) {
							state.message = { type: 'error', text: result.message || t.error };
							render();
							return;
						}
						state.message = null;
						state.details.email = state.account;
						state.step = 'details';
						render();
					} }, t.useAnother ) ),
				];
			},
			placing() {
				return [ heading( t.checkEmail ), el( 'p', { role: 'status', class: 'invoiceninja-loading', text: t.placing } ) ];
			},
			done() {
				return orderView( state.order, true );
			},
		};

		const render = async ( busy ) => {
			if ( busy ) {
				root.setAttribute( 'aria-busy', 'true' );
				return;
			}
			root.removeAttribute( 'aria-busy' );
			if ( state.step !== 'code' ) {
				window.clearInterval( timer );
			}
			const data = cart.data || { lines: [] };
			const admin = Array.prototype.slice.call( root.querySelectorAll( '.invoiceninja-notice--admin' ) );
			root.textContent = '';
			admin.forEach( ( node ) => root.appendChild( node ) );
			if ( state.message ) {
				root.appendChild( notice( state.message.type, null, state.message.text ) );
			}
			const view = views[ state.step ] ? views[ state.step ]( data ) : [];
			view.forEach( ( node ) => node && root.appendChild( node ) );
			const h2 = root.querySelector( '.invoiceninja-heading' );
			if ( h2 && render.lastStep && render.lastStep !== state.step ) {
				h2.focus();
			}
			render.lastStep = state.step;
		};

		// The page may be cached, so whether ordering is available (and the account's email) comes
		// from the server with the cart, never from the page.
		const boot = async () => {
			const data = await cart.load( true );
			if ( ! Array.isArray( data.lines ) ) {
				state.failed = data.message || t.error;
				state.step = 'failed';
				render();
				return;
			}
			if ( data.unavailable ) {
				state.step = 'unavailable';
				render();
				return;
			}
			state.step = 'cart';
			if ( data.account_email ) {
				state.account = data.account_email;
				state.details.email = data.account_email;
			}
			if ( ( ! data.lines || ! data.lines.length ) && cookie( 'invoiceninja_order_flag' ) ) {
				const last = await api( 'GET', '/orders/last' );
				if ( last.ok && last.status === 'awaiting_payment' && last.pay_link ) {
					state.step = 'waiting';
					views.waiting = () => waitingView( last, () => {
						state.step = 'cart';
						render();
					} );
				}
			}
			render();
		};
		boot();
	}

	function waitingView( order, onChanged ) {
		const box = el( 'div', { class: 'invoiceninja-panel' } );
		const paint = ( confirming ) => {
			box.textContent = '';
			if ( ! confirming ) {
				box.appendChild( el( 'h2', { class: 'invoiceninja-heading', tabindex: '-1', text: fmt( t.waiting, order.number ) } ) );
				box.appendChild( el( 'p', { text: order.items.map( ( i ) => i.quantity + ' × ' + i.title ).join( ', ' ) + ' · ' + money( order.total ) } ) );
				box.appendChild( el( 'p', { class: 'invoiceninja-actions' }, payButton( order.pay_link ), button( t.changeOrder, () => paint( true ), { class: 'invoiceninja-secondary' } ) ) );
				return;
			}
			box.appendChild( el( 'p', { text: fmt( t.changeConfirm, order.number ) } ) );
			const keep = button( t.keepOrder, () => paint( false ) );
			box.appendChild( el( 'p', { class: 'invoiceninja-actions' }, keep, button( t.cancelEdit, async () => {
				const result = await api( 'POST', '/orders/last/change', {} );
				if ( result.ok ) {
					await cart.load();
					onChanged();
				} else {
					box.appendChild( notice( 'error', null, result.message || t.error ) );
				}
			}, { class: 'invoiceninja-secondary' } ) ) );
			keep.focus();
		};
		paint( false );
		return [ box ];
	}

	// ---------- Orders ----------

	function statusLabel( status ) {
		return {
			awaiting_payment: [ 'waiting', t.statusAwaiting ],
			paid: [ 'paid', t.statusPaid ],
			cancelled: [ 'cancelled', t.statusCancelled ],
		}[ status ] || [ 'received', t.statusQueued ];
	}

	function orderView( order, justPlaced ) {
		const [ tone, label ] = statusLabel( order.status );
		const nodes = [];
		if ( order.problem === 'currency' ) {
			// The buyer's account with us uses another currency: an admin sorts it out.
			nodes.push( el( 'h2', { class: 'invoiceninja-heading', tabindex: '-1', text: t.cantTake } ) );
			nodes.push( el( 'p', { class: 'invoiceninja-muted', text: fmt( t.orderLineLater, order.number ) } ) );
			nodes.push( el( 'p', null, t.cantTakeText, cfg.contact ? ' ' : null, cfg.contact ? el( 'a', { href: cfg.contact, text: t.contactUs } ) : null ) );
			return nodes;
		}
		const title = justPlaced ? ( order.status === 'awaiting_payment' && order.pay_link ? t.thanksPay : t.thanksReceived ) : ( order.status === 'paid' ? fmt( t.bannerPaid, order.number ) : t.yourOrder );
		nodes.push( el( 'h2', { class: 'invoiceninja-heading', tabindex: '-1', text: title } ) );
		nodes.push( el( 'p', { class: 'invoiceninja-muted', text: order.invoice_number ? fmt( t.orderLine, order.number, order.invoice_number ) : fmt( t.orderLineLater, order.number ) } ) );
		nodes.push( el( 'p', null, el( 'span', { class: 'invoiceninja-pill invoiceninja-pill--' + tone, text: label } ), order.email ? el( 'span', { class: 'invoiceninja-muted', text: ' ' + order.email } ) : null ) );
		nodes.push( el( 'div', { class: 'invoiceninja-summary' },
			el( 'ul', { class: 'invoiceninja-lines' }, order.items.map( ( i ) => el( 'li', { class: 'invoiceninja-line' }, el( 'span', { class: 'invoiceninja-line__title', text: i.quantity + ' × ' + i.title } ), el( 'span', { class: 'invoiceninja-line__total', text: money( i.price * i.quantity ) } ) ) ) ),
			el( 'p', { class: 'invoiceninja-summary__row' }, el( 'span', { text: order.status === 'paid' ? t.total : t.subtotal } ), el( 'strong', { text: money( order.status === 'paid' || typeof order.subtotal !== 'number' ? order.total : order.subtotal ) } ) ),
			order.status === 'paid' ? null : el( 'p', { class: 'invoiceninja-summary__row invoiceninja-muted' }, el( 'span', { text: t.tax } ), el( 'span', { text: taxText( !! order.inclusive ) } ) ) ) );
		if ( order.status === 'awaiting_payment' && order.pay_link ) {
			nodes.push( el( 'p', { class: 'invoiceninja-actions' }, payButton( order.pay_link ) ) );
			if ( justPlaced ) {
				nodes.push( el( 'p', { class: 'invoiceninja-muted', text: t.payLater } ) );
			}
		} else if ( order.status === 'awaiting_payment' ) {
			nodes.push( el( 'p', { text: t.inTouch } ) );
		} else if ( order.status === 'queued' || order.status === 'placing' ) {
			nodes.push( el( 'p', { text: t.linkSoon } ) );
		} else if ( order.status === 'attention' ) {
			nodes.push( el( 'p', { text: t.inTouch } ) );
		} else if ( order.status === 'cancelled' ) {
			nodes.push( el( 'p', { text: t.cancelledText } ) );
		} else if ( order.status === 'paid' ) {
			nodes.push( el( 'p', { text: t.paidText } ) );
		}
		if ( justPlaced && order.status_url ) {
			nodes.push( el( 'p', null, el( 'a', { href: order.status_url, text: t.viewOrder } ) ) );
		}
		return nodes;
	}

	function initStatus( root ) {
		const params = new URLSearchParams( window.location.search );
		// invoiceninja_order: a link made while the site had no Order Status page.
		const number = params.get( 'order' ) || params.get( 'invoiceninja_order' ) || '';
		const key = ( window.location.hash.match( /key=([0-9a-f]{32})/ ) || [] )[ 1 ] || '';
		const paint = ( nodes ) => {
			root.textContent = '';
			nodes.forEach( ( n ) => n && root.appendChild( n ) );
		};
		const find = () => {
			const problem = el( 'div' );
			const form = el( 'form', { class: 'invoiceninja-find', onSubmit: async ( e ) => {
				e.preventDefault();
				const data = new FormData( form );
				problem.textContent = '';
				const result = await api( 'POST', '/orders/find', { number: data.get( 'number' ), email: data.get( 'email' ) } );
				if ( result.code === 'network' || result.httpStatus >= 500 ) {
					// Nothing was sent: say so, and keep what was typed for another try.
					problem.appendChild( notice( 'error', null, result.message || t.error ) );
					return;
				}
				paint( [ el( 'h2', { class: 'invoiceninja-heading', tabindex: '-1', text: t.linkOnWay } ), el( 'p', { role: 'status', text: t.findSent } ), cfg.pages.shop ? el( 'p', null, el( 'a', { href: cfg.pages.shop, text: t.backToShop } ) ) : null ] );
				root.querySelector( '.invoiceninja-heading' ).focus();
			} },
				problem,
				el( 'p', { class: 'invoiceninja-field' }, el( 'label', { for: 'invoiceninja-find-number', text: t.orderNumber } ), el( 'input', { id: 'invoiceninja-find-number', name: 'number', value: number, required: true, 'aria-describedby': 'invoiceninja-find-hint' } ), el( 'span', { id: 'invoiceninja-find-hint', class: 'invoiceninja-help', text: t.orderNumberHint } ) ),
				el( 'p', { class: 'invoiceninja-field' }, el( 'label', { for: 'invoiceninja-find-email', text: t.email } ), el( 'input', { id: 'invoiceninja-find-email', name: 'email', type: 'email', autocomplete: 'email', required: true } ) ),
				el( 'p', { class: 'invoiceninja-actions' }, el( 'button', { type: 'submit', class: 'wp-element-button' }, t.emailLink ) ) );
			paint( [ el( 'h2', { class: 'invoiceninja-heading', text: t.findOrder } ), number ? el( 'p', { text: t.findIntro } ) : null, form ] );
		};
		const load = async () => {
			// The key goes in a header, never the address, so it stays out of server logs.
			const order = await api( 'GET', '/orders/' + encodeURIComponent( number ), null, { 'X-Order-Key': key } );
			if ( order.code === 'network' ) {
				// Not "we couldn't find that order": the site didn't answer.
				paint( [ notice( 'error', null, order.message ), el( 'p', null, button( t.tryAgain, () => {
					paint( [ el( 'p', { role: 'status', class: 'invoiceninja-loading', text: t.checking } ) ] );
					load();
				} ) ) ] );
				return;
			}
			if ( order.httpStatus !== 200 || ! order.number ) {
				find();
				return;
			}
			const nodes = orderView( order, false );
			if ( order.status === 'awaiting_payment' ) {
				nodes.push( el( 'p', null, button( t.checkAgain, async ( e ) => {
					e.target.disabled = true;
					root.setAttribute( 'aria-busy', 'true' );
					announce( t.checking );
					await load();
					root.removeAttribute( 'aria-busy' );
					const after = root.querySelector( '.invoiceninja-pill' );
					announce( after ? after.textContent : '' );
				}, { class: 'invoiceninja-secondary' } ) ) );
			}
			paint( nodes );
		};
		if ( ! number || ! key ) {
			find();
			return;
		}
		paint( [ el( 'p', { role: 'status', class: 'invoiceninja-loading', text: t.checking } ) ] );
		load();
	}

	// ---------- Last-order banner ----------

	async function initBanner() {
		if ( ! cookie( 'invoiceninja_order_flag' ) || document.querySelector( '[data-invoiceninja-status]' ) ) {
			return;
		}
		// Above the page's own store content, never inside the header next to a Mini Cart.
		const blocks = Array.prototype.filter.call( document.querySelectorAll( '[data-invoiceninja-store]' ), ( node ) =>
			! node.hasAttribute( 'data-invoiceninja-minicart' ) && ! node.closest( 'header, [data-invoiceninja-minicart]' ) );
		const main = document.querySelector( 'main' );
		const first = blocks.length ? blocks[ 0 ] : ( main && main.firstChild ) || null;
		if ( ! first ) {
			return;
		}
		const last = await api( 'GET', '/orders/last' );
		if ( ! last.ok || [ 'awaiting_payment', 'paid' ].indexOf( last.status ) < 0 ) {
			return;
		}
		const dismissed = window.sessionStorage ? window.sessionStorage.getItem( 'invoiceninja_banner_' + last.number + last.status ) : null;
		if ( dismissed ) {
			return;
		}
		const banner = el( 'div', { class: 'invoiceninja-banner invoiceninja-banner--' + last.status, role: 'region', 'aria-label': t.yourOrder },
			el( 'span', { class: 'invoiceninja-banner__text' }, el( 'strong', { text: fmt( last.status === 'paid' ? t.bannerPaid : t.bannerWaiting, last.number ) } ) ),
			last.status === 'awaiting_payment' && last.pay_link ? payButton( last.pay_link ) : null,
			last.status_url ? el( 'a', { href: last.status_url, text: t.viewOrder } ) : null,
			el( 'button', { type: 'button', class: 'invoiceninja-iconbutton', 'aria-label': t.hide, onClick: () => {
				try {
					window.sessionStorage.setItem( 'invoiceninja_banner_' + last.number + last.status, '1' );
				} catch ( e ) {
					// Private mode: hide for this page only.
				}
				banner.remove();
			} }, '×' ) );
		first.parentNode.insertBefore( banner, first );
	}

	// ---------- Start ----------

	function boot() {
		initAddForms();
		initMiniCarts();
		document.querySelectorAll( '[data-invoiceninja-checkout]' ).forEach( initCheckout );
		document.querySelectorAll( '[data-invoiceninja-status]' ).forEach( initStatus );
		if ( ! document.querySelector( '[data-invoiceninja-checkout]' ) ) {
			initBanner();
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
