/**
 * Storefront runtime: flash-sale bars, personal-offer banners, intent tracker.
 * Vanilla JS, no jQuery. Sends a request only when a trigger fires.
 */
import { createTriggerEngine } from './triggers';
import { clockOffset, startCountdown } from './countdown';
import { cartHasItems, trackingAllowed } from './consent';
import { flashBar, offerBanner, setCountdown, el } from './banner';

const OFFER_KEY = 'dev3sc_offer';
const DISMISS_KEY = 'dev3sc_dismissed';
const FLASH_KEY = 'dev3sc_flash_closed';
const VISITS_KEY = 'dev3sc_cart_visits';
const SEEN_KEY = 'dev3sc_seen';

const store = ( kind ) => {
	try {
		const s = window[ kind ];
		const probe = '__dev3sc';
		s.setItem( probe, '1' );
		s.removeItem( probe );
		return s;
	} catch ( e ) {
		return null;
	}
};

const readJSON = ( s, key, fallback ) => {
	try {
		const v = s && s.getItem( key );
		return v ? JSON.parse( v ) : fallback;
	} catch ( e ) {
		return fallback;
	}
};
const writeJSON = ( s, key, value ) => {
	try {
		if ( s ) {
			s.setItem( key, JSON.stringify( value ) );
		}
	} catch ( e ) {}
};

function blockCartStore() {
	try {
		if ( ! document.querySelector( '.wp-block-woocommerce-cart, .wp-block-woocommerce-checkout' ) ) {
			return null;
		}
		const d = window.wp && window.wp.data && window.wp.data.dispatch( 'wc/store/cart' );
		return d && typeof d.applyCoupon === 'function' ? d : null;
	} catch ( e ) {
		return null;
	}
}

function applyHref( cfg, offer ) {
	const onCartOrCheckout = cfg.page === 'cart' || cfg.page === 'checkout';
	const url = new URL( onCartOrCheckout ? window.location.href : offer.cart_url || cfg.cartUrl, window.location.href );
	url.searchParams.set( 'dev3sc_apply', offer.code );
	url.searchParams.set( 'dev3sc_token', offer.apply_token || '' );
	return url.toString();
}

function markApplied( container, strings ) {
	container.classList.add( 'is-applied' );
	container.querySelectorAll( '[data-dev3sc-apply]' ).forEach( ( a ) => {
		a.replaceWith( el( 'span', { className: 'dev3sc-applied', role: 'status', text: strings.applied || 'Discount applied' } ) );
	} );
}

function markExpired( container, strings ) {
	container.classList.add( 'is-expired' );
	const actions = container.querySelector( '.dev3sc-banner__actions' );
	if ( actions ) {
		actions.replaceChildren( el( 'span', { className: 'dev3sc-expired', role: 'status', text: strings.expired || 'This offer has expired.' } ) );
	}
}

/**
 * Wires apply click + countdown on a banner container.
 *
 * @param {HTMLElement} container Banner.
 * @param {Object}      offer     Offer.
 * @param {Object}      ctx       { cfg, strings, offset, session, onExpire }.
 */
function hydrate( container, offer, ctx ) {
	const { cfg, strings } = ctx;
	container.addEventListener( 'click', ( e ) => {
		const link = e.target.closest( '[data-dev3sc-apply]' );
		if ( ! link ) {
			return;
		}
		const cart = blockCartStore();
		if ( ! cart ) {
			return; // Normal navigation to the one-click apply URL.
		}
		e.preventDefault();
		link.setAttribute( 'aria-disabled', 'true' );
		link.textContent = strings.applying || 'Applying…';
		Promise.resolve( cart.applyCoupon( offer.code ) )
			.then( () => {
				markApplied( container, strings );
				writeJSON( ctx.session, OFFER_KEY, { ...readJSON( ctx.session, OFFER_KEY, {} ), applied: true } );
			} )
			.catch( () => {
				window.location.href = link.href;
			} );
	} );
	if ( ! container.querySelector( '.dev3sc-countdown' ) ) {
		return;
	}
	startCountdown( {
		expiresAt: offer.expires_at,
		offset: ctx.offset,
		onTick: ( left ) => setCountdown( container, left ),
		onEnd: () => {
			markExpired( container, strings );
			if ( ctx.onExpire ) {
				ctx.onExpire();
			}
		},
	} );
}

/**
 * Shows a floating banner / modal for an offer.
 *
 * @param {Object} offer Offer.
 * @param {Object} ctx   Context.
 */
function showFloating( offer, ctx ) {
	const { cfg, strings } = ctx;
	if ( document.querySelector( '[data-dev3sc-inline], .dev3sc-float' ) ) {
		return;
	}
	const now = Date.now();
	const seconds = Math.max( 0, Math.floor( ( offer.expires_at * 1000 - ( now + ctx.offset ) ) / 1000 ) );
	if ( seconds <= 0 ) {
		return;
	}
	const { root, panel } = offerBanner( offer, cfg.banner, strings, { seconds, applyHref: applyHref( cfg, offer ) } );
	const previous = document.activeElement;
	const close = () => {
		root.remove();
		const dismissed = readJSON( ctx.session, DISMISS_KEY, [] );
		writeJSON( ctx.session, DISMISS_KEY, [ ...dismissed, offer.id ].slice( -10 ) );
		document.removeEventListener( 'keydown', onKey );
		if ( previous && previous.focus ) {
			previous.focus();
		}
	};
	const onKey = ( e ) => {
		if ( e.key === 'Escape' ) {
			close();
		}
		if ( e.key === 'Tab' && cfg.banner.position === 'modal' ) {
			const focusable = panel.querySelectorAll( 'a[href], button' );
			const first = focusable[ 0 ];
			const last = focusable[ focusable.length - 1 ];
			if ( e.shiftKey && document.activeElement === first ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && document.activeElement === last ) {
				e.preventDefault();
				first.focus();
			}
		}
	};
	panel.querySelector( '[data-dev3sc-close]' ).addEventListener( 'click', close );
	if ( cfg.banner.position === 'modal' ) {
		root.addEventListener( 'click', ( e ) => e.target === root && close() );
	}
	document.addEventListener( 'keydown', onKey );
	// Live region announces the offer once; then it is a normal region.
	const live = el( 'div', { className: 'dev3sc-sr', 'aria-live': 'polite' } );
	document.body.appendChild( live );
	document.body.appendChild( root );
	window.setTimeout( () => {
		live.textContent = `${ offer.headline } ${ offer.message }`;
	}, 50 );
	if ( cfg.banner.position === 'modal' ) {
		const btn = panel.querySelector( '[data-dev3sc-apply], button' );
		if ( btn ) {
			btn.focus();
		}
	}
	hydrate( panel, offer, { ...ctx, onExpire: () => ctx.session && ctx.session.removeItem( OFFER_KEY ) } );
}

function renderFlash( cfg, strings, session ) {
	const sales = Array.isArray( cfg.flash ) ? cfg.flash : [];
	const closed = readJSON( session, FLASH_KEY, [] );
	const nowSec = Date.now() / 1000;
	const visible = sales.filter( ( s ) => s.starts <= nowSec && s.ends > nowSec && ! closed.includes( s.id ) );
	if ( ! visible.length ) {
		document.body.classList.remove( 'dev3sc-flash-space-top', 'dev3sc-flash-space-bottom' );
	}
	sales
		.filter( ( s ) => s.starts <= nowSec && s.ends > nowSec && ! closed.includes( s.id ) )
		.slice( 0, 1 )
		.forEach( ( sale ) => {
			const bar = flashBar( sale, cfg.banner, strings, sale.ends - nowSec );
			const side = sale.placement === 'bottom' ? 'bottom' : 'top';
			const remove = () => {
				bar.remove();
				document.documentElement.classList.remove( `dev3sc-has-flash-${ side }` );
				document.body.classList.remove( `dev3sc-flash-space-${ side }` );
			};
			bar.querySelector( '[data-dev3sc-close]' ).addEventListener( 'click', () => {
				remove();
				writeJSON( session, FLASH_KEY, [ ...closed, sale.id ] );
			} );
			document.body.appendChild( bar );
			document.documentElement.classList.add( `dev3sc-has-flash-${ side }` );
			const fit = () => document.body.style.setProperty( '--dev3sc-flash-h', `${ bar.offsetHeight }px` );
			fit();
			window.addEventListener( 'resize', fit, { passive: true } );
			startCountdown( {
				expiresAt: sale.ends,
				offset: 0,
				onTick: ( left ) => setCountdown( bar, left ),
				onEnd: remove,
			} );
		} );
}

function boot() {
	const cfg = window.dev3scStore;
	if ( ! cfg ) {
		return;
	}
	const strings = cfg.i18n || {};
	const session = store( 'sessionStorage' );
	const allowed = trackingAllowed( cfg.consent, { cookie: document.cookie, navigator: window.navigator, win: window } );
	const ctx = { cfg, strings, session: allowed ? session : null, offset: 0 };

	renderFlash( cfg, strings, session );

	// 1. Server-rendered inline banners (cart/checkout): hydrate.
	const inline = document.querySelectorAll( '[data-dev3sc-inline]' );
	if ( inline.length && cfg.offer ) {
		const c = inline[ 0 ].querySelector( '.dev3sc-countdown' );
		ctx.offset = c ? clockOffset( Number( c.getAttribute( 'data-now' ) ), Date.now() ) : clockOffset( cfg.offer.now, Date.now() );
		inline.forEach( ( node ) => hydrate( node, cfg.offer, ctx ) );
		return;
	}

	// 2. A known offer (embedded on cart/checkout, or remembered from this browsing session).
	const remembered = readJSON( ctx.session, OFFER_KEY, null );
	let known = null;
	if ( cfg.offer ) {
		known = { offer: cfg.offer, offset: clockOffset( cfg.offer.now, Date.now() ) };
	} else if ( remembered && remembered.offer && remembered.offer.expires_at * 1000 > Date.now() + ( remembered.offset || 0 ) ) {
		known = remembered;
	}
	if ( known ) {
		ctx.offset = known.offset || 0;
		const dismissed = readJSON( ctx.session, DISMISS_KEY, [] );
		if ( ! dismissed.includes( known.offer.id ) ) {
			showFloating( { ...known.offer, applied: known.offer.applied || !! known.applied }, ctx );
		}
		return; // One offer per session: nothing more to track.
	}

	// 3. Tracker.
	if ( ! cfg.track || ! allowed || ! window.fetch ) {
		return;
	}
	const local = store( 'localStorage' );
	let returning = false;
	try {
		const seen = Number( local && local.getItem( SEEN_KEY ) );
		returning = !! seen && Date.now() - seen > 30 * 60 * 1000;
		if ( local && ! seen ) {
			local.setItem( SEEN_KEY, String( Date.now() ) );
		}
	} catch ( e ) {}
	let visits = Number( readJSON( session, VISITS_KEY, 0 ) ) || 0;
	if ( cfg.page === 'cart' ) {
		visits += 1;
		writeJSON( session, VISITS_KEY, visits );
	}
	const isMobile = !! ( window.matchMedia && window.matchMedia( '(pointer: coarse)' ).matches );

	let engine = null;
	const send = ( trigger, metrics ) => {
		engine.setBusy( true );
		const headers = { 'Content-Type': 'application/json' };
		if ( cfg.nonce ) {
			headers[ 'X-WP-Nonce' ] = cfg.nonce;
		}
		const sentAt = Date.now();
		window
			.fetch( cfg.endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				keepalive: trigger === 'tab_hidden',
				headers,
				body: JSON.stringify( {
					trigger,
					page_type: cfg.page,
					idle_seconds: metrics.idle_seconds,
					dwell: metrics.dwell,
					cart_visits: visits,
					returning,
					device: isMobile ? 'mobile' : 'desktop',
				} ),
			} )
			.then( ( r ) => ( r.ok ? r.json() : null ) )
			.then( ( res ) => {
				engine.setBusy( false );
				if ( res && res.offer ) {
					engine.stop();
					const offset = clockOffset( res.offer.now, ( sentAt + Date.now() ) / 2 );
					writeJSON( session, OFFER_KEY, { offer: res.offer, offset } );
					ctx.offset = offset;
					const show = () => showFloating( res.offer, ctx );
					if ( document.hidden ) {
						document.addEventListener( 'visibilitychange', show, { once: true } );
					} else {
						show();
					}
				} else if ( ! res || ! [ 'low_risk', 'empty_cart', 'min_spend', 'no_session' ].includes( res.reason ) ) {
					engine.stop(); // Final answer for this page (already offered, budget, cooldown, …).
				}
			} )
			.catch( () => engine.setBusy( false ) );
	};
	engine = createTriggerEngine( { ...cfg.triggers, isMobile }, { now: () => Date.now(), onTrigger: send, canFire: () => cartHasItems( document.cookie ) } );

	const activity = () => engine.activity();
	[ 'mousemove', 'keydown', 'click', 'touchstart', 'input' ].forEach( ( ev ) => document.addEventListener( ev, activity, { passive: true } ) );
	document.addEventListener( 'mouseout', ( e ) => engine.mouseOut( e ) );
	window.addEventListener( 'scroll', () => engine.scroll( window.scrollY ), { passive: true } );
	document.addEventListener( 'visibilitychange', () => engine.visibility( document.hidden ) );
	const timer = window.setInterval( () => {
		if ( engine.isStopped() ) {
			window.clearInterval( timer );
			return;
		}
		engine.tick();
	}, 1000 );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', boot );
} else {
	boot();
}
