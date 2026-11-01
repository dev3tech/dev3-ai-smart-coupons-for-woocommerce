<?php
/**
 * Merchant safety rules. Every incentive — from the AI or the fallback — passes through clamp().
 * The model's output is treated as an untrusted suggestion.
 *
 * @package Dev3\AiSmartCoupons
 */

namespace Dev3\AiSmartCoupons\Engine;

use Dev3\AiSmartCoupons\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Pure functions over a cart snapshot (see Cart_Snapshot) and the rules array.
 */
class Rules {

	/**
	 * Which cart lines a generated coupon may discount, and how much margin room they have.
	 *
	 * Room per line = line total − cost × qty × (1 + min margin %). The most constrained line sets
	 * the maximum discount ratio, because percent and fixed-cart discounts are spread proportionally.
	 * Lines with no room are excluded from the coupon instead of killing the whole offer.
	 *
	 * @param array $snapshot Cart snapshot.
	 * @param array $rules    Rules.
	 * @return array { lines, product_ids, excluded_ids, eligible_subtotal, max_ratio, margin_room }
	 */
	public static function eligibility( array $snapshot, array $rules ) {
		$rules         = array_merge( Settings::rule_defaults(), $rules );
		$excl_products = array_map( 'intval', (array) $rules['excluded_product_ids'] );
		$excl_cats     = array_map( 'intval', (array) $rules['excluded_category_ids'] );
		$margin        = max( 0, (float) $rules['min_margin_percent'] ) / 100;
		$lines         = array();
		$excluded      = array();
		$subtotal      = 0.0;
		$room_total    = 0.0;
		$max_ratio     = 1.0;

		foreach ( isset( $snapshot['lines'] ) ? (array) $snapshot['lines'] : array() as $line ) {
			$pid    = (int) $line['product_id'];
			$parent = isset( $line['parent_id'] ) ? (int) $line['parent_id'] : 0;
			$total  = max( 0.0, (float) $line['line_total'] );
			$qty    = max( 1, (int) $line['qty'] );
			$cost   = isset( $line['unit_cost'] ) && null !== $line['unit_cost'] && '' !== $line['unit_cost'] ? max( 0.0, (float) $line['unit_cost'] ) : null;
			$cats   = array_map( 'intval', isset( $line['category_ids'] ) ? (array) $line['category_ids'] : array() );
			$reason = '';

			if ( $total <= 0 ) {
				$reason = 'free';
			} elseif ( in_array( $pid, $excl_products, true ) || ( $parent && in_array( $parent, $excl_products, true ) ) ) {
				$reason = 'excluded_product';
			} elseif ( array_intersect( $cats, $excl_cats ) ) {
				$reason = 'excluded_category';
			} elseif ( ! empty( $line['on_sale'] ) && ! empty( $rules['exclude_sale_items'] ) ) {
				$reason = 'on_sale';
			} elseif ( null === $cost && 'skip' === $rules['missing_cost'] ) {
				$reason = 'no_cost';
			}

			$room = null === $cost ? $total : $total - $cost * $qty * ( 1 + $margin );
			if ( '' === $reason && $room <= 0.0001 ) {
				$reason = 'margin';
			}
			if ( '' !== $reason ) {
				$excluded[] = array(
					'product_id' => $pid,
					'reason'     => $reason,
				);
				continue;
			}
			$lines[]     = $line + array( 'room' => $room );
			$subtotal   += $total;
			$room_total += $room;
			$max_ratio   = min( $max_ratio, $room / $total );
		}

		return array(
			'lines'             => $lines,
			'product_ids'       => array_values( array_unique( wp_list_pluck( $lines, 'product_id' ) ) ),
			'excluded'          => $excluded,
			'excluded_ids'      => array_values( array_unique( wp_list_pluck( $excluded, 'product_id' ) ) ),
			'eligible_subtotal' => round( $subtotal, 4 ),
			'max_ratio'         => $lines ? max( 0, $max_ratio ) : 0,
			'margin_room'       => round( $room_total, 4 ),
		);
	}

	/**
	 * Clamps a proposal to the rules. Never trusts any field of the proposal.
	 *
	 * @param array $proposal { type, amount, urgency_minutes, headline, message, cta }.
	 * @param array $snapshot Cart snapshot.
	 * @param array $rules    Rules.
	 * @param array $ctx      { budget_remaining: float|null (null = unlimited), free_shipping_available: bool, banner: array }.
	 * @return array|\WP_Error Final offer.
	 */
	public static function clamp( array $proposal, array $snapshot, array $rules, array $ctx = array() ) {
		$rules   = array_merge( Settings::rule_defaults(), $rules );
		$allowed = array_values( array_intersect( Settings::TYPES, (array) $rules['allowed_types'] ) );
		$adjust  = array();
		if ( ! $allowed ) {
			return new \WP_Error( 'dev3sc_no_types', 'No incentive types are allowed.' );
		}
		$subtotal = isset( $snapshot['subtotal'] ) ? (float) $snapshot['subtotal'] : 0.0;
		if ( $subtotal < (float) $rules['min_spend'] ) {
			return new \WP_Error( 'dev3sc_min_spend', 'Cart is below the minimum spend.' );
		}

		$type = isset( $proposal['type'] ) ? (string) $proposal['type'] : '';
		if ( ! in_array( $type, $allowed, true ) ) {
			$adjust[] = 'type';
			$type     = in_array( $rules['fallback_type'], $allowed, true ) ? $rules['fallback_type'] : $allowed[0];
		}
		$raw_amount = isset( $proposal['amount'] ) && is_numeric( $proposal['amount'] ) && is_finite( (float) $proposal['amount'] ) ? (float) $proposal['amount'] : 0.0;
		$budget     = array_key_exists( 'budget_remaining', $ctx ) && null !== $ctx['budget_remaining'] ? max( 0.0, (float) $ctx['budget_remaining'] ) : null;
		$elig       = self::eligibility( $snapshot, $rules );

		if ( 'free_shipping' === $type ) {
			if ( empty( $ctx['free_shipping_available'] ) ) {
				return new \WP_Error( 'dev3sc_no_free_shipping', 'No free-shipping method accepts coupons.' );
			}
			$value = isset( $snapshot['shipping'] ) && (float) $snapshot['shipping'] > 0 ? (float) $snapshot['shipping'] : (float) $rules['free_shipping_estimate'];
			if ( $value > $elig['margin_room'] ) {
				return new \WP_Error( 'dev3sc_margin', 'Free shipping would cut into the protected margin.' );
			}
			if ( null !== $budget && $value > $budget ) {
				return new \WP_Error( 'dev3sc_budget', 'Daily discount budget exhausted.' );
			}
			$amount = 0.0;
		} else {
			if ( ! $elig['lines'] || $elig['eligible_subtotal'] <= 0 ) {
				return new \WP_Error( 'dev3sc_no_eligible', 'No cart item can be discounted within the rules.' );
			}
			$base = $elig['eligible_subtotal'];
			if ( 'percent' === $type ) {
				$min    = (int) $rules['min_percent'];
				$amount = (float) min( (int) $rules['max_percent'], max( $min, (int) round( $raw_amount ) ) );
				$caps   = array( floor( $elig['max_ratio'] * 100 + 1e-9 ) );
				if ( null !== $budget ) {
					$caps[] = floor( $budget / $base * 100 + 1e-9 );
				}
				$capped = min( $amount, min( $caps ) );
				if ( $capped < $amount ) {
					$adjust[] = 'amount_capped';
				}
				$amount = (float) $capped;
				if ( $amount < $min || $amount <= 0 ) {
					return new \WP_Error( 'dev3sc_margin', 'The margin floor or budget leaves no room for the minimum percentage.' );
				}
				$value = round( $base * $amount / 100, 2 );
			} else {
				$min    = (float) $rules['min_fixed'];
				$amount = min( (float) $rules['max_fixed'], max( $min, $raw_amount ) );
				$caps   = array( $elig['max_ratio'] * $base, $base * (float) $rules['max_percent'] / 100 );
				if ( null !== $budget ) {
					$caps[] = $budget;
				}
				$capped = floor( min( $amount, min( $caps ) ) * 100 + 1e-6 ) / 100;
				if ( $capped < $amount ) {
					$adjust[] = 'amount_capped';
				}
				$amount = $capped;
				if ( $amount < $min || $amount <= 0 ) {
					return new \WP_Error( 'dev3sc_margin', 'The margin floor or budget leaves no room for the minimum fixed discount.' );
				}
				$value = $amount;
			}
		}
		if ( $raw_amount !== $amount && 'free_shipping' !== $type && ! in_array( 'amount_capped', $adjust, true ) ) {
			$adjust[] = 'amount';
		}

		$umin    = (int) $rules['urgency_min'];
		$umax    = max( $umin, (int) $rules['urgency_max'] );
		$minutes = isset( $proposal['urgency_minutes'] ) && is_numeric( $proposal['urgency_minutes'] ) ? (int) round( (float) $proposal['urgency_minutes'] ) : (int) round( ( $umin + $umax ) / 2 );
		$minutes = max( $umin, min( $umax, $minutes ) );

		$offer = array(
			'type'            => $type,
			'amount'          => $amount,
			'discount_value'  => round( (float) $value, 2 ),
			'urgency_minutes' => $minutes,
			'product_ids'     => 'free_shipping' === $type ? array() : $elig['product_ids'],
			'excluded_ids'    => $elig['excluded_ids'],
			'adjustments'     => $adjust,
		);
		$copy  = Copy::finalise( $proposal, $offer, isset( $ctx['banner'] ) ? (array) $ctx['banner'] : Settings::banner_defaults(), isset( $snapshot['currency_symbol'] ) ? (string) $snapshot['currency_symbol'] : '$' );
		if ( $copy['replaced'] ) {
			$offer['adjustments'][] = 'copy';
		}
		unset( $copy['replaced'] );
		return $offer + $copy;
	}

	/**
	 * Deterministic proposal scaled by risk (used when AI is off or fails).
	 *
	 * @param int    $risk  Risk score.
	 * @param array  $rules Rules.
	 * @param string $type Type.
	 * @return array Proposal.
	 */
	public static function fallback_proposal( $risk, array $rules, $type ) {
		$rules     = array_merge( Settings::rule_defaults(), $rules );
		$threshold = (int) $rules['risk_threshold'];
		$t         = $threshold >= 100 ? 1 : max( 0, min( 1, ( (int) $risk - $threshold ) / ( 100 - $threshold ) ) );
		$amount    = 0;
		if ( 'percent' === $type ) {
			$amount = (int) round( $rules['min_percent'] + ( $rules['max_percent'] - $rules['min_percent'] ) * $t );
		} elseif ( 'fixed_cart' === $type ) {
			$amount = round( $rules['min_fixed'] + ( $rules['max_fixed'] - $rules['min_fixed'] ) * $t );
		}
		return array(
			'type'            => $type,
			'amount'          => $amount,
			'urgency_minutes' => (int) round( $rules['urgency_max'] - ( $rules['urgency_max'] - $rules['urgency_min'] ) * $t ),
		);
	}

	/**
	 * Best deterministic offer within the rules: preferred type first, then the other allowed types.
	 *
	 * @param int   $risk     Risk score.
	 * @param array $snapshot Snapshot.
	 * @param array $rules    Rules.
	 * @param array $ctx      Clamp context.
	 * @return array|\WP_Error
	 */
	public static function fallback( $risk, array $snapshot, array $rules, array $ctx = array() ) {
		$rules = array_merge( Settings::rule_defaults(), $rules );
		if ( 'none' === $rules['fallback'] ) {
			return new \WP_Error( 'dev3sc_no_fallback', 'Fallback offers are disabled.' );
		}
		$order = array_values( array_unique( array_merge( array( $rules['fallback_type'] ), Settings::TYPES ) ) );
		$last  = new \WP_Error( 'dev3sc_no_types', 'No incentive types are allowed.' );
		foreach ( $order as $type ) {
			if ( ! in_array( $type, (array) $rules['allowed_types'], true ) ) {
				continue;
			}
			$offer = self::clamp( self::fallback_proposal( $risk, $rules, $type ), $snapshot, $rules, $ctx );
			if ( ! is_wp_error( $offer ) ) {
				return $offer;
			}
			$last = $offer;
		}
		return $last;
	}
}
