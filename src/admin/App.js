import { useEffect, useState } from '@wordpress/element';
import { Notice, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import ApiTab from '../core3/ApiTab';
import UsageTab from '../core3/UsageTab';
import AnalyticsTab from './AnalyticsTab';
import RulesTab from './RulesTab';
import TriggersTab from './TriggersTab';
import BannerTab from './BannerTab';
import FlashTab from './FlashTab';
import { adminCfg } from './components';
import './admin.css';

const TABS = [
	{ id: 'analytics', label: __( 'Analytics', 'dev3-ai-smart-coupons-for-woocommerce' ) },
	{ id: 'rules', label: __( 'Rules', 'dev3-ai-smart-coupons-for-woocommerce' ) },
	{ id: 'triggers', label: __( 'Triggers', 'dev3-ai-smart-coupons-for-woocommerce' ) },
	{ id: 'banner', label: __( 'Banner design', 'dev3-ai-smart-coupons-for-woocommerce' ) },
	{ id: 'flash', label: __( 'Flash sales', 'dev3-ai-smart-coupons-for-woocommerce' ) },
	{ id: 'api', label: __( 'API Config', 'dev3-ai-smart-coupons-for-woocommerce' ) },
	{ id: 'usage', label: __( 'Usage Logs', 'dev3-ai-smart-coupons-for-woocommerce' ) },
];

const fromHash = () => {
	const id = window.location.hash.replace( /^#\/?/, '' );
	return TABS.some( ( t ) => t.id === id ) ? id : TABS[ 0 ].id;
};

export default function App() {
	const [ tab, setTab ] = useState( fromHash() );
	const [ settings, setSettings ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ saved, setSaved ] = useState( false );

	useEffect( () => {
		const onHash = () => setTab( fromHash() );
		window.addEventListener( 'hashchange', onHash );
		request( 'settings' ).then( setSettings ).catch( setError );
		return () => window.removeEventListener( 'hashchange', onHash );
	}, [] );

	const save = ( patch ) =>
		request( 'settings', { method: 'POST', data: patch } )
			.then( ( next ) => {
				setSettings( next );
				setSaved( true );
				setTimeout( () => setSaved( false ), 2500 );
				return next;
			} )
			.catch( ( err ) => {
				setError( err );
				throw err;
			} );

	const needsKey = settings && ! settings.api_key_set && settings.ai_enabled && tab !== 'api';
	return (
		<div className="dev3sc-root">
			<h1 className="wp-heading-inline">
				{ __( 'DEV3 AI Smart Coupons & Dynamic Discounts', 'dev3-ai-smart-coupons-for-woocommerce' ) } <span className="dev3sc-text-xs dev3sc-text-gray-500">v{ config.version }</span>
			</h1>
			<nav className="dev3sc-admin-nav" aria-label={ __( 'Sections', 'dev3-ai-smart-coupons-for-woocommerce' ) }>
				{ TABS.map( ( t ) => (
					<a key={ t.id } href={ `#/${ t.id }` } className={ tab === t.id ? 'is-active' : '' } aria-current={ tab === t.id ? 'page' : undefined }>
						{ t.label }
					</a>
				) ) }
			</nav>
			{ adminCfg.woo === false && <Notice status="error" isDismissible={ false }>{ __( 'WooCommerce is not active. Activate it to generate offers.', 'dev3-ai-smart-coupons-for-woocommerce' ) }</Notice> }
			{ needsKey && (
				<Notice status="info" isDismissible={ false }>
					{ __( 'Offers currently use your rules only. Add an API key to let AI pick the incentive and write the copy.', 'dev3-ai-smart-coupons-for-woocommerce' ) } <a href="#/api">{ __( 'Add API key', 'dev3-ai-smart-coupons-for-woocommerce' ) }</a>
				</Notice>
			) }
			{ error && <Notice status="error" onRemove={ () => setError( null ) }>{ error.message } { error.hint }</Notice> }
			{ saved && <Notice status="success" isDismissible={ false }>{ __( 'Settings saved.', 'dev3-ai-smart-coupons-for-woocommerce' ) }</Notice> }
			{ ! settings && ! error && <Spinner /> }
			{ settings && tab === 'analytics' && <AnalyticsTab /> }
			{ settings && tab === 'rules' && <RulesTab settings={ settings } save={ save } /> }
			{ settings && tab === 'triggers' && <TriggersTab settings={ settings } save={ save } /> }
			{ settings && tab === 'banner' && <BannerTab settings={ settings } save={ save } /> }
			{ settings && tab === 'flash' && <FlashTab settings={ settings } /> }
			{ settings && tab === 'api' && <ApiTab settings={ settings } save={ save } /> }
			{ tab === 'usage' && <UsageTab /> }
		</div>
	);
}
