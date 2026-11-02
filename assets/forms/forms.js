/**
 * Invoice Ninja Quote Request Form: sends the server-rendered form to REST and shows
 * errors (a summary plus a message on each field) and the confirmation in place.
 *
 * Plain JavaScript, no build step. Text is set with textContent, never innerHTML.
 */
( function () {
	'use strict';

	const store = window.invoiceNinjaStore;
	const cfg = window.invoiceNinjaQuoteForm || { i18n: {} };
	if ( ! store || ! window.fetch ) {
		return;
	}
	const t = cfg.i18n || {};

	function el( tag, attrs, text ) {
		const node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( ( key ) => node.setAttribute( key, attrs[ key ] ) );
		if ( text ) {
			node.textContent = text;
		}
		return node;
	}

	function clear( node ) {
		while ( node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	function values( form ) {
		const data = {};
		Array.prototype.forEach.call( form.elements, ( input ) => {
			if ( ! input.name ) {
				return;
			}
			data[ input.name ] = input.type === 'checkbox' ? input.checked : input.value;
		} );
		return data;
	}

	function init( root ) {
		const form = root.querySelector( 'form' );
		const summary = root.querySelector( '.invoiceninja-quote-form__summary' );
		const done = root.querySelector( '.invoiceninja-quote-form__done' );
		const submit = form ? form.querySelector( '[type="submit"]' ) : null;
		if ( ! form || ! summary || ! done || ! submit ) {
			return;
		}
		const started = Date.now();
		const label = submit.textContent;

		const fieldOf = ( name ) => form.querySelector( '[data-field="' + name + '"]' );
		const inputOf = ( name ) => form.querySelector( '[name="' + name + '"]' );

		function resetErrors() {
			summary.hidden = true;
			summary.removeAttribute( 'role' );
			clear( summary );
			form.querySelectorAll( '[data-field]' ).forEach( ( field ) => {
				field.classList.remove( 'is-invalid' );
				const error = field.querySelector( '.invoiceninja-field__error' );
				if ( error ) {
					error.hidden = true;
					error.textContent = '';
				}
				const input = inputOf( field.getAttribute( 'data-field' ) );
				if ( input ) {
					input.removeAttribute( 'aria-invalid' );
				}
			} );
		}

		function showSummary( title, items ) {
			clear( summary );
			summary.appendChild( el( 'h3', { class: 'invoiceninja-quote-form__summary-title' }, title ) );
			if ( items && items.length ) {
				const list = el( 'ul' );
				items.forEach( ( item ) => {
					const li = el( 'li' );
					if ( item.id ) {
						const link = el( 'a', { href: '#' + item.id }, item.text );
						link.addEventListener( 'click', ( e ) => {
							e.preventDefault();
							const target = document.getElementById( item.id );
							if ( target ) {
								target.focus();
							}
						} );
						li.appendChild( link );
					} else {
						li.textContent = item.text;
					}
					list.appendChild( li );
				} );
				summary.appendChild( list );
			}
			summary.className = 'invoiceninja-quote-form__summary invoiceninja-notice invoiceninja-notice--error';
			summary.setAttribute( 'role', 'alert' );
			summary.hidden = false;
			summary.focus();
		}

		function showErrors( errors, message ) {
			const items = [];
			Object.keys( errors || {} ).forEach( ( name ) => {
				const field = fieldOf( name );
				const input = inputOf( name );
				if ( ! field || ! input ) {
					items.push( { text: errors[ name ] } );
					return;
				}
				field.classList.add( 'is-invalid' );
				input.setAttribute( 'aria-invalid', 'true' );
				const error = field.querySelector( '.invoiceninja-field__error' );
				if ( error ) {
					error.textContent = errors[ name ];
					error.hidden = false;
				}
				items.push( { id: input.id, text: errors[ name ] } );
			} );
			const title = items.length > 1 ? String( t.problems || '' ).replace( '%d', String( items.length ) ) : ( t.problem || message );
			showSummary( title, items.length ? items : [ { text: message } ] );
		}

		function busy( on ) {
			submit.disabled = on;
			submit.setAttribute( 'aria-busy', on ? 'true' : 'false' );
			submit.textContent = on ? ( t.sending || label ) : label;
		}

		function success( result ) {
			clear( done );
			done.appendChild( el( 'h3', { class: 'invoiceninja-quote-form__done-title' }, result.title ) );
			if ( result.message ) {
				done.appendChild( el( 'p', {}, result.message ) );
			}
			done.className = 'invoiceninja-quote-form__done invoiceninja-notice invoiceninja-notice--success';
			done.setAttribute( 'role', 'status' );
			form.hidden = true;
			done.hidden = false;
			done.focus();
			if ( result.redirect ) {
				done.appendChild( el( 'p', { class: 'invoiceninja-muted' }, t.redirecting ) );
				window.setTimeout( () => window.location.assign( result.redirect ), 1200 );
			}
		}

		form.addEventListener( 'submit', async ( e ) => {
			e.preventDefault();
			resetErrors();
			const data = values( form );
			data.elapsed = Math.floor( ( Date.now() - started ) / 1000 );
			busy( true );
			let response;
			let result = {};
			try {
				response = await fetch( store.rest + '/quote-requests', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( data ),
				} );
				try {
					result = await response.json();
				} catch ( err ) {
					result = {};
				}
			} catch ( err ) {
				busy( false );
				showSummary( t.offline );
				return;
			}
			busy( false );
			if ( response.ok && result.ok ) {
				success( result );
				return;
			}
			if ( result.errors ) {
				showErrors( result.errors, result.message || t.error );
			} else {
				showSummary( result.message || t.error );
			}
		} );
	}

	function boot() {
		document.querySelectorAll( '[data-invoiceninja-quote-form]' ).forEach( init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
