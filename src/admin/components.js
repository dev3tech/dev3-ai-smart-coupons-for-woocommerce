import { Button, FormTokenField, TextControl } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';

export const adminCfg = window.dev3scAdmin || {};
export const money = ( v ) => `${ adminCfg.currency || '$' }${ Number( v || 0 ).toLocaleString( undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 } ) }`;

/**
 * Card with title + description.
 *
 * @param {Object} p Props.
 * @return {JSX.Element} Card.
 */
export function Card( { title, description, children, actions } ) {
	return (
		<div className="dev3sc-card">
			<div className="dev3sc-flex dev3sc-items-start dev3sc-justify-between dev3sc-gap-4">
				<div>
					{ title && <h2>{ title }</h2> }
					{ description && <p className="description dev3sc-mt-0 dev3sc-mb-4">{ description }</p> }
				</div>
				{ actions }
			</div>
			{ children }
		</div>
	);
}

/**
 * Number input that keeps the raw string while typing.
 *
 * @param {Object} p Props.
 * @return {JSX.Element} Field.
 */
export function NumberField( { label, help, value, onChange, min, max, step = 1, suffix } ) {
	return (
		<div className="dev3sc-number">
			<TextControl
				__nextHasNoMarginBottom
				label={ label }
				help={ help }
				type="number"
				min={ min }
				max={ max }
				step={ step }
				value={ value === undefined || value === null ? '' : String( value ) }
				onChange={ ( v ) => onChange( v === '' ? '' : Number( v ) ) }
			/>
			{ suffix && <span className="dev3sc-number__suffix">{ suffix }</span> }
		</div>
	);
}

/**
 * Sticky save bar for a settings group.
 *
 * @param {Object} p Props.
 * @return {JSX.Element} Bar.
 */
export function SaveBar( { onSave, busy, dirty } ) {
	return (
		<div className="dev3sc-savebar">
			<Button variant="primary" onClick={ onSave } isBusy={ busy } disabled={ busy || ! dirty }>
				{ __( 'Save changes', 'dev3-ai-smart-coupons-for-woocommerce' ) }
			</Button>
			{ dirty && <span className="dev3sc-text-gray-500">{ __( 'Unsaved changes', 'dev3-ai-smart-coupons-for-woocommerce' ) }</span> }
		</div>
	);
}

/**
 * Group editor state: local copy + save of one settings group.
 *
 * @param {Object}   settings Settings.
 * @param {string}   group    Group key.
 * @param {Function} save     Save fn.
 * @return {Object} { value, set, dirty, busy, submit }.
 */
export function useGroup( settings, group, save ) {
	const [ value, setValue ] = useState( settings[ group ] || {} );
	const [ busy, setBusy ] = useState( false );
	const [ dirty, setDirty ] = useState( false );
	const set = ( key ) => ( v ) => {
		setValue( ( prev ) => ( { ...prev, [ key ]: v } ) );
		setDirty( true );
	};
	const submit = () => {
		setBusy( true );
		return save( { [ group ]: value } )
			.then( ( next ) => {
				setValue( next[ group ] );
				setDirty( false );
			} )
			.finally( () => setBusy( false ) );
	};
	return { value, set, dirty, busy, submit };
}

/**
 * Product / category multi-picker backed by /lookup.
 *
 * @param {Object} p Props { type, label, ids, onChange, help }.
 * @return {JSX.Element} Picker.
 */
export function IdPicker( { type, label, ids, onChange, help } ) {
	const [ known, setKnown ] = useState( {} );
	const [ suggestions, setSuggestions ] = useState( [] );
	const timer = useRef( null );
	const remember = ( rows ) => setKnown( ( prev ) => ( { ...prev, ...Object.fromEntries( rows.map( ( r ) => [ r.id, r.name ] ) ) } ) );

	useEffect( () => {
		const missing = ( ids || [] ).filter( ( id ) => ! known[ id ] );
		if ( missing.length ) {
			request( `lookup?type=${ type }&include=${ missing.join( ',' ) }` ).then( remember ).catch( () => {} );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ ( ids || [] ).join( ',' ) ] );

	const label4 = ( id ) => ( known[ id ] ? `${ known[ id ] } (#${ id })` : `#${ id }` );
	const search = ( term ) => {
		clearTimeout( timer.current );
		timer.current = setTimeout( () => {
			request( `lookup?type=${ type }&search=${ encodeURIComponent( term ) }` )
				.then( ( rows ) => {
					remember( rows );
					setSuggestions( rows.map( ( r ) => `${ r.name } (#${ r.id })` ) );
				} )
				.catch( () => {} );
		}, 250 );
	};
	return (
		<FormTokenField
			__nextHasNoMarginBottom
			__experimentalShowHowTo={ false }
			label={ label }
			value={ ( ids || [] ).map( label4 ) }
			suggestions={ suggestions }
			onInputChange={ search }
			onChange={ ( tokens ) => {
				const next = tokens
					.map( ( t ) => {
						const m = String( typeof t === 'string' ? t : t.value ).match( /#(\d+)\)?$/ );
						return m ? Number( m[ 1 ] ) : null;
					} )
					.filter( Boolean );
				onChange( Array.from( new Set( next ) ) );
			} }
			help={ help }
		/>
	);
}

/**
 * WCAG contrast ratio between two hex colours.
 *
 * @param {string} a Hex.
 * @param {string} b Hex.
 * @return {number} Ratio.
 */
export function contrast( a, b ) {
	const lum = ( hex ) => {
		const h = String( hex || '#000' ).replace( '#', '' );
		const full = h.length === 3 ? h.split( '' ).map( ( c ) => c + c ).join( '' ) : h.padEnd( 6, '0' );
		const ch = [ 0, 2, 4 ].map( ( i ) => parseInt( full.slice( i, i + 2 ), 16 ) / 255 ).map( ( c ) => ( c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 ) ) );
		return 0.2126 * ch[ 0 ] + 0.7152 * ch[ 1 ] + 0.0722 * ch[ 2 ];
	};
	const [ x, y ] = [ lum( a ), lum( b ) ].sort( ( m, n ) => n - m );
	return ( x + 0.05 ) / ( y + 0.05 );
}

/**
 * Site-local "YYYY-MM-DDTHH:mm" <-> Unix seconds, using the site's GMT offset.
 */
export const toLocalInput = ( unix ) => {
	if ( ! unix ) {
		return '';
	}
	return new Date( ( unix + ( adminCfg.gmtOffset || 0 ) * 3600 ) * 1000 ).toISOString().slice( 0, 16 );
};
export const fromLocalInput = ( str ) => {
	const m = String( str || '' ).match( /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/ );
	if ( ! m ) {
		return 0;
	}
	return Date.UTC( +m[ 1 ], +m[ 2 ] - 1, +m[ 3 ], +m[ 4 ], +m[ 5 ] ) / 1000 - ( adminCfg.gmtOffset || 0 ) * 3600;
};
