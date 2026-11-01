<?php
/**
 * Plugin-specific settings (shared AI settings live in Core3\Core\Settings_Store).
 *
 * Groups: `rules` (safety rules), `triggers` (tracker + consent), `banner` (design + copy).
 *
 * @package Dev3\AiSmartCoupons
 */

namespace Dev3\AiSmartCoupons;

use Dev3\AiSmartCoupons\Core3\Core\Settings_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Hooked into dev3sc_settings_defaults / dev3sc_settings_sanitize.
 */
class Settings {

	const TYPES     = array( 'percent', 'fixed_cart', 'free_shipping' );
	const POSITIONS = array( 'bottom', 'top', 'corner', 'modal' );
	const PAGES     = array( 'cart_checkout', 'shop', 'all' );

	/**
	 * Safety-rule defaults.
	 *
	 * @return array
	 */
	public static function rule_defaults() {
		return array(
			'allowed_types'          => array( 'percent', 'fixed_cart', 'free_shipping' ),
			'min_percent'            => 5,
			'max_percent'            => 15,
			'min_fixed'              => 2,
			'max_fixed'              => 20,
			'min_spend'              => 30,
			'min_margin_percent'     => 15,
			'cost_meta_key'          => '_wc_cog_cost',
			'missing_cost'           => 'allow',
			'exclude_sale_items'     => true,
			'excluded_product_ids'   => array(),
			'excluded_category_ids'  => array(),
			'risk_threshold'         => 55,
			'daily_budget'           => 250,
			'limit_per_ip'           => true,
			'individual_use'         => true,
			'urgency_min'            => 10,
			'urgency_max'            => 30,
			'code_prefix'            => 'SAVE',
			'fallback'               => 'deterministic',
			'fallback_type'          => 'percent',
			'free_shipping_estimate' => 6,
			'retention_days'         => 3,
		);
	}

	/**
	 * Trigger defaults.
	 *
	 * @return array
	 */
	public static function trigger_defaults() {
		return array(
			'exit_intent'    => true,
			'mobile_exit'    => true,
			'idle'           => true,
			'idle_seconds'   => 30,
			'min_dwell'      => 4,
			'pages'          => 'all',
			'consent'        => 'none',
			'consent_cookie' => '',
			'respect_dnt'    => true,
		);
	}

	/**
	 * Banner defaults.
	 *
	 * @return array
	 */
	public static function banner_defaults() {
		return array(
			'position'         => 'bottom',
			'bg'               => '#111827',
			'text'             => '#ffffff',
			'accent'           => '#f59e0b',
			'accent_text'      => '#111827',
			'radius'           => 10,
			'show_countdown'   => true,
			'inline_cart'      => true,
			'inline_checkout'  => true,
			'brand_voice'      => '',
			'fallback_heading' => 'Wait — here is {discount} just for you',
			'fallback_message' => 'Complete your order in the next {minutes} minutes and the discount is yours.',
			'fallback_cta'     => 'Apply discount',
		);
	}

	/**
	 * Adds plugin defaults.
	 *
	 * @param array $defaults Shared defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		return array_merge(
			$defaults,
			array(
				'enabled'    => true,
				'ai_enabled' => true,
				'rules'      => self::rule_defaults(),
				'triggers'   => self::trigger_defaults(),
				'banner'     => self::banner_defaults(),
			)
		);
	}

	/**
	 * Sanitizes plugin keys. Each group may be sent partially (one admin tab at a time).
	 *
	 * @param array $out     Sanitized so far.
	 * @param array $input   Raw input.
	 * @param array $current Current.
	 * @return array
	 */
	public static function sanitize( $out, $input, $current ) {
		foreach ( array( 'enabled', 'ai_enabled' ) as $flag ) {
			if ( isset( $input[ $flag ] ) ) {
				$out[ $flag ] = (bool) $input[ $flag ];
			}
		}
		if ( isset( $input['rules'] ) && is_array( $input['rules'] ) ) {
			$out['rules'] = self::sanitize_rules( $input['rules'], isset( $current['rules'] ) ? (array) $current['rules'] : self::rule_defaults() );
		}
		if ( isset( $input['triggers'] ) && is_array( $input['triggers'] ) ) {
			$out['triggers'] = self::sanitize_triggers( $input['triggers'], isset( $current['triggers'] ) ? (array) $current['triggers'] : self::trigger_defaults() );
		}
		if ( isset( $input['banner'] ) && is_array( $input['banner'] ) ) {
			$out['banner'] = self::sanitize_banner( $input['banner'], isset( $current['banner'] ) ? (array) $current['banner'] : self::banner_defaults() );
		}
		return $out;
	}

	/**
	 * Clamp helper (never absint: negatives must clamp, not flip).
	 *
	 * @param mixed $value Raw.
	 * @param float $min   Min.
	 * @param float $max   Max.
	 * @param bool  $is_int Integer.
	 * @return float|int
	 */
	public static function clamp( $value, $min, $max, $is_int = false ) {
		$num = is_numeric( $value ) ? (float) $value : (float) $min;
		$num = min( (float) $max, max( (float) $min, $num ) );
		return $is_int ? (int) round( $num ) : round( $num, 2 );
	}

	/**
	 * List of positive ints.
	 *
	 * @param mixed $value Raw (array or comma string).
	 * @return int[]
	 */
	private static function id_list( $value ) {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}
		$ids = array();
		foreach ( (array) $value as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Rules.
	 *
	 * @param array $in  Raw.
	 * @param array $cur Current.
	 * @return array
	 */
	public static function sanitize_rules( array $in, array $cur ) {
		$r = array_merge( self::rule_defaults(), $cur );
		if ( isset( $in['allowed_types'] ) ) {
			$r['allowed_types'] = array_values( array_intersect( self::TYPES, array_map( 'strval', (array) $in['allowed_types'] ) ) );
		}
		$nums = array(
			'min_percent'            => array( 1, 90, true ),
			'max_percent'            => array( 1, 90, true ),
			'min_fixed'              => array( 0, 100000, false ),
			'max_fixed'              => array( 0, 100000, false ),
			'min_spend'              => array( 0, 1000000, false ),
			'min_margin_percent'     => array( 0, 500, false ),
			'risk_threshold'         => array( 0, 100, true ),
			'daily_budget'           => array( 0, 10000000, false ),
			'urgency_min'            => array( 1, 1440, true ),
			'urgency_max'            => array( 1, 1440, true ),
			'free_shipping_estimate' => array( 0, 100000, false ),
			'retention_days'         => array( 0, 365, true ),
		);
		foreach ( $nums as $key => $spec ) {
			if ( isset( $in[ $key ] ) ) {
				$r[ $key ] = self::clamp( $in[ $key ], $spec[0], $spec[1], $spec[2] );
			}
		}
		// Keep ranges consistent.
		$r['min_percent'] = min( $r['min_percent'], $r['max_percent'] );
		$r['min_fixed']   = min( $r['min_fixed'], $r['max_fixed'] );
		$r['urgency_min'] = min( $r['urgency_min'], $r['urgency_max'] );

		if ( isset( $in['cost_meta_key'] ) ) {
			$r['cost_meta_key'] = substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $in['cost_meta_key'] ), 0, 64 );
		}
		if ( isset( $in['missing_cost'] ) ) {
			$r['missing_cost'] = 'skip' === $in['missing_cost'] ? 'skip' : 'allow';
		}
		if ( isset( $in['fallback'] ) ) {
			$r['fallback'] = 'none' === $in['fallback'] ? 'none' : 'deterministic';
		}
		if ( isset( $in['fallback_type'] ) && in_array( $in['fallback_type'], self::TYPES, true ) ) {
			$r['fallback_type'] = $in['fallback_type'];
		}
		if ( isset( $in['code_prefix'] ) ) {
			$r['code_prefix'] = substr( strtoupper( preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $in['code_prefix'] ) ), 0, 12 );
		}
		foreach ( array( 'exclude_sale_items', 'limit_per_ip', 'individual_use' ) as $flag ) {
			if ( isset( $in[ $flag ] ) ) {
				$r[ $flag ] = (bool) $in[ $flag ];
			}
		}
		foreach ( array( 'excluded_product_ids', 'excluded_category_ids' ) as $list ) {
			if ( isset( $in[ $list ] ) ) {
				$r[ $list ] = self::id_list( $in[ $list ] );
			}
		}
		return $r;
	}

	/**
	 * Triggers.
	 *
	 * @param array $in  Raw.
	 * @param array $cur Current.
	 * @return array
	 */
	public static function sanitize_triggers( array $in, array $cur ) {
		$t = array_merge( self::trigger_defaults(), $cur );
		foreach ( array( 'exit_intent', 'mobile_exit', 'idle', 'respect_dnt' ) as $flag ) {
			if ( isset( $in[ $flag ] ) ) {
				$t[ $flag ] = (bool) $in[ $flag ];
			}
		}
		if ( isset( $in['idle_seconds'] ) ) {
			$t['idle_seconds'] = self::clamp( $in['idle_seconds'], 5, 600, true );
		}
		if ( isset( $in['min_dwell'] ) ) {
			$t['min_dwell'] = self::clamp( $in['min_dwell'], 0, 120, true );
		}
		if ( isset( $in['pages'] ) && in_array( $in['pages'], self::PAGES, true ) ) {
			$t['pages'] = $in['pages'];
		}
		if ( isset( $in['consent'] ) ) {
			$t['consent'] = 'cookie' === $in['consent'] ? 'cookie' : 'none';
		}
		if ( isset( $in['consent_cookie'] ) ) {
			$t['consent_cookie'] = substr( preg_replace( '/[^A-Za-z0-9_\-:=.]/', '', (string) $in['consent_cookie'] ), 0, 80 );
		}
		return $t;
	}

	/**
	 * Banner.
	 *
	 * @param array $in  Raw.
	 * @param array $cur Current.
	 * @return array
	 */
	public static function sanitize_banner( array $in, array $cur ) {
		$b = array_merge( self::banner_defaults(), $cur );
		if ( isset( $in['position'] ) && in_array( $in['position'], self::POSITIONS, true ) ) {
			$b['position'] = $in['position'];
		}
		foreach ( array( 'bg', 'text', 'accent', 'accent_text' ) as $color ) {
			if ( isset( $in[ $color ] ) ) {
				$hex = sanitize_hex_color( (string) $in[ $color ] );
				if ( $hex ) {
					$b[ $color ] = $hex;
				}
			}
		}
		if ( isset( $in['radius'] ) ) {
			$b['radius'] = self::clamp( $in['radius'], 0, 32, true );
		}
		foreach ( array( 'show_countdown', 'inline_cart', 'inline_checkout' ) as $flag ) {
			if ( isset( $in[ $flag ] ) ) {
				$b[ $flag ] = (bool) $in[ $flag ];
			}
		}
		$texts = array(
			'brand_voice'      => 300,
			'fallback_heading' => 80,
			'fallback_message' => 180,
			'fallback_cta'     => 30,
		);
		foreach ( $texts as $key => $len ) {
			if ( isset( $in[ $key ] ) ) {
				$b[ $key ] = self::limit( sanitize_text_field( wp_unslash( (string) $in[ $key ] ) ), $len );
			}
		}
		return $b;
	}

	/**
	 * Multibyte-safe truncation.
	 *
	 * @param string $text Text.
	 * @param int    $len  Max chars.
	 * @return string
	 */
	public static function limit( $text, $len ) {
		$text = trim( (string) $text );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $len ) : substr( $text, 0, $len );
	}

	/**
	 * Group accessor.
	 *
	 * @param string $group rules|triggers|banner.
	 * @return array
	 */
	public static function group( $group ) {
		$defaults = array(
			'rules'    => self::rule_defaults(),
			'triggers' => self::trigger_defaults(),
			'banner'   => self::banner_defaults(),
		);
		$value    = Settings_Store::get( $group, array() );
		return array_merge( $defaults[ $group ], is_array( $value ) ? $value : array() );
	}
}
