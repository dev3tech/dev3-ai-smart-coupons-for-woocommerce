import { Button, Notice, SelectControl, Spinner } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { money } from './components';
import { typeLabel } from './RulesTab';

// Reference categorical slots 1–3 (validated all-pairs, light surface). Identity is never colour-only:
// legend + direct end labels + table view.
const SERIES = [
	{ key: 'shown', label: __( 'Shown', 'dev3-ai-smart-coupons-for-woocommerce' ), color: '#2a78d6' },
	{ key: 'applied', label: __( 'Applied', 'dev3-ai-smart-coupons-for-woocommerce' ), color: '#eb6834' },
	{ key: 'redeemed', label: __( 'Redeemed', 'dev3-ai-smart-coupons-for-woocommerce' ), color: '#1baf7a' },
];

const TRIGGERS = {
	exit_intent: __( 'Exit intent', 'dev3-ai-smart-coupons-for-woocommerce' ),
	scroll_up: __( 'Fast scroll up', 'dev3-ai-smart-coupons-for-woocommerce' ),
	tab_hidden: __( 'Tab hidden', 'dev3-ai-smart-coupons-for-woocommerce' ),
	idle: __( 'Idle', 'dev3-ai-smart-coupons-for-woocommerce' ),
};

const STATUS = {
	active: [ '', __( 'Active', 'dev3-ai-smart-coupons-for-woocommerce' ) ],
	applied: [ 'is-warn', __( 'Applied', 'dev3-ai-smart-coupons-for-woocommerce' ) ],
	redeemed: [ 'is-ok', __( 'Redeemed', 'dev3-ai-smart-coupons-for-woocommerce' ) ],
	expired: [ 'is-bad', __( 'Expired', 'dev3-ai-smart-coupons-for-woocommerce' ) ],
};

function fillDays( daily, days ) {
	const byDay = Object.fromEntries( daily.map( ( d ) => [ d.day, d ] ) );
	const out = [];
	const today = new Date();
	for ( let i = days - 1; i >= 0; i-- ) {
		const d = new Date( today.getFullYear(), today.getMonth(), today.getDate() - i );
		const key = `${ d.getFullYear() }-${ String( d.getMonth() + 1 ).padStart( 2, '0' ) }-${ String( d.getDate() ).padStart( 2, '0' ) }`;
		out.push( { day: key, shown: 0, applied: 0, redeemed: 0, ...( byDay[ key ] || {} ) } );
	}
	return out;
}

function useWidth() {
	const ref = useRef( null );
	const [ width, setWidth ] = useState( 720 );
	useEffect( () => {
		if ( ! ref.current || ! window.ResizeObserver ) {
			return;
		}
		const ro = new window.ResizeObserver( ( entries ) => setWidth( Math.max( 320, Math.floor( entries[ 0 ].contentRect.width ) ) ) );
		ro.observe( ref.current );
		return () => ro.disconnect();
	}, [] );
	return [ ref, width ];
}

// Direct end labels: keep at least 14px apart (ties would overlap).
function spreadLabels( items, min = 14 ) {
	const sorted = [ ...items ].sort( ( a, b ) => a.y - b.y );
	for ( let i = 1; i < sorted.length; i++ ) {
		if ( sorted[ i ].y - sorted[ i - 1 ].y < min ) {
			sorted[ i ].y = sorted[ i - 1 ].y + min;
		}
	}
	return Object.fromEntries( sorted.map( ( s ) => [ s.key, s.y ] ) );
}

function LineChart( { data } ) {
	const [ hover, setHover ] = useState( null );
	const [ wrap, W ] = useWidth();
	const H = 220;
	const pad = { l: 36, r: 96, t: 12, b: 26 };
	const max = Math.max( 1, ...data.map( ( d ) => d.shown ) );
	const niceMax = max <= 4 ? max : Math.ceil( max / 4 ) * 4;
	const x = ( i ) => pad.l + ( data.length <= 1 ? 0 : ( i / ( data.length - 1 ) ) * ( W - pad.l - pad.r ) );
	const y = ( v ) => pad.t + ( 1 - v / niceMax ) * ( H - pad.t - pad.b );
	const ticks = Array.from( new Set( [ 0, niceMax / 2, niceMax ].map( ( v ) => Math.round( v ) ) ) );
	const step = Math.max( 1, Math.ceil( data.length / 6 ) );
	const last = data.length - 1;
	const labelY = spreadLabels( SERIES.map( ( s ) => ( { key: s.key, y: y( data[ last ][ s.key ] ) + 4 } ) ) );
	return (
		<div className="dev3sc-chart" ref={ wrap }>
			<svg width={ W } height={ H } viewBox={ `0 0 ${ W } ${ H }` } role="img" aria-label={ __( 'Offers per day: shown, applied and redeemed', 'dev3-ai-smart-coupons-for-woocommerce' ) } onMouseLeave={ () => setHover( null ) }>
				{ ticks.map( ( t ) => (
					<g key={ t }>
						<line x1={ pad.l } x2={ W - pad.r } y1={ y( t ) } y2={ y( t ) } stroke="#e5e7eb" strokeWidth="1" />
						<text x={ pad.l - 8 } y={ y( t ) + 4 } textAnchor="end" className="dev3sc-chart__tick">{ t }</text>
					</g>
				) ) }
				{ data.map( ( d, i ) =>
					i % step === 0 || i === last ? (
						<text key={ d.day } x={ x( i ) } y={ H - 6 } textAnchor="middle" className="dev3sc-chart__tick">{ d.day.slice( 5 ) }</text>
					) : null
				) }
				{ hover !== null && <line x1={ x( hover ) } x2={ x( hover ) } y1={ pad.t } y2={ H - pad.b } stroke="#9ca3af" strokeWidth="1" /> }
				{ SERIES.map( ( s ) => (
					<g key={ s.key }>
						<polyline fill="none" stroke={ s.color } strokeWidth="2" strokeLinejoin="round" strokeLinecap="round" points={ data.map( ( d, i ) => `${ x( i ) },${ y( d[ s.key ] ) }` ).join( ' ' ) } />
						<circle cx={ x( last ) } cy={ y( data[ last ][ s.key ] ) } r="4" fill={ s.color } stroke="#fff" strokeWidth="2" />
						<text x={ x( last ) + 8 } y={ labelY[ s.key ] } className="dev3sc-chart__label">{ `${ s.label } ${ data[ last ][ s.key ] }` }</text>
						{ hover !== null && <circle cx={ x( hover ) } cy={ y( data[ hover ][ s.key ] ) } r="4" fill={ s.color } stroke="#fff" strokeWidth="2" /> }
					</g>
				) ) }
				{ data.map( ( d, i ) => (
					<rect key={ d.day } x={ x( i ) - ( W - pad.l - pad.r ) / Math.max( 1, data.length - 1 ) / 2 } y={ pad.t } width={ ( W - pad.l - pad.r ) / Math.max( 1, data.length - 1 ) } height={ H - pad.t - pad.b } fill="transparent" onMouseEnter={ () => setHover( i ) } />
				) ) }
			</svg>
			{ hover !== null && (
				<div className="dev3sc-chart__tip" style={ { left: `${ ( x( hover ) / W ) * 100 }%` } } role="status">
					<strong>{ data[ hover ].day }</strong>
					{ SERIES.map( ( s ) => (
						<span key={ s.key }>
							<i style={ { background: s.color } } />
							{ s.label } <b>{ data[ hover ][ s.key ] }</b>
						</span>
					) ) }
				</div>
			) }
			<div className="dev3sc-legend">
				{ SERIES.map( ( s ) => (
					<span key={ s.key }>
						<i style={ { background: s.color } } />
						{ s.label }
					</span>
				) ) }
			</div>
		</div>
	);
}

function Breakdown( { title, rows, label } ) {
	return (
		<div className="dev3sc-card">
			<h2>{ title }</h2>
			{ rows.length === 0 ? (
				<p className="description">{ __( 'No data yet.', 'dev3-ai-smart-coupons-for-woocommerce' ) }</p>
			) : (
				<table className="widefat striped">
					<thead>
						<tr>
							<th />
							<th className="dev3sc-text-right">{ __( 'Shown', 'dev3-ai-smart-coupons-for-woocommerce' ) }</th>
							<th className="dev3sc-text-right">{ __( 'Redeemed', 'dev3-ai-smart-coupons-for-woocommerce' ) }</th>
							<th className="dev3sc-text-right">{ __( 'Conv.', 'dev3-ai-smart-coupons-for-woocommerce' ) }</th>
							<th className="dev3sc-text-right">{ __( 'Revenue', 'dev3-ai-smart-coupons-for-woocommerce' ) }</th>
							<th className="dev3sc-text-right">{ __( 'Discount', 'dev3-ai-smart-coupons-for-woocommerce' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( r ) => (
							<tr key={ r.label }>
								<td>{ label( r.label ) }</td>
								<td className="dev3sc-text-right">{ r.shown }</td>
								<td className="dev3sc-text-right">{ r.redeemed }</td>
								<td className="dev3sc-text-right">{ r.shown ? `${ Math.round( ( r.redeemed / r.shown ) * 1000 ) / 10 }%` : '—' }</td>
								<td className="dev3sc-text-right">{ money( r.revenue ) }</td>
								<td className="dev3sc-text-right">{ money( r.discount_cost ) }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</div>
	);
}

export default function AnalyticsTab() {
	const [ days, setDays ] = useState( 30 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ table, setTable ] = useState( false );

	const load = () => {
		setError( null );
		request( `analytics?days=${ days }` ).then( setData ).catch( setError );
	};
	// eslint-disable-next-line react-hooks/exhaustive-deps
	useEffect( load, [ days ] );

	if ( error ) {
		return <Notice status="error" isDismissible={ false }>{ error.message }</Notice>;
	}
	if ( ! data ) {
		return <Spinner />;
	}
	const t = data.totals;
	const series = fillDays( data.daily, Math.min( days, 90 ) );
	const tiles = [
		[ t.shown, __( 'Offers shown', 'dev3-ai-smart-coupons-for-woocommerce' ) ],
		[ t.applied, sprintf( /* translators: %s: percent */ __( 'Applied (%s%%)', 'dev3-ai-smart-coupons-for-woocommerce' ), t.apply_rate ) ],
		[ t.redeemed, __( 'Redeemed in orders', 'dev3-ai-smart-coupons-for-woocommerce' ) ],
		[ `${ t.conversion }%`, __( 'Conversion (redeemed ÷ shown)', 'dev3-ai-smart-coupons-for-woocommerce' ) ],
		[ money( t.revenue ), __( 'Revenue from offered carts', 'dev3-ai-smart-coupons-for-woocommerce' ) ],
		[ money( t.discount_cost ), __( 'Total discount cost', 'dev3-ai-smart-coupons-for-woocommerce' ) ],
	];
	return (
		<>
			<div className="dev3sc-flex dev3sc-items-end dev3sc-gap-3 dev3sc-mb-4 dev3sc-flex-wrap">
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Period', 'dev3-ai-smart-coupons-for-woocommerce' ) }
					value={ String( days ) }
					onChange={ ( v ) => setDays( Number( v ) ) }
					options={ [ 7, 30, 90, 365 ].map( ( d ) => ( { value: String( d ), label: sprintf( /* translators: %d: days */ __( 'Last %d days', 'dev3-ai-smart-coupons-for-woocommerce' ), d ) } ) ) }
				/>
				<Button variant="secondary" onClick={ load }>{ __( 'Refresh', 'dev3-ai-smart-coupons-for-woocommerce' ) }</Button>
				{ data.budget.limit > 0 && (
					<span className="dev3sc-budget">
						{ sprintf( /* translators: 1: spent, 2: limit */ __( 'Today’s discount budget: %1$s of %2$s reserved', 'dev3-ai-smart-coupons-for-woocommerce' ), money( data.budget.spent ), money( data.budget.limit ) ) }
					</span>
				) }
			</div>
			<div className="dev3sc-grid dev3sc-grid-cols-2 md:dev3sc-grid-cols-3 lg:dev3sc-grid-cols-6 dev3sc-gap-3 dev3sc-mb-5">
				{ tiles.map( ( [ v, l ] ) => (
					<div className="dev3sc-stat" key={ l }>
						<strong>{ v }</strong>
						<span>{ l }</span>
					</div>
				) ) }
			</div>
			<div className="dev3sc-card">
				<div className="dev3sc-flex dev3sc-justify-between dev3sc-items-center">
					<h2>{ __( 'Offers per day', 'dev3-ai-smart-coupons-for-woocommerce' ) }</h2>
					<Button variant="link" onClick={ () => setTable( ! table ) }>{ table ? __( 'Show chart', 'dev3-ai-smart-coupons-for-woocommerce' ) : __( 'Show table', 'dev3-ai-smart-coupons-for-woocommerce' ) }</Button>
				</div>
				{ table ? (
					<table className="widefat striped">
						<thead>
							<tr>
								<th>{ __( 'Day', 'dev3-ai-smart-coupons-for-woocommerce' ) }</th>
								{ SERIES.map( ( s ) => <th key={ s.key } className="dev3sc-text-right">{ s.label }</th> ) }
							</tr>
						</thead>
						<tbody>
							{ series.filter( ( d ) => d.shown ).map( ( d ) => (
								<tr key={ d.day }>
									<td>{ d.day }</td>
									{ SERIES.map( ( s ) => <td key={ s.key } className="dev3sc-text-right">{ d[ s.key ] }</td> ) }
								</tr>
							) ) }
						</tbody>
					</table>
				) : (
					<LineChart data={ series } />
				) }
			</div>
			<div className="dev3sc-grid lg:dev3sc-grid-cols-2 dev3sc-gap-5">
				<Breakdown title={ __( 'By incentive type', 'dev3-ai-smart-coupons-for-woocommerce' ) } rows={ data.by_type } label={ typeLabel } />
				<Breakdown title={ __( 'By trigger', 'dev3-ai-smart-coupons-for-woocommerce' ) } rows={ data.by_trigger } label={ ( k ) => TRIGGERS[ k ] || k } />
			</div>
			<div className="dev3sc-card">
				<h2>{ __( 'Recent offers', 'dev3-ai-smart-coupons-for-woocommerce' ) }</h2>
				{ data.recent.length === 0 ? (
					<p className="description">{ __( 'No offers yet. They appear here as soon as a shopper triggers one.', 'dev3-ai-smart-coupons-for-woocommerce' ) }</p>
				) : (
					<div className="dev3sc-table-scroll">
						<table className="widefat striped">
							<thead>
								<tr>
									<th>{ __( 'Created', 'dev3-ai-smart-coupons-for-woocommerce' ) }</th>
									<th>{ __( 'Code', 'dev3-ai-smart-coupons-for-woocommerce' ) }</th>
									<th>{ __( 'Offer', 'dev3-ai-smart-coupons-for-woocommerce' ) }</th>
									<th className="dev3sc-text-right">{ __( 'Cart', 'dev3-ai-smart-coupons-for-woocommerce' ) }</th>
									<th className="dev3sc-text-right">{ __( 'Risk', 'dev3-ai-smart-coupons-for-woocommerce' ) }</th>
									<th>{ __( 'Trigger', 'dev3-ai-smart-coupons-for-woocommerce' ) }</th>
									<th>{ __( 'Source', 'dev3-ai-smart-coupons-for-woocommerce' ) }</th>
									<th>{ __( 'Status', 'dev3-ai-smart-coupons-for-woocommerce' ) }</th>
									<th>{ __( 'Order', 'dev3-ai-smart-coupons-for-woocommerce' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ data.recent.map( ( r ) => {
									const [ cls, label ] = STATUS[ r.status ] || [ '', r.status ];
									return (
										<tr key={ r.id }>
											<td>{ new Date( `${ r.created_at.replace( ' ', 'T' ) }Z` ).toLocaleString() }</td>
											<td><code>{ String( r.code ).toUpperCase() }</code></td>
											<td>{ r.type === 'percent' ? `${ Number( r.amount ) }%` : r.type === 'fixed_cart' ? money( r.amount ) : __( 'Free shipping', 'dev3-ai-smart-coupons-for-woocommerce' ) }</td>
											<td className="dev3sc-text-right">{ money( r.cart_value ) }</td>
											<td className="dev3sc-text-right">{ r.risk }</td>
											<td>{ TRIGGERS[ r.trigger_type ] || r.trigger_type }</td>
											<td>{ r.source === 'ai' ? __( 'AI', 'dev3-ai-smart-coupons-for-woocommerce' ) : __( 'Rules', 'dev3-ai-smart-coupons-for-woocommerce' ) }</td>
											<td><span className={ `dev3sc-badge ${ cls }` }>{ label }</span></td>
											<td>{ Number( r.order_id ) > 0 ? <a href={ r.order_url }>{ `#${ r.order_id } · ${ money( r.order_total ) }` }</a> : '—' }</td>
										</tr>
									);
								} ) }
							</tbody>
						</table>
					</div>
				) }
			</div>
		</>
	);
}
