/**
 * Countdown helpers. The server's expiry is the truth: the browser only measures the offset
 * between its clock and the server clock once, then counts down to the server timestamp.
 */

/**
 * Server-minus-client clock offset in ms.
 *
 * @param {number} serverNowSec Server time (Unix seconds).
 * @param {number} clientNowMs  Client time (ms).
 * @return {number} Offset ms.
 */
export function clockOffset( serverNowSec, clientNowMs ) {
	const server = Number( serverNowSec );
	if ( ! Number.isFinite( server ) || server <= 0 ) {
		return 0;
	}
	return server * 1000 - clientNowMs;
}

/**
 * Whole seconds left.
 *
 * @param {number} expiresAtSec Expiry (Unix seconds, from the server).
 * @param {number} offsetMs     clockOffset().
 * @param {number} clientNowMs  Client time (ms).
 * @return {number} Seconds ≥ 0.
 */
export function remaining( expiresAtSec, offsetMs, clientNowMs ) {
	const expires = Number( expiresAtSec );
	if ( ! Number.isFinite( expires ) ) {
		return 0;
	}
	return Math.max( 0, Math.floor( ( expires * 1000 - ( clientNowMs + ( offsetMs || 0 ) ) ) / 1000 ) );
}

const pad = ( n ) => String( n ).padStart( 2, '0' );

/**
 * mm:ss, or h:mm:ss for an hour or more, or "Nd hh:mm:ss" for days.
 *
 * @param {number} secs Seconds.
 * @return {string} Label.
 */
export function format( secs ) {
	const s = Math.max( 0, Math.floor( Number( secs ) || 0 ) );
	const days = Math.floor( s / 86400 );
	const h = Math.floor( ( s % 86400 ) / 3600 );
	const m = Math.floor( ( s % 3600 ) / 60 );
	const sec = s % 60;
	if ( days > 0 ) {
		return `${ days }d ${ pad( h ) }:${ pad( m ) }:${ pad( sec ) }`;
	}
	if ( h > 0 ) {
		return `${ h }:${ pad( m ) }:${ pad( sec ) }`;
	}
	return `${ pad( m ) }:${ pad( sec ) }`;
}

/**
 * Starts a 1 s ticker; calls onTick(secondsLeft) immediately and every second, onEnd once.
 *
 * @param {Object}   o              Options.
 * @param {number}   o.expiresAt    Expiry (Unix seconds).
 * @param {number}   o.offset       Clock offset ms.
 * @param {Function} o.onTick       Tick callback.
 * @param {Function} [o.onEnd]      End callback.
 * @param {Function} [o.now]        Clock (ms).
 * @param {Function} [o.setTimer]   setInterval.
 * @param {Function} [o.clearTimer] clearInterval.
 * @return {Function} Stop function.
 */
export function startCountdown( o ) {
	const now = o.now || ( () => Date.now() );
	const setTimer = o.setTimer || ( ( fn, ms ) => setInterval( fn, ms ) );
	const clearTimer = o.clearTimer || ( ( id ) => clearInterval( id ) );
	let id = null;
	let done = false;
	const step = () => {
		const left = remaining( o.expiresAt, o.offset, now() );
		o.onTick( left );
		if ( left <= 0 && ! done ) {
			done = true;
			if ( id !== null ) {
				clearTimer( id );
			}
			if ( o.onEnd ) {
				o.onEnd();
			}
		}
	};
	step();
	if ( ! done ) {
		id = setTimer( step, 1000 );
	}
	return () => {
		done = true;
		if ( id !== null ) {
			clearTimer( id );
		}
	};
}
