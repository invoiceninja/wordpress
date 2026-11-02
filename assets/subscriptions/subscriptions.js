/**
 * Invoice Ninja subscriptions: the Pricing Table's billing-period toggle and the return page.
 *
 * Plain JavaScript, no build step. Text is set with textContent, never innerHTML.
 */
( function () {
	'use strict';

	// ---------- Pricing Table toggle ----------

	function setPeriod( table, period ) {
		table.querySelectorAll( '.invoiceninja-pricing__period' ).forEach( ( b ) => {
			b.setAttribute( 'aria-pressed', b.dataset.period === period ? 'true' : 'false' );
		} );
		table.querySelectorAll( '.invoiceninja-plan' ).forEach( ( plan ) => {
			const bodies = plan.querySelectorAll( '.invoiceninja-plan__body' );
			// A plan without a pair keeps showing its only price.
			const hasAlt = plan.querySelector( '.invoiceninja-plan__body[data-period="alt"]' );
			bodies.forEach( ( body ) => {
				body.hidden = hasAlt ? body.dataset.period !== period : false;
			} );
		} );
	}

	document.querySelectorAll( '[data-invoiceninja-pricing]' ).forEach( ( table ) => {
		const toggle = table.querySelector( '[data-invoiceninja-toggle]' );
		if ( ! toggle ) {
			return;
		}
		toggle.hidden = false;
		toggle.addEventListener( 'click', ( e ) => {
			const button = e.target.closest( '.invoiceninja-pricing__period' );
			if ( button ) {
				setPeriod( table, button.dataset.period );
			}
		} );
	} );

	// ---------- Return page ----------

	const page = document.querySelector( '[data-invoiceninja-return]' );
	const cfg = window.invoiceNinjaSubscriptions;
	if ( ! page || ! cfg || ! window.fetch ) {
		return;
	}
	const title = page.querySelector( '[data-title]' );
	const text = page.querySelector( '[data-text]' );

	function show( state ) {
		const copy = cfg.copy[ state ];
		if ( ! copy || page.dataset.invoiceninjaReturn === state ) {
			return;
		}
		page.dataset.invoiceninjaReturn = state;
		title.textContent = copy[ 0 ];
		text.textContent = copy[ 1 ]; // The text is a polite live region: announced once per change.
	}

	if ( page.dataset.invoiceninjaReturn !== 'pending' ) {
		return;
	}
	const started = Date.now();
	function poll() {
		if ( Date.now() - started > 32000 ) {
			show( 'slow' );
			return;
		}
		window.fetch( cfg.rest, { credentials: 'same-origin', headers: { Accept: 'application/json' } } )
			.then( ( r ) => r.json() )
			.then( ( data ) => {
				const state = data && data.state;
				if ( state && state !== 'pending' ) {
					show( state );
					return;
				}
				window.setTimeout( poll, 3000 );
			} )
			.catch( () => window.setTimeout( poll, 5000 ) );
	}
	window.setTimeout( poll, 2000 );
}() );
