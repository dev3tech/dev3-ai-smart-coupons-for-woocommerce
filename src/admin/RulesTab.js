import { Button, CheckboxControl, Notice, RangeControl, SelectControl, TextControl, ToggleControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { adminCfg, Card, IdPicker, money, NumberField, SaveBar, useGroup } from './components';

const TYPE_LABELS = {
	percent: __( 'Percentage off', 'dev3-ai-smart-coupons-for-woocommerce' ),
	fixed_cart: __( 'Fixed amount off the cart', 'dev3-ai-smart-coupons-for-woocommerce' ),
	free_shipping: __( 'Free shipping', 'dev3-ai-smart-coupons-for-woocommerce' ),
};
export const typeLabel = ( t ) => TYPE_LABELS[ t ] || t;

function Simulator() {
	const [ input, setInput ] = useState( { cart_value: 120, cost_percent: 45, idle_seconds: 60, trigger: 'exit_intent', page_type: 'cart', cart_visits: 1, returning: false, order_count: 0 } );
	const [ result, setResult ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( null );
	const set = ( k ) => ( v ) => setInput( { ...input, [ k ]: v } );
	const run = ( useAi ) => {
		setBusy( useAi ? 'ai' : 'rules' );
		setError( null );
		request( 'simulate', { method: 'POST', data: { ...input, use_ai: useAi } } )
			.then( setResult )
			.catch( setError )
			.finally( () => setBusy( '' ) );
	};
	return (
		<Card
			title={ __( 'Risk & offer simulator', 'dev3-ai-smart-coupons-for-woocommerce' ) }
			description={ __( 'Try a hypothetical shopper against your saved rules. Nothing is created — no coupon, no budget used.', 'dev3-ai-smart-coupons-for-woocommerce' ) }
		>
			<div className="dev3sc-grid dev3sc-grid-cols-2 md:dev3sc-grid-cols-4 dev3sc-gap-4">
				<NumberField label={ __( 'Cart value', 'dev3-ai-smart-coupons-for-woocommerce' ) } value={ input.cart_value } onChange={ set( 'cart_value' ) } min={ 0 } />
				<NumberField label={ __( 'Product cost (% of price)', 'dev3-ai-smart-coupons-for-woocommerce' ) } value={ input.cost_percent } onChange={ set( 'cost_percent' ) } min={ 0 } max={ 200 } />
				<NumberField label={ __( 'Idle seconds', 'dev3-ai-smart-coupons-for-woocommerce' ) } value={ input.idle_seconds } onChange={ set( 'idle_seconds' ) } min={ 0 } />
				<NumberField label={ __( 'Previous orders', 'dev3-ai-smart-coupons-for-woocommerce' ) } value={ input.order_count } onChange={ set( 'order_count' ) } min={ 0 } />
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Trigger', 'dev3-ai-smart-coupons-for-woocommerce' ) }
					value={ input.trigger }
					onChange={ set( 'trigger' ) }
					options={ [
						{ value: 'exit_intent', label: __( 'Exit intent (desktop)', 'dev3-ai-smart-coupons-for-woocommerce' ) },
						{ value: 'scroll_up', label: __( 'Fast scroll up (mobile)', 'dev3-ai-smart-coupons-for-woocommerce' ) },
						{ value: 'tab_hidden', label: __( 'Tab hidden (mobile)', 'dev3-ai-smart-coupons-for-woocommerce' ) },
						{ value: 'idle', label: __( 'Idle', 'dev3-ai-smart-coupons-for-woocommerce' ) },
					] }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Page', 'dev3-ai-smart-coupons-for-woocommerce' ) }
					value={ input.page_type }
					onChange={ set( 'page_type' ) }
					options={ [ 'cart', 'checkout', 'product', 'shop', 'home' ].map( ( p ) => ( { value: p, label: p } ) ) }
				/>
				<NumberField label={ __( 'Cart page views', 'dev3-ai-smart-coupons-for-woocommerce' ) } value={ input.cart_visits } onChange={ set( 'cart_visits' ) } min={ 0 } />
				<div className="dev3sc-pt-6">
					<ToggleControl __nextHasNoMarginBottom label={ __( 'Returning visitor', 'dev3-ai-smart-coupons-for-woocommerce' ) } checked={ input.returning } onChange={ set( 'returning' ) } />
				</div>
			</div>
			<div className="dev3sc-flex dev3sc-gap-2 dev3sc-mt-4">
				<Button variant="secondary" onClick={ () => run( false ) } isBusy={ busy === 'rules' } disabled={ !! busy }>
					{ __( 'Score with rules only', 'dev3-ai-smart-coupons-for-woocommerce' ) }
				</Button>
				<Button variant="primary" onClick={ () => run( true ) } isBusy={ busy === 'ai' } disabled={ !! busy }>
					{ __( 'Ask AI for an offer', 'dev3-ai-smart-coupons-for-woocommerce' ) }
				</Button>
			</div>
			{ error && <Notice status="error" isDismissible={ false }>{ error.message }</Notice> }
			{ result && (
				<div className="dev3sc-grid md:dev3sc-grid-cols-2 dev3sc-gap-6 dev3sc-mt-4">
					<div>
						<h3 className="dev3sc-mt-0">
							{ sprintf( /* translators: 1: score, 2: threshold */ __( 'Risk score %1$d / 100 (threshold %2$d)', 'dev3-ai-smart-coupons-for-woocommerce' ), result.risk.score, result.threshold ) }
						</h3>
						<div className="dev3sc-meter" aria-hidden="true">
							<span style={ { width: `${ result.risk.score }%` } } />
							<i style={ { left: `${ result.threshold }%` } } />
						</div>
						<table className="widefat striped dev3sc-mt-2">
							<tbody>
								{ result.risk.factors.map( ( f ) => (
									<tr key={ f.key }>
										<td>{ f.label }</td>
										<td className="dev3sc-text-right"><strong>{ f.points > 0 ? `+${ f.points }` : f.points }</strong></td>
									</tr>
								) ) }
							</tbody>
						</table>
						{ ! result.eligible && <p className="description">{ __( 'Below the threshold: a real shopper would not get an offer.', 'dev3-ai-smart-coupons-for-woocommerce' ) }</p> }
					</div>
					<div>
						<h3 className="dev3sc-mt-0">{ result.source === 'ai' ? __( 'AI offer (after safety rules)', 'dev3-ai-smart-coupons-for-woocommerce' ) : __( 'Rules-based offer', 'dev3-ai-smart-coupons-for-woocommerce' ) }</h3>
						{ result.ai_error && <Notice status="warning" isDismissible={ false }>{ result.ai_error }</Notice> }
						{ result.offer ? (
							<>
								<p className="dev3sc-text-lg dev3sc-m-0"><strong>{ result.offer.headline }</strong></p>
								<p className="dev3sc-mt-1">{ result.offer.message }</p>
								<p>
									<span className="dev3sc-badge is-ok">{ result.offer.discount_label }</span>{ ' ' }
									{ sprintf( /* translators: 1: money, 2: minutes */ __( 'Est. cost %1$s · expires in %2$d min', 'dev3-ai-smart-coupons-for-woocommerce' ), money( result.offer.discount_value ), result.offer.urgency_minutes ) }
								</p>
								{ result.proposal && (
									<p className="description">
										{ sprintf( /* translators: 1: type, 2: amount */ __( 'The model proposed %1$s %2$s.', 'dev3-ai-smart-coupons-for-woocommerce' ), typeLabel( result.proposal.type ), result.proposal.amount ) }{ ' ' }
										{ result.offer.adjustments.length > 0 && __( 'The safety rules adjusted it.', 'dev3-ai-smart-coupons-for-woocommerce' ) }
										{ result.proposal.reason && <><br />{ result.proposal.reason }</> }
									</p>
								) }
							</>
						) : (
							<Notice status="info" isDismissible={ false }>{ result.offer_error || __( 'No offer fits the rules for this cart.', 'dev3-ai-smart-coupons-for-woocommerce' ) }</Notice>
						) }
					</div>
				</div>
			) }
		</Card>
	);
}

export default function RulesTab( { settings, save } ) {
	const { value: r, set, dirty, busy, submit } = useGroup( settings, 'rules', save );
	const cur = adminCfg.currency || '$';
	const toggleType = ( t ) => ( on ) => set( 'allowed_types' )( on ? [ ...new Set( [ ...r.allowed_types, t ] ) ] : r.allowed_types.filter( ( x ) => x !== t ) );
	return (
		<>
			<Card title={ __( 'Incentive types & caps', 'dev3-ai-smart-coupons-for-woocommerce' ) } description={ __( 'The AI only suggests; every offer is clamped to these limits on the server.', 'dev3-ai-smart-coupons-for-woocommerce' ) }>
				<div className="dev3sc-flex dev3sc-gap-6 dev3sc-flex-wrap dev3sc-mb-4">
					{ Object.keys( TYPE_LABELS ).map( ( t ) => (
						<CheckboxControl __nextHasNoMarginBottom key={ t } label={ TYPE_LABELS[ t ] } checked={ r.allowed_types.includes( t ) } onChange={ toggleType( t ) } />
					) ) }
				</div>
				{ r.allowed_types.includes( 'free_shipping' ) && ! adminCfg.freeShipping && (
					<Notice status="warning" isDismissible={ false }>
						{ __( 'Free-shipping offers need a “Free shipping” method that requires “A valid free shipping coupon” in at least one shipping zone.', 'dev3-ai-smart-coupons-for-woocommerce' ) }{ ' ' }
						<a href={ adminCfg.shippingUrl }>{ __( 'Shipping settings', 'dev3-ai-smart-coupons-for-woocommerce' ) }</a>
					</Notice>
				) }
				<div className="dev3sc-grid dev3sc-grid-cols-2 md:dev3sc-grid-cols-4 dev3sc-gap-4">
					<NumberField label={ __( 'Min percentage', 'dev3-ai-smart-coupons-for-woocommerce' ) } suffix="%" value={ r.min_percent } onChange={ set( 'min_percent' ) } min={ 1 } max={ 90 } />
					<NumberField label={ __( 'Max percentage', 'dev3-ai-smart-coupons-for-woocommerce' ) } suffix="%" value={ r.max_percent } onChange={ set( 'max_percent' ) } min={ 1 } max={ 90 } />
					<NumberField label={ sprintf( /* translators: %s: currency */ __( 'Min fixed (%s)', 'dev3-ai-smart-coupons-for-woocommerce' ), cur ) } value={ r.min_fixed } onChange={ set( 'min_fixed' ) } min={ 0 } step={ 0.5 } />
					<NumberField label={ sprintf( /* translators: %s: currency */ __( 'Max fixed (%s)', 'dev3-ai-smart-coupons-for-woocommerce' ), cur ) } value={ r.max_fixed } onChange={ set( 'max_fixed' ) } min={ 0 } step={ 0.5 } />
					<NumberField label={ sprintf( /* translators: %s: currency */ __( 'Minimum spend (%s)', 'dev3-ai-smart-coupons-for-woocommerce' ), cur ) } value={ r.min_spend } onChange={ set( 'min_spend' ) } min={ 0 } />
					<NumberField
						label={ sprintf( /* translators: %s: currency */ __( 'Daily discount budget (%s)', 'dev3-ai-smart-coupons-for-woocommerce' ), cur ) }
						help={ __( '0 = unlimited. Resets at midnight.', 'dev3-ai-smart-coupons-for-woocommerce' ) }
						value={ r.daily_budget }
						onChange={ set( 'daily_budget' ) }
						min={ 0 }
					/>
					<NumberField label={ __( 'Offer window min (minutes)', 'dev3-ai-smart-coupons-for-woocommerce' ) } value={ r.urgency_min } onChange={ set( 'urgency_min' ) } min={ 1 } max={ 1440 } />
					<NumberField label={ __( 'Offer window max (minutes)', 'dev3-ai-smart-coupons-for-woocommerce' ) } value={ r.urgency_max } onChange={ set( 'urgency_max' ) } min={ 1 } max={ 1440 } />
				</div>
				<p className="description">{ __( 'Fixed discounts are also capped at the maximum percentage of the discountable cart.', 'dev3-ai-smart-coupons-for-woocommerce' ) }</p>
			</Card>

			<Card title={ __( 'Margin protection', 'dev3-ai-smart-coupons-for-woocommerce' ) } description={ __( 'A discount never pushes any product below its cost plus your minimum margin. Products with no room are left out of the coupon.', 'dev3-ai-smart-coupons-for-woocommerce' ) }>
				<div className="dev3sc-grid md:dev3sc-grid-cols-3 dev3sc-gap-4">
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Cost meta key', 'dev3-ai-smart-coupons-for-woocommerce' ) }
						help={ __( 'e.g. _wc_cog_cost (Cost of Goods), _alg_wc_cog_cost, _purchase_price', 'dev3-ai-smart-coupons-for-woocommerce' ) }
						value={ r.cost_meta_key }
						onChange={ set( 'cost_meta_key' ) }
					/>
					<NumberField label={ __( 'Minimum margin over cost', 'dev3-ai-smart-coupons-for-woocommerce' ) } suffix="%" value={ r.min_margin_percent } onChange={ set( 'min_margin_percent' ) } min={ 0 } max={ 500 } />
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Products without a cost', 'dev3-ai-smart-coupons-for-woocommerce' ) }
						value={ r.missing_cost }
						onChange={ set( 'missing_cost' ) }
						options={ [
							{ value: 'allow', label: __( 'Discount them (caps still apply)', 'dev3-ai-smart-coupons-for-woocommerce' ) },
							{ value: 'skip', label: __( 'Never discount them', 'dev3-ai-smart-coupons-for-woocommerce' ) },
						] }
					/>
				</div>
			</Card>

			<Card title={ __( 'Exclusions & coupon settings', 'dev3-ai-smart-coupons-for-woocommerce' ) }>
				<div className="dev3sc-grid md:dev3sc-grid-cols-2 dev3sc-gap-4">
					<IdPicker type="products" label={ __( 'Excluded products', 'dev3-ai-smart-coupons-for-woocommerce' ) } ids={ r.excluded_product_ids } onChange={ set( 'excluded_product_ids' ) } />
					<IdPicker type="categories" label={ __( 'Excluded categories', 'dev3-ai-smart-coupons-for-woocommerce' ) } ids={ r.excluded_category_ids } onChange={ set( 'excluded_category_ids' ) } />
				</div>
				<div className="dev3sc-grid md:dev3sc-grid-cols-3 dev3sc-gap-4 dev3sc-mt-4">
					<ToggleControl __nextHasNoMarginBottom label={ __( 'Exclude sale items', 'dev3-ai-smart-coupons-for-woocommerce' ) } checked={ r.exclude_sale_items } onChange={ set( 'exclude_sale_items' ) } />
					<ToggleControl __nextHasNoMarginBottom label={ __( 'Individual use only', 'dev3-ai-smart-coupons-for-woocommerce' ) } help={ __( 'Cannot be combined with other coupons.', 'dev3-ai-smart-coupons-for-woocommerce' ) } checked={ r.individual_use } onChange={ set( 'individual_use' ) } />
					<ToggleControl __nextHasNoMarginBottom label={ __( 'One offer per IP per day', 'dev3-ai-smart-coupons-for-woocommerce' ) } help={ __( 'In addition to one per session and email.', 'dev3-ai-smart-coupons-for-woocommerce' ) } checked={ r.limit_per_ip } onChange={ set( 'limit_per_ip' ) } />
					<TextControl __nextHasNoMarginBottom label={ __( 'Coupon code prefix', 'dev3-ai-smart-coupons-for-woocommerce' ) } value={ r.code_prefix } onChange={ set( 'code_prefix' ) } maxLength={ 12 } />
					<NumberField label={ __( 'Delete expired unused coupons after (days)', 'dev3-ai-smart-coupons-for-woocommerce' ) } value={ r.retention_days } onChange={ set( 'retention_days' ) } min={ 0 } max={ 365 } />
					<NumberField label={ sprintf( /* translators: %s: currency */ __( 'Free-shipping cost estimate (%s)', 'dev3-ai-smart-coupons-for-woocommerce' ), cur ) } value={ r.free_shipping_estimate } onChange={ set( 'free_shipping_estimate' ) } min={ 0 } />
				</div>
			</Card>

			<Card title={ __( 'When to make an offer', 'dev3-ai-smart-coupons-for-woocommerce' ) }>
				<RangeControl
					__nextHasNoMarginBottom
					label={ __( 'Risk threshold', 'dev3-ai-smart-coupons-for-woocommerce' ) }
					help={ __( 'Only shoppers whose abandonment risk score reaches this value get an offer. Higher = fewer, more targeted offers.', 'dev3-ai-smart-coupons-for-woocommerce' ) }
					value={ r.risk_threshold }
					onChange={ set( 'risk_threshold' ) }
					min={ 0 }
					max={ 100 }
				/>
				<div className="dev3sc-grid md:dev3sc-grid-cols-2 dev3sc-gap-4 dev3sc-mt-4">
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'If the AI is off or fails', 'dev3-ai-smart-coupons-for-woocommerce' ) }
						value={ r.fallback }
						onChange={ set( 'fallback' ) }
						options={ [
							{ value: 'deterministic', label: __( 'Make a rules-based offer (scaled by risk)', 'dev3-ai-smart-coupons-for-woocommerce' ) },
							{ value: 'none', label: __( 'Make no offer', 'dev3-ai-smart-coupons-for-woocommerce' ) },
						] }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Preferred rules-based type', 'dev3-ai-smart-coupons-for-woocommerce' ) }
						value={ r.fallback_type }
						onChange={ set( 'fallback_type' ) }
						options={ Object.keys( TYPE_LABELS ).map( ( t ) => ( { value: t, label: TYPE_LABELS[ t ] } ) ) }
					/>
				</div>
			</Card>
			<SaveBar onSave={ submit } busy={ busy } dirty={ dirty } />
			<Simulator />
		</>
	);
}
