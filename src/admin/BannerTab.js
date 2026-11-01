import { ColorPicker, Dropdown, Button, Notice, RangeControl, SelectControl, TextControl, TextareaControl, ToggleControl } from '@wordpress/components';
import { useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { offerBanner } from '../storefront/banner';
import { Card, contrast, SaveBar, useGroup } from './components';

function ColorField( { label, value, onChange } ) {
	return (
		<div className="dev3sc-color">
			<span className="dev3sc-color__label">{ label }</span>
			<Dropdown
				popoverProps={ { placement: 'bottom-start' } }
				renderToggle={ ( { isOpen, onToggle } ) => (
					<Button className="dev3sc-color__btn" onClick={ onToggle } aria-expanded={ isOpen } aria-label={ `${ label }: ${ value }` }>
						<span className="dev3sc-color__swatch" style={ { background: value } } />
						<code>{ value }</code>
					</Button>
				) }
				renderContent={ () => <ColorPicker color={ value } onChange={ onChange } enableAlpha={ false } /> }
			/>
		</div>
	);
}

export function Preview( { banner, strings } ) {
	const stage = useRef( null );
	useEffect( () => {
		if ( ! stage.current ) {
			return;
		}
		const fill = ( t ) => String( t || '' ).replace( '{discount}', '10% off' ).replace( '{minutes}', '15' );
		const design = { position: banner.position, bg: banner.bg, text: banner.text, accent: banner.accent, accentText: banner.accent_text, radius: banner.radius, countdown: banner.show_countdown };
		const { root } = offerBanner(
			{ id: 0, code: 'SAVE-K7M2Q9XA', headline: fill( banner.fallback_heading ), message: fill( banner.fallback_message ), cta: banner.fallback_cta },
			design,
			strings,
			{ seconds: 14 * 60 + 52, applyHref: '#' }
		);
		root.querySelectorAll( 'a, button' ).forEach( ( n ) => n.addEventListener( 'click', ( e ) => e.preventDefault() ) );
		stage.current.replaceChildren( root );
	}, [ banner, strings ] );
	return (
		<div className={ `dev3sc-stage dev3sc-stage--${ banner.position }` } aria-label={ __( 'Live preview', 'dev3-ai-smart-coupons-for-woocommerce' ) }>
			<div className="dev3sc-stage__page" aria-hidden="true">
				<span />
				<span />
				<span />
			</div>
			<div ref={ stage } />
		</div>
	);
}

const STRINGS = {
	expires: __( 'Offer ends in', 'dev3-ai-smart-coupons-for-woocommerce' ),
	code: __( 'Code', 'dev3-ai-smart-coupons-for-woocommerce' ),
	offer: __( 'Personal offer', 'dev3-ai-smart-coupons-for-woocommerce' ),
	dismiss: __( 'Dismiss offer', 'dev3-ai-smart-coupons-for-woocommerce' ),
	applied: __( 'Discount applied', 'dev3-ai-smart-coupons-for-woocommerce' ),
};

export default function BannerTab( { settings, save } ) {
	const { value: b, set, dirty, busy, submit } = useGroup( settings, 'banner', save );
	const ratios = [
		[ __( 'Text on background', 'dev3-ai-smart-coupons-for-woocommerce' ), contrast( b.text, b.bg ) ],
		[ __( 'Button text on button', 'dev3-ai-smart-coupons-for-woocommerce' ), contrast( b.accent_text, b.accent ) ],
	];
	const low = ratios.filter( ( r ) => r[ 1 ] < 4.5 );
	return (
		<div className="dev3sc-grid lg:dev3sc-grid-cols-2 dev3sc-gap-5 dev3sc-items-start">
			<div>
				<Card title={ __( 'Layout', 'dev3-ai-smart-coupons-for-woocommerce' ) } description={ __( 'Offers that appear mid-visit float over the page (no layout shift). On cart and checkout the active offer is also printed inline.', 'dev3-ai-smart-coupons-for-woocommerce' ) }>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Floating style', 'dev3-ai-smart-coupons-for-woocommerce' ) }
						value={ b.position }
						onChange={ set( 'position' ) }
						options={ [
							{ value: 'bottom', label: __( 'Bottom bar', 'dev3-ai-smart-coupons-for-woocommerce' ) },
							{ value: 'top', label: __( 'Top bar', 'dev3-ai-smart-coupons-for-woocommerce' ) },
							{ value: 'corner', label: __( 'Corner card', 'dev3-ai-smart-coupons-for-woocommerce' ) },
							{ value: 'modal', label: __( 'Modal', 'dev3-ai-smart-coupons-for-woocommerce' ) },
						] }
					/>
					<div className="dev3sc-grid dev3sc-grid-cols-2 dev3sc-gap-4 dev3sc-mt-4">
						<ToggleControl __nextHasNoMarginBottom label={ __( 'Inline on cart', 'dev3-ai-smart-coupons-for-woocommerce' ) } checked={ b.inline_cart } onChange={ set( 'inline_cart' ) } />
						<ToggleControl __nextHasNoMarginBottom label={ __( 'Inline on checkout', 'dev3-ai-smart-coupons-for-woocommerce' ) } checked={ b.inline_checkout } onChange={ set( 'inline_checkout' ) } />
						<ToggleControl __nextHasNoMarginBottom label={ __( 'Show countdown', 'dev3-ai-smart-coupons-for-woocommerce' ) } checked={ b.show_countdown } onChange={ set( 'show_countdown' ) } />
					</div>
				</Card>
				<Card title={ __( 'Colours', 'dev3-ai-smart-coupons-for-woocommerce' ) }>
					<div className="dev3sc-grid dev3sc-grid-cols-2 dev3sc-gap-4">
						<ColorField label={ __( 'Background', 'dev3-ai-smart-coupons-for-woocommerce' ) } value={ b.bg } onChange={ set( 'bg' ) } />
						<ColorField label={ __( 'Text', 'dev3-ai-smart-coupons-for-woocommerce' ) } value={ b.text } onChange={ set( 'text' ) } />
						<ColorField label={ __( 'Button', 'dev3-ai-smart-coupons-for-woocommerce' ) } value={ b.accent } onChange={ set( 'accent' ) } />
						<ColorField label={ __( 'Button text', 'dev3-ai-smart-coupons-for-woocommerce' ) } value={ b.accent_text } onChange={ set( 'accent_text' ) } />
					</div>
					<RangeControl __nextHasNoMarginBottom className="dev3sc-mt-4" label={ __( 'Corner radius', 'dev3-ai-smart-coupons-for-woocommerce' ) } value={ b.radius } onChange={ set( 'radius' ) } min={ 0 } max={ 32 } />
					{ low.length > 0 && (
						<Notice status="warning" isDismissible={ false }>
							{ low.map( ( r ) => sprintf( /* translators: 1: pair, 2: ratio */ __( '%1$s contrast is %2$s:1 — aim for at least 4.5:1 so everyone can read it.', 'dev3-ai-smart-coupons-for-woocommerce' ), r[ 0 ], r[ 1 ].toFixed( 1 ) ) ).join( ' ' ) }
						</Notice>
					) }
				</Card>
				<Card title={ __( 'Copy', 'dev3-ai-smart-coupons-for-woocommerce' ) } description={ __( 'Used for rules-based offers and whenever AI copy is rejected. Placeholders: {discount}, {minutes}.', 'dev3-ai-smart-coupons-for-woocommerce' ) }>
					<TextControl __nextHasNoMarginBottom label={ __( 'Headline', 'dev3-ai-smart-coupons-for-woocommerce' ) } value={ b.fallback_heading } onChange={ set( 'fallback_heading' ) } maxLength={ 80 } />
					<div className="dev3sc-mt-4">
						<TextareaControl __nextHasNoMarginBottom label={ __( 'Message', 'dev3-ai-smart-coupons-for-woocommerce' ) } value={ b.fallback_message } onChange={ set( 'fallback_message' ) } rows={ 2 } maxLength={ 180 } />
					</div>
					<div className="dev3sc-mt-4">
						<TextControl __nextHasNoMarginBottom label={ __( 'Button label', 'dev3-ai-smart-coupons-for-woocommerce' ) } value={ b.fallback_cta } onChange={ set( 'fallback_cta' ) } maxLength={ 30 } />
					</div>
					<div className="dev3sc-mt-4">
						<TextareaControl
							__nextHasNoMarginBottom
							label={ __( 'Brand voice for AI copy', 'dev3-ai-smart-coupons-for-woocommerce' ) }
							help={ __( 'e.g. “Warm, playful, never pushy. We say ‘friend’, not ‘customer’.”', 'dev3-ai-smart-coupons-for-woocommerce' ) }
							value={ b.brand_voice }
							onChange={ set( 'brand_voice' ) }
							rows={ 2 }
							maxLength={ 300 }
						/>
					</div>
				</Card>
				<SaveBar onSave={ submit } busy={ busy } dirty={ dirty } />
			</div>
			<div className="dev3sc-sticky">
				<Card title={ __( 'Live preview', 'dev3-ai-smart-coupons-for-woocommerce' ) } description={ __( 'Exactly what shoppers see (sample code and offer).', 'dev3-ai-smart-coupons-for-woocommerce' ) }>
					<Preview banner={ b } strings={ STRINGS } />
				</Card>
			</div>
		</div>
	);
}
