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

	async function api( method, path, body ) {
		const response = await fetch( cfg.rest + path, {
			method,
			credentials: 'same-origin',
			headers: body ? { 'Content-Type': 'application/json' } : {},
			body: body ? JSON.stringify( body ) : undefined,
		} );
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
		async load() {
			this.data = await api( 'GET', '/cart' );
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
						if ( result.ok && cfg.pages.checkout ) {
							window.location.href = cfg.pages.checkout;
							return;
						}
						showAddResult( form, result, title );
					} else {
						if ( ! cart.data ) {
							await cart.load();
						}
						const result = await cart.set( product, cart.quantity( product ) + qty );
						showAddResult( form, result, title );
					}
				} catch ( e ) {
					showAddResult( form, { ok: false, message: t.error }, title );
				}
				submit.disabled = false;
				submit.removeAttribute( 'aria-busy' );
			} );
		} );
	}

	function showAddResult( form, result, title ) {
		const old = form.parentNode.querySelector( '.invoiceninja-toast' );
		if ( old ) {
			old.remove();
		}
		let box;
		if ( result.ok ) {
			const text = fmt( t.added, title );
			box = el( 'p', { class: 'invoiceninja-toast' }, text, ' ',
				cfg.pages.checkout ? el( 'a', { href: cfg.pages.checkout, text: t.viewCart } ) : null,
				result.message ? el( 'span', { class: 'invoiceninja-toast__note', text: ' ' + result.message } ) : null );
			announce( text + '. ' + t.viewCart + '.' );
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
			const paint = () => {
				const count = cart.count();
				badge.textContent = count ? String( count ) : '';
				badge.hidden = ! count;
				toggle.setAttribute( 'aria-label', count ? toggle.textContent.trim() + ', ' + count + ' ' + t.items : toggle.textContent.trim() );
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
			const fill = () => {
				panel.textContent = '';
				const data = cart.data || { lines: [] };
				panel.appendChild( el( 'div', { class: 'invoiceninja-minicart__head' },
					el( 'strong', { text: t.yourCart } ),
					el( 'button', { type: 'button', class: 'invoiceninja-iconbutton', 'aria-label': t.closeCart, onClick: () => {
						close();
						toggle.focus();
					} }, '×' ) ) );
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
			toggle.addEventListener( 'click', async () => {
				if ( ! panel.hidden ) {
					close();
					return;
				}
				panel.hidden = false;
				toggle.setAttribute( 'aria-expanded', 'true' );
				panel.textContent = '';
				panel.appendChild( el( 'p', { class: 'invoiceninja-loading', text: '…' } ) );
				await cart.load();
				fill();
				const first = panel.querySelector( 'a, button' );
				if ( first ) {
					first.focus();
				}
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
		};
		let timer = null;

		const field = ( name, label, attrs, help ) => {
			const id = 'invoiceninja-' + name;
			return el( 'p', { class: 'invoiceninja-field' },
				el( 'label', { for: id }, label, attrs && attrs.optional ? el( 'span', { class: 'invoiceninja-muted', text: ' ' + t.optional } ) : null ),
				el( 'input', Object.assign( { id, name, value: state.details[ name ] || '', onInput: ( e ) => {
					state.details[ name ] = e.target.value;
				} }, attrs && attrs.input ? attrs.input : {} ) ),
				help ? el( 'span', { class: 'invoiceninja-help', text: help } ) : null );
		};

		const summary = ( data, editable ) => {
			const rows = ( data.lines || [] ).map( ( line ) => {
				const qty = editable ? el( 'span', { class: 'invoiceninja-stepper' },
					el( 'button', { type: 'button', class: 'invoiceninja-iconbutton', 'aria-label': fmt( t.oneFewer, line.title ), onClick: () => change( line, line.quantity - 1 ) }, '−' ),
					el( 'span', { class: 'invoiceninja-stepper__value' }, hidden( t.quantity + ' ' ), String( line.quantity ) ),
					el( 'button', { type: 'button', class: 'invoiceninja-iconbutton', 'aria-label': fmt( t.oneMore, line.title ), disabled: line.quantity >= line.max, onClick: () => change( line, line.quantity + 1 ) }, '+' ),
					line.quantity >= line.max ? el( 'span', { class: 'invoiceninja-muted', text: fmt( t.max, line.max ) } ) : null,
					el( 'button', { type: 'button', class: 'invoiceninja-linkbutton', onClick: () => change( line, 0 ) }, t.remove, hidden( ' ' + line.title ) ) ) : el( 'span', { class: 'invoiceninja-muted', text: '× ' + line.quantity } );
				return el( 'li', { class: 'invoiceninja-line' },
					el( 'span', { class: 'invoiceninja-line__title' }, el( 'strong', { text: line.title } ), el( 'span', { class: 'invoiceninja-muted', text: fmt( t.each, money( line.price ) ) } ) ),
					qty,
					el( 'span', { class: 'invoiceninja-line__total', text: money( line.line_total ) } ) );
			} );
			return el( 'div', { class: 'invoiceninja-summary' },
				el( 'ul', { class: 'invoiceninja-lines' }, rows ),
				el( 'p', { class: 'invoiceninja-summary__row' }, el( 'span', { text: t.subtotal } ), el( 'strong', { text: money( data.subtotal ) } ) ),
				el( 'p', { class: 'invoiceninja-summary__row invoiceninja-muted' }, el( 'span', { text: t.tax } ), el( 'span', { text: cfg.taxFields ? t.taxAddress : t.taxLater } ) ) );
		};

		const change = async ( line, quantity ) => {
			await cart.set( line.product, Math.max( 0, quantity ) );
			announce( line.title + ': ' + Math.max( 0, quantity ) );
			render();
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
			}
			return false;
		};

		const start = async () => {
			state.message = null;
			render( true );
			const result = await api( 'POST', '/checkout/start', state.details );
			if ( ! handle( result ) ) {
				render();
				return;
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
			state.step = 'placing';
			state.message = null;
			render();
			const result = await api( 'POST', '/checkout/confirm', Object.assign( {}, state.details, { code: state.code, remember: state.remember } ) );
			if ( ! handle( result ) ) {
				if ( result.code && result.code.indexOf( 'code_' ) === 0 ) {
					state.step = 'code';
					state.code = '';
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
			details( data ) {
				const form = el( 'form', { class: 'invoiceninja-details', onSubmit: ( e ) => {
					e.preventDefault();
					start();
				} },
					cfg.loggedIn ? null : el( 'p', { class: 'invoiceninja-muted' }, t.haveAccount + ' ', el( 'a', { href: cfg.loginUrl, text: t.logIn } ) ),
					field( 'email', t.email, { input: { type: 'email', autocomplete: 'email', required: true } } ),
					el( 'div', { class: 'invoiceninja-row' },
						field( 'first_name', t.firstName, { input: { autocomplete: 'given-name', required: true } } ),
						field( 'last_name', t.lastName, { input: { autocomplete: 'family-name', required: true } } ) ),
					field( 'phone', t.phone, { optional: true, input: { type: 'tel', autocomplete: 'tel' } } ),
					// Spam trap: people never see or fill it.
					el( 'p', { class: 'invoiceninja-sr', 'aria-hidden': 'true' }, el( 'input', { name: 'website', tabindex: '-1', autocomplete: 'off' } ) ),
					el( 'p', { class: 'invoiceninja-actions' }, el( 'button', { type: 'submit', class: 'wp-element-button' }, t.continue ) ),
					el( 'p', { class: 'invoiceninja-muted', text: t.nextCode } ) );
				return [ heading( t.checkout ), el( 'div', { class: 'invoiceninja-columns' }, form, el( 'aside', { class: 'invoiceninja-aside', 'aria-label': t.orderSummary }, el( 'h3', { text: t.orderSummary } ), summary( data, false ), el( 'button', { type: 'button', class: 'invoiceninja-linkbutton', onClick: () => {
					state.step = 'cart';
					render();
				} }, t.editCart ) ) ) ];
			},
			code() {
				const input = el( 'input', { id: 'invoiceninja-code', inputmode: 'numeric', autocomplete: 'one-time-code', maxlength: '6', 'aria-describedby': 'invoiceninja-code-help', class: 'invoiceninja-code', value: state.code, onInput: ( e ) => {
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
					el( 'p', { class: 'invoiceninja-actions' }, button( t.placeOrder, confirm ), el( 'button', { type: 'button', class: 'invoiceninja-linkbutton', onClick: () => {
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

		( async () => {
			if ( cfg.unavailable ) {
				state.step = 'unavailable';
				render();
				return;
			}
			const data = await cart.load();
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
		} )();
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
		const title = justPlaced ? ( order.status === 'awaiting_payment' && order.pay_link ? t.thanksPay : t.thanksReceived ) : ( order.status === 'paid' ? fmt( t.bannerPaid, order.number ) : t.yourOrder );
		nodes.push( el( 'h2', { class: 'invoiceninja-heading', tabindex: '-1', text: title } ) );
		nodes.push( el( 'p', { class: 'invoiceninja-muted', text: order.invoice_number ? fmt( t.orderLine, order.number, order.invoice_number ) : fmt( t.orderLineLater, order.number ) } ) );
		nodes.push( el( 'p', null, el( 'span', { class: 'invoiceninja-pill invoiceninja-pill--' + tone, text: label } ), order.email ? el( 'span', { class: 'invoiceninja-muted', text: ' ' + order.email } ) : null ) );
		nodes.push( el( 'div', { class: 'invoiceninja-summary' },
			el( 'ul', { class: 'invoiceninja-lines' }, order.items.map( ( i ) => el( 'li', { class: 'invoiceninja-line' }, el( 'span', { class: 'invoiceninja-line__title', text: i.quantity + ' × ' + i.title } ), el( 'span', { class: 'invoiceninja-line__total', text: money( i.price * i.quantity ) } ) ) ) ),
			el( 'p', { class: 'invoiceninja-summary__row' }, el( 'span', { text: order.status === 'paid' ? t.total : t.subtotal } ), el( 'strong', { text: money( order.total ) } ) ),
			order.status === 'paid' ? null : el( 'p', { class: 'invoiceninja-summary__row invoiceninja-muted' }, el( 'span', { text: t.tax } ), el( 'span', { text: t.taxLater } ) ) ) );
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
		const number = params.get( 'order' ) || '';
		const key = ( window.location.hash.match( /key=([0-9a-f]{32})/ ) || [] )[ 1 ] || '';
		const paint = ( nodes ) => {
			root.textContent = '';
			nodes.forEach( ( n ) => n && root.appendChild( n ) );
		};
		const find = () => {
			const form = el( 'form', { class: 'invoiceninja-find', onSubmit: async ( e ) => {
				e.preventDefault();
				const data = new FormData( form );
				await api( 'POST', '/orders/find', { number: data.get( 'number' ), email: data.get( 'email' ) } );
				paint( [ el( 'h2', { class: 'invoiceninja-heading', tabindex: '-1', text: t.linkOnWay } ), el( 'p', { role: 'status', text: t.findSent } ), cfg.pages.shop ? el( 'p', null, el( 'a', { href: cfg.pages.shop, text: t.backToShop } ) ) : null ] );
				root.querySelector( '.invoiceninja-heading' ).focus();
			} },
				el( 'p', { class: 'invoiceninja-field' }, el( 'label', { for: 'invoiceninja-find-number', text: t.orderNumber } ), el( 'input', { id: 'invoiceninja-find-number', name: 'number', value: number, required: true, 'aria-describedby': 'invoiceninja-find-hint' } ), el( 'span', { id: 'invoiceninja-find-hint', class: 'invoiceninja-help', text: t.orderNumberHint } ) ),
				el( 'p', { class: 'invoiceninja-field' }, el( 'label', { for: 'invoiceninja-find-email', text: t.email } ), el( 'input', { id: 'invoiceninja-find-email', name: 'email', type: 'email', autocomplete: 'email', required: true } ) ),
				el( 'p', { class: 'invoiceninja-actions' }, el( 'button', { type: 'submit', class: 'wp-element-button' }, t.emailLink ) ) );
			paint( [ el( 'h2', { class: 'invoiceninja-heading', text: t.findOrder } ), number ? el( 'p', { text: t.findIntro } ) : null, form ] );
		};
		const load = async () => {
			const order = await api( 'GET', '/orders/' + encodeURIComponent( number ) + '?key=' + encodeURIComponent( key ) );
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
		const first = document.querySelector( '[data-invoiceninja-store]' );
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
