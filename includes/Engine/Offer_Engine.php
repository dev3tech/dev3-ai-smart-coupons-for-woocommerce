<?php
/**
 * Turns a storefront trigger into (at most) one safe, single-use offer.
 *
 * Order of gates: session → active offer reuse → cart/min spend → once per session/email/IP per
 * day → risk threshold → budget → AI proposal (optional) → clamp → deterministic fallback.
 *
 * @package Dev3\AiSmartCoupons
 */

namespace Dev3\AiSmartCoupons\Engine;

use Dev3\AiSmartCoupons\Core3\Api\Ai;
use Dev3\AiSmartCoupons\Core3\Core\Rate_Limiter;
use Dev3\AiSmartCoupons\Core3\Core\Settings_Store;
use Dev3\AiSmartCoupons\Coupons\Coupon_Factory;
use Dev3\AiSmartCoupons\Data\Offers_Repository;
use Dev3\AiSmartCoupons\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Offer orchestration.
 */
class Offer_Engine {

	const SESSION_CODES = 'dev3sc_codes';
	const AOV_TRANSIENT = 'dev3sc_aov';

	/**
	 * Keyed hash (never reversible without the site salts).
	 *
	 * @param string $value Value.
	 * @return string 32 hex chars or ''.
	 */
	public static function hash( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return '' === $value ? '' : substr( hash_hmac( 'sha256', $value, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * Identity of the current shopper, from the WooCommerce session.
	 *
	 * @return array { session_hash, email, email_hash, ip_hash, user_id }
	 */
	public static function identity() {
		$session = function_exists( 'WC' ) && WC()->session ? WC()->session : null;
		$cid     = $session && $session->has_session() ? (string) $session->get_customer_id() : '';
		$user_id = get_current_user_id();
		$email   = '';
		if ( $user_id ) {
			$user  = get_userdata( $user_id );
			$email = $user ? (string) $user->user_email : '';
		} elseif ( WC()->customer ) {
			$email = (string) WC()->customer->get_billing_email();
		}
		$email = is_email( $email ) ? strtolower( $email ) : '';
		return array(
			'session_hash' => self::hash( $cid ),
			'email'        => $email,
			'email_hash'   => self::hash( $email ),
			'ip_hash'      => self::hash( Rate_Limiter::client_ip() ),
			'user_id'      => (int) $user_id,
		);
	}

	/**
	 * Store average order value (paid orders, last 90 days), cached for 12 hours.
	 *
	 * @return float
	 */
	public static function aov() {
		$cached = get_transient( self::AOV_TRANSIENT );
		if ( false !== $cached ) {
			return (float) $cached;
		}
		$orders = wc_get_orders(
			array(
				'limit'        => 200,
				'status'       => wc_get_is_paid_statuses(),
				'date_created' => '>' . ( time() - 90 * DAY_IN_SECONDS ),
				'type'         => 'shop_order',
			)
		);
		$sum    = 0.0;
		foreach ( $orders as $order ) {
			$sum += (float) $order->get_total();
		}
		$aov = $orders ? $sum / count( $orders ) : 0.0;
		/**
		 * AOV used when the store has no recent orders.
		 *
		 * @param float $fallback Fallback.
		 */
		$aov = $aov > 0 ? $aov : (float) apply_filters( 'dev3sc_fallback_aov', 60.0 );
		set_transient( self::AOV_TRANSIENT, $aov, 12 * HOUR_IN_SECONDS );
		return $aov;
	}

	/**
	 * Order history for a logged-in customer.
	 *
	 * @param int $user_id User.
	 * @return array { order_count, days_since_last_order }
	 */
	public static function history( $user_id ) {
		if ( ! $user_id ) {
			return array(
				'order_count'           => 0,
				'days_since_last_order' => null,
			);
		}
		$last = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'limit'       => 1,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'status'      => wc_get_is_paid_statuses(),
				'type'        => 'shop_order',
			)
		);
		$days = null;
		if ( $last && $last[0]->get_date_created() ) {
			$days = (int) floor( ( time() - $last[0]->get_date_created()->getTimestamp() ) / DAY_IN_SECONDS );
		}
		return array(
			'order_count'           => (int) wc_get_customer_order_count( $user_id ),
			'days_since_last_order' => $days,
		);
	}

	/**
	 * Whether any shipping zone has a Free Shipping method that accepts coupons.
	 *
	 * @return bool
	 */
	public static function free_shipping_available() {
		if ( ! class_exists( '\WC_Shipping_Zones' ) ) {
			return false;
		}
		$zones   = \WC_Shipping_Zones::get_zones();
		$zones[] = array( 'zone_id' => 0 );
		foreach ( $zones as $zone_data ) {
			$zone = \WC_Shipping_Zones::get_zone( (int) $zone_data['zone_id'] );
			if ( ! $zone ) {
				continue;
			}
			foreach ( $zone->get_shipping_methods( true ) as $method ) {
				if ( 'free_shipping' === $method->id && in_array( $method->get_option( 'requires' ), array( 'coupon', 'either', 'both' ), true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Entry point for the tracker endpoint.
	 *
	 * @param array $signals Validated tracker signals.
	 * @return array { offer: array|null, reason: string }
	 */
	public static function handle_trigger( array $signals ) {
		$id = self::identity();
		if ( '' === $id['session_hash'] ) {
			return self::none( 'no_session' );
		}
		$active = Offers_Repository::active_for_session( $id['session_hash'] );
		if ( $active ) {
			return array(
				'offer'  => self::public_offer( $active ),
				'reason' => 'active',
			);
		}
		return self::evaluate( $signals, Cart_Snapshot::from_cart(), $id );
	}

	/**
	 * Evaluates a cart for an offer and creates it.
	 *
	 * @param array $signals  Signals.
	 * @param array $snapshot Cart snapshot.
	 * @param array $id       Identity.
	 * @return array { offer, reason, risk? }
	 */
	public static function evaluate( array $signals, array $snapshot, array $id ) {
		if ( ! Settings_Store::get( 'enabled', true ) ) {
			return self::none( 'disabled' );
		}
		$rules = Settings::group( 'rules' );
		if ( empty( $snapshot['item_count'] ) ) {
			return self::none( 'empty_cart' );
		}
		if ( (float) $snapshot['subtotal'] < (float) $rules['min_spend'] ) {
			return self::none( 'min_spend' );
		}
		if ( Offers_Repository::seen_recently( $id['session_hash'], $id['email_hash'], ! empty( $rules['limit_per_ip'] ) ? $id['ip_hash'] : '' ) ) {
			return self::none( 'already_offered' );
		}

		$risk = Risk_Score::score(
			array_merge(
				$signals,
				self::history( (int) $id['user_id'] ),
				array(
					'cart_value' => (float) $snapshot['subtotal'],
					'aov'        => self::aov(),
					'logged_in'  => $id['user_id'] > 0,
				)
			)
		);
		if ( $risk['score'] < (int) $rules['risk_threshold'] ) {
			return self::none( 'low_risk' ) + array( 'risk' => $risk['score'] );
		}

		$budget = Budget::remaining();
		if ( null !== $budget && $budget <= 0 ) {
			return self::none( 'budget' );
		}

		// One evaluation per session every 15 minutes, so failed evaluations cannot hammer the API.
		$lock = 'dev3sc_eval_' . $id['session_hash'];
		if ( get_transient( $lock ) ) {
			return self::none( 'cooldown' );
		}
		set_transient( $lock, 1, 15 * MINUTE_IN_SECONDS );

		$ctx    = array(
			'budget_remaining'        => $budget,
			'free_shipping_available' => self::free_shipping_available(),
			'banner'                  => Settings::group( 'banner' ),
		);
		$offer  = null;
		$source = 'fallback';
		if ( Settings_Store::get( 'ai_enabled', true ) && '' !== Settings_Store::api_key() ) {
			$proposal = self::ask_ai( $snapshot, $risk, $rules, $signals, $ctx );
			if ( ! is_wp_error( $proposal ) ) {
				$clamped = Rules::clamp( $proposal, $snapshot, $rules, $ctx );
				if ( ! is_wp_error( $clamped ) ) {
					$offer  = $clamped;
					$source = 'ai';
				}
			}
		}
		if ( ! $offer ) {
			$offer = Rules::fallback( $risk['score'], $snapshot, $rules, $ctx );
			if ( is_wp_error( $offer ) ) {
				return self::none( 'no_valid_offer' ) + array( 'risk' => $risk['score'] );
			}
		}
		/**
		 * Final chance to veto or adjust an offer (must stay within the rules).
		 *
		 * @param array $offer    Clamped offer.
		 * @param array $snapshot Cart snapshot.
		 * @param array $risk     Risk result.
		 */
		$offer = apply_filters( 'dev3sc_offer', $offer, $snapshot, $risk );
		if ( ! is_array( $offer ) ) {
			return self::none( 'vetoed' );
		}

		$expires = time() + (int) $offer['urgency_minutes'] * MINUTE_IN_SECONDS;
		$coupon  = Coupon_Factory::create(
			$offer,
			$rules,
			array(
				'session_hash' => $id['session_hash'],
				'email'        => $id['email'],
				'subtotal'     => $snapshot['subtotal'],
				'expires'      => $expires,
			)
		);
		if ( is_wp_error( $coupon ) ) {
			return self::none( 'coupon_failed' );
		}
		$row_id = Offers_Repository::insert(
			array(
				'expires_at'     => gmdate( 'Y-m-d H:i:s', $expires ),
				'session_hash'   => $id['session_hash'],
				'email_hash'     => $id['email_hash'],
				'ip_hash'        => $id['ip_hash'],
				'user_id'        => (int) $id['user_id'],
				'coupon_id'      => $coupon->get_id(),
				'code'           => $coupon->get_code(),
				'type'           => $offer['type'],
				'amount'         => $offer['amount'],
				'discount_value' => $offer['discount_value'],
				'cart_value'     => (float) $snapshot['subtotal'],
				'risk'           => $risk['score'],
				'trigger_type'   => isset( $signals['trigger'] ) ? (string) $signals['trigger'] : '',
				'page_type'      => isset( $signals['page_type'] ) ? (string) $signals['page_type'] : '',
				'source'         => $source,
				'headline'       => Settings::limit( $offer['headline'], 160 ),
				'message'        => Settings::limit( $offer['message'], 255 ),
				'cta'            => Settings::limit( $offer['cta'], 60 ),
				'discount_label' => Settings::limit( $offer['discount_label'], 60 ),
			)
		);
		$coupon->update_meta_data( Coupon_Factory::META_OFFER, $row_id );
		$coupon->save_meta_data();
		Budget::reserve( $offer['discount_value'] );
		self::remember_code( $coupon->get_code() );

		$row = Offers_Repository::get( $row_id );
		return array(
			'offer'  => $row ? self::public_offer( $row ) : null,
			'reason' => 'created',
			'risk'   => $risk['score'],
		);
	}

	/**
	 * Remembers a code in the WooCommerce session (binds the coupon to this shopper).
	 *
	 * @param string $code Code.
	 */
	public static function remember_code( $code ) {
		if ( function_exists( 'WC' ) && WC()->session ) {
			$codes   = (array) WC()->session->get( self::SESSION_CODES, array() );
			$codes[] = strtoupper( $code );
			WC()->session->set( self::SESSION_CODES, array_values( array_unique( array_slice( $codes, -5 ) ) ) );
		}
	}

	/**
	 * Asks the model for a proposal.
	 *
	 * @param array $snapshot Snapshot.
	 * @param array $risk     Risk.
	 * @param array $rules    Rules.
	 * @param array $signals  Signals.
	 * @param array $ctx      Clamp context.
	 * @return array|\WP_Error
	 */
	public static function ask_ai( array $snapshot, array $risk, array $rules, array $signals, array $ctx ) {
		$elig    = Rules::eligibility( $snapshot, $rules );
		$allowed = array_values( array_intersect( Settings::TYPES, (array) $rules['allowed_types'] ) );
		if ( empty( $ctx['free_shipping_available'] ) ) {
			$allowed = array_values( array_diff( $allowed, array( 'free_shipping' ) ) );
		}
		if ( ! $allowed ) {
			return new \WP_Error( 'dev3sc_no_types', 'No types.' );
		}
		$banner  = isset( $ctx['banner'] ) ? $ctx['banner'] : Settings::banner_defaults();
		$segment = ! empty( $signals['returning'] ) ? 'returning visitor' : 'first-time visitor';
		$hist    = isset( $risk['signals']['order_count'] ) ? (int) $risk['signals']['order_count'] : 0;
		$context = array(
			'currency'                  => $snapshot['currency'],
			'cart_value'                => round( (float) $snapshot['subtotal'], 2 ),
			'item_count'                => (int) $snapshot['item_count'],
			'categories'                => array_slice( (array) $snapshot['categories'], 0, 8 ),
			'store_average_order_value' => round( self::aov(), 2 ),
			'abandonment_risk'          => $risk['score'],
			'risk_reasons'              => wp_list_pluck( $risk['factors'], 'label' ),
			'trigger'                   => isset( $signals['trigger'] ) ? $signals['trigger'] : 'idle',
			'page'                      => isset( $signals['page_type'] ) ? $signals['page_type'] : 'other',
			'customer'                  => $segment . ( $hist ? ", {$hist} previous orders" : ', no previous orders' ),
			'limits'                    => array(
				'allowed_types'             => $allowed,
				'percent_range'             => array( (int) $rules['min_percent'], (int) min( $rules['max_percent'], floor( $elig['max_ratio'] * 100 ) ) ),
				'fixed_range'               => array( (float) $rules['min_fixed'], (float) $rules['max_fixed'] ),
				'urgency_minutes_range'     => array( (int) $rules['urgency_min'], (int) $rules['urgency_max'] ),
				'prefer_smallest_effective' => true,
			),
		);
		$system  = 'You are a conversion expert for an online store. Pick ONE incentive that is most likely to stop this shopper from abandoning the cart while costing the merchant as little as possible: higher risk justifies a bigger incentive, low-risk or loyal shoppers deserve a small one or free shipping. Stay inside the limits. '
			. 'Write short, honest, friendly banner copy in the store language (English unless told otherwise). NEVER write digits, prices, percentages, currency symbols, coupon codes, links or emojis in the copy: write the placeholder {discount} where the deal goes (it is replaced by text such as "10% off", "$5 off" or "free shipping", so never add "off" after it) and {minutes} for the time limit. No false scarcity claims beyond the time limit.';
		if ( '' !== trim( (string) $banner['brand_voice'] ) ) {
			$system .= ' Brand voice: ' . Copy::clean( $banner['brand_voice'], 300 );
		}
		$schema = array(
			'type'             => 'OBJECT',
			'properties'       => array(
				'type'            => array(
					'type' => 'STRING',
					'enum' => $allowed,
				),
				'amount'          => array(
					'type'        => 'NUMBER',
					'description' => 'Percent (for percent) or money amount (for fixed_cart); 0 for free_shipping.',
				),
				'urgency_minutes' => array( 'type' => 'INTEGER' ),
				'headline'        => array(
					'type'      => 'STRING',
					'maxLength' => Copy::LIMITS['headline'],
				),
				'message'         => array(
					'type'      => 'STRING',
					'maxLength' => Copy::LIMITS['message'],
				),
				'cta'             => array(
					'type'      => 'STRING',
					'maxLength' => Copy::LIMITS['cta'],
				),
				'reason'          => array(
					'type'      => 'STRING',
					'maxLength' => 160,
				),
			),
			'required'         => array( 'type', 'amount', 'urgency_minutes', 'headline', 'message', 'cta' ),
			'propertyOrdering' => array( 'type', 'amount', 'urgency_minutes', 'headline', 'message', 'cta', 'reason' ),
		);
		$result = Ai::run(
			'incentive',
			array(
				'model'      => 'default',
				'system'     => $system,
				'prompt'     => "Shopper context (JSON):\n" . wp_json_encode( $context ) . "\nReturn the incentive as JSON.",
				'schema'     => $schema,
				'max_tokens' => 1024,
				/**
				 * Storefront AI timeout in seconds (the shopper is waiting).
				 *
				 * @param int $seconds Seconds.
				 */
				'timeout'    => (int) apply_filters( 'dev3sc_storefront_ai_timeout', 20 ),
			),
			'storefront'
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return is_array( $result['data'] ) ? $result['data'] : new \WP_Error( 'dev3sc_bad_json', 'Bad JSON.' );
	}

	/**
	 * Session-bound one-click apply token (WooCommerce only maps guest nonces for its own actions,
	 * so a WP nonce would be identical for every guest).
	 *
	 * @param string $code Coupon code.
	 * @return string
	 */
	public static function apply_token( $code ) {
		$cid = function_exists( 'WC' ) && WC()->session && WC()->session->has_session() ? (string) WC()->session->get_customer_id() : '';
		return substr( hash_hmac( 'sha256', strtoupper( (string) $code ) . '|' . $cid, wp_salt( 'nonce' ) ), 0, 20 );
	}

	/**
	 * Data safe to send to the shopper who owns the offer.
	 *
	 * @param array $row Offer row.
	 * @return array
	 */
	public static function public_offer( array $row ) {
		$code    = isset( $row['code'] ) ? strtoupper( (string) $row['code'] ) : '';
		$applied = '' !== $code && function_exists( 'WC' ) && WC()->cart && WC()->cart->has_discount( $code );
		return array(
			'id'             => (int) $row['id'],
			'code'           => $code,
			'type'           => (string) $row['type'],
			'discount_label' => (string) $row['discount_label'],
			'headline'       => (string) $row['headline'],
			'message'        => (string) $row['message'],
			'cta'            => (string) $row['cta'],
			'expires_at'     => (int) strtotime( $row['expires_at'] . ' UTC' ),
			'now'            => time(),
			'applied'        => (bool) $applied,
			'apply_token'    => self::apply_token( $code ),
			'cart_url'       => wc_get_cart_url(),
		);
	}

	/**
	 * Empty result.
	 *
	 * @param string $reason Reason code.
	 * @return array
	 */
	private static function none( $reason ) {
		return array(
			'offer'  => null,
			'reason' => $reason,
		);
	}
}
