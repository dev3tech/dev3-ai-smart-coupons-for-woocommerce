import { SelectControl, TextControl, ToggleControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Card, NumberField, SaveBar, useGroup } from './components';

export default function TriggersTab( { settings, save } ) {
	const { value: t, set, dirty, busy, submit } = useGroup( settings, 'triggers', save );
	const [ flags, setFlags ] = useState( { enabled: settings.enabled, ai_enabled: settings.ai_enabled } );
	const flag = ( k ) => ( v ) => {
		setFlags( { ...flags, [ k ]: v } );
		save( { [ k ]: v } );
	};
	return (
		<>
			<Card title={ __( 'Status', 'dev3-ai-smart-coupons-for-woocommerce' ) }>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Smart offers enabled', 'dev3-ai-smart-coupons-for-woocommerce' ) }
					help={ __( 'Turn off to stop tracking and new offers immediately (flash sales keep running).', 'dev3-ai-smart-coupons-for-woocommerce' ) }
					checked={ flags.enabled }
					onChange={ flag( 'enabled' ) }
				/>
				<div className="dev3sc-mt-4">
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Let AI choose the incentive and write the copy', 'dev3-ai-smart-coupons-for-woocommerce' ) }
						help={ __( 'Off = rules-based offers only (no API calls from the storefront).', 'dev3-ai-smart-coupons-for-woocommerce' ) }
						checked={ flags.ai_enabled }
						onChange={ flag( 'ai_enabled' ) }
					/>
				</div>
			</Card>
			<Card title={ __( 'Intent triggers', 'dev3-ai-smart-coupons-for-woocommerce' ) } description={ __( 'The storefront script watches for these signals while the cart has items. It sends one small request only when a trigger fires.', 'dev3-ai-smart-coupons-for-woocommerce' ) }>
				<div className="dev3sc-grid md:dev3sc-grid-cols-3 dev3sc-gap-4">
					<ToggleControl __nextHasNoMarginBottom label={ __( 'Exit intent (desktop)', 'dev3-ai-smart-coupons-for-woocommerce' ) } help={ __( 'Pointer leaves through the top of the window.', 'dev3-ai-smart-coupons-for-woocommerce' ) } checked={ t.exit_intent } onChange={ set( 'exit_intent' ) } />
					<ToggleControl __nextHasNoMarginBottom label={ __( 'Exit signals (mobile)', 'dev3-ai-smart-coupons-for-woocommerce' ) } help={ __( 'Fast scroll up or switching away from the tab.', 'dev3-ai-smart-coupons-for-woocommerce' ) } checked={ t.mobile_exit } onChange={ set( 'mobile_exit' ) } />
					<ToggleControl __nextHasNoMarginBottom label={ __( 'Idle time', 'dev3-ai-smart-coupons-for-woocommerce' ) } help={ __( 'No mouse, touch, scroll or key activity.', 'dev3-ai-smart-coupons-for-woocommerce' ) } checked={ t.idle } onChange={ set( 'idle' ) } />
				</div>
				<div className="dev3sc-grid md:dev3sc-grid-cols-3 dev3sc-gap-4 dev3sc-mt-4">
					<NumberField label={ __( 'Idle after (seconds)', 'dev3-ai-smart-coupons-for-woocommerce' ) } value={ t.idle_seconds } onChange={ set( 'idle_seconds' ) } min={ 5 } max={ 600 } />
					<NumberField label={ __( 'Minimum time on page (seconds)', 'dev3-ai-smart-coupons-for-woocommerce' ) } value={ t.min_dwell } onChange={ set( 'min_dwell' ) } min={ 0 } max={ 120 } />
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Run the tracker on', 'dev3-ai-smart-coupons-for-woocommerce' ) }
						value={ t.pages }
						onChange={ set( 'pages' ) }
						options={ [
							{ value: 'all', label: __( 'All pages', 'dev3-ai-smart-coupons-for-woocommerce' ) },
							{ value: 'shop', label: __( 'Shop, product, cart and checkout', 'dev3-ai-smart-coupons-for-woocommerce' ) },
							{ value: 'cart_checkout', label: __( 'Cart and checkout only', 'dev3-ai-smart-coupons-for-woocommerce' ) },
						] }
					/>
				</div>
			</Card>
			<Card title={ __( 'Privacy & consent', 'dev3-ai-smart-coupons-for-woocommerce' ) } description={ __( 'The tracker stores no personal data. You can still require consent before it runs.', 'dev3-ai-smart-coupons-for-woocommerce' ) }>
				<ToggleControl __nextHasNoMarginBottom label={ __( 'Respect Do Not Track / Global Privacy Control', 'dev3-ai-smart-coupons-for-woocommerce' ) } checked={ t.respect_dnt } onChange={ set( 'respect_dnt' ) } />
				<div className="dev3sc-grid md:dev3sc-grid-cols-2 dev3sc-gap-4 dev3sc-mt-4">
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Consent', 'dev3-ai-smart-coupons-for-woocommerce' ) }
						value={ t.consent }
						onChange={ set( 'consent' ) }
						options={ [
							{ value: 'none', label: __( 'Not required', 'dev3-ai-smart-coupons-for-woocommerce' ) },
							{ value: 'cookie', label: __( 'Require a consent cookie', 'dev3-ai-smart-coupons-for-woocommerce' ) },
						] }
					/>
					{ t.consent === 'cookie' && (
						<TextControl
							__nextHasNoMarginBottom
							label={ __( 'Consent cookie', 'dev3-ai-smart-coupons-for-woocommerce' ) }
							help={ __( 'Cookie name, or name=text that the value must contain (e.g. cookieyes-consent=analytics:yes). Your consent tool can also set window.dev3scConsent = true.', 'dev3-ai-smart-coupons-for-woocommerce' ) }
							value={ t.consent_cookie }
							onChange={ set( 'consent_cookie' ) }
						/>
					) }
				</div>
			</Card>
			<SaveBar onSave={ submit } busy={ busy } dirty={ dirty } />
		</>
	);
}
