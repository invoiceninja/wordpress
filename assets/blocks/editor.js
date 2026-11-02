/**
 * Invoice Ninja blocks in the editor: a server-rendered preview and settings for each.
 *
 * Plain JavaScript on WordPress globals (no build step). Each block's name, title,
 * attributes and variations come from its block.json on the server; this file only adds
 * the edit view. Blocks are dynamic, so save() is null.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}

	const { createElement: h, Fragment, useEffect, useState } = wp.element;
	const { __ } = wp.i18n;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, ToggleControl, RangeControl, SelectControl, TextControl, Spinner, Placeholder } = wp.components;
	const ServerSideRender = wp.serverSideRender;

	let productsCache = null;
	function useProducts() {
		const [ list, setList ] = useState( productsCache );
		useEffect( () => {
			if ( productsCache ) {
				return;
			}
			wp.apiFetch( { path: '/invoiceninja/v1/admin/products' } ).then( ( rows ) => {
				productsCache = rows;
				setList( rows );
			} ).catch( () => setList( [] ) );
		}, [] );
		return list;
	}

	function ProductPicker( { value, onChange } ) {
		const list = useProducts();
		if ( ! list ) {
			return h( Spinner );
		}
		return h( SelectControl, {
			label: __( 'Product', 'invoiceninja' ),
			value,
			options: [ { value: '', label: __( 'This page’s product, or choose…', 'invoiceninja' ) } ].concat( list.map( ( p ) => ( { value: p.id, label: p.title } ) ) ),
			onChange,
			__nextHasNoMarginBottom: true,
		} );
	}

	const controls = {
		'invoiceninja/products': ( a, set ) => [
			h( PanelBody, { key: 'layout', title: __( 'Settings', 'invoiceninja' ) },
				h( RangeControl, { label: __( 'Columns', 'invoiceninja' ), min: 1, max: 6, value: a.columns, onChange: ( v ) => set( { columns: v } ) } ),
				h( RangeControl, { label: __( 'Products per page', 'invoiceninja' ), min: 1, max: 100, value: a.perPage, onChange: ( v ) => set( { perPage: v } ) } ),
				h( SelectControl, { label: __( 'Order by', 'invoiceninja' ), value: a.orderBy, options: [
					{ value: 'title', label: __( 'Name, A to Z', 'invoiceninja' ) },
					{ value: 'price', label: __( 'Price, low to high', 'invoiceninja' ) },
					{ value: 'date', label: __( 'Newest', 'invoiceninja' ) },
				], onChange: ( v ) => set( { orderBy: v } ), __nextHasNoMarginBottom: true } ),
				h( SelectControl, { label: __( 'Sold-out products', 'invoiceninja' ), value: a.soldOut, options: [
					{ value: 'show', label: __( 'Show with a Sold out badge', 'invoiceninja' ) },
					{ value: 'hide', label: __( 'Hide', 'invoiceninja' ) },
				], onChange: ( v ) => set( { soldOut: v } ), __nextHasNoMarginBottom: true } ) ),
			h( PanelBody, { key: 'show', title: __( 'Show', 'invoiceninja' ) },
				[ [ 'showImage', __( 'Image', 'invoiceninja' ) ], [ 'showPrice', __( 'Price', 'invoiceninja' ) ], [ 'showStock', __( 'Stock left (when low)', 'invoiceninja' ) ], [ 'showExcerpt', __( 'Short description', 'invoiceninja' ) ], [ 'showButton', __( 'Add to Cart button', 'invoiceninja' ) ] ]
					.map( ( [ key, label ] ) => h( ToggleControl, { key, label, checked: !! a[ key ], onChange: ( v ) => set( { [ key ]: v } ), __nextHasNoMarginBottom: true } ) ) ),
		],
		'invoiceninja/product': ( a, set ) => [
			h( PanelBody, { key: 'p', title: __( 'Settings', 'invoiceninja' ) },
				h( ProductPicker, { value: a.productId, onChange: ( v ) => set( { productId: v } ) } ),
				h( SelectControl, { label: __( 'Show', 'invoiceninja' ), value: a.show, options: [
					{ value: 'all', label: __( 'Price, stock and button', 'invoiceninja' ) },
					{ value: 'price', label: __( 'Price only', 'invoiceninja' ) },
					{ value: 'stock', label: __( 'Stock only', 'invoiceninja' ) },
					{ value: 'button', label: __( 'Button only', 'invoiceninja' ) },
				], onChange: ( v ) => set( { show: v } ), __nextHasNoMarginBottom: true } ),
				h( ToggleControl, { label: __( 'Quantity field', 'invoiceninja' ), checked: !! a.quantity, onChange: ( v ) => set( { quantity: v } ), __nextHasNoMarginBottom: true } ) ),
		],
		'invoiceninja/add-to-cart': ( a, set ) => [
			h( PanelBody, { key: 'p', title: __( 'Settings', 'invoiceninja' ) },
				h( ProductPicker, { value: a.productId, onChange: ( v ) => set( { productId: v } ) } ),
				h( TextControl, { label: __( 'Button text', 'invoiceninja' ), value: a.label, placeholder: __( 'Add to cart', 'invoiceninja' ), onChange: ( v ) => set( { label: v } ), __nextHasNoMarginBottom: true } ),
				h( ToggleControl, { label: __( 'Quantity field', 'invoiceninja' ), checked: !! a.quantity, onChange: ( v ) => set( { quantity: v } ), __nextHasNoMarginBottom: true } ) ),
		],
		'invoiceninja/mini-cart': ( a, set ) => [
			h( PanelBody, { key: 'p', title: __( 'Settings', 'invoiceninja' ) },
				h( TextControl, { label: __( 'Label', 'invoiceninja' ), value: a.label, placeholder: __( 'Cart', 'invoiceninja' ), onChange: ( v ) => set( { label: v } ), __nextHasNoMarginBottom: true } ) ),
		],
	};

	// Blocks whose preview depends on the shopper (cart, order): a static placeholder.
	const placeholders = {
		'invoiceninja/checkout': __( 'Shoppers see their cart, then their details, the email code and the payment button here.', 'invoiceninja' ),
		'invoiceninja/order-status': __( 'Shoppers see their order here when they open the link in their email.', 'invoiceninja' ),
	};

	function edit( name ) {
		return function Edit( props ) {
			const blockProps = useBlockProps();
			const extra = controls[ name ] ? controls[ name ]( props.attributes, props.setAttributes ) : ( wp.invoiceNinjaBlockControls && wp.invoiceNinjaBlockControls[ name ] ? wp.invoiceNinjaBlockControls[ name ]( props.attributes, props.setAttributes, h ) : null );
			const type = wp.blocks.getBlockType( name );
			const preview = placeholders[ name ]
				? h( Placeholder, { icon: type && type.icon ? type.icon.src : null, label: type ? type.title : name, instructions: placeholders[ name ] } )
				: h( ServerSideRender, { block: name, attributes: props.attributes } );
			return h( Fragment, null,
				extra ? h( InspectorControls, null, extra ) : null,
				h( 'div', blockProps, preview ) );
		};
	}

	// The blocks the server registered (a module that's off registers none).
	( window.invoiceNinjaBlocks || [] ).forEach( ( name ) => {
		if ( ! wp.blocks.getBlockType( name ) ) {
			wp.blocks.registerBlockType( name, { edit: edit( name ), save: () => null } );
		}
	} );
}( window.wp ) );
