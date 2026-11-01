import { Button, Notice, SelectControl, Spinner, TextControl, ToggleControl } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { flashBar } from '../storefront/banner';
import { adminCfg, Card, fromLocalInput, toLocalInput } from './components';

const newSale = () => {
	const start = Math.floor( Date.now() / 1000 / 300 ) * 300;
	return {
		id: '',
		enabled: true,
		title: '',
		headline: '',
		message: '',
		cta_label: __( 'Shop the sale', 'dev3-ai-smart-coupons-for-woocommerce' ),
		cta_url: '',
		coupon_code: '',
		starts: start,
		ends: start + 2 * 86400,
		placement: 'top',
		pages: 'all',
		bg: '',
		text: '',
		_brief: '',
	};
};

const status = ( s ) => {
	const now = Date.now() / 1000;
	if ( ! s.enabled ) {
		return [ 'is-bad', __( 'Off', 'dev3-ai-smart-coupons-for-woocommerce' ) ];
	}
	if ( s.ends <= now ) {
		return [ 'is-bad', __( 'Ended', 'dev3-ai-smart-coupons-for-woocommerce' ) ];
	}
	if ( s.starts > now ) {
		return [ 'is-warn', __( 'Scheduled', 'dev3-ai-smart-coupons-for-woocommerce' ) ];
	}
	return [ 'is-ok', __( 'Live', 'dev3-ai-smart-coupons-for-woocommerce' ) ];
};

function FlashPreview( { sale, design } ) {
	const ref = useRef( null );
	useEffect( () => {
		if ( ! ref.current ) {
			return;
		}
		const bar = flashBar( { ...sale, headline: sale.headline || __( 'Your headline', 'dev3-ai-smart-coupons-for-woocommerce' ) }, design, { flash: __( 'Flash sale', 'dev3-ai-smart-coupons-for-woocommerce' ), endsIn: __( 'Ends in', 'dev3-ai-smart-coupons-for-woocommerce' ), close: __( 'Close', 'dev3-ai-smart-coupons-for-woocommerce' ) }, Math.max( 0, sale.ends - Math.max( sale.starts, Date.now() / 1000 ) ) );
		bar.querySelectorAll( 'a, button' ).forEach( ( n ) => n.addEventListener( 'click', ( e ) => e.preventDefault() ) );
		ref.current.replaceChildren( bar );
	}, [ sale, design ] );
	return <div className="dev3sc-stage dev3sc-stage--flash" ref={ ref } />;
}

function Editor( { sale, onChange, onDone, onDelete, design } ) {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const set = ( k ) => ( v ) => onChange( { ...sale, [ k ]: v } );
	const generate = () => {
		setBusy( true );
		setError( null );
		request( 'flash-sales/copy', { method: 'POST', data: { brief: sale._brief || sale.title, tone: sale._tone || 'energetic', minutes: Math.round( ( sale.ends - sale.starts ) / 60 ) } } )
			.then( ( copy ) => onChange( { ...sale, ...copy } ) )
			.catch( setError )
			.finally( () => setBusy( false ) );
	};
	const invalid = ! sale.headline || sale.ends <= sale.starts;
	return (
		<Card title={ sale.id ? __( 'Edit flash sale', 'dev3-ai-smart-coupons-for-woocommerce' ) : __( 'New flash sale', 'dev3-ai-smart-coupons-for-woocommerce' ) }>
			<div className="dev3sc-grid md:dev3sc-grid-cols-2 dev3sc-gap-4">
				<TextControl __nextHasNoMarginBottom label={ __( 'Internal name', 'dev3-ai-smart-coupons-for-woocommerce' ) } value={ sale.title } onChange={ set( 'title' ) } placeholder={ __( 'Black Friday hoodies', 'dev3-ai-smart-coupons-for-woocommerce' ) } />
				<ToggleControl __nextHasNoMarginBottom className="dev3sc-pt-6" label={ __( 'Enabled', 'dev3-ai-smart-coupons-for-woocommerce' ) } checked={ sale.enabled } onChange={ set( 'enabled' ) } />
				<TextControl
					__nextHasNoMarginBottom
					type="datetime-local"
					label={ sprintf( /* translators: %s: timezone */ __( 'Starts (%s)', 'dev3-ai-smart-coupons-for-woocommerce' ), adminCfg.timezone || 'UTC' ) }
					value={ toLocalInput( sale.starts ) }
					onChange={ ( v ) => set( 'starts' )( fromLocalInput( v ) ) }
				/>
				<TextControl
					__nextHasNoMarginBottom
					type="datetime-local"
					label={ sprintf( /* translators: %s: timezone */ __( 'Ends (%s)', 'dev3-ai-smart-coupons-for-woocommerce' ), adminCfg.timezone || 'UTC' ) }
					value={ toLocalInput( sale.ends ) }
					onChange={ ( v ) => set( 'ends' )( fromLocalInput( v ) ) }
				/>
			</div>

			<div className="dev3sc-ai-box dev3sc-mt-4">
				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'Describe the sale for the AI', 'dev3-ai-smart-coupons-for-woocommerce' ) }
					value={ sale._brief || '' }
					onChange={ set( '_brief' ) }
					placeholder={ __( '25% off all hoodies and beanies this weekend, code COZY25', 'dev3-ai-smart-coupons-for-woocommerce' ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Tone', 'dev3-ai-smart-coupons-for-woocommerce' ) }
					value={ sale._tone || 'energetic' }
					onChange={ set( '_tone' ) }
					options={ [ 'energetic', 'friendly', 'premium', 'playful', 'calm' ].map( ( t ) => ( { value: t, label: t } ) ) }
				/>
				<Button variant="secondary" icon="admin-customizer" onClick={ generate } isBusy={ busy } disabled={ busy || ! ( sale._brief || sale.title ) }>
					{ __( 'Generate copy with DEV3 AI', 'dev3-ai-smart-coupons-for-woocommerce' ) }
				</Button>
			</div>
			{ error && <Notice status="error" isDismissible={ false }>{ error.message } { error.hint }</Notice> }

			<div className="dev3sc-grid md:dev3sc-grid-cols-2 dev3sc-gap-4 dev3sc-mt-4">
				<TextControl __nextHasNoMarginBottom label={ __( 'Headline', 'dev3-ai-smart-coupons-for-woocommerce' ) } value={ sale.headline } onChange={ set( 'headline' ) } maxLength={ 90 } />
				<TextControl __nextHasNoMarginBottom label={ __( 'Message', 'dev3-ai-smart-coupons-for-woocommerce' ) } value={ sale.message } onChange={ set( 'message' ) } maxLength={ 180 } />
				<TextControl __nextHasNoMarginBottom label={ __( 'Button label', 'dev3-ai-smart-coupons-for-woocommerce' ) } value={ sale.cta_label } onChange={ set( 'cta_label' ) } maxLength={ 30 } />
				<TextControl __nextHasNoMarginBottom label={ __( 'Button link (this site)', 'dev3-ai-smart-coupons-for-woocommerce' ) } value={ sale.cta_url } onChange={ set( 'cta_url' ) } placeholder="/shop/" />
				<TextControl __nextHasNoMarginBottom label={ __( 'Coupon code to show (optional)', 'dev3-ai-smart-coupons-for-woocommerce' ) } value={ sale.coupon_code } onChange={ set( 'coupon_code' ) } />
				<div className="dev3sc-grid dev3sc-grid-cols-2 dev3sc-gap-4">
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Position', 'dev3-ai-smart-coupons-for-woocommerce' ) }
						value={ sale.placement }
						onChange={ set( 'placement' ) }
						options={ [
							{ value: 'top', label: __( 'Top', 'dev3-ai-smart-coupons-for-woocommerce' ) },
							{ value: 'bottom', label: __( 'Bottom', 'dev3-ai-smart-coupons-for-woocommerce' ) },
						] }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Show on', 'dev3-ai-smart-coupons-for-woocommerce' ) }
						value={ sale.pages }
						onChange={ set( 'pages' ) }
						options={ [
							{ value: 'all', label: __( 'All pages', 'dev3-ai-smart-coupons-for-woocommerce' ) },
							{ value: 'shop', label: __( 'Store pages', 'dev3-ai-smart-coupons-for-woocommerce' ) },
							{ value: 'cart_checkout', label: __( 'Cart & checkout', 'dev3-ai-smart-coupons-for-woocommerce' ) },
						] }
					/>
				</div>
			</div>
			<h3>{ __( 'Preview', 'dev3-ai-smart-coupons-for-woocommerce' ) }</h3>
			<FlashPreview sale={ sale } design={ design } />
			{ sale.ends <= sale.starts && <Notice status="error" isDismissible={ false }>{ __( 'The end must be after the start.', 'dev3-ai-smart-coupons-for-woocommerce' ) }</Notice> }
			<div className="dev3sc-flex dev3sc-gap-2 dev3sc-mt-4">
				<Button variant="primary" onClick={ onDone } disabled={ invalid }>
					{ __( 'Save flash sale', 'dev3-ai-smart-coupons-for-woocommerce' ) }
				</Button>
				{ onDelete && (
					<Button variant="tertiary" isDestructive onClick={ onDelete }>
						{ __( 'Delete', 'dev3-ai-smart-coupons-for-woocommerce' ) }
					</Button>
				) }
			</div>
		</Card>
	);
}

export default function FlashTab( { settings } ) {
	const [ items, setItems ] = useState( null );
	const [ editing, setEditing ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ saved, setSaved ] = useState( false );
	const b = settings.banner || {};
	const design = { bg: b.bg, text: b.text, accent: b.accent, accentText: b.accent_text, radius: b.radius };

	useEffect( () => {
		request( 'flash-sales' ).then( setItems ).catch( setError );
	}, [] );

	const persist = ( next ) =>
		request( 'flash-sales', { method: 'POST', data: { items: next.map( ( { _brief, _tone, ...rest } ) => rest ) } } )
			.then( ( list ) => {
				setItems( list );
				setEditing( null );
				setSaved( true );
				setTimeout( () => setSaved( false ), 2500 );
			} )
			.catch( setError );

	if ( ! items ) {
		return error ? <Notice status="error">{ error.message }</Notice> : <Spinner />;
	}
	if ( editing ) {
		const idx = editing.index;
		return (
			<Editor
				sale={ editing.sale }
				design={ design }
				onChange={ ( sale ) => setEditing( { ...editing, sale } ) }
				onDone={ () => persist( idx === -1 ? [ ...items, editing.sale ] : items.map( ( s, i ) => ( i === idx ? editing.sale : s ) ) ) }
				onDelete={ idx === -1 ? null : () => persist( items.filter( ( s, i ) => i !== idx ) ) }
			/>
		);
	}
	return (
		<Card
			title={ __( 'Flash sales', 'dev3-ai-smart-coupons-for-woocommerce' ) }
			description={ __( 'Scheduled, site-wide urgency bars with a live countdown. They show to every visitor between the start and end time.', 'dev3-ai-smart-coupons-for-woocommerce' ) }
			actions={
				<Button variant="primary" onClick={ () => setEditing( { index: -1, sale: newSale() } ) }>
					{ __( 'Add flash sale', 'dev3-ai-smart-coupons-for-woocommerce' ) }
				</Button>
			}
		>
			{ saved && <Notice status="success" isDismissible={ false }>{ __( 'Flash sales saved.', 'dev3-ai-smart-coupons-for-woocommerce' ) }</Notice> }
			{ error && <Notice status="error" onRemove={ () => setError( null ) }>{ error.message }</Notice> }
			{ items.length === 0 ? (
				<p>{ __( 'No flash sales yet.', 'dev3-ai-smart-coupons-for-woocommerce' ) }</p>
			) : (
				<table className="widefat striped">
					<thead>
						<tr>
							<th>{ __( 'Sale', 'dev3-ai-smart-coupons-for-woocommerce' ) }</th>
							<th>{ __( 'Status', 'dev3-ai-smart-coupons-for-woocommerce' ) }</th>
							<th>{ __( 'Starts', 'dev3-ai-smart-coupons-for-woocommerce' ) }</th>
							<th>{ __( 'Ends', 'dev3-ai-smart-coupons-for-woocommerce' ) }</th>
							<th />
						</tr>
					</thead>
					<tbody>
						{ items.map( ( s, i ) => {
							const [ cls, label ] = status( s );
							return (
								<tr key={ s.id }>
									<td>
										<strong>{ s.title || s.headline }</strong>
										<br />
										<span className="dev3sc-text-gray-500">{ s.headline }</span>
									</td>
									<td><span className={ `dev3sc-badge ${ cls }` }>{ label }</span></td>
									<td>{ toLocalInput( s.starts ).replace( 'T', ' ' ) }</td>
									<td>{ toLocalInput( s.ends ).replace( 'T', ' ' ) }</td>
									<td className="dev3sc-text-right">
										<Button variant="secondary" size="small" onClick={ () => setEditing( { index: i, sale: s } ) }>
											{ __( 'Edit', 'dev3-ai-smart-coupons-for-woocommerce' ) }
										</Button>
									</td>
								</tr>
							);
						} ) }
					</tbody>
				</table>
			) }
		</Card>
	);
}
