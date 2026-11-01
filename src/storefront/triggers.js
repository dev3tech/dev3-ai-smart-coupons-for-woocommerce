/**
 * Intent-trigger engine (pure: no DOM access, time is injected — fully unit-testable).
 *
 * Triggers:
 *  - exit_intent: desktop pointer leaves through the top edge of the viewport.
 *  - scroll_up:   mobile fast upward scroll (heading for the address bar / back).
 *  - tab_hidden:  mobile tab/app switched away.
 *  - idle:        no interaction for `idleSeconds`.
 *
 * Each trigger type fires at most once per page view, never before `minDwell` seconds on the
 * page, and only while `canFire()` says so (e.g. the cart has items and consent is given).
 */

export const SCROLL_UP_PX = 140;
export const SCROLL_UP_MS = 350;
export const SCROLL_MIN_DEPTH = 300;

/**
 * @param {Object}   opts             Options.
 * @param {boolean}  opts.exit        Desktop exit intent.
 * @param {boolean}  opts.mobile      Mobile exit signals (scroll up, tab hidden).
 * @param {boolean}  opts.idle        Idle trigger.
 * @param {number}   opts.idleSeconds Idle threshold.
 * @param {number}   opts.minDwell    Minimum seconds on page before any trigger.
 * @param {boolean}  opts.isMobile    Coarse pointer device.
 * @param {Object}   deps             Dependencies.
 * @param {Function} deps.now         () => ms.
 * @param {Function} deps.onTrigger   ( trigger, metrics ) => void.
 * @param {Function} [deps.canFire]   () => boolean.
 * @return {Object} Engine.
 */
export function createTriggerEngine( opts, deps ) {
	const o = {
		exit: true,
		mobile: true,
		idle: true,
		idleSeconds: 30,
		minDwell: 4,
		isMobile: false,
		...opts,
	};
	const now = deps.now;
	const canFire = deps.canFire || ( () => true );
	const start = now();
	let lastActivity = start;
	let lastScroll = null;
	let stopped = false;
	let busy = false;
	const fired = new Set();

	const metrics = () => ( {
		idle_seconds: Math.max( 0, Math.floor( ( now() - lastActivity ) / 1000 ) ),
		dwell: Math.max( 0, Math.floor( ( now() - start ) / 1000 ) ),
	} );

	const fire = ( trigger ) => {
		if ( stopped || busy || fired.has( trigger ) ) {
			return false;
		}
		if ( now() - start < o.minDwell * 1000 || ! canFire() ) {
			return false;
		}
		fired.add( trigger );
		deps.onTrigger( trigger, metrics() );
		return true;
	};

	return {
		/** Any interaction resets the idle clock. */
		activity() {
			lastActivity = now();
		},
		/**
		 * Pointer left the document.
		 *
		 * @param {{clientY:number, relatedTarget:*}} e Event-like.
		 * @return {boolean} Fired.
		 */
		mouseOut( e ) {
			if ( ! o.exit || o.isMobile || ! e || e.relatedTarget || e.clientY > 0 ) {
				return false;
			}
			return fire( 'exit_intent' );
		},
		/**
		 * Scroll position changed.
		 *
		 * @param {number} y scrollY.
		 * @return {boolean} Fired.
		 */
		scroll( y ) {
			const t = now();
			lastActivity = t;
			const prev = lastScroll;
			if ( ! prev || y > prev.y || t - prev.t > SCROLL_UP_MS ) {
				// Start a new upward-movement window from the deepest/most recent point.
				lastScroll = { y, t };
				return false;
			}
			if ( o.mobile && o.isMobile && prev.y >= SCROLL_MIN_DEPTH && prev.y - y >= SCROLL_UP_PX ) {
				lastScroll = { y, t };
				return fire( 'scroll_up' );
			}
			return false;
		},
		/**
		 * Visibility changed.
		 *
		 * @param {boolean} hidden document.hidden.
		 * @return {boolean} Fired.
		 */
		visibility( hidden ) {
			if ( ! hidden ) {
				lastActivity = now();
				return false;
			}
			if ( ! o.mobile || ! o.isMobile ) {
				return false;
			}
			return fire( 'tab_hidden' );
		},
		/**
		 * Called every second.
		 *
		 * @return {boolean} Fired.
		 */
		tick() {
			if ( ! o.idle || now() - lastActivity < o.idleSeconds * 1000 ) {
				return false;
			}
			return fire( 'idle' );
		},
		/** Blocks further triggers while a request is in flight. */
		setBusy( value ) {
			busy = !! value;
		},
		/** Stops the engine for this page (offer shown or final answer). */
		stop() {
			stopped = true;
		},
		isStopped: () => stopped,
		fired: () => Array.from( fired ),
		metrics,
	};
}
