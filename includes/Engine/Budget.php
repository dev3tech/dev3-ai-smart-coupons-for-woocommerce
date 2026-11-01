<?php
/**
 * Global daily discount budget (store currency). Offers reserve their estimated value when issued.
 *
 * @package Dev3\AiSmartCoupons
 */

namespace Dev3\AiSmartCoupons\Engine;

use Dev3\AiSmartCoupons\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Stored as { day: Y-m-d (site time), spent: float } in one option.
 */
class Budget {

	const OPTION = 'dev3sc_budget';

	/**
	 * Today in site time.
	 *
	 * @return string
	 */
	public static function today() {
		return wp_date( 'Y-m-d' );
	}

	/**
	 * Current state (self-heals on a new day even if the reset action has not run yet).
	 *
	 * @return array
	 */
	public static function state() {
		$state = get_option( self::OPTION, array() );
		if ( ! is_array( $state ) || ! isset( $state['day'] ) || self::today() !== $state['day'] ) {
			$state = array(
				'day'   => self::today(),
				'spent' => 0.0,
			);
		}
		$state['spent'] = (float) $state['spent'];
		return $state;
	}

	/**
	 * Remaining budget, or null when unlimited.
	 *
	 * @return float|null
	 */
	public static function remaining() {
		$limit = (float) Settings::group( 'rules' )['daily_budget'];
		if ( $limit <= 0 ) {
			return null;
		}
		return max( 0.0, $limit - self::state()['spent'] );
	}

	/**
	 * Reserves an amount.
	 *
	 * @param float $amount Amount.
	 */
	public static function reserve( $amount ) {
		$state          = self::state();
		$state['spent'] = round( $state['spent'] + max( 0.0, (float) $amount ), 2 );
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Daily reset (Action Scheduler).
	 */
	public static function reset() {
		update_option(
			self::OPTION,
			array(
				'day'   => self::today(),
				'spent' => 0.0,
			),
			false
		);
	}
}
