/**
 * Invoice Ninja catalog: the shop's category chips and sort menu, the product page's
 * quantity stepper and button total, and the photo thumbnails.
 *
 * Plain JavaScript, no build step. Everything here also works without it: the filters are a
 * GET form, the quantity is a number field, and the first photo is always shown.
 */
( function () {
	'use strict';

	const cfg = window.invoiceNinjaStore || {};

	// The same format as store.js (and the server's Statics::money()).
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

	// ---------- Shop filters ----------

	// Submit as a clean URL: the last value of each field wins (a chip after the kept
	// category), empty values are dropped, and paging starts again.
	function go( form, submitter ) {
		const data = new FormData( form );
		if ( submitter && submitter.name ) {
			data.append( submitter.name, submitter.value );
		}
		const values = {};
		data.forEach( ( value, key ) => {
			values[ key ] = String( value );
		} );
		const url = new URL( form.getAttribute( 'action' ) || window.location.href, window.location.href );
		url.search = '';
		Object.keys( values ).forEach( ( key ) => {
			if ( values[ key ] !== '' ) {
				url.searchParams.set( key, values[ key ] );
			}
		} );
		window.location.assign( url.toString() );
	}

	function initShops() {
		document.querySelectorAll( '[data-invoiceninja-shop]' ).forEach( ( form ) => {
			const apply = form.querySelector( '[data-invoiceninja-apply]' );
			if ( apply ) {
				apply.hidden = true;
			}
			form.addEventListener( 'submit', ( event ) => {
				event.preventDefault();
				go( form, event.submitter || null );
			} );
			const select = form.querySelector( 'select[name="sort"]' );
			if ( select ) {
				select.addEventListener( 'change', () => go( form, null ) );
			}
		} );
	}

	// ---------- Quantity stepper and total ----------

	function initSteppers() {
		document.querySelectorAll( 'form[data-invoiceninja-add]' ).forEach( ( form ) => {
			const field = form.querySelector( '[data-invoiceninja-qty]' );
			if ( ! field ) {
				return;
			}
			const total = form.querySelector( '[data-invoiceninja-total]' );
			const steps = form.querySelectorAll( '[data-invoiceninja-step]' );
			const max = parseInt( field.getAttribute( 'max' ), 10 ) || 99;
			const value = () => Math.max( 1, Math.min( max, parseInt( field.value, 10 ) || 1 ) );
			const paint = () => {
				const qty = value();
				steps.forEach( ( step ) => {
					const by = parseInt( step.getAttribute( 'data-invoiceninja-step' ), 10 );
					const off = by < 0 ? qty <= 1 : qty >= max;
					if ( off && document.activeElement === step ) {
						field.focus(); // Don't drop keyboard focus on a button that just turned off.
					}
					step.disabled = off;
				} );
				if ( total ) {
					const price = parseFloat( total.getAttribute( 'data-invoiceninja-total' ) ) || 0;
					// Like the cart: the line is rounded to cents.
					total.textContent = money( Math.round( price * qty * 100 ) / 100 );
				}
			};
			steps.forEach( ( step ) => {
				step.hidden = false;
				step.addEventListener( 'click', () => {
					field.value = String( Math.max( 1, Math.min( max, value() + parseInt( step.getAttribute( 'data-invoiceninja-step' ), 10 ) ) ) );
					paint();
				} );
			} );
			field.addEventListener( 'input', paint );
			field.addEventListener( 'change', () => {
				field.value = String( value() );
				paint();
			} );
			paint();
		} );
	}

	// ---------- Photos ----------

	function initGalleries() {
		document.querySelectorAll( '[data-invoiceninja-gallery]' ).forEach( ( gallery ) => {
			const thumbs = Array.prototype.slice.call( gallery.querySelectorAll( '.invoiceninja-gallery__thumb' ) );
			if ( thumbs.length < 2 ) {
				return;
			}
			const main = document.getElementById( thumbs[ 0 ].getAttribute( 'aria-controls' ) );
			if ( ! main ) {
				return;
			}
			const show = ( thumb ) => {
				thumbs.forEach( ( other ) => other.setAttribute( 'aria-pressed', other === thumb ? 'true' : 'false' ) );
				const srcset = thumb.getAttribute( 'data-srcset' );
				if ( srcset ) {
					main.setAttribute( 'srcset', srcset );
				} else {
					main.removeAttribute( 'srcset' );
				}
				main.src = thumb.getAttribute( 'data-src' );
				main.alt = thumb.getAttribute( 'data-alt' ) || '';
			};
			thumbs.forEach( ( thumb, index ) => {
				thumb.addEventListener( 'click', () => show( thumb ) );
				// Arrow keys move between the photos (and show them); Home and End jump to the ends.
				thumb.addEventListener( 'keydown', ( event ) => {
					const rtl = getComputedStyle( gallery ).direction === 'rtl';
					const keys = { ArrowRight: rtl ? -1 : 1, ArrowDown: 1, ArrowLeft: rtl ? 1 : -1, ArrowUp: -1 };
					let next = null;
					if ( keys[ event.key ] ) {
						next = ( index + keys[ event.key ] + thumbs.length ) % thumbs.length;
					} else if ( event.key === 'Home' ) {
						next = 0;
					} else if ( event.key === 'End' ) {
						next = thumbs.length - 1;
					}
					if ( next !== null ) {
						event.preventDefault();
						thumbs[ next ].focus();
						show( thumbs[ next ] );
					}
				} );
			} );
		} );
	}

	function init() {
		initShops();
		initSteppers();
		initGalleries();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
