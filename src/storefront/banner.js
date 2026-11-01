/**
 * Banner DOM builders (vanilla). Shared by the storefront and the admin live preview so the
 * preview is pixel-identical. Text is always set with textContent (never innerHTML).
 */
import { format } from './countdown';

let uid = 0;

/**
 * Small element helper.
 *
 * @param {string} tag   Tag.
 * @param {Object} attrs Attributes (className, text, and any attribute).
 * @param {Array}  kids  Children.
 * @return {HTMLElement} Element.
 */
export function el( tag, attrs = {}, kids = [] ) {
	const node = document.createElement( tag );
	Object.keys( attrs ).forEach( ( k ) => {
		const v = attrs[ k ];
		if ( v === undefined || v === null || v === false ) {
			return;
		}
		if ( k === 'className' ) {
			node.className = v;
		} else if ( k === 'text' ) {
			node.textContent = v;
		} else {
			node.setAttribute( k, v === true ? '' : String( v ) );
		}
	} );
	kids.filter( Boolean ).forEach( ( kid ) => node.appendChild( kid ) );
	return node;
}

/**
 * CSS variables for a design.
 *
 * @param {HTMLElement} node   Node.
 * @param {Object}      design { bg, text, accent, accentText, radius }.
 */
export function applyDesign( node, design ) {
	const d = design || {};
	node.style.setProperty( '--dev3sc-bg', d.bg || '#111827' );
	node.style.setProperty( '--dev3sc-fg', d.text || '#ffffff' );
	node.style.setProperty( '--dev3sc-accent', d.accent || '#f59e0b' );
	node.style.setProperty( '--dev3sc-accent-fg', d.accentText || '#111827' );
	node.style.setProperty( '--dev3sc-radius', `${ Number.isFinite( Number( d.radius ) ) ? Number( d.radius ) : 10 }px` );
}

/**
 * Countdown element.
 *
 * @param {string} label   Label.
 * @param {number} seconds Initial seconds.
 * @return {HTMLElement} Element.
 */
export function countdownEl( label, seconds ) {
	return el( 'span', { className: 'dev3sc-countdown', role: 'timer', 'aria-live': 'off' }, [
		el( 'span', { className: 'dev3sc-countdown__label', text: label } ),
		el( 'span', { className: 'dev3sc-countdown__time', text: format( seconds ) } ),
	] );
}

/**
 * Personal-offer banner.
 *
 * @param {Object} offer   Offer { id, code, headline, message, cta, applied }.
 * @param {Object} design  Design { position, countdown, ... }.
 * @param {Object} strings i18n.
 * @param {Object} o       { seconds, applyHref }.
 * @return {{root:HTMLElement, panel:HTMLElement}} Nodes (root is the overlay for modals).
 */
export function offerBanner( offer, design, strings, o = {} ) {
	const position = design.position || 'bottom';
	const headId = `dev3sc-h-${ ++uid }`;
	const actions = el( 'div', { className: 'dev3sc-banner__actions' }, [
		design.countdown !== false ? countdownEl( strings.expires || 'Offer ends in', o.seconds || 0 ) : null,
		el( 'span', { className: 'dev3sc-code' }, [ el( 'span', { className: 'dev3sc-sr', text: `${ strings.code || 'Code' }: ` } ), document.createTextNode( offer.code || '' ) ] ),
		offer.applied
			? el( 'span', { className: 'dev3sc-applied', role: 'status', text: strings.applied || 'Discount applied' } )
			: el( 'a', { className: 'dev3sc-btn', href: o.applyHref || '#', 'data-dev3sc-apply': true, text: offer.cta || 'Apply' } ),
	] );
	const panel = el(
		'section',
		{
			className: `dev3sc-banner dev3sc-float dev3sc-pos-${ position }${ offer.applied ? ' is-applied' : '' }`,
			'aria-labelledby': headId,
			'data-dev3sc-offer': offer.id,
			role: position === 'modal' ? 'dialog' : 'region',
			'aria-modal': position === 'modal' ? 'true' : null,
		},
		[
			el( 'div', { className: 'dev3sc-banner__body' }, [
				el( 'p', { className: 'dev3sc-banner__eyebrow', text: strings.offer || 'Personal offer' } ),
				el( 'p', { className: 'dev3sc-banner__headline', id: headId, text: offer.headline || '' } ),
				el( 'p', { className: 'dev3sc-banner__message', text: offer.message || '' } ),
			] ),
			actions,
			el( 'button', { type: 'button', className: 'dev3sc-close', 'aria-label': strings.dismiss || 'Dismiss offer', 'data-dev3sc-close': true, text: '×' } ),
		]
	);
	applyDesign( panel, design );
	if ( position !== 'modal' ) {
		return { root: panel, panel };
	}
	const overlay = el( 'div', { className: 'dev3sc-overlay', 'data-dev3sc-overlay': true }, [ panel ] );
	return { root: overlay, panel };
}

/**
 * Flash-sale bar.
 *
 * @param {Object} sale    Sale { id, headline, message, cta_label, cta_url, coupon_code, placement, bg, text }.
 * @param {Object} design  Banner design (fallback colours).
 * @param {Object} strings i18n.
 * @param {number} seconds Seconds left.
 * @return {HTMLElement} Bar.
 */
export function flashBar( sale, design, strings, seconds ) {
	const bar = el(
		'section',
		{
			className: `dev3sc-flash dev3sc-flash--${ sale.placement === 'bottom' ? 'bottom' : 'top' }`,
			'aria-label': strings.flash || 'Flash sale',
			'data-dev3sc-flash': sale.id,
		},
		[
			el( 'div', { className: 'dev3sc-flash__inner' }, [
				el( 'span', { className: 'dev3sc-flash__badge', text: strings.flash || 'Flash sale' } ),
				el( 'span', { className: 'dev3sc-flash__text' }, [
					el( 'strong', { className: 'dev3sc-flash__headline', text: sale.headline || '' } ),
					sale.message ? el( 'span', { className: 'dev3sc-flash__message', text: ` ${ sale.message }` } ) : null,
				] ),
				countdownEl( strings.endsIn || 'Ends in', seconds ),
				sale.coupon_code ? el( 'span', { className: 'dev3sc-code', text: sale.coupon_code } ) : null,
				sale.cta_url && sale.cta_label ? el( 'a', { className: 'dev3sc-btn', href: sale.cta_url, text: sale.cta_label } ) : null,
			] ),
			el( 'button', { type: 'button', className: 'dev3sc-close', 'aria-label': strings.close || 'Close', 'data-dev3sc-close': true, text: '×' } ),
		]
	);
	applyDesign( bar, { ...design, bg: sale.bg || design.accent, text: sale.text || design.accentText, accent: design.bg, accentText: design.text } );
	return bar;
}

/**
 * Updates a countdown element's time text.
 *
 * @param {HTMLElement} root    Container.
 * @param {number}      seconds Seconds left.
 */
export function setCountdown( root, seconds ) {
	const t = root && root.querySelector( '.dev3sc-countdown__time' );
	if ( t ) {
		t.textContent = format( seconds );
	}
}
