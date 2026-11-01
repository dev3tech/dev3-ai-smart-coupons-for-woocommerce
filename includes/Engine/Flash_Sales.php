<?php
/**
 * Merchant-scheduled, site-wide flash-sale urgency banners (with optional AI-written copy).
 *
 * @package Dev3\AiSmartCoupons
 */

namespace Dev3\AiSmartCoupons\Engine;

use Dev3\AiSmartCoupons\Core3\Api\Ai;
use Dev3\AiSmartCoupons\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Stored as a list in the dev3sc_flash_sales option.
 */
class Flash_Sales {

	const OPTION = 'dev3sc_flash_sales';
	const MAX    = 50;

	/**
	 * All sales.
	 *
	 * @return array
	 */
	public static function all() {
		$list = get_option( self::OPTION, array() );
		return is_array( $list ) ? array_values( $list ) : array();
	}

	/**
	 * Replaces the list.
	 *
	 * @param array $items Raw items.
	 * @return array Saved list.
	 */
	public static function save( array $items ) {
		$clean = array();
		foreach ( array_slice( $items, 0, self::MAX ) as $item ) {
			if ( is_array( $item ) ) {
				$sale = self::sanitize( $item );
				if ( $sale ) {
					$clean[] = $sale;
				}
			}
		}
		update_option( self::OPTION, $clean, false );
		return $clean;
	}

	/**
	 * One sale.
	 *
	 * @param array $in Raw.
	 * @return array|null
	 */
	public static function sanitize( array $in ) {
		$starts = isset( $in['starts'] ) ? (int) $in['starts'] : 0;
		$ends   = isset( $in['ends'] ) ? (int) $in['ends'] : 0;
		if ( $starts <= 0 || $ends <= $starts ) {
			return null;
		}
		$id  = isset( $in['id'] ) ? preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $in['id'] ) ) : '';
		$url = isset( $in['cta_url'] ) ? esc_url_raw( (string) $in['cta_url'] ) : '';
		if ( '' !== $url && wp_parse_url( $url, PHP_URL_HOST ) && wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			$url = ''; // Same-site links only.
		}
		$color = static function ( $key ) use ( $in ) {
			$hex = isset( $in[ $key ] ) ? sanitize_hex_color( (string) $in[ $key ] ) : '';
			return $hex ? $hex : '';
		};
		return array(
			'id'          => '' !== $id ? substr( $id, 0, 20 ) : strtolower( wp_generate_password( 10, false ) ),
			'enabled'     => ! empty( $in['enabled'] ),
			'title'       => Copy::clean( isset( $in['title'] ) ? $in['title'] : '', 80 ),
			'headline'    => Copy::clean( isset( $in['headline'] ) ? $in['headline'] : '', 90 ),
			'message'     => Copy::clean( isset( $in['message'] ) ? $in['message'] : '', 180 ),
			'cta_label'   => Copy::clean( isset( $in['cta_label'] ) ? $in['cta_label'] : '', 30 ),
			'cta_url'     => $url,
			'coupon_code' => strtoupper( substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', isset( $in['coupon_code'] ) ? (string) $in['coupon_code'] : '' ), 0, 40 ) ),
			'starts'      => $starts,
			'ends'        => $ends,
			'placement'   => isset( $in['placement'] ) && 'bottom' === $in['placement'] ? 'bottom' : 'top',
			'pages'       => isset( $in['pages'] ) && in_array( $in['pages'], Settings::PAGES, true ) ? $in['pages'] : 'all',
			'bg'          => $color( 'bg' ),
			'text'        => $color( 'text' ),
		);
	}

	/**
	 * Sales to embed in a page: enabled, not ended, starting within 24 hours (pages may be cached;
	 * the browser shows each one only between its start and end).
	 *
	 * @param string $page_type Page type.
	 * @return array
	 */
	public static function for_page( $page_type ) {
		$now = time();
		$out = array();
		foreach ( self::all() as $sale ) {
			if ( empty( $sale['enabled'] ) || $sale['ends'] <= $now || $sale['starts'] > $now + DAY_IN_SECONDS ) {
				continue;
			}
			if ( 'cart_checkout' === $sale['pages'] && ! in_array( $page_type, array( 'cart', 'checkout' ), true ) ) {
				continue;
			}
			if ( 'shop' === $sale['pages'] && ! in_array( $page_type, array( 'cart', 'checkout', 'product', 'shop' ), true ) ) {
				continue;
			}
			unset( $sale['title'], $sale['enabled'] );
			$out[] = $sale;
		}
		return array_slice( $out, 0, 3 );
	}

	/**
	 * AI-written micro-copy for a sale.
	 *
	 * @param string $brief   What the sale is (e.g. "20% off all hoodies this weekend").
	 * @param string $tone    Tone.
	 * @param int    $minutes Duration in minutes (context only).
	 * @return array|\WP_Error { headline, message, cta_label }
	 */
	public static function generate_copy( $brief, $tone = 'energetic', $minutes = 0 ) {
		$brief = Copy::clean( $brief, 300 );
		if ( '' === $brief ) {
			return new \WP_Error( 'dev3sc_brief', __( 'Describe the sale first.', 'dev3-ai-smart-coupons-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$banner = Settings::group( 'banner' );
		$system = 'You write micro-copy for e-commerce flash-sale banners. Be concise, concrete and honest; no claims the merchant did not make (no "limited stock", "selling fast" or invented numbers), no emojis, no ALL CAPS, no links. The headline may restate the deal exactly as the merchant described it.';
		if ( '' !== trim( (string) $banner['brand_voice'] ) ) {
			$system .= ' Brand voice: ' . Copy::clean( $banner['brand_voice'], 300 );
		}
		$result = Ai::run(
			'flash_copy',
			array(
				'model'      => 'default',
				'system'     => $system,
				'prompt'     => wp_json_encode(
					array(
						'sale'     => $brief,
						'tone'     => Copy::clean( $tone, 40 ),
						'duration' => $minutes > 0 ? round( $minutes / 60, 1 ) . ' hours' : 'limited time',
						'store'    => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
					)
				),
				'schema'     => array(
					'type'             => 'OBJECT',
					'properties'       => array(
						'headline'  => array(
							'type'      => 'STRING',
							'maxLength' => 70,
						),
						'message'   => array(
							'type'      => 'STRING',
							'maxLength' => 150,
						),
						'cta_label' => array(
							'type'      => 'STRING',
							'maxLength' => 24,
						),
					),
					'required'         => array( 'headline', 'message', 'cta_label' ),
					'propertyOrdering' => array( 'headline', 'message', 'cta_label' ),
				),
				'max_tokens' => 1024,
				'timeout'    => 45,
			),
			'admin'
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data = (array) $result['data'];
		return array(
			'headline'  => Copy::clean( isset( $data['headline'] ) ? $data['headline'] : '', 90 ),
			'message'   => Copy::clean( isset( $data['message'] ) ? $data['message'] : '', 180 ),
			'cta_label' => Copy::clean( isset( $data['cta_label'] ) ? $data['cta_label'] : '', 30 ),
		);
	}
}
