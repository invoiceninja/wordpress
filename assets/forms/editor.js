/**
 * Editor settings for the Quote Request Form block: which fields show and are required,
 * the consent pages and the products people can choose.
 *
 * Plain JavaScript on WordPress globals (no build step). The block itself is registered
 * by assets/blocks/editor.js, which calls the controls set here.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.element || ! wp.components ) {
		return;
	}

	const { createElement: h, useEffect, useState } = wp.element;
	const { __ } = wp.i18n;
	const { PanelBody, ToggleControl, SelectControl, TextControl, CheckboxControl, Spinner, Notice } = wp.components;
	const boot = window.invoiceNinjaFormsEditor || { defaultProducts: [] };

	let productsCache = null;
	function useProducts() {
		const [ list, setList ] = useState( productsCache );
		useEffect( () => {
			if ( productsCache ) {
				return;
			}
			wp.apiFetch( { path: '/invoiceninja/v1/admin/products' } ).then( ( rows ) => {
				productsCache = Array.isArray( rows ) ? rows : [];
				setList( productsCache );
			} ).catch( () => setList( [] ) );
		}, [] );
		return list;
	}

	function usePages() {
		return wp.data.useSelect( ( select ) => select( 'core' ).getEntityRecords( 'postType', 'page', { per_page: 100, status: 'publish', orderby: 'title', order: 'asc', _fields: 'id,title' } ), [] );
	}

	function ProductPicker( { value, onChange } ) {
		const list = useProducts();
		if ( ! list ) {
			return h( Spinner );
		}
		if ( ! list.length ) {
			return h( 'p', { className: 'components-base-control__help' }, __( 'No products yet. They appear here after they sync from Invoice Ninja.', 'invoiceninja' ) );
		}
		const chosen = Array.isArray( value ) ? value : [];
		return h( 'fieldset', { className: 'invoiceninja-product-picker' },
			h( 'legend', null, __( 'Products people can choose', 'invoiceninja' ) ),
			h( 'p', { className: 'components-base-control__help' }, chosen.length
				? __( '“Not sure yet” is always offered too.', 'invoiceninja' )
				: ( boot.defaultProducts && boot.defaultProducts.length
					? __( 'None chosen: the products from Settings → Forms are offered.', 'invoiceninja' )
					: __( 'None chosen: the product question is hidden.', 'invoiceninja' ) ) ),
			list.map( ( p ) => h( CheckboxControl, {
				key: p.id,
				label: p.title,
				checked: chosen.includes( p.id ),
				onChange: ( on ) => onChange( on ? chosen.concat( [ p.id ] ) : chosen.filter( ( id ) => id !== p.id ) ),
				__nextHasNoMarginBottom: true,
			} ) ) );
	}

	function PagePicker( { label, value, onChange } ) {
		const pages = usePages();
		if ( ! pages ) {
			return h( Spinner );
		}
		return h( SelectControl, {
			label,
			value: String( value || 0 ),
			options: [ { value: '0', label: __( 'None (no checkbox)', 'invoiceninja' ) } ].concat( pages.map( ( p ) => ( { value: String( p.id ), label: ( p.title && p.title.rendered ) || ( '#' + p.id ) } ) ) ),
			onChange: ( v ) => onChange( parseInt( v, 10 ) || 0 ),
			__nextHasNoMarginBottom: true,
		} );
	}

	const FIELDS = [
		[ 'FirstName', __( 'First name', 'invoiceninja' ) ],
		[ 'LastName', __( 'Last name', 'invoiceninja' ) ],
		[ 'Phone', __( 'Phone', 'invoiceninja' ) ],
		[ 'Product', __( 'Product', 'invoiceninja' ) ],
		[ 'Quantity', __( 'Quantity', 'invoiceninja' ), __( 'A whole number. With a product chosen it becomes the quote line’s quantity; otherwise it goes in the quote’s private notes.', 'invoiceninja' ) ],
		[ 'NeededBy', __( 'Needed by (date)', 'invoiceninja' ), __( 'Goes in the quote’s private notes. The quote’s own due date is when it expires, so it is left alone.', 'invoiceninja' ) ],
		[ 'Message', __( 'Message', 'invoiceninja' ) ],
	];

	wp.invoiceNinjaBlockControls = wp.invoiceNinjaBlockControls || {};
	wp.invoiceNinjaBlockControls[ 'invoiceninja/quote-form' ] = ( a, set ) => [
		h( PanelBody, { key: 'text', title: __( 'Settings', 'invoiceninja' ) },
			h( TextControl, { label: __( 'Heading', 'invoiceninja' ), value: a.heading, placeholder: __( 'None', 'invoiceninja' ), onChange: ( v ) => set( { heading: v } ), __nextHasNoMarginBottom: true } ),
			h( TextControl, { label: __( 'Button text', 'invoiceninja' ), value: a.submitLabel, placeholder: __( 'Send request', 'invoiceninja' ), onChange: ( v ) => set( { submitLabel: v } ), __nextHasNoMarginBottom: true } ) ),
		h( PanelBody, { key: 'fields', title: __( 'Fields', 'invoiceninja' ) },
			h( 'p', { className: 'components-base-control__help' }, __( 'Email is always shown and required: it is how you reply.', 'invoiceninja' ) ),
			FIELDS.map( ( [ key, label, help ] ) => h( 'div', { key, className: 'invoiceninja-field-toggle' },
				h( ToggleControl, { label, help: help || undefined, checked: !! a[ 'show' + key ], onChange: ( v ) => set( { [ 'show' + key ]: v } ), __nextHasNoMarginBottom: true } ),
				a[ 'show' + key ] ? h( ToggleControl, {
					label: __( 'Required', 'invoiceninja' ),
					checked: !! a[ 'require' + key ],
					onChange: ( v ) => set( { [ 'require' + key ]: v } ),
					__nextHasNoMarginBottom: true,
				} ) : null ) ) ),
		h( PanelBody, { key: 'products', title: __( 'Products', 'invoiceninja' ), initialOpen: false },
			a.showProduct ? [
				h( SelectControl, {
					key: 'display',
					label: __( 'Show the choices as', 'invoiceninja' ),
					value: a.productDisplay || 'auto',
					options: [
						{ value: 'auto', label: __( 'Automatic: buttons for up to 6 choices', 'invoiceninja' ) },
						{ value: 'chips', label: __( 'Buttons', 'invoiceninja' ) },
						{ value: 'dropdown', label: __( 'Dropdown', 'invoiceninja' ) },
					],
					onChange: ( v ) => set( { productDisplay: v } ),
					__nextHasNoMarginBottom: true,
				} ),
				h( ProductPicker, { key: 'picker', value: a.products, onChange: ( v ) => set( { products: v } ) } ),
			] : h( 'p', null, __( 'Turn on the Product field to choose products.', 'invoiceninja' ) ) ),
		h( PanelBody, { key: 'consent', title: __( 'Consent', 'invoiceninja' ), initialOpen: false },
			h( PagePicker, { label: __( 'Privacy policy page', 'invoiceninja' ), value: a.privacyPage, onChange: ( v ) => set( { privacyPage: v } ) } ),
			a.privacyPage ? h( ToggleControl, { label: __( 'Required', 'invoiceninja' ), checked: !! a.requirePrivacy, onChange: ( v ) => set( { requirePrivacy: v } ), __nextHasNoMarginBottom: true } ) : null,
			h( PagePicker, { label: __( 'Terms page', 'invoiceninja' ), value: a.termsPage, onChange: ( v ) => set( { termsPage: v } ) } ),
			a.termsPage ? h( ToggleControl, { label: __( 'Required', 'invoiceninja' ), checked: !! a.requireTerms, onChange: ( v ) => set( { requireTerms: v } ), __nextHasNoMarginBottom: true } ) : null,
			h( Notice, { status: 'info', isDismissible: false }, __( 'Each agreement is saved with the request and the time it was given.', 'invoiceninja' ) ) ),
	];
}( window.wp ) );
