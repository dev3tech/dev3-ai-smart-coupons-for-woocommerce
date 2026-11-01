import { createTriggerEngine } from '../../src/storefront/triggers';
import { clockOffset, format, remaining, startCountdown } from '../../src/storefront/countdown';
import { cartHasItems, hasConsentCookie, trackingAllowed } from '../../src/storefront/consent';
import { offerBanner, flashBar } from '../../src/storefront/banner';

const make = ( opts = {}, extra = {} ) => {
	let t = 0;
	const fired = [];
	const engine = createTriggerEngine( { idleSeconds: 30, minDwell: 4, ...opts }, { now: () => t, onTrigger: ( trig, m ) => fired.push( [ trig, m ] ), ...extra } );
	return { engine, fired, advance: ( ms ) => ( t += ms ), at: ( ms ) => ( t = ms ) };
};

describe( 'trigger engine', () => {
	test( 'desktop exit intent fires once when leaving through the top', () => {
		const { engine, fired, advance } = make();
		advance( 5000 );
		expect( engine.mouseOut( { clientY: 200, relatedTarget: null } ) ).toBe( false );
		expect( engine.mouseOut( { clientY: -2, relatedTarget: {} } ) ).toBe( false );
		expect( engine.mouseOut( { clientY: -2, relatedTarget: null } ) ).toBe( true );
		expect( engine.mouseOut( { clientY: -2, relatedTarget: null } ) ).toBe( false );
		expect( fired ).toEqual( [ [ 'exit_intent', { idle_seconds: 5, dwell: 5 } ] ] );
	} );

	test( 'nothing fires before the minimum dwell time', () => {
		const { engine, fired, advance } = make();
		advance( 2000 );
		expect( engine.mouseOut( { clientY: 0, relatedTarget: null } ) ).toBe( false );
		advance( 3000 );
		expect( engine.mouseOut( { clientY: 0, relatedTarget: null } ) ).toBe( true );
		expect( fired ).toHaveLength( 1 );
	} );

	test( 'exit intent is ignored on mobile; tab hidden and fast scroll-up fire instead', () => {
		const { engine, fired, advance } = make( { isMobile: true } );
		advance( 6000 );
		expect( engine.mouseOut( { clientY: -1, relatedTarget: null } ) ).toBe( false );
		engine.scroll( 900 );
		advance( 100 );
		expect( engine.scroll( 850 ) ).toBe( false );
		advance( 100 );
		expect( engine.scroll( 700 ) ).toBe( true );
		expect( engine.visibility( true ) ).toBe( true );
		expect( fired.map( ( f ) => f[ 0 ] ) ).toEqual( [ 'scroll_up', 'tab_hidden' ] );
	} );

	test( 'slow scroll-up or scroll near the top does not count', () => {
		const { engine, fired, advance } = make( { isMobile: true } );
		advance( 6000 );
		engine.scroll( 1000 );
		advance( 500 );
		expect( engine.scroll( 800 ) ).toBe( false );
		advance( 500 );
		engine.scroll( 250 );
		advance( 50 );
		expect( engine.scroll( 50 ) ).toBe( false );
		expect( fired ).toHaveLength( 0 );
	} );

	test( 'idle fires after the threshold and activity resets the clock', () => {
		const { engine, fired, advance } = make( { idleSeconds: 30 } );
		advance( 20000 );
		engine.activity();
		advance( 20000 );
		expect( engine.tick() ).toBe( false );
		advance( 10000 );
		expect( engine.tick() ).toBe( true );
		expect( fired[ 0 ] ).toEqual( [ 'idle', { idle_seconds: 30, dwell: 50 } ] );
	} );

	test( 'disabled triggers never fire', () => {
		const { engine, fired, advance } = make( { exit: false, idle: false, mobile: false, isMobile: true } );
		advance( 120000 );
		engine.tick();
		engine.visibility( true );
		engine.mouseOut( { clientY: -1, relatedTarget: null } );
		expect( fired ).toHaveLength( 0 );
	} );

	test( 'canFire gate (empty cart / consent) blocks without latching', () => {
		let allowed = false;
		const { engine, fired, advance } = make( {}, { canFire: () => allowed } );
		advance( 40000 );
		expect( engine.tick() ).toBe( false );
		allowed = true;
		expect( engine.tick() ).toBe( true );
		expect( fired ).toHaveLength( 1 );
	} );

	test( 'busy and stop block further triggers', () => {
		const { engine, fired, advance } = make();
		advance( 40000 );
		engine.setBusy( true );
		expect( engine.tick() ).toBe( false );
		engine.setBusy( false );
		engine.stop();
		expect( engine.tick() ).toBe( false );
		expect( engine.isStopped() ).toBe( true );
		expect( fired ).toHaveLength( 0 );
	} );
} );

describe( 'countdown', () => {
	test( 'formats mm:ss, h:mm:ss and days', () => {
		expect( format( 0 ) ).toBe( '00:00' );
		expect( format( 65 ) ).toBe( '01:05' );
		expect( format( 3600 + 61 ) ).toBe( '1:01:01' );
		expect( format( 2 * 86400 + 5 ) ).toBe( '2d 00:00:05' );
		expect( format( -5 ) ).toBe( '00:00' );
	} );

	test( 'server expiry is the truth even when the client clock is wrong', () => {
		const serverNow = 1_800_000_000;
		const clientNow = ( serverNow - 3600 ) * 1000; // Client clock one hour behind.
		const offset = clockOffset( serverNow, clientNow );
		expect( offset ).toBe( 3600 * 1000 );
		expect( remaining( serverNow + 600, offset, clientNow ) ).toBe( 600 );
		expect( remaining( serverNow + 600, offset, clientNow + 601000 ) ).toBe( 0 );
		expect( clockOffset( 'nope', 5 ) ).toBe( 0 );
		expect( remaining( 'x', 0, 0 ) ).toBe( 0 );
	} );

	test( 'ticks every second and ends once', () => {
		let now = 1000 * 1000;
		let tick = null;
		const ticks = [];
		const onEnd = jest.fn();
		startCountdown( {
			expiresAt: 1000 + 2,
			offset: 0,
			now: () => now,
			onTick: ( s ) => ticks.push( s ),
			onEnd,
			setTimer: ( fn ) => {
				tick = fn;
				return 1;
			},
			clearTimer: () => {
				tick = null;
			},
		} );
		now += 1000;
		tick();
		now += 1000;
		tick();
		expect( ticks ).toEqual( [ 2, 1, 0 ] );
		expect( onEnd ).toHaveBeenCalledTimes( 1 );
		expect( tick ).toBeNull();
	} );

	test( 'an already-expired offer ends immediately without a timer', () => {
		const setTimer = jest.fn();
		const onEnd = jest.fn();
		startCountdown( { expiresAt: 10, offset: 0, now: () => 20000, onTick: () => {}, onEnd, setTimer } );
		expect( onEnd ).toHaveBeenCalled();
		expect( setTimer ).not.toHaveBeenCalled();
	} );
} );

describe( 'consent', () => {
	test( 'Do Not Track and Global Privacy Control are respected', () => {
		expect( trackingAllowed( { respectDnt: true }, { navigator: { doNotTrack: '1' } } ) ).toBe( false );
		expect( trackingAllowed( { respectDnt: true }, { navigator: { globalPrivacyControl: true } } ) ).toBe( false );
		expect( trackingAllowed( { respectDnt: false }, { navigator: { doNotTrack: '1' } } ) ).toBe( true );
		expect( trackingAllowed( { mode: 'none' }, {} ) ).toBe( true );
	} );

	test( 'consent cookie rules', () => {
		expect( trackingAllowed( { mode: 'cookie', cookie: 'ok' }, { cookie: 'a=1' } ) ).toBe( false );
		expect( trackingAllowed( { mode: 'cookie', cookie: 'ok' }, { cookie: 'a=1; ok=yes' } ) ).toBe( true );
		expect( hasConsentCookie( 'ok', 'ok=0' ) ).toBe( false );
		expect( hasConsentCookie( 'cookieyes-consent=analytics:yes', 'cookieyes-consent=necessary:yes%2Canalytics:yes' ) ).toBe( true );
		expect( hasConsentCookie( 'cookieyes-consent=analytics:yes', 'cookieyes-consent=analytics:no' ) ).toBe( false );
		expect( trackingAllowed( { mode: 'cookie', cookie: 'ok' }, { cookie: '', win: { dev3scConsent: true } } ) ).toBe( true );
		expect( trackingAllowed( { mode: 'none' }, { win: { dev3scConsent: false } } ) ).toBe( false );
	} );

	test( 'cart cookie detection', () => {
		expect( cartHasItems( 'x=1; woocommerce_items_in_cart=1; y=2' ) ).toBe( true );
		expect( cartHasItems( 'woocommerce_items_in_cart=0' ) ).toBe( false );
		expect( cartHasItems( '' ) ).toBe( false );
	} );
} );

describe( 'banner markup', () => {
	test( 'offer banner escapes text and is accessible', () => {
		const { root, panel } = offerBanner( { id: 3, code: 'SAVE-1', headline: '<img src=x onerror=alert(1)>', message: 'Hi', cta: 'Apply' }, { position: 'modal' }, {}, { seconds: 90, applyHref: '/cart/?dev3sc_apply=SAVE-1' } );
		expect( root.className ).toBe( 'dev3sc-overlay' );
		expect( panel.getAttribute( 'role' ) ).toBe( 'dialog' );
		expect( panel.getAttribute( 'aria-modal' ) ).toBe( 'true' );
		expect( panel.querySelector( 'img' ) ).toBeNull();
		expect( panel.querySelector( '.dev3sc-banner__headline' ).textContent ).toContain( '<img' );
		expect( panel.querySelector( '.dev3sc-countdown__time' ).textContent ).toBe( '01:30' );
		expect( panel.querySelector( '[data-dev3sc-apply]' ).getAttribute( 'href' ) ).toContain( 'dev3sc_apply=SAVE-1' );
		expect( panel.querySelector( '[data-dev3sc-close]' ).getAttribute( 'aria-label' ) ).toBeTruthy();
	} );

	test( 'applied offers show status instead of the button; flash bar renders', () => {
		const { panel } = offerBanner( { id: 1, code: 'A', applied: true }, { position: 'bottom' }, { applied: 'Done' } );
		expect( panel.querySelector( '[data-dev3sc-apply]' ) ).toBeNull();
		expect( panel.querySelector( '.dev3sc-applied' ).textContent ).toBe( 'Done' );
		const bar = flashBar( { id: 'x', headline: 'Sale', placement: 'bottom', cta_url: '/shop/', cta_label: 'Shop' }, {}, {}, 3700 );
		expect( bar.className ).toContain( 'dev3sc-flash--bottom' );
		expect( bar.querySelector( '.dev3sc-countdown__time' ).textContent ).toBe( '1:01:40' );
	} );
} );
