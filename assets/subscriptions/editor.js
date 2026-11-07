/**
 * Block settings for the Pricing Table and Subscribe Button (plain JavaScript, no build).
 * The shared block editor script (assets/blocks/editor.js) calls these.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.element || ! wp.components ) {
		return;
	}
	const { useEffect, useState } = wp.element;
	const { __, sprintf } = wp.i18n;
	const { PanelBody, CheckboxControl, SelectControl, TextControl, ToggleControl, Spinner } = wp.components;
	const cfg = window.invoiceNinjaSubscriptionsEditor || {};

	let cache = null;
	function useSubscriptions() {
		const [ list, setList ] = useState( cache );
		useEffect( () => {
			if ( cache || ! cfg.canList ) {
				return;
			}
			wp.apiFetch( { path: '/invoiceninja/v1/admin/subscriptions' } ).then( ( r ) => {
				// Archived ones and those hidden from this site (Settings → Subscriptions) aren't offered.
				cache = ( r.items || [] ).filter( ( s ) => ! s.archived && ! s.hidden );
				setList( cache );
			} ).catch( () => setList( [] ) );
		}, [] );
		return cfg.canList ? list : [];
	}

	const split = ( v ) => String( v || '' ).split( ',' ).map( ( s ) => s.trim() ).filter( Boolean );
	const pairs = ( v ) => {
		const out = {};
		split( v ).forEach( ( p ) => {
			const [ a, b ] = p.split( ':' );
			if ( a && b ) {
				out[ a ] = b;
			}
		} );
		return out;
	};
	const joinPairs = ( map ) => Object.keys( map ).filter( ( k ) => map[ k ] ).map( ( k ) => k + ':' + map[ k ] ).join( ',' );
	const label = ( s ) => s.name + ' · ' + s.price + ( s.per ? ' ' + s.per : '' );

	function PricingControls( a, set, h ) {
		const list = useSubscriptions();
		const ids = split( a.ids );
		const yearly = pairs( a.yearly );
		if ( list === null ) {
			return h( PanelBody, { title: __( 'Plans', 'invoiceninja' ) }, h( Spinner ) );
		}
		if ( ! list.length ) {
			return h( PanelBody, { title: __( 'Plans', 'invoiceninja' ) },
				h( TextControl, {
					label: __( 'Subscription ids, separated by commas', 'invoiceninja' ),
					help: __( 'No subscriptions to choose from yet: sync them in Settings → Subscriptions, or type their Invoice Ninja ids.', 'invoiceninja' ),
					value: a.ids,
					onChange: ( v ) => set( { ids: v } ),
					__nextHasNoMarginBottom: true,
				} ) );
		}
		const toggle = ( id, on ) => set( { ids: ( on ? ids.concat( [ id ] ) : ids.filter( ( x ) => x !== id ) ).join( ',' ) } );
		const others = ( id ) => [ { value: '', label: __( 'None', 'invoiceninja' ) } ].concat( list.filter( ( s ) => s.id !== id ).map( ( s ) => ( { value: s.id, label: label( s ) } ) ) );
		return [
			h( PanelBody, { key: 'plans', title: __( 'Plans', 'invoiceninja' ) },
				list.map( ( s ) => h( CheckboxControl, { key: s.id, label: label( s ), checked: ids.includes( s.id ), onChange: ( on ) => toggle( s.id, on ), __nextHasNoMarginBottom: true } ) ) ),
			ids.length ? h( PanelBody, { key: 'yearly', title: __( 'Billing toggle', 'invoiceninja' ), initialOpen: false },
				h( 'p', null, __( 'Pair a plan with its yearly (or other period) version to show a Monthly / Yearly toggle above the table.', 'invoiceninja' ) ),
				ids.map( ( id ) => {
					const s = list.find( ( x ) => x.id === id );
					return h( SelectControl, {
						key: id,
						/* translators: %s: plan name */
						label: sprintf( __( 'Other period for %s', 'invoiceninja' ), s ? s.name : id ),
						value: yearly[ id ] || '',
						options: others( id ),
						onChange: ( v ) => set( { yearly: joinPairs( Object.assign( {}, yearly, { [ id ]: v } ) ) } ),
						__nextHasNoMarginBottom: true,
					} );
				} ) ) : null,
			h( PanelBody, { key: 'look', title: __( 'Settings', 'invoiceninja' ), initialOpen: false },
				h( SelectControl, {
					label: __( 'Highlight', 'invoiceninja' ),
					value: a.highlight,
					options: [ { value: '', label: __( 'None', 'invoiceninja' ) } ].concat( ids.map( ( id ) => ( { value: id, label: ( list.find( ( x ) => x.id === id ) || { name: id } ).name } ) ) ),
					onChange: ( v ) => set( { highlight: v } ),
					__nextHasNoMarginBottom: true,
				} ),
				h( TextControl, { label: __( 'Button text', 'invoiceninja' ), value: a.buttonLabel, placeholder: __( 'Subscribe', 'invoiceninja' ), onChange: ( v ) => set( { buttonLabel: v } ), __nextHasNoMarginBottom: true } ),
				h( ToggleControl, { label: __( 'Show free trials', 'invoiceninja' ), checked: !! a.showTrial, onChange: ( v ) => set( { showTrial: v } ), __nextHasNoMarginBottom: true } ) ),
		];
	}

	function ButtonControls( a, set, h ) {
		const list = useSubscriptions();
		return h( PanelBody, { title: __( 'Settings', 'invoiceninja' ) },
			list === null ? h( Spinner ) : list.length
				? h( SelectControl, {
					label: __( 'Subscription', 'invoiceninja' ),
					value: a.subscriptionId,
					options: [ { value: '', label: __( 'Choose…', 'invoiceninja' ) } ].concat( list.map( ( s ) => ( { value: s.id, label: label( s ) } ) ) ),
					onChange: ( v ) => set( { subscriptionId: v } ),
					__nextHasNoMarginBottom: true,
				} )
				: h( TextControl, { label: __( 'Subscription id', 'invoiceninja' ), value: a.subscriptionId, onChange: ( v ) => set( { subscriptionId: v } ), __nextHasNoMarginBottom: true } ),
			h( TextControl, { label: __( 'Button text', 'invoiceninja' ), value: a.label, placeholder: __( 'Subscribe', 'invoiceninja' ), onChange: ( v ) => set( { label: v } ), __nextHasNoMarginBottom: true } ),
			h( ToggleControl, { label: __( 'Show the price', 'invoiceninja' ), checked: !! a.showPrice, onChange: ( v ) => set( { showPrice: v } ), __nextHasNoMarginBottom: true } ),
			h( 'p', { className: 'description' }, __( 'Customers with a confirmed email arrive on the purchase page already signed in; others confirm their email there.', 'invoiceninja' ) ) );
	}

	wp.invoiceNinjaBlockControls = wp.invoiceNinjaBlockControls || {};
	wp.invoiceNinjaBlockControls[ 'invoiceninja/pricing-table' ] = PricingControls;
	wp.invoiceNinjaBlockControls[ 'invoiceninja/subscribe-button' ] = ButtonControls;
}( window.wp ) );
