<?php
/**
 * Deterministic, explainable abandonment-risk model (0–100).
 *
 * Every point comes from a named factor so merchants (and tests) can see exactly why a
 * score is what it is. No AI is involved here.
 *
 * @package Dev3\AiSmartCoupons
 */

namespace Dev3\AiSmartCoupons\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Weighted additive model.
 */
class Risk_Score {

	/**
	 * Default weights (points).
	 *
	 * @return array
	 */
	public static function weights() {
		$weights = array(
			'exit_intent'     => 30,
			'scroll_up'       => 24,
			'tab_hidden'      => 18,
			'idle_max'        => 25,
			'idle_floor'      => 10,
			'idle_cap'        => 120,
			'cart_high'       => 20,
			'cart_above'      => 12,
			'cart_typical'    => 6,
			'cart_low'        => 2,
			'visitor_new'     => 10,
			'visitor_return'  => 4,
			'no_orders'       => 10,
			'few_orders'      => -5,
			'loyal'           => -15,
			'recent_order'    => -10,
			'lapsed'          => 6,
			'repeat_visits_2' => 5,
			'repeat_visits_3' => 10,
			'checkout_page'   => 5,
		);
		/**
		 * Risk weights. Points are added per factor and the total is clamped to 0–100.
		 *
		 * @param array $weights Weights.
		 */
		return (array) apply_filters( 'dev3sc_risk_weights', $weights );
	}

	/**
	 * Normalises raw signals.
	 *
	 * @param array $s Raw signals.
	 * @return array
	 */
	public static function normalise( array $s ) {
		$days = isset( $s['days_since_last_order'] ) && null !== $s['days_since_last_order'] && '' !== $s['days_since_last_order'] ? max( 0, (int) $s['days_since_last_order'] ) : null;
		return array(
			'cart_value'            => max( 0.0, (float) ( isset( $s['cart_value'] ) ? $s['cart_value'] : 0 ) ),
			'aov'                   => max( 0.0, (float) ( isset( $s['aov'] ) ? $s['aov'] : 0 ) ),
			'idle_seconds'          => max( 0, min( 86400, (int) ( isset( $s['idle_seconds'] ) ? $s['idle_seconds'] : 0 ) ) ),
			'trigger'               => isset( $s['trigger'] ) ? (string) $s['trigger'] : 'idle',
			'is_returning'          => ! empty( $s['is_returning'] ),
			'logged_in'             => ! empty( $s['logged_in'] ),
			'order_count'           => max( 0, (int) ( isset( $s['order_count'] ) ? $s['order_count'] : 0 ) ),
			'days_since_last_order' => $days,
			'cart_visits'           => max( 0, min( 100, (int) ( isset( $s['cart_visits'] ) ? $s['cart_visits'] : 0 ) ) ),
			'page_type'             => isset( $s['page_type'] ) ? (string) $s['page_type'] : 'other',
		);
	}

	/**
	 * Scores signals.
	 *
	 * @param array $signals Signals (see normalise()).
	 * @return array { score:int, factors: list<{key,label,points}>, signals }
	 */
	public static function score( array $signals ) {
		$s       = self::normalise( $signals );
		$w       = self::weights();
		$factors = array();
		$add     = static function ( $key, $label, $points ) use ( &$factors ) {
			$points = (int) round( $points );
			if ( 0 !== $points ) {
				$factors[] = array(
					'key'    => $key,
					'label'  => $label,
					'points' => $points,
				);
			}
		};

		// 1. Intent trigger.
		if ( 'exit_intent' === $s['trigger'] ) {
			$add( 'exit_intent', __( 'Exit intent (pointer left the page)', 'dev3-ai-smart-coupons-for-woocommerce' ), $w['exit_intent'] );
		} elseif ( 'scroll_up' === $s['trigger'] ) {
			$add( 'scroll_up', __( 'Exit intent (fast scroll up on mobile)', 'dev3-ai-smart-coupons-for-woocommerce' ), $w['scroll_up'] );
		} elseif ( 'tab_hidden' === $s['trigger'] ) {
			$add( 'tab_hidden', __( 'Switched away from the tab', 'dev3-ai-smart-coupons-for-woocommerce' ), $w['tab_hidden'] );
		}

		// 2. Idle time: 0 below the floor, linear up to the cap.
		$floor = max( 0, (int) $w['idle_floor'] );
		$cap   = max( $floor + 1, (int) $w['idle_cap'] );
		if ( $s['idle_seconds'] > $floor ) {
			$ratio = min( 1, ( $s['idle_seconds'] - $floor ) / ( $cap - $floor ) );
			/* translators: %d: seconds */
			$add( 'idle', sprintf( __( 'Idle for %d seconds', 'dev3-ai-smart-coupons-for-woocommerce' ), $s['idle_seconds'] ), $w['idle_max'] * $ratio );
		}

		// 3. Cart value vs average order value (bigger carts hesitate more).
		if ( $s['cart_value'] > 0 && $s['aov'] > 0 ) {
			$r = $s['cart_value'] / $s['aov'];
			if ( $r >= 2 ) {
				$add( 'cart_value', __( 'Cart is at least 2× your average order', 'dev3-ai-smart-coupons-for-woocommerce' ), $w['cart_high'] );
			} elseif ( $r >= 1.25 ) {
				$add( 'cart_value', __( 'Cart is above your average order', 'dev3-ai-smart-coupons-for-woocommerce' ), $w['cart_above'] );
			} elseif ( $r >= 0.75 ) {
				$add( 'cart_value', __( 'Cart is close to your average order', 'dev3-ai-smart-coupons-for-woocommerce' ), $w['cart_typical'] );
			} else {
				$add( 'cart_value', __( 'Cart is below your average order', 'dev3-ai-smart-coupons-for-woocommerce' ), $w['cart_low'] );
			}
		}

		// 4. New vs returning visitor.
		if ( $s['is_returning'] || $s['logged_in'] ) {
			$add( 'visitor', __( 'Returning visitor', 'dev3-ai-smart-coupons-for-woocommerce' ), $w['visitor_return'] );
		} else {
			$add( 'visitor', __( 'First-time visitor', 'dev3-ai-smart-coupons-for-woocommerce' ), $w['visitor_new'] );
		}

		// 5. Order history (logged-in customers only; guests count as no history).
		if ( 0 === $s['order_count'] ) {
			$add( 'history', __( 'No previous orders', 'dev3-ai-smart-coupons-for-woocommerce' ), $w['no_orders'] );
		} elseif ( $s['order_count'] < 3 ) {
			$add( 'history', __( '1–2 previous orders', 'dev3-ai-smart-coupons-for-woocommerce' ), $w['few_orders'] );
		} else {
			$add( 'history', __( 'Loyal customer (3+ orders)', 'dev3-ai-smart-coupons-for-woocommerce' ), $w['loyal'] );
		}
		if ( $s['order_count'] > 0 && null !== $s['days_since_last_order'] ) {
			if ( $s['days_since_last_order'] <= 30 ) {
				$add( 'recency', __( 'Ordered in the last 30 days', 'dev3-ai-smart-coupons-for-woocommerce' ), $w['recent_order'] );
			} elseif ( $s['days_since_last_order'] > 180 ) {
				$add( 'recency', __( 'Lapsed customer (no order for 6+ months)', 'dev3-ai-smart-coupons-for-woocommerce' ), $w['lapsed'] );
			}
		}

		// 6. Repeated cart visits = hesitation.
		if ( $s['cart_visits'] >= 3 ) {
			/* translators: %d: visits */
			$add( 'cart_visits', sprintf( __( 'Viewed the cart %d times', 'dev3-ai-smart-coupons-for-woocommerce' ), $s['cart_visits'] ), $w['repeat_visits_3'] );
		} elseif ( 2 === $s['cart_visits'] ) {
			$add( 'cart_visits', __( 'Viewed the cart twice', 'dev3-ai-smart-coupons-for-woocommerce' ), $w['repeat_visits_2'] );
		}

		// 7. Leaving from checkout.
		if ( 'checkout' === $s['page_type'] ) {
			$add( 'checkout', __( 'On the checkout page', 'dev3-ai-smart-coupons-for-woocommerce' ), $w['checkout_page'] );
		}

		$total = 0;
		foreach ( $factors as $f ) {
			$total += $f['points'];
		}
		return array(
			'score'   => (int) max( 0, min( 100, $total ) ),
			'factors' => $factors,
			'signals' => $s,
		);
	}
}
