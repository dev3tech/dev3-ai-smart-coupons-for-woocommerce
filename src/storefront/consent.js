/**
 * Consent / Do-Not-Track gate for the tracker (flash-sale banners do not track and always show).
 *
 * @param {Object} cfg            consent config { mode: 'none'|'cookie', cookie: 'name' or 'name=value', respectDnt }.
 * @param {Object} env            { cookie: string, navigator, win }.
 * @return {boolean} Tracking allowed.
 */
export function trackingAllowed( cfg, env ) {
	const c = cfg || {};
	const nav = env.navigator || {};
	const win = env.win || {};
	if ( win.dev3scConsent === false ) {
		return false;
	}
	if ( c.respectDnt ) {
		const dnt = nav.doNotTrack === '1' || nav.doNotTrack === 'yes' || win.doNotTrack === '1' || nav.msDoNotTrack === '1';
		if ( dnt || nav.globalPrivacyControl === true ) {
			return false;
		}
	}
	if ( c.mode !== 'cookie' ) {
		return true;
	}
	if ( win.dev3scConsent === true ) {
		return true;
	}
	return hasConsentCookie( c.cookie, env.cookie || '' );
}

/**
 * Cookie check: "name" (present and not "0/false/no") or "name=fragment" (value contains fragment).
 *
 * @param {string} rule   Rule.
 * @param {string} cookie document.cookie.
 * @return {boolean} Match.
 */
export function hasConsentCookie( rule, cookie ) {
	if ( ! rule ) {
		return false;
	}
	const [ name, fragment ] = String( rule ).split( /=(.*)/s );
	const pair = String( cookie )
		.split( ';' )
		.map( ( p ) => p.trim() )
		.find( ( p ) => p.split( '=' )[ 0 ] === name );
	if ( ! pair ) {
		return false;
	}
	let value = pair.slice( name.length + 1 );
	try {
		value = decodeURIComponent( value );
	} catch ( e ) {}
	if ( fragment ) {
		return value.includes( fragment );
	}
	return ! [ '', '0', 'false', 'no', 'deny', 'denied' ].includes( value.toLowerCase() );
}

/**
 * WooCommerce sets this cookie while the cart has items (works with page caching and blocks).
 *
 * @param {string} cookie document.cookie.
 * @return {boolean} Cart has items.
 */
export function cartHasItems( cookie ) {
	return /(?:^|;\s*)woocommerce_items_in_cart=1(?:;|$)/.test( String( cookie ) );
}
