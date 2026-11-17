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

	// Whether checkout asks for a delivery address (Settings → Store). Only the server knows:
	// it comes with the checkout's cart, and with a delivery_address error.
	const delivery = { fields: false, countries: [], country: '' };
	function applyDelivery( data ) {
		if ( ! data || typeof data.fields !== 'boolean' ) {
			return;
		}
		delivery.fields = data.fields;
		delivery.countries = Array.isArray( data.countries ) ? data.countries : [];
		delivery.country = data.country || '';
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

	function itemCount( count ) {
		return fmt( 1 === count ? t.oneItem : t.manyItems, count );
	}

	function quantityText( quantity ) {
		return String( Math.round( Number( quantity ) * 1000 ) / 1000 );
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

	function primary( label, onClick, extra ) {
		return button( label, onClick, Object.assign( { class: 'wp-element-button invoiceninja-primary' }, extra || {} ) );
	}

	// "Pay $64.00" once the invoice exists: its amount is what the payment page asks for.
	function payButton( href, amount ) {
		return el( 'a', { class: 'wp-element-button invoiceninja-primary invoiceninja-pay', href },
			lockIcon(), typeof amount === 'number' && amount > 0 ? fmt( t.payAmount, money( amount ) ) : t.payButton, hidden( ' ' + t.opensBilling ) );
	}

	// What is left to pay on an order: the invoice's balance, or its total.
	function amountDue( order ) {
		if ( order.invoice && typeof order.invoice.balance === 'number' && order.invoice.balance > 0 ) {
			return order.invoice.balance;
		}
		return order.invoice ? order.invoice.total : order.total;
	}

	// What Invoice Ninja's payment page asks for now: a deposit (partial) when the invoice
	// has one, else what is left to pay.
	function payDue( order ) {
		const inv = order.invoice;
		if ( inv && typeof inv.partial === 'number' && inv.partial > 0 && inv.partial < amountDue( order ) ) {
			return inv.partial;
		}
		return amountDue( order );
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
			if ( this.data.delivery_address ) {
				applyDelivery( this.data.delivery_address );
				delete this.data.delivery_address;
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

	// ---------- Shared pieces: page title, thumbnails, lines and totals ----------

	// The theme's own page title ("Checkout", "Order Status") above a block, if there is one.
	function pageTitle( root ) {
		return Array.prototype.find.call( document.querySelectorAll( 'h1.wp-block-post-title, h1.entry-title, h1.page-title' ), ( h ) =>
			! root.contains( h ) && ( h.compareDocumentPosition( root ) & Node.DOCUMENT_POSITION_FOLLOWING ) && h.textContent.trim() ) || null;
	}

	// One heading per screen: a view that says what the page is ("Your cart", "Your order is
	// paid.") stands in for the theme's title; other views keep it and use h2s below it.
	function titleControl( root ) {
		const title = pageTitle( root );
		return {
			has: !! title,
			own( mine ) {
				if ( title ) {
					title.style.display = mine ? 'none' : '';
				}
				return mine || ! title ? 1 : 2;
			},
		};
	}

	// The page's background, for parts that sit over content (the phone checkout bar, the drawer).
	function pageColors( node ) {
		const solid = ( c ) => c && c !== 'transparent' && ! /^rgba\(.*,\s*0\)$/.test( c );
		let background = '';
		for ( let n = node; n && n.nodeType === 1; n = n.parentElement ) {
			const c = window.getComputedStyle( n ).backgroundColor;
			if ( solid( c ) ) {
				background = c;
				break;
			}
		}
		return { background, color: window.getComputedStyle( node ).color };
	}

	function thumb( line, size ) {
		const box = el( 'span', { class: 'invoiceninja-thumb', 'aria-hidden': 'true', style: '--invoiceninja-thumb:' + size + 'px' } );
		if ( line.image ) {
			box.appendChild( el( 'img', { src: line.image, alt: '', width: String( size ), height: String( size ), loading: 'lazy', decoding: 'async' } ) );
		} else {
			box.classList.add( 'is-empty' );
			box.textContent = String( line.title || '' ).trim().charAt( 0 ).toUpperCase();
		}
		return box;
	}

	function totalRow( label, value, kind ) {
		return el( 'p', { class: 'invoiceninja-total' + ( kind ? ' is-' + kind : '' ) }, el( 'span', { text: label } ), el( 'span', { class: 'invoiceninja-total__value', text: value } ) );
	}

	// Totals of a cart (or an order not invoiced yet). The tax is shown only when this cart
	// works it out exactly as the invoice will; when Invoice Ninja calculates it from the
	// buyer's address, the subtotal is the last line and the tax comes on the payment page.
	function totals( data ) {
		if ( data.tax_later ) {
			return [ totalRow( t.tax, t.taxOnPayment, 'muted' ), totalRow( t.subtotal, money( data.subtotal ), 'grand' ) ];
		}
		const tax = typeof data.tax === 'number' ? data.tax : 0;
		const rows = [];
		if ( tax > 0 && ! data.inclusive ) {
			rows.push( totalRow( t.subtotal, money( data.subtotal ) ), totalRow( t.tax, money( tax ) ) );
		}
		rows.push( totalRow( t.total, money( data.total ), 'grand' ) );
		if ( tax > 0 && data.inclusive ) {
			rows.push( totalRow( t.taxIncludedLabel, money( tax ), 'muted' ) );
		}
		return rows;
	}

	// An order's totals before its invoice exists: its tax is the total less the lines.
	function orderTotals( order ) {
		const tax = order.inclusive || order.tax_later ? 0 : Math.round( ( order.total - order.subtotal ) * 100 ) / 100;
		return totals( { tax_later: order.tax_later, inclusive: order.inclusive, subtotal: order.subtotal, total: order.total, tax } );
	}

	// The invoice's own figures, row by row: they always add up to its total.
	function invoiceTotals( inv ) {
		const rows = [];
		if ( ( inv.tax > 0 && ! inv.inclusive ) || inv.adjustments ) {
			rows.push( totalRow( t.subtotal, money( inv.subtotal ) ) );
		}
		if ( inv.adjustments ) {
			rows.push( totalRow( t.adjustments, money( inv.adjustments ) ) );
		}
		if ( inv.tax > 0 && ! inv.inclusive ) {
			rows.push( totalRow( t.tax, money( inv.tax ) ) );
		}
		rows.push( totalRow( t.total, money( inv.total ), 'grand' ) );
		if ( inv.tax > 0 && inv.inclusive ) {
			rows.push( totalRow( t.taxIncludedLabel, money( inv.tax ), 'muted' ) );
		}
		if ( inv.paid > 0 && inv.balance > 0 ) {
			rows.push( totalRow( t.paidSoFar, money( inv.paid ) ), totalRow( t.balanceDue, money( inv.balance ), 'grand' ) );
		}
		return rows;
	}

	// A read-only line: thumbnail, name × quantity, line total.
	function summaryLine( line ) {
		return el( 'li', { class: 'invoiceninja-sline' },
			thumb( line, 48 ),
			el( 'span', { class: 'invoiceninja-sline__title' }, line.title, el( 'span', { class: 'invoiceninja-muted', text: ' × ' + quantityText( line.quantity ) } ) ),
			el( 'span', { class: 'invoiceninja-sline__total', text: money( line.line_total ) } ) );
	}

	// An order's lines: the invoice's when it exists (with the order's pictures), else the order's.
	function orderLines( order ) {
		if ( ! order.invoice ) {
			return order.items;
		}
		return order.invoice.lines.map( ( line ) => {
			const item = ( order.items || [] ).find( ( i ) => i.key === line.title || i.title === line.title ) || {};
			// The shop's name for the product when the line is one of the order's (an invoice line shows its product key).
			return Object.assign( {}, line, { image: item.image || '', title: item.title || line.title } );
		} );
	}

	// ---------- Cart lines (the cart page and the drawer) ----------

	// One editable list: what was just removed (for Undo), and which line just hit its limit.
	function cartUi( prefix, rerender ) {
		const ui = { prefix, removed: null, capped: '', error: '', busy: false };
		ui.change = async ( line, quantity, action ) => {
			ui.busy = true;
			const result = await cart.set( line.product, Math.max( 0, quantity ) );
			ui.busy = false;
			ui.capped = '';
			ui.error = '';
			if ( result.ok ) {
				const now = typeof result.quantity === 'number' ? result.quantity : Math.max( 0, quantity );
				if ( action === 'remove' ) {
					ui.removed = { product: line.product, title: line.title, quantity: line.quantity };
					announce( fmt( t.removed, line.title ) );
				} else {
					ui.removed = null;
					const max = typeof result.max === 'number' ? result.max : line.max;
					const atLimit = action === 'more' && max > 0 && ( result.limited || now >= max );
					if ( atLimit ) {
						ui.capped = line.product;
					}
					announce( line.title + ': ' + now + ( atLimit ? '. ' + fmt( t.onlyAvailable, max ) : '' ) );
				}
			} else {
				ui.error = result.message || t.error;
			}
			rerender( line.product, action );
		};
		ui.undo = async () => {
			const back = ui.removed;
			if ( ! back ) {
				return;
			}
			ui.busy = true;
			const result = await cart.set( back.product, back.quantity );
			ui.busy = false;
			ui.removed = null;
			if ( result.ok ) {
				announce( fmt( t.restored, back.title ) );
			} else {
				ui.error = result.message || t.error;
			}
			rerender( back.product, 'remove' );
		};
		ui.forget = () => {
			ui.removed = null;
			ui.capped = '';
			ui.error = '';
		};
		return ui;
	}

	function cartLine( line, ui ) {
		const capId = ui.prefix + '-cap-' + line.product;
		const capped = ui.capped === line.product;
		const name = line.url
			? el( 'a', { class: 'invoiceninja-cartline__name', href: line.url, text: line.title } )
			: el( 'span', { class: 'invoiceninja-cartline__name', text: line.title } );
		return el( 'li', { class: 'invoiceninja-cartline', 'data-product': line.product },
			thumb( line, 72 ),
			el( 'div', { class: 'invoiceninja-cartline__body' },
				el( 'div', { class: 'invoiceninja-cartline__top' }, name, el( 'span', { class: 'invoiceninja-cartline__total', text: money( line.line_total ) } ) ),
				line.descriptor ? el( 'p', { class: 'invoiceninja-cartline__meta invoiceninja-muted', text: line.descriptor } ) : null,
				el( 'p', { class: 'invoiceninja-cartline__meta invoiceninja-muted', text: fmt( t.each, money( line.price ) ) } ),
				el( 'div', { class: 'invoiceninja-cartline__controls' },
					el( 'span', { class: 'invoiceninja-stepper', role: 'group', 'aria-label': t.quantity + ': ' + line.title },
						// Remove is the way to delete, so minus stops at one.
						el( 'button', { type: 'button', class: 'invoiceninja-stepper__button', 'data-action': 'fewer', 'aria-label': fmt( t.oneFewer, line.title ), disabled: line.quantity <= 1, onClick: () => ui.change( line, line.quantity - 1, 'fewer' ) }, '−' ),
						el( 'span', { class: 'invoiceninja-stepper__value' }, hidden( t.quantity + ' ' ), String( line.quantity ) ),
						el( 'button', { type: 'button', class: 'invoiceninja-stepper__button', 'data-action': 'more', 'aria-label': fmt( t.oneMore, line.title ), 'aria-describedby': capped ? capId : null, disabled: line.quantity >= line.max, onClick: () => ui.change( line, line.quantity + 1, 'more' ) }, '+' ) ),
					el( 'button', { type: 'button', class: 'invoiceninja-linkbutton invoiceninja-cartline__remove', 'data-action': 'remove', onClick: () => ui.change( line, 0, 'remove' ) }, t.remove, hidden( ' ' + line.title ) ) ),
				capped ? el( 'p', { id: capId, class: 'invoiceninja-cartline__limit', text: fmt( t.onlyAvailable, line.max ) } ) : null ) );
	}

	function cartLines( data, ui ) {
		return [
			ui.error ? notice( 'error', null, ui.error ) : null,
			ui.removed ? el( 'p', { class: 'invoiceninja-undo' }, fmt( t.removed, ui.removed.title ) + ' ',
				el( 'button', { type: 'button', class: 'invoiceninja-linkbutton', 'data-action': 'undo', 'aria-label': fmt( t.undoRemove, ui.removed.title ), onClick: ui.undo }, t.undo ) ) : null,
			data.lines && data.lines.length ? el( 'ul', { class: 'invoiceninja-cartlines' }, data.lines.map( ( line ) => cartLine( line, ui ) ) ) : null,
		];
	}

	// Back on the control that was used (re-rendering drops focus to the page): Undo after a
	// removal, else the same button on the same line, another of its buttons when that one is
	// now disabled, or the fallback (a heading) when the line is gone.
	function refocus( container, product, action, fallback ) {
		const row = Array.prototype.find.call( container.querySelectorAll( '[data-product]' ), ( node ) => node.getAttribute( 'data-product' ) === product );
		let target = action === 'remove' ? container.querySelector( '[data-action="undo"]' ) : null;
		if ( ! target && row ) {
			const same = row.querySelector( '[data-action="' + action + '"]' );
			target = same && ! same.disabled ? same : row.querySelector( '[data-action]:not([disabled])' );
		}
		( target || fallback || container ).focus();
	}

	// ---------- Mini Cart: a drawer from the end edge ----------

	function initMiniCarts() {
		document.querySelectorAll( '[data-invoiceninja-minicart]' ).forEach( ( root, index ) => {
			let toggle = root.querySelector( '.invoiceninja-minicart__button' );
			const panel = root.querySelector( '.invoiceninja-minicart__panel' );
			if ( ! toggle || ! panel ) {
				return;
			}
			// Without JavaScript the toggle is a link to the cart page; with it, a button for the drawer.
			if ( toggle.tagName === 'A' ) {
				const replacement = el( 'button', { type: 'button', class: toggle.className, 'aria-haspopup': 'dialog', 'aria-expanded': 'false' } );
				while ( toggle.firstChild ) {
					replacement.appendChild( toggle.firstChild );
				}
				toggle.replaceWith( replacement );
				toggle = replacement;
			}
			const badge = root.querySelector( '[data-invoiceninja-count]' );
			// The button's own words, without the count badge.
			const name = Array.prototype.filter.call( toggle.childNodes, ( node ) => node !== badge && ! ( node.contains && node.contains( badge ) ) )
				.map( ( node ) => node.textContent ).join( '' ).trim() || t.cart;
			const titleId = 'invoiceninja-drawer-title-' + index;
			// The drawer sits at the end of the page, so nothing in the header clips or covers it.
			const backdrop = el( 'div', { class: 'invoiceninja-drawer__backdrop' } );
			const layer = el( 'div', { class: 'invoiceninja-drawer', hidden: true }, backdrop );
			panel.classList.add( 'invoiceninja-drawer__panel' );
			panel.setAttribute( 'aria-modal', 'true' );
			panel.setAttribute( 'aria-labelledby', titleId );
			panel.removeAttribute( 'aria-label' );
			panel.hidden = false;
			layer.appendChild( panel );
			document.body.appendChild( layer );
			const paint = () => {
				const count = cart.count();
				badge.textContent = count ? String( count ) : '';
				badge.hidden = ! count;
				toggle.setAttribute( 'aria-label', count ? name + ', ' + itemCount( count ) : name );
			};
			const close = ( focusToggle ) => {
				if ( layer.hidden ) {
					return;
				}
				layer.classList.remove( 'is-open' );
				document.documentElement.classList.remove( 'invoiceninja-locked' );
				toggle.setAttribute( 'aria-expanded', 'false' );
				const reduced = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
				window.setTimeout( () => {
					if ( ! layer.classList.contains( 'is-open' ) ) {
						layer.hidden = true;
					}
				}, reduced ? 0 : 220 );
				if ( focusToggle ) {
					toggle.focus();
				}
			};
			const ui = cartUi( 'invoiceninja-drawer-' + index, ( product, action ) => {
				fill();
				refocus( panel, product, action, panel.querySelector( '.invoiceninja-drawer__title' ) );
			} );
			const head = ( count ) => el( 'div', { class: 'invoiceninja-drawer__head' },
				el( 'h2', { id: titleId, class: 'invoiceninja-drawer__title', tabindex: '-1' }, t.yourCart, typeof count === 'number' ? el( 'span', { class: 'invoiceninja-muted', text: ' (' + count + ')' } ) : null ),
				el( 'button', { type: 'button', class: 'invoiceninja-iconbutton', 'aria-label': t.closeCart, onClick: () => close( true ) }, '×' ) );
			const keepShopping = () => el( 'button', { type: 'button', class: 'invoiceninja-linkbutton', onClick: () => close( true ) }, t.keepShopping );
			const fill = () => {
				panel.textContent = '';
				const data = cart.data || { lines: [] };
				const lines = data.lines || [];
				panel.appendChild( head( data.count || 0 ) );
				panel.appendChild( el( 'div', { class: 'invoiceninja-drawer__body' },
					cartLines( data, ui ),
					lines.length ? null : el( 'p', { class: 'invoiceninja-drawer__empty', text: t.cartEmpty } ) ) );
				panel.appendChild( el( 'div', { class: 'invoiceninja-drawer__foot' },
					lines.length ? el( 'div', { class: 'invoiceninja-totals' }, totals( data ) ) : null,
					lines.length && cfg.pages.checkout ? linkButton( t.checkout, cfg.pages.checkout, { class: 'wp-element-button invoiceninja-primary invoiceninja-wide' } ) : null,
					! lines.length && cfg.pages.shop ? linkButton( t.continueShop, cfg.pages.shop, { class: 'wp-element-button invoiceninja-primary invoiceninja-wide' } ) : null,
					keepShopping() ) );
			};
			const open = async () => {
				const colors = pageColors( document.body );
				if ( colors.background ) {
					layer.style.setProperty( '--invoiceninja-bg', colors.background );
				}
				layer.style.setProperty( '--invoiceninja-fg', colors.color );
				ui.forget();
				layer.hidden = false;
				window.requestAnimationFrame( () => layer.classList.add( 'is-open' ) );
				document.documentElement.classList.add( 'invoiceninja-locked' );
				toggle.setAttribute( 'aria-expanded', 'true' );
				panel.textContent = '';
				panel.appendChild( head() );
				panel.appendChild( el( 'p', { class: 'invoiceninja-loading', role: 'status', text: t.loadingCart } ) );
				panel.querySelector( '.invoiceninja-drawer__title' ).focus();
				const result = await cart.load();
				if ( layer.hidden ) {
					return; // Closed while loading.
				}
				if ( Array.isArray( result.lines ) ) {
					fill();
				} else {
					// Not the (possibly old) cart: what went wrong, and a way to try again.
					panel.textContent = '';
					panel.appendChild( head() );
					panel.appendChild( el( 'div', { class: 'invoiceninja-drawer__body' },
						el( 'p', { class: 'invoiceninja-toast is-error', role: 'alert', text: result.message } ),
						button( t.tryAgain, open ) ) );
				}
				panel.querySelector( '.invoiceninja-drawer__title' ).focus();
			};
			paint();
			cart.listeners.push( () => {
				paint();
				if ( ! layer.hidden && ! ui.busy ) {
					fill();
				}
			} );
			toggle.addEventListener( 'click', () => ( layer.hidden ? open() : close( true ) ) );
			backdrop.addEventListener( 'click', () => close( true ) );
			// A modal drawer: Escape closes it, and Tab stays inside.
			layer.addEventListener( 'keydown', ( event ) => {
				if ( event.key === 'Escape' ) {
					event.stopPropagation();
					close( true );
					return;
				}
				if ( event.key !== 'Tab' ) {
					return;
				}
				const focusable = Array.prototype.filter.call( panel.querySelectorAll( 'a[href], button:not([disabled]), input:not([disabled]), select, [tabindex="0"]' ), ( n ) => n.getClientRects().length > 0 );
				if ( ! focusable.length ) {
					return;
				}
				const first = focusable[ 0 ];
				const last = focusable[ focusable.length - 1 ];
				if ( event.shiftKey && ( document.activeElement === first || ! panel.contains( document.activeElement ) || document.activeElement === panel.querySelector( '.invoiceninja-drawer__title' ) ) ) {
					event.preventDefault();
					last.focus();
				} else if ( ! event.shiftKey && document.activeElement === last ) {
					event.preventDefault();
					first.focus();
				}
			} );
		} );
	}

	// ---------- Checkout ----------

	function initCheckout( root ) {
		const title = titleControl( root );
		const state = {
			step: 'loading',
			details: { email: '', first_name: '', last_name: '', phone: '' },
			remember: true,
			code: '',
			codeError: '',
			message: null,
			order: null,
			retryAfter: 0,
			trap: '',
			// With login required, orders use the account's email (the server says which).
			account: '',
			failed: '',
			// Field errors by name, shown under each field; focusErrors moves focus to the first.
			fieldErrors: {},
			focusErrors: false,
			// With taxes and a delivery address both asked for: the tax comes from the delivery address.
			taxFromDelivery: true,
			resent: false,
		};
		let timer = null;
		// Whether the summary sits beside the steps: the block is at least 50rem wide (the
		// stylesheet's container query), whatever the screen.
		const wide = () => root.getBoundingClientRect().width >= 50 * ( parseFloat( window.getComputedStyle( document.documentElement ).fontSize ) || 16 );
		let wasWide = null;
		if ( window.ResizeObserver ) {
			new window.ResizeObserver( () => {
				const now = wide();
				const box = root.querySelector( '.invoiceninja-summary' );
				if ( box && now !== wasWide ) {
					box.open = now;
				}
				wasWide = now;
			} ).observe( root );
		}

		const ui = cartUi( 'invoiceninja-cart', ( product, action ) => {
			if ( state.message && state.message.type === 'error' ) {
				state.message = null; // An earlier failed change.
			}
			render();
			if ( state.step === 'cart' ) {
				refocus( root, product, action, root.querySelector( '.invoiceninja-heading' ) );
			}
		} );
		// The drawer changed the cart: show it here too.
		cart.listeners.push( () => {
			if ( state.step === 'cart' && ! ui.busy ) {
				render();
			}
		} );

		// What the server checks besides the details: the spam trap and the total the shopper saw.
		const checks = () => ( { in_leave_empty: state.trap, total: cart.data ? cart.data.total : undefined } );

		const clearError = ( name, input ) => {
			if ( ! state.fieldErrors[ name ] ) {
				return;
			}
			delete state.fieldErrors[ name ];
			input.removeAttribute( 'aria-invalid' );
			const wrap = input.closest( '.invoiceninja-field' );
			const error = wrap ? wrap.querySelector( '.invoiceninja-field__error' ) : null;
			if ( error ) {
				input.setAttribute( 'aria-describedby', ( input.getAttribute( 'aria-describedby' ) || '' ).replace( error.id, '' ).trim() );
				error.remove();
				wrap.classList.remove( 'is-invalid' );
			}
		};

		const described = ( id, help, error, extra ) => [ help ? id + '-help' : '', error ? id + '-error' : '', extra || '' ].filter( Boolean ).join( ' ' ) || null;

		const field = ( name, label, attrs, help ) => {
			const id = 'invoiceninja-' + name;
			const error = state.fieldErrors[ name ];
			const input = Object.assign( {}, attrs && attrs.input ? attrs.input : {} );
			const extra = input[ 'aria-describedby' ];
			delete input[ 'aria-describedby' ];
			return el( 'p', { class: 'invoiceninja-field' + ( error ? ' is-invalid' : '' ) },
				el( 'label', { for: id }, label, attrs && attrs.optional ? el( 'span', { class: 'invoiceninja-muted', text: ' ' + t.optional } ) : null ),
				el( 'input', Object.assign( { id, name, type: 'text', value: state.details[ name ] || '', onInput: ( e ) => {
					state.details[ name ] = e.target.value;
					clearError( name, e.target );
				} }, input, { 'aria-describedby': described( id, help, error, extra ), 'aria-invalid': error ? 'true' : null } ) ),
				help ? el( 'span', { id: id + '-help', class: 'invoiceninja-help', text: help } ) : null,
				error ? el( 'span', { id: id + '-error', class: 'invoiceninja-field__error', text: error } ) : null );
		};

		const countryField = ( name, label, countries, autocomplete, onChange, help ) => {
			const id = 'invoiceninja-' + name;
			const error = state.fieldErrors[ name ];
			return el( 'p', { class: 'invoiceninja-field' + ( error ? ' is-invalid' : '' ) },
				el( 'label', { for: id, text: label } ),
				el( 'select', { id, name, required: true, autocomplete, 'aria-invalid': error ? 'true' : null, 'aria-describedby': described( id, false, error, help ), onChange: ( e ) => {
					state.details[ name ] = e.target.value;
					clearError( name, e.target );
					if ( onChange ) {
						onChange();
					}
				} }, el( 'option', { value: '', text: t.chooseCountry } ), countries.map( ( c ) => el( 'option', { value: c[ 0 ], text: c[ 1 ], selected: c[ 0 ] === state.details[ name ] } ) ) ),
				error ? el( 'span', { id: id + '-error', class: 'invoiceninja-field__error', text: error } ) : null );
		};

		// The browser's own checks, shown as our inline errors (the form has novalidate).
		const validate = ( form ) => {
			const errors = {};
			Array.prototype.forEach.call( form.querySelectorAll( 'input[name], select[name]' ), ( input ) => {
				if ( input.name === 'in_leave_empty' || input.type === 'checkbox' || input.checkValidity() ) {
					return;
				}
				const v = input.validity;
				errors[ input.name ] = v.valueMissing ? t.required : ( v.typeMismatch && input.type === 'email' ? t.invalidEmail : t.checkField );
			} );
			state.fieldErrors = errors;
			if ( Object.keys( errors ).length ) {
				state.focusErrors = true;
				render();
				return false;
			}
			return true;
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
			return [
				el( 'div', { class: 'invoiceninja-row' },
					countryField( 'country_id', t.country, tax.countries, 'country', syncPostcode, 'invoiceninja-tax-help' ),
					postcode ),
				el( 'p', { id: 'invoiceninja-tax-help', class: 'invoiceninja-help', text: t.taxHelp } ),
			];
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
			} else if ( result.code === 'invalid_email' ) {
				state.fieldErrors = { email: result.message };
				state.message = null;
				state.step = 'details';
			} else if ( result.code === 'tax_address' ) {
				// The server needs the tax address even if this (cached) page didn't know.
				applyTax( result.tax_address );
				const errors = result.fields || {};
				if ( delivery.fields && state.taxFromDelivery ) {
					// Asked as part of the delivery address: show the errors there.
					state.fieldErrors = {};
					Object.keys( errors ).forEach( ( key ) => {
						state.fieldErrors[ key === 'country_id' ? 'shipping_country_id' : 'shipping_postal_code' ] = errors[ key ];
					} );
				} else {
					state.fieldErrors = Object.assign( {}, errors );
				}
				state.message = null;
				state.step = delivery.fields ? 'delivery' : 'details';
			} else if ( result.code === 'delivery_address' ) {
				applyDelivery( result.delivery_address );
				state.fieldErrors = Object.assign( {}, result.fields || {} );
				state.message = null;
				state.step = 'delivery';
			}
			state.focusErrors = Object.keys( state.fieldErrors ).length > 0;
			return false;
		};

		const start = async ( again ) => {
			state.message = null;
			render( true );
			const result = await api( 'POST', '/checkout/start', Object.assign( {}, state.details, checks() ) );
			// Sent within a moment of the first add: wait those seconds out once, then go on.
			if ( result.code === 'too_fast' && again !== true ) {
				window.setTimeout( () => start( true ), Math.min( 10, result.retry_after || 3 ) * 1000 );
				return;
			}
			if ( ! handle( result ) ) {
				state.resent = false;
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
				state.codeError = '';
				state.retryAfter = Date.now() + ( ( result.retry_after || 60 ) * 1000 );
			}
			render();
			if ( state.resent ) {
				state.resent = false;
				announce( t.codeResent );
			}
		};

		const confirm = async () => {
			if ( state.step === 'code' && state.code.length !== 6 ) {
				state.codeError = t.codeHelp;
				render();
				return;
			}
			const from = state.step;
			state.step = 'placing';
			state.message = null;
			state.codeError = '';
			render();
			const result = await api( 'POST', '/checkout/confirm', Object.assign( {}, state.details, checks(), { code: state.code, remember: state.remember } ) );
			if ( ! handle( result ) ) {
				if ( result.code === 'network' ) {
					// Back where the shopper was, code kept: sending again can't place the order twice.
					state.step = from === 'remembered' ? 'remembered' : 'code';
				} else if ( result.code && result.code.indexOf( 'code_' ) === 0 ) {
					// A wrong or expired code: said under the boxes, which are cleared for another try.
					state.step = 'code';
					state.code = '';
					state.codeError = result.message || t.error;
					state.message = null;
				} else if ( state.step === 'placing' ) {
					state.step = from === 'remembered' ? 'remembered' : 'details'; // With the message, never a "Placing…" that doesn't end.
				}
				render();
				return;
			}
			state.confirmed = from;
			state.order = result;
			state.step = 'done';
			cart.data = { lines: [], count: 0, subtotal: 0, total: 0 };
			cart.emit();
			render();
		};

		const pageHeading = ( text ) => ( title.has ? null : el( 'h2', { class: 'invoiceninja-heading', tabindex: '-1', text } ) );

		// ----- The cart -----

		const goToCheckout = () => {
			ui.forget();
			state.step = cfg.requireLogin && ! cfg.loggedIn ? 'login' : 'details';
			render();
		};

		const cartView = ( data, level ) => {
			const lines = data.lines || [];
			const head = el( 'div', { class: 'invoiceninja-cart__head' },
				el( 'h' + level, { class: 'invoiceninja-heading invoiceninja-cart__title', tabindex: '-1', text: t.yourCart } ),
				el( 'span', { class: 'invoiceninja-muted', text: itemCount( data.count || 0 ) } ) );
			if ( ! lines.length ) {
				return [ el( 'div', { class: 'invoiceninja-cart is-empty' }, head,
					cartLines( data, ui ),
					el( 'p', { class: 'invoiceninja-cart__empty', text: t.cartEmpty } ),
					cfg.pages.shop ? el( 'p', null, linkButton( t.continueShop, cfg.pages.shop, { class: 'wp-element-button invoiceninja-primary' } ) ) : null ) ];
			}
			return [ el( 'div', { class: 'invoiceninja-cart' },
				head,
				el( 'div', { class: 'invoiceninja-cart__items' }, cartLines( data, ui ) ),
				el( 'div', { class: 'invoiceninja-cart__summary invoiceninja-totals' }, totals( data ) ),
				// On phones this bar stays at the bottom of the screen while the cart scrolls.
				el( 'div', { class: 'invoiceninja-cart__bar' },
					el( 'button', { type: 'button', class: 'wp-element-button invoiceninja-primary invoiceninja-wide invoiceninja-cart__checkout', onClick: goToCheckout },
						el( 'span', { text: t.checkout } ), data.tax_later ? null : el( 'span', { text: money( data.total ) } ) ) ),
				cfg.pages.shop ? el( 'p', { class: 'invoiceninja-cart__more' }, el( 'a', { href: cfg.pages.shop, text: t.continueShop } ) ) : null ) ];
		};

		// ----- The steps -----

		const stepIds = () => [ 'details', delivery.fields ? 'delivery' : null, 'confirm', 'payment' ].filter( Boolean );
		const stepNames = { details: t.stepContact, delivery: t.stepDelivery, confirm: t.stepConfirm, payment: t.stepPayment };
		const stepOf = { details: 'details', delivery: 'delivery', code: 'confirm', remembered: 'confirm', placing: 'confirm', done: 'payment' };

		const countryName = ( id ) => {
			const found = delivery.countries.concat( tax.countries ).find( ( c ) => c[ 0 ] === id );
			return found ? found[ 1 ] : '';
		};
		const addressText = () => {
			const d = state.details;
			const town = [ d.shipping_state, d.shipping_postal_code ].filter( Boolean ).join( ' ' );
			return [ d.shipping_address1, d.shipping_address2, [ d.shipping_city, town ].filter( Boolean ).join( ', ' ), countryName( d.shipping_country_id ) ].filter( Boolean ).join( ', ' );
		};
		const stepSummary = ( id ) => {
			if ( id === 'details' ) {
				return [ [ state.details.first_name, state.details.last_name ].filter( Boolean ).join( ' ' ), state.details.email ].filter( Boolean ).join( ' · ' );
			}
			if ( id === 'delivery' ) {
				return addressText();
			}
			return state.confirmed === 'remembered' ? fmt( t.remembered, state.details.email ) : t.emailConfirmed;
		};

		const focusField = ( name ) => {
			const input = root.querySelector( '[name="' + name + '"]' );
			if ( input ) {
				input.focus();
			}
		};

		const stepSection = ( id, body ) => {
			const order = stepIds();
			const at = order.indexOf( id );
			const now = order.indexOf( stepOf[ state.step ] );
			const status = at < now ? 'done' : ( at === now ? 'current' : 'todo' );
			const headingId = 'invoiceninja-step-' + id;
			const editable = status === 'done' && ( id === 'details' || id === 'delivery' ) && state.step !== 'done' && state.step !== 'placing';
			// A plain section (not a labelled region): seven landmarks for one form would be noise.
			return el( 'section', { class: 'invoiceninja-step is-' + status },
				el( 'div', { class: 'invoiceninja-step__head' },
					el( 'h2', { id: headingId, class: 'invoiceninja-step__title' + ( status === 'current' ? ' invoiceninja-heading' : '' ), tabindex: status === 'current' ? '-1' : null },
						el( 'span', { class: 'invoiceninja-step__number', text: ( at + 1 ) + ' · ' } ), stepNames[ id ],
						status === 'current' ? hidden( ' (' + fmt( t.stepOf, at + 1, order.length ) + ')' ) : null ),
					editable ? el( 'button', { type: 'button', class: 'invoiceninja-linkbutton', onClick: () => {
						state.step = id;
						render();
						focusField( id === 'details' ? 'email' : 'shipping_address1' );
					} }, t.edit, hidden( ' ' + stepNames[ id ] ) ) : null ),
				status === 'done' ? el( 'p', { class: 'invoiceninja-step__summary invoiceninja-muted', text: stepSummary( id ) } ) : null,
				status === 'current' ? el( 'div', { class: 'invoiceninja-step__body' }, body() ) : null );
		};

		const trapField = () => el( 'p', { class: 'invoiceninja-sr', 'aria-hidden': 'true' }, el( 'input', { type: 'text', name: 'in_leave_empty', tabindex: '-1', autocomplete: 'off', 'data-1p-ignore': true, 'data-lpignore': 'true', 'data-bwignore': true, value: state.trap, onInput: ( e ) => {
			state.trap = e.target.value;
		} } ) );

		const detailsBody = () => {
			const form = el( 'form', { class: 'invoiceninja-form', novalidate: true, onSubmit: ( e ) => {
				e.preventDefault();
				const trap = form.querySelector( '[name="in_leave_empty"]' );
				state.trap = trap ? trap.value : '';
				if ( ! validate( form ) ) {
					return;
				}
				if ( delivery.fields ) {
					state.step = 'delivery';
					render();
					return;
				}
				start();
			} },
				cfg.loggedIn ? null : el( 'p', { class: 'invoiceninja-muted' }, t.haveAccount + ' ', el( 'a', { href: cfg.loginUrl, text: t.logIn } ) ),
				state.account
					? field( 'email', t.email, { input: { type: 'email', autocomplete: 'email', required: true, readonly: true } }, t.accountEmail )
					: field( 'email', t.email, { input: { type: 'email', autocomplete: 'email', required: true, autocapitalize: 'off', spellcheck: 'false' } } ),
				el( 'div', { class: 'invoiceninja-row' },
					field( 'first_name', t.firstName, { input: { autocomplete: 'given-name', required: true } } ),
					field( 'last_name', t.lastName, { input: { autocomplete: 'family-name', required: true } } ) ),
				field( 'phone', t.phone, { optional: true, input: { type: 'tel', autocomplete: 'tel' } } ),
				delivery.fields ? null : taxFields(),
				// Spam trap: people never see or fill it, and its name is one autofill leaves alone.
				trapField(),
				el( 'p', { class: 'invoiceninja-actions' }, el( 'button', { type: 'submit', class: 'wp-element-button invoiceninja-primary' }, t.continue ) ),
				delivery.fields ? null : el( 'p', { class: 'invoiceninja-muted', text: t.nextCode } ) );
			return form;
		};

		const deliveryBody = () => {
			if ( state.details.shipping_country_id === undefined ) {
				state.details.shipping_country_id = delivery.countries.some( ( c ) => c[ 0 ] === delivery.country ) ? delivery.country : '';
			}
			const both = tax.fields;
			// A US buyer's tax needs a ZIP code; elsewhere the postcode is optional.
			const zipNeeded = () => both && state.taxFromDelivery && tax.postcodeRequired.indexOf( String( state.details.shipping_country_id ) ) >= 0;
			const postcode = field( 'shipping_postal_code', t.postcode, { optional: true, input: { autocomplete: 'shipping postal-code', maxlength: '16' } } );
			const syncPostcode = () => {
				const optional = postcode.querySelector( 'label .invoiceninja-muted' );
				postcode.querySelector( 'input' ).required = zipNeeded();
				if ( optional ) {
					optional.hidden = zipNeeded();
				}
			};
			syncPostcode();
			const form = el( 'form', { class: 'invoiceninja-form', novalidate: true, onSubmit: ( e ) => {
				e.preventDefault();
				if ( ! validate( form ) ) {
					return;
				}
				if ( both && state.taxFromDelivery ) {
					state.details.country_id = state.details.shipping_country_id || '';
					state.details.postal_code = state.details.shipping_postal_code || '';
				}
				start();
			} },
				field( 'shipping_address1', t.address1, { input: { autocomplete: 'shipping address-line1', required: true, maxlength: '100' } } ),
				field( 'shipping_address2', t.address2, { optional: true, input: { autocomplete: 'shipping address-line2', maxlength: '100' } } ),
				el( 'div', { class: 'invoiceninja-row' },
					field( 'shipping_city', t.city, { input: { autocomplete: 'shipping address-level2', required: true, maxlength: '100' } } ),
					field( 'shipping_state', t.region, { optional: true, input: { autocomplete: 'shipping address-level1', maxlength: '100' } } ) ),
				el( 'div', { class: 'invoiceninja-row' },
					postcode,
					delivery.countries.length ? countryField( 'shipping_country_id', t.country, delivery.countries, 'shipping country', syncPostcode ) : null ),
				both ? el( 'p', { class: 'invoiceninja-check' }, el( 'label', null, el( 'input', { type: 'checkbox', checked: state.taxFromDelivery, onChange: ( e ) => {
					state.taxFromDelivery = e.target.checked;
					render();
					focusField( 'shipping_postal_code' );
					const box = root.querySelector( '.invoiceninja-check input' );
					if ( box ) {
						box.focus();
					}
				} } ), ' ' + t.taxFromDelivery ) ) : null,
				both && ! state.taxFromDelivery ? el( 'fieldset', { class: 'invoiceninja-fieldset' }, el( 'legend', { text: t.taxAddressTitle } ), taxFields() ) : null,
				el( 'p', { class: 'invoiceninja-actions' }, el( 'button', { type: 'submit', class: 'wp-element-button invoiceninja-primary' }, t.continue ) ),
				el( 'p', { class: 'invoiceninja-muted', text: t.nextCode } ) );
			return form;
		};

		const codeBody = () => {
			const id = 'invoiceninja-code';
			const boxes = [ 0, 1, 2, 3, 4, 5 ].map( () => el( 'span', { class: 'invoiceninja-otp__box' } ) );
			// One real input over six boxes: one-time-code autofill, paste and screen readers all
			// see a single field; the boxes only show what's in it.
			const input = el( 'input', { id, name: 'code', type: 'text', inputmode: 'numeric', autocomplete: 'one-time-code', pattern: '[0-9]*', maxlength: '6', spellcheck: 'false', class: 'invoiceninja-otp__input', value: state.code,
				'aria-describedby': described( id, true, state.codeError ), 'aria-invalid': state.codeError ? 'true' : null,
				onInput: ( e ) => {
					state.code = e.target.value.replace( /\D/g, '' ).slice( 0, 6 );
					e.target.value = state.code;
					paintBoxes();
					if ( state.code.length === 6 ) {
						confirm();
					}
				},
				onFocus: () => paintBoxes(),
				onBlur: () => paintBoxes(),
			} );
			const paintBoxes = () => {
				const focused = document.activeElement === input;
				boxes.forEach( ( box, i ) => {
					box.textContent = state.code[ i ] || '';
					box.classList.toggle( 'is-filled', i < state.code.length );
					box.classList.toggle( 'is-active', focused && i === Math.min( state.code.length, 5 ) );
				} );
			};
			paintBoxes();
			const resend = el( 'button', { type: 'button', class: 'invoiceninja-linkbutton', 'data-resend': '1', onClick: () => {
				state.resent = true;
				start();
			} } );
			const hint = el( 'p', { class: 'invoiceninja-help', hidden: true, text: t.resendNow } );
			const paintResend = () => {
				const left = Math.max( 0, Math.ceil( ( state.retryAfter - Date.now() ) / 1000 ) );
				if ( left > 0 ) {
					resend.disabled = true;
					resend.textContent = fmt( t.resendCodeIn, Math.floor( left / 60 ) + ':' + String( left % 60 ).padStart( 2, '0' ) );
					return;
				}
				window.clearInterval( timer );
				resend.disabled = false;
				resend.textContent = t.resend;
				hint.hidden = false;
			};
			window.clearInterval( timer );
			timer = window.setInterval( paintResend, 1000 );
			paintResend();
			window.setTimeout( () => input.focus(), 0 );
			return [
				el( 'p', { text: fmt( t.codeEnter, state.details.email ) + ' ' + t.codeWhy } ),
				el( 'p', { class: 'invoiceninja-check' }, el( 'label', null, el( 'input', { type: 'checkbox', checked: state.remember, onChange: ( e ) => {
					state.remember = e.target.checked;
				} } ), ' ' + t.remember + ' ', el( 'span', { class: 'invoiceninja-muted', text: t.rememberHint } ) ) ),
				el( 'div', { class: 'invoiceninja-field' + ( state.codeError ? ' is-invalid' : '' ) },
					el( 'label', { for: id, text: t.code } ),
					el( 'div', { class: 'invoiceninja-otp' }, el( 'div', { class: 'invoiceninja-otp__boxes', 'aria-hidden': 'true' }, boxes ), input ),
					el( 'span', { id: id + '-help', class: 'invoiceninja-help', text: t.codeHelp } ),
					state.codeError ? el( 'span', { id: id + '-error', class: 'invoiceninja-field__error', role: 'alert', text: state.codeError } ) : null ),
				el( 'p', { class: 'invoiceninja-actions' }, primary( t.confirmOrder, confirm ) ),
				el( 'p', { class: 'invoiceninja-links' }, resend, el( 'button', { type: 'button', class: 'invoiceninja-linkbutton', onClick: () => {
					state.step = 'details';
					state.code = '';
					state.codeError = '';
					render();
					focusField( 'email' );
				} }, t.differentEmail ) ),
				hint,
			];
		};

		const rememberedBody = () => [
			el( 'p', { text: fmt( t.remembered, state.details.email ) } ),
			el( 'p', { class: 'invoiceninja-actions' }, primary( t.placeOrder, confirm ), el( 'button', { type: 'button', class: 'invoiceninja-linkbutton', onClick: async () => {
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
				focusField( 'email' );
			} }, t.useAnother ) ),
		];

		const paymentBody = () => {
			const order = state.order;
			const line = el( 'p', { class: 'invoiceninja-muted', text: order.invoice_number ? fmt( t.orderLine, order.number, order.invoice_number ) : fmt( t.orderLineLater, order.number ) } );
			const view = order.status_url ? el( 'p', null, el( 'a', { href: order.status_url, text: t.viewOrder } ) ) : null;
			if ( order.problem === 'currency' ) {
				// The buyer's account with us uses another currency: an admin sorts it out.
				return [ el( 'p', { class: 'invoiceninja-lead', text: t.cantTake } ), line,
					el( 'p', null, t.cantTakeText, cfg.contact ? ' ' : null, cfg.contact ? el( 'a', { href: cfg.contact, text: t.contactUs } ) : null ) ];
			}
			if ( order.status === 'awaiting_payment' && order.pay_link ) {
				return [ el( 'p', { class: 'invoiceninja-lead', text: t.thanksPay } ), line,
					el( 'p', { class: 'invoiceninja-actions' }, payButton( order.pay_link, payDue( order ) ) ),
					el( 'p', { class: 'invoiceninja-muted', text: t.paySecure + ' ' + t.payLater } ), view ];
			}
			const next = order.status === 'queued' || order.status === 'placing' ? t.linkSoon : t.inTouch;
			return [ el( 'p', { class: 'invoiceninja-lead', text: t.thanksReceived } ), line, el( 'p', { text: next } ), view ];
		};

		const bodies = {
			details: detailsBody,
			delivery: deliveryBody,
			confirm: () => ( { code: codeBody, remembered: rememberedBody, placing: () => el( 'p', { role: 'status', class: 'invoiceninja-loading', text: t.placing } ) }[ state.step ] )(),
			payment: paymentBody,
		};

		// The order summary: beside the steps on wide screens, folded at the top on phones.
		const summaryAside = ( data ) => {
			const order = state.step === 'done' ? state.order : null;
			const lines = order ? orderLines( order ) : ( data.lines || [] );
			const rows = order ? ( order.invoice ? invoiceTotals( order.invoice ) : orderTotals( order ) ) : totals( data );
			let amount = null;
			if ( order ) {
				amount = order.invoice ? order.invoice.total : ( order.tax_later ? null : order.total );
			} else if ( ! data.tax_later ) {
				amount = data.total;
			}
			const box = el( 'details', { class: 'invoiceninja-summary', open: wide() },
				el( 'summary', { class: 'invoiceninja-summary__toggle', onClick: ( e ) => {
					if ( wide() ) {
						e.preventDefault(); // Always open beside the steps.
					}
				} },
					el( 'span', { class: 'invoiceninja-summary__title' }, el( 'span', { class: 'invoiceninja-summary__wide', text: t.orderSummary } ), el( 'span', { class: 'invoiceninja-summary__narrow', text: t.showSummary } ) ),
					null !== amount ? el( 'strong', { class: 'invoiceninja-summary__amount', text: money( amount ) } ) : null ),
				el( 'div', { class: 'invoiceninja-summary__body' },
					el( 'ul', { class: 'invoiceninja-slines' }, lines.map( summaryLine ) ),
					el( 'div', { class: 'invoiceninja-totals' }, rows ),
					order ? null : el( 'p', { class: 'invoiceninja-summary__edit' }, el( 'button', { type: 'button', class: 'invoiceninja-linkbutton', onClick: () => {
						state.step = 'cart';
						render();
					} }, t.editCart ) ) ) );
			return el( 'aside', { class: 'invoiceninja-checkout__summary', 'aria-label': t.orderSummary }, box );
		};

		const stepsView = ( data ) => {
			const order = stepIds();
			return [ el( 'div', { class: 'invoiceninja-checkout__layout' },
				summaryAside( data ),
				el( 'div', { class: 'invoiceninja-steps' },
					el( 'p', { class: 'invoiceninja-steps__count invoiceninja-muted', 'aria-hidden': 'true', text: fmt( t.stepOf, order.indexOf( stepOf[ state.step ] ) + 1, order.length ) } ),
					order.map( ( id ) => stepSection( id, bodies[ id ] ) ) ) ) ];
		};

		const views = {
			cart: cartView,
			login() {
				return [ pageHeading( t.checkout ), notice( 'info', t.loginRequired ), el( 'p', null, linkButton( t.logIn, state.loginUrl || cfg.loginUrl ) ) ];
			},
			unavailable() {
				return [ pageHeading( t.checkout ), notice( 'info', t.unavailable, t.unavailableText ) ];
			},
			loading() {
				return [ el( 'p', { class: 'invoiceninja-loading', role: 'status', text: t.loadingCart } ) ];
			},
			failed() {
				return [ pageHeading( t.checkout ), notice( 'error', null, state.failed ), el( 'p', { class: 'invoiceninja-actions' }, button( t.tryAgain, () => {
					state.step = 'loading';
					render();
					boot();
				} ) ) ];
			},
		};

		const render = ( busy ) => {
			if ( busy ) {
				root.setAttribute( 'aria-busy', 'true' );
				root.querySelectorAll( 'button[type="submit"]' ).forEach( ( b ) => {
					b.disabled = true;
				} );
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
			const level = title.own( state.step === 'cart' );
			if ( state.message ) {
				root.appendChild( notice( state.message.type, null, state.message.text ) );
			}
			const view = stepOf[ state.step ] ? stepsView( data ) : ( views[ state.step ] ? views[ state.step ]( data, level ) : [] );
			view.forEach( ( node ) => node && root.appendChild( node ) );
			const heading = root.querySelector( '.invoiceninja-heading' );
			if ( state.focusErrors ) {
				state.focusErrors = false;
				const invalid = root.querySelector( '[aria-invalid="true"]' );
				if ( invalid ) {
					invalid.focus();
				}
			} else if ( heading && render.lastStep && render.lastStep !== state.step ) {
				heading.focus();
			}
			render.lastStep = state.step;
		};

		// The page may be cached, so whether ordering is available (and the account's email) comes
		// from the server with the cart, never from the page.
		const boot = async () => {
			const colors = pageColors( root.parentElement || document.body );
			if ( colors.background ) {
				root.style.setProperty( '--invoiceninja-bg', colors.background );
			}
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
				box.appendChild( el( 'p', { text: order.items.map( ( i ) => i.quantity + ' × ' + i.title ).join( ', ' ) + ' · ' + money( amountDue( order ) ) } ) );
				box.appendChild( el( 'p', { class: 'invoiceninja-actions' }, payButton( order.pay_link, payDue( order ) ), button( t.changeOrder, () => paint( true ), { class: 'invoiceninja-secondary' } ) ) );
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

	// ---------- Order Status ----------

	function statusHeadline( order ) {
		if ( order.problem === 'currency' ) {
			return t.cantTake;
		}
		if ( order.status === 'paid' ) {
			return order.first_name ? fmt( t.headPaidName, order.first_name ) : t.headPaid;
		}
		if ( order.status === 'awaiting_payment' && order.pay_link ) {
			return t.headWaiting;
		}
		if ( order.status === 'cancelled' ) {
			return t.headCancelled;
		}
		return t.thanksReceived;
	}

	function statusText( order ) {
		if ( order.problem === 'currency' ) {
			return el( 'p', null, t.cantTakeText, cfg.contact ? ' ' : null, cfg.contact ? el( 'a', { href: cfg.contact, text: t.contactUs } ) : null );
		}
		const text = {
			paid: t.receiptOnWay,
			cancelled: t.cancelledText,
			queued: t.linkSoon,
			placing: t.linkSoon,
			attention: t.inTouch,
			awaiting_payment: order.pay_link ? '' : t.inTouch,
		}[ order.status ];
		return text ? el( 'p', { class: 'invoiceninja-status__text', text } ) : null;
	}

	// The real stages only: placed, then paid, each with its date.
	function progress( order ) {
		if ( order.problem || order.status === 'cancelled' ) {
			return null;
		}
		const paid = order.status === 'paid';
		const stage = ( label, date, done ) => el( 'li', { class: 'invoiceninja-progress__stage' + ( done ? ' is-done' : '' ) },
			el( 'span', { class: 'invoiceninja-progress__label', text: label } ),
			el( 'span', { class: 'invoiceninja-progress__date invoiceninja-muted', text: date } ) );
		return el( 'ol', { class: 'invoiceninja-progress', 'aria-label': t.progress },
			stage( t.stagePlaced, order.placed_on || '', true ),
			stage( t.statusPaid, paid ? order.paid_on || '' : t.stageNotYet, paid ) );
	}

	function downloadButton( order, key ) {
		const label = order.invoice_number ? fmt( t.downloadNumber, order.invoice_number ) : t.downloadInvoice;
		const btn = el( 'button', { type: 'button', class: 'wp-element-button invoiceninja-secondary', 'aria-label': label }, t.downloadInvoice );
		btn.addEventListener( 'click', async () => {
			const old = btn.parentNode.querySelector( '.invoiceninja-field__error' );
			if ( old ) {
				old.remove();
			}
			btn.disabled = true;
			btn.setAttribute( 'aria-busy', 'true' );
			let problem = t.error;
			try {
				// The key goes in a header, never the address.
				const response = await fetch( cfg.rest + '/orders/' + encodeURIComponent( order.number ) + '/pdf', { credentials: 'same-origin', headers: { 'X-Order-Key': key } } );
				if ( response.ok && ( response.headers.get( 'content-type' ) || '' ).indexOf( 'pdf' ) >= 0 ) {
					const url = URL.createObjectURL( await response.blob() );
					const name = ( ( response.headers.get( 'content-disposition' ) || '' ).match( /filename="([^"]+)"/ ) || [] )[ 1 ] || 'invoice.pdf';
					const link = el( 'a', { href: url, download: name, hidden: true } );
					document.body.appendChild( link );
					link.click();
					link.remove();
					window.setTimeout( () => URL.revokeObjectURL( url ), 30000 );
					problem = '';
				} else {
					try {
						problem = ( await response.json() ).message || t.error;
					} catch ( e ) {
						problem = t.error;
					}
				}
			} catch ( e ) {
				problem = t.offline;
			}
			btn.disabled = false;
			btn.removeAttribute( 'aria-busy' );
			btn.focus();
			if ( problem ) {
				btn.parentNode.appendChild( el( 'span', { class: 'invoiceninja-field__error', role: 'alert', text: problem } ) );
			}
		} );
		return btn;
	}

	function orderView( order, key, level, extra ) {
		const nodes = [];
		const meta = [ order.invoice_number ? fmt( t.orderLine, order.number, order.invoice_number ) : fmt( t.orderLineLater, order.number ), order.placed_on ? fmt( t.placedOn, order.placed_on ) : '' ];
		nodes.push( el( 'p', { class: 'invoiceninja-status__meta invoiceninja-muted', text: meta.filter( Boolean ).join( ' · ' ) } ) );
		nodes.push( el( 'h' + level, { class: 'invoiceninja-heading invoiceninja-status__headline', tabindex: '-1', text: statusHeadline( order ) } ) );
		nodes.push( statusText( order ) );
		if ( order.problem === 'currency' ) {
			return nodes;
		}
		nodes.push( progress( order ) );
		if ( ( order.status === 'awaiting_payment' && order.pay_link ) || extra ) {
			nodes.push( el( 'p', { class: 'invoiceninja-actions' }, order.status === 'awaiting_payment' && order.pay_link ? payButton( order.pay_link, payDue( order ) ) : null, extra || null ) );
		}
		const facts = [];
		if ( order.address && order.address.length ) {
			facts.push( el( 'div', { class: 'invoiceninja-fact' }, el( 'h' + ( level + 1 ), { class: 'invoiceninja-fact__label', text: t.deliveryAddress } ),
				el( 'p', null, order.address.reduce( ( parts, line, i ) => parts.concat( i ? [ el( 'br' ), line ] : [ line ] ), [] ) ) ) );
		}
		if ( order.email ) {
			facts.push( el( 'div', { class: 'invoiceninja-fact' }, el( 'h' + ( level + 1 ), { class: 'invoiceninja-fact__label', text: t.email } ), el( 'p', { text: order.email } ) ) );
		}
		if ( facts.length ) {
			nodes.push( el( 'div', { class: 'invoiceninja-facts' }, facts ) );
		}
		nodes.push( el( 'div', { class: 'invoiceninja-status__lines' },
			el( 'ul', { class: 'invoiceninja-slines' }, orderLines( order ).map( summaryLine ) ),
			el( 'div', { class: 'invoiceninja-totals' }, order.invoice ? invoiceTotals( order.invoice ) : orderTotals( order ) ) ) );
		const help = cfg.contact ? cfg.contact + ( cfg.contact.indexOf( '?' ) < 0 ? '?' : '&' ) + 'subject=' + encodeURIComponent( fmt( t.helpSubject, order.number ) ) : '';
		if ( ( order.pdf && key ) || help ) {
			nodes.push( el( 'p', { class: 'invoiceninja-actions invoiceninja-status__help' },
				order.pdf && key ? downloadButton( order, key ) : null,
				help ? el( 'a', { href: help, text: t.needHelp } ) : null ) );
		}
		return nodes;
	}

	function initStatus( root ) {
		const title = titleControl( root );
		const params = new URLSearchParams( window.location.search );
		// invoiceninja_order: a link made while the site had no Order Status page.
		const number = params.get( 'order' ) || params.get( 'invoiceninja_order' ) || '';
		const key = ( window.location.hash.match( /key=([0-9a-f]{32})/ ) || [] )[ 1 ] || '';
		const paint = ( nodes ) => {
			root.textContent = '';
			nodes.forEach( ( n ) => n && root.appendChild( n ) );
		};
		const find = () => {
			title.own( false );
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
				el( 'p', { class: 'invoiceninja-field' }, el( 'label', { for: 'invoiceninja-find-number', text: t.orderNumber } ), el( 'input', { id: 'invoiceninja-find-number', name: 'number', value: number, required: true, autocapitalize: 'characters', spellcheck: 'false', 'aria-describedby': 'invoiceninja-find-hint' } ), el( 'span', { id: 'invoiceninja-find-hint', class: 'invoiceninja-help', text: t.orderNumberHint } ) ),
				el( 'p', { class: 'invoiceninja-field' }, el( 'label', { for: 'invoiceninja-find-email', text: t.email } ), el( 'input', { id: 'invoiceninja-find-email', name: 'email', type: 'email', autocomplete: 'email', required: true } ) ),
				el( 'p', { class: 'invoiceninja-actions' }, el( 'button', { type: 'submit', class: 'wp-element-button invoiceninja-primary' }, t.emailLink ) ) );
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
			const again = order.status === 'awaiting_payment' ? button( t.checkAgain, async ( e ) => {
				e.target.disabled = true;
				root.setAttribute( 'aria-busy', 'true' );
				announce( t.checking );
				await load();
				root.removeAttribute( 'aria-busy' );
				const after = root.querySelector( '.invoiceninja-status__headline' );
				announce( after ? after.textContent : '' );
			}, { class: 'wp-element-button invoiceninja-secondary' } ) : null;
			paint( orderView( order, key, title.own( true ), again ) );
		};
		const colors = pageColors( root.parentElement || document.body );
		if ( colors.background ) {
			root.style.setProperty( '--invoiceninja-bg', colors.background );
		}
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
			last.status === 'awaiting_payment' && last.pay_link ? payButton( last.pay_link, payDue( last ) ) : null,
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
