<?php
/**
 * Banner copy: sanitises model text and makes sure it can never promise a different deal.
 *
 * The model is asked to write {discount} and {minutes} placeholders instead of numbers. Any copy
 * that still contains digits, links or markup is discarded for the merchant's template, so a
 * hallucinated "50% off" can never reach a shopper when the real offer is 10%.
 *
 * @package Dev3\AiSmartCoupons
 */

namespace Dev3\AiSmartCoupons\Engine;

use Dev3\AiSmartCoupons\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Copy helpers.
 */
class Copy {

	const LIMITS = array(
		'headline' => 70,
		'message'  => 160,
		'cta'      => 28,
	);

	/**
	 * Human label for the deal, e.g. "15% off", "$10.00 off", "free shipping".
	 *
	 * @param array  $offer  Offer (type, amount).
	 * @param string $symbol Currency symbol.
	 * @return string
	 */
	public static function discount_label( array $offer, $symbol = '$' ) {
		if ( 'free_shipping' === $offer['type'] ) {
			return __( 'free shipping', 'dev3-ai-smart-coupons-for-woocommerce' );
		}
		if ( 'percent' === $offer['type'] ) {
			/* translators: %s: percentage */
			return sprintf( __( '%s%% off', 'dev3-ai-smart-coupons-for-woocommerce' ), (int) $offer['amount'] );
		}
		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		$amount   = (float) $offer['amount'];
		$decimals = floor( $amount ) === $amount ? 0 : $decimals;
		/* translators: %s: money amount */
		return sprintf( __( '%s off', 'dev3-ai-smart-coupons-for-woocommerce' ), html_entity_decode( $symbol, ENT_QUOTES, 'UTF-8' ) . number_format_i18n( $amount, $decimals ) );
	}

	/**
	 * Plain, single-line, length-limited text.
	 *
	 * @param mixed $text Raw.
	 * @param int   $len  Limit.
	 * @return string
	 */
	public static function clean( $text, $len ) {
		$text = is_scalar( $text ) ? (string) $text : '';
		$text = wp_strip_all_tags( $text );
		$text = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $text );
		$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
		return Settings::limit( $text, $len );
	}

	/**
	 * Whether model text is safe to show as-is (before placeholder substitution).
	 *
	 * @param string $text Clean text.
	 * @return bool
	 */
	public static function is_safe( $text ) {
		if ( '' === $text ) {
			return false;
		}
		if ( preg_match( '/\d|https?:|www\.|[<>]|@|%|\$|€|£|¥|₹/u', $text ) ) {
			return false;
		}
		// Only our two placeholders are allowed.
		$stripped = str_replace( array( '{discount}', '{minutes}' ), '', $text );
		return false === strpos( $stripped, '{' ) && false === strpos( $stripped, '}' );
	}

	/**
	 * Substitutes placeholders.
	 *
	 * @param string $text    Text.
	 * @param string $label   Discount label.
	 * @param int    $minutes Minutes.
	 * @return string
	 */
	public static function fill( $text, $label, $minutes ) {
		// The label already says "off" ("10% off"); models often write "{discount} off".
		$text = (string) preg_replace( '/\{discount\}\s+off\b/iu', '{discount}', (string) $text );
		return str_replace( array( '{discount}', '{minutes}' ), array( $label, (string) (int) $minutes ), $text );
	}

	/**
	 * Final copy for an offer.
	 *
	 * @param array  $proposal Proposal (headline, message, cta from the model; may be missing).
	 * @param array  $offer    Clamped offer (type, amount, urgency_minutes).
	 * @param array  $banner   Banner settings (fallback templates).
	 * @param string $symbol   Currency symbol.
	 * @return array { headline, message, cta, discount_label, replaced }
	 */
	public static function finalise( array $proposal, array $offer, array $banner, $symbol = '$' ) {
		$banner   = array_merge( Settings::banner_defaults(), $banner );
		$fields   = array();
		$safe     = true;
		$provided = false;
		foreach ( self::LIMITS as $key => $len ) {
			$fields[ $key ] = self::clean( isset( $proposal[ $key ] ) ? $proposal[ $key ] : '', $len );
			$provided       = $provided || '' !== $fields[ $key ];
			$safe           = $safe && self::is_safe( $fields[ $key ] );
		}
		if ( ! $safe ) {
			$fields = array(
				'headline' => self::clean( $banner['fallback_heading'], self::LIMITS['headline'] + 20 ),
				'message'  => self::clean( $banner['fallback_message'], self::LIMITS['message'] + 20 ),
				'cta'      => self::clean( $banner['fallback_cta'], self::LIMITS['cta'] ),
			);
		}
		$label = self::discount_label( $offer, $symbol );
		foreach ( $fields as $key => $text ) {
			$fields[ $key ] = self::fill( $text, $label, $offer['urgency_minutes'] );
		}
		$fields['discount_label'] = $label;
		$fields['replaced']       = $provided && ! $safe;
		return $fields;
	}
}
